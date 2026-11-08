<?php

namespace Perk11\Viktor89;

use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\ContextLengthErrorDetector;
use Perk11\Viktor89\IPC\EchoUpdateCallback;
use Perk11\Viktor89\Repository\ChatSummaryRepository;
use Perk11\Viktor89\Repository\MessageRepository;
use Perk11\Viktor89\Util\ImageNormalizer;
use Perk11\Viktor89\Util\NormalizedImage;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * Summarizes a chat's messages in as few requests as the summary model's
 * context allows, splitting them into evenly sized batches that are
 * summarized independently when they do not fit into one. Batch sizes come
 * from an estimate of tokens per message that is corrected from the token
 * counts the server reports.
 *
 * @phpstan-type PromptItem array{message: InternalMessage, header: string, text: string, image: ?NormalizedImage, textTokens: int, fallbackTokens: int}
 * @phpstan-type Batch array{start: int, end: int, imageCount: int}
 */
class OpenAISummaryProvider
{
    public const LAST_SUMMARY_TIMESTAMP_SYSTEM_VARIABLE_NAME = 'last-summary-timestamp';

    private const int MAX_MESSAGE_CHARACTERS = 2000;
    /** The model writes one sentence per line; they are joined into paragraphs of this size to keep the summary compact. */
    private const int SENTENCES_PER_PARAGRAPH = 5;
    /** Batches above this many messages are asked to cover more threads than the system prompt's usual range. */
    private const int ACTIVE_CHAT_MESSAGE_THRESHOLD = 400;
    /** A typical day's message count and how many lines cover it well; busier batches scale from it by square root. */
    private const int TYPICAL_DAY_MESSAGES = 264;
    private const int TYPICAL_DAY_LINES = 12;
    /** Typical length of a generated alt text, used before it is generated. */
    private const int ESTIMATED_ALT_TEXT_CHARACTERS = 800;
    /** Chat template and content-part markup not covered by the per-message estimate. */
    private const int REQUEST_OVERHEAD_TOKENS = 256;
    private const int MAX_CONTEXT_OVERFLOW_RETRIES = 4;
    private const float CONTEXT_OVERFLOW_SAFETY_FACTOR = 1.02;

    private const string SYSTEM_PROMPT = <<<PROMPT
        Summarize messages sent in a group chat as a digest for its members. Respond only in Russian. Use past tense.
        Write exactly one sentence per discussion thread, each on its own line. Merge everything said on a topic, including its side branches, into that sentence: what it was about and what it came to (the conclusion, answer or recommendation), with the most important specifics (device models, versions, numbers), naming the one or two users who drove it. Never retell who said what in turn.
        Keep each sentence concise: describe the outcome rather than the process and leave out secondary details such as how long something took, intermediate steps or what someone held off doing.
        Write user names, brands, device models and versions exactly as they appear in the chat, without translating or transliterating them.
        Never enumerate steps, parts, reasons or examples, generalize them into a short phrase that keeps their meaning. For instance, instead of "Андрей рассказал, что ноутбук неделю перегревался, поэтому он заменил вентилятор, термопасту, два конденсатора и зарядку, потратив на это пять часов", write "Андрей за пять часов починил перегревавшийся ноутбук, обновив охлаждение и часть цепи питания".
        Technical discussions (hardware, electronics, software, programming, networking, repairs, firmware and the like) matter most: put them first. Some days have none, that is fine.
        Then add the most notable non-technical threads, a short sentence each. Skip greetings, one-off reactions, side jokes and minor remarks.
        Usually 6-12 lines are enough, a day with many substantial discussions may need a few more.
        Each message is formatted as "#<message id> <author>: <text>", "↩#<id>" after the author means the message is a reply to message <id>. Do not mention message ids in the summary.
        An image that follows a message was attached to that message, take what it shows into account.
        PROMPT;

    private readonly OpenAi $openAiClient;
    private readonly ImageNormalizer $imageNormalizer;

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly ChatSummaryRepository $chatSummaryRepository,
        private readonly AltTextProvider $altTextProvider,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly SummaryModelConfig $config,
        private readonly LoggerInterface $logger,
    )
    {
        $this->openAiClient = new OpenAi($config->apiKey);
        if ($config->url !== '') {
            $this->openAiClient->setBaseURL($config->url);
        }
        $this->imageNormalizer = new ImageNormalizer(
            ['image/jpeg', 'image/png'],
            $logger,
            maxEdgePixels: $config->maxImageEdgePixels,
            resizedEdgePixels: $config->maxImageEdgePixels,
        );
    }

    public function sendChatSummaryWithMessagesSinceLastOne(int $chatId): bool
    {
        $lastSummaryDate = $this->chatSummaryRepository->getLastChatSummaryDate($chatId);
        if ($lastSummaryDate === null) {
            $lastSummaryDate = 0;
        }

        $summary = $this->provideSummary($chatId, $lastSummaryDate);
        if ($summary === null) {
            return false;
        }
        $this->chatSummaryRepository->recordChatSummary($chatId, $summary);
        $summary = preg_replace('/<think>.*?<\/think>/s', '', $summary);
        $maxSize = 64000;
        $chunks = mb_str_split($summary, $maxSize);
        foreach ($chunks as $chunk) {
            $message = new InternalMessage();
            $message->parseMode = 'RichMarkdown';
            $message->chatId = $chatId;
            $message->messageText = "#summary\n<details>\n<summary>Сводка чата за последние 24 часа</summary>\n" . $chunk . "\n</details>\n";
            $message->send();
        }

        return true;
    }

    public function provideSummary(int $chatId, int $startTimestamp, int $maxMessages = 10000, int $endTimestamp = PHP_INT_MAX): ?string
    {
        $allMessages = $this->messageRepository->findMessagesSentAfterTimestampInChat($chatId, $startTimestamp, $endTimestamp);
        if (count($allMessages) < 10) {
            return null;
        }
        if (count($allMessages) > $maxMessages) {
            $allMessages = array_slice($allMessages, 0, $maxMessages);
        }
        $this->logger->log(LogLevel::INFO, "Generating summary of " . count($allMessages) . " messages found in chat $chatId");

        $sentences = [];
        foreach ($this->summarizeItems($this->buildPromptItems($allMessages)) as $batchSummary) {
            array_push($sentences, ...array_filter(array_map('trim', explode("\n", $batchSummary)), 'strlen'));
        }
        $paragraphs = array_map(
            static fn(array $paragraphSentences): string => implode(' ', $paragraphSentences),
            array_chunk($sentences, self::SENTENCES_PER_PARAGRAPH),
        );
        $summary = "Сообщений проанализировано: " . count($allMessages) . "\n\n" . implode("\n\n", $paragraphs);
        $this->logger->log(LogLevel::DEBUG, $summary);

        return $summary;
    }

    /**
     * @param InternalMessage[] $messages
     * @return list<PromptItem>
     */
    private function buildPromptItems(array $messages): array
    {
        $items = [];
        foreach ($messages as $message) {
            $text = trim(mb_substr($message->messageText, 0, self::MAX_MESSAGE_CHARACTERS));
            $image = null;
            if ($message->photoFileId !== null && $this->config->supportsImages) {
                $image = $this->downloadImage($message);
            }
            if ($image === null && ($message->photoFileId !== null || $text === '')) {
                $altText = $this->config->supportsImages
                    ? $message->altText ?? ($message->photoFileId !== null ? '[image]' : '')
                    : $this->altTextProvider->provide($message, $this->createUpdateCallback());
                $text = trim($altText . "\n" . $text);
            }
            if ($text === '' && $image === null) {
                continue;
            }
            $header = '#' . $message->id . ' ' . $message->userName
                . ($message->replyToMessageId !== null ? ' ↩#' . $message->replyToMessageId : '')
                . ': ';
            $textTokens = $this->estimateTextTokens($header . $text);
            $items[] = [
                'message'        => $message,
                'header'         => $header,
                'text'           => $text,
                'image'          => $image,
                'textTokens'     => $textTokens,
                'fallbackTokens' => $image === null ? $textTokens : $textTokens + $this->estimateTextTokens(
                    $message->altText ?? str_repeat(' ', self::ESTIMATED_ALT_TEXT_CHARACTERS)
                ),
            ];
        }

        return $items;
    }

    private function createUpdateCallback(): EchoUpdateCallback
    {
        return new EchoUpdateCallback(logger: $this->logger);
    }

    /** Describes an image that did not fit into the image budget with text instead. */
    private function buildFallbackText(array $item): string
    {
        if ($item['image'] === null) {
            return $item['header'] . $item['text'];
        }
        $altText = $this->altTextProvider->provide($item['message'], $this->createUpdateCallback()) ?? '[image]';

        return $item['header'] . trim($altText . "\n" . $item['text']);
    }

    private function downloadImage(InternalMessage $message): ?NormalizedImage
    {
        try {
            $image = $this->telegramFileDownloader->downloadPhotoFromInternalMessage($message);
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, "Failed to download image $message->photoFileId: " . $e->getMessage());
            return null;
        }

        return $this->imageNormalizer->normalize($image);
    }

    private function estimateTextTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / $this->config->charactersPerToken);
    }

    /**
     * @param list<PromptItem> $items
     * @return list<string>
     */
    private function summarizeItems(array $items): array
    {
        $promptTokenLimit = $this->config->contextSize - $this->config->maxResponseTokens;
        $fixedTokens = $this->estimateTextTokens(self::SYSTEM_PROMPT) + self::REQUEST_OVERHEAD_TOKENS;
        // Actual prompt tokens per estimated token, learned from the server's reports.
        $estimateScale = 1.0;
        $summaries = [];
        $overflowRetries = 0;
        while ($items !== []) {
            // Re-planned after every request, since the scale may have changed.
            $batches = $this->splitIntoBatches($items, $fixedTokens, $promptTokenLimit / $estimateScale);
            $batch = $batches[0];
            $batchItems = $this->selectImages(array_slice($items, $batch['start'], $batch['end'] - $batch['start']), $batch['imageCount']);
            $estimatedTokens = $fixedTokens + array_sum(array_column($batchItems, 'tokens'));
            $this->logger->log(LogLevel::INFO, sprintf(
                'Summarizing %d messages with %d images (%d messages left, %d batches planned), estimated prompt tokens: %d, limit %d',
                count($batchItems),
                $batch['imageCount'],
                count($items),
                count($batches),
                (int) ($estimatedTokens * $estimateScale),
                $promptTokenLimit,
            ));

            $startTime = microtime(true);
            $result = $this->requestSummary($batchItems);
            if ($result['overflow']) {
                if (count($batchItems) === 1 || ++$overflowRetries > self::MAX_CONTEXT_OVERFLOW_RETRIES) {
                    throw new RuntimeException('Summary prompt does not fit in the context even after shrinking: ' . $result['error']);
                }
                $estimateScale = $result['promptTokens'] !== null
                    ? max($estimateScale, $result['promptTokens'] / $estimatedTokens) * self::CONTEXT_OVERFLOW_SAFETY_FACTOR
                    : $estimateScale * 1.25;
                $this->logger->log(LogLevel::WARNING, "Summary prompt exceeded the context, retrying with a smaller batch: " . $result['error']);
                continue;
            }

            $overflowRetries = 0;
            if ($result['promptTokens'] !== null) {
                $estimateScale = $result['promptTokens'] / $estimatedTokens;
            }
            $this->logger->log(LogLevel::INFO, sprintf(
                'Batch summarized in %.1fs, actual prompt tokens: %s, completion tokens: %s',
                microtime(true) - $startTime,
                $result['promptTokens'] ?? 'unknown',
                $result['completionTokens'] ?? 'unknown',
            ));
            $summaries[] = $result['content'];
            $items = array_slice($items, $batch['end']);
        }

        return $summaries;
    }

    /**
     * Splits into as few batches as fit into the limit, sized as evenly as possible.
     *
     * @param list<PromptItem> $items
     * @return non-empty-list<Batch>
     */
    private function splitIntoBatches(array $items, int $fixedTokens, float $tokenLimit): array
    {
        $batches = $this->packGreedily($items, $fixedTokens, $tokenLimit);
        $batchCount = count($batches);
        if ($batchCount === 1) {
            return $batches;
        }
        $low = $tokenLimit / $batchCount;
        $high = $tokenLimit;
        while ($high - $low > 1) {
            $middle = ($low + $high) / 2;
            if (count($this->packGreedily($items, $fixedTokens, $middle)) <= $batchCount) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return $this->packGreedily($items, $fixedTokens, $high);
    }

    /**
     * Images take at most maxImageContextShare of each batch; the rest are
     * represented by their fallback text.
     *
     * @param list<PromptItem> $items
     * @return non-empty-list<Batch>
     */
    private function packGreedily(array $items, int $fixedTokens, float $tokenLimit): array
    {
        $imageTokenBudget = $tokenLimit * $this->config->maxImageContextShare;
        $batches = [];
        $start = 0;
        $tokens = $fixedTokens;
        $imageCount = 0;
        foreach ($items as $index => $item) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $usesImage = $item['image'] !== null && ($imageCount + 1) * $this->config->imageTokens <= $imageTokenBudget;
                $cost = $usesImage ? $item['textTokens'] + $this->config->imageTokens : $item['fallbackTokens'];
                if ($index === $start || $tokens + $cost <= $tokenLimit) {
                    break;
                }
                $batches[] = ['start' => $start, 'end' => $index, 'imageCount' => $imageCount];
                $start = $index;
                $tokens = $fixedTokens;
                $imageCount = 0;
            }
            $tokens += $cost;
            $imageCount += $usesImage ? 1 : 0;
        }
        $batches[] = ['start' => $start, 'end' => count($items), 'imageCount' => $imageCount];

        return $batches;
    }

    /**
     * Keeps $imageCount images, taking them from each user in turn so one
     * user posting many photos does not crowd out everyone else's.
     *
     * @param list<PromptItem> $items
     * @return list<array{text: string, image: ?NormalizedImage, tokens: int}>
     */
    private function selectImages(array $items, int $imageCount): array
    {
        $imageIndexesByUser = [];
        foreach ($items as $index => $item) {
            if ($item['image'] !== null) {
                $imageIndexesByUser[$item['message']->userId][] = $index;
            }
        }
        $selectedIndexes = [];
        for ($round = 0; count($selectedIndexes) < $imageCount; $round++) {
            foreach ($imageIndexesByUser as $indexes) {
                if (isset($indexes[$round]) && count($selectedIndexes) < $imageCount) {
                    $selectedIndexes[$indexes[$round]] = true;
                }
            }
        }

        $batchItems = [];
        foreach ($items as $index => $item) {
            $batchItems[] = isset($selectedIndexes[$index])
                ? ['text' => $item['header'] . $item['text'], 'image' => $item['image'], 'tokens' => $item['textTokens'] + $this->config->imageTokens]
                : ['text' => $this->buildFallbackText($item), 'image' => null, 'tokens' => $item['fallbackTokens']];
        }

        return $batchItems;
    }

    /**
     * @param list<array{text: string, image: ?NormalizedImage, tokens: int}> $batch
     * @return array{overflow: bool, content: string, promptTokens: ?int, completionTokens?: ?int, error: string}
     */
    private function requestSummary(array $batch): array
    {
        $systemPrompt = self::SYSTEM_PROMPT;
        if (count($batch) > self::ACTIVE_CHAT_MESSAGE_THRESHOLD) {
            $systemPrompt .= sprintf(
                "\nThis chat was very active (%d messages), so cover more threads than usual, about %d lines, keeping each sentence just as concise.",
                count($batch),
                round(self::TYPICAL_DAY_LINES * sqrt(count($batch) / self::TYPICAL_DAY_MESSAGES)),
            );
        }
        $request = [
            'messages'   => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $this->buildUserContent($batch)],
            ],
            'max_tokens' => $this->config->maxResponseTokens,
        ];
        if ($this->config->model !== null) {
            $request['model'] = $this->config->model;
        }
        $response = $this->openAiClient->chat($request);
        $statusCode = $this->openAiClient->getCURLInfo()['http_code'] ?? null;
        $parsedResponse = json_decode($response, true);
        if (!is_array($parsedResponse)) {
            throw new RuntimeException("Unexpected response from the summary API (HTTP $statusCode): $response");
        }

        if (!isset($parsedResponse['choices'][0]['message'])) {
            $error = $parsedResponse['error'] ?? null;
            $errorMessage = is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) ($error ?? $response);
            if (!ContextLengthErrorDetector::matches($statusCode, $errorMessage . ' ' . ($error['type'] ?? ''))) {
                throw new RuntimeException("Summary API error (HTTP $statusCode): $response");
            }

            return [
                'overflow'     => true,
                'content'      => '',
                'promptTokens' => isset($error['n_prompt_tokens']) ? (int) $error['n_prompt_tokens'] : null,
                'error'        => $errorMessage,
            ];
        }

        $content = (string) $parsedResponse['choices'][0]['message']['content'];
        if (preg_match('/<\|channel\|>final<\|message\|>(.*?)(?=<\|channel\|>|$)/s', $content, $matches)) {
            $content = $matches[1];
        }
        if (($parsedResponse['choices'][0]['finish_reason'] ?? null) === 'length') {
            $this->logger->log(LogLevel::WARNING, 'Summary response was cut off by maxResponseTokens');
        }

        return [
            'overflow'         => false,
            'content'          => trim($content),
            'promptTokens'     => isset($parsedResponse['usage']['prompt_tokens']) ? (int) $parsedResponse['usage']['prompt_tokens'] : null,
            'completionTokens' => $parsedResponse['usage']['completion_tokens'] ?? null,
            'error'            => '',
        ];
    }

    /**
     * @param list<array{text: string, image: ?NormalizedImage, tokens: int}> $batch
     */
    private function buildUserContent(array $batch): string|array
    {
        $parts = [];
        $text = '';
        foreach ($batch as $item) {
            $text .= $item['text'] . "\n";
            if ($item['image'] !== null) {
                $parts[] = ['type' => 'text', 'text' => $text];
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $item['image']->toDataUrl()]];
                $text = '';
            }
        }
        if ($parts === []) {
            return $text;
        }
        if ($text !== '') {
            $parts[] = ['type' => 'text', 'text' => $text];
        }

        return $parts;
    }
}
