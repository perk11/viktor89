<?php

namespace Perk11\Viktor89;

use Exception;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\IPC\EchoUpdateCallback;

class OpenAISummaryProvider
{
    public const LAST_SUMMARY_TIMESTAMP_SYSTEM_VARIABLE_NAME = 'last-summary-timestamp';
    private readonly OpenAi $openAiClient;

    public function __construct(
        private readonly Database $database,
        private readonly AltTextProvider $altTextProvider,
    )
    {
        $apiKey = $_ENV['SUMMARY_OPENAI_KEY'];
        $this->openAiClient = new OpenAi($apiKey);
        if (strlen($apiKey) === 0 && strlen($_ENV['SUMMARY_SERVER']) === 0) {
            throw new Exception('SUMMARY_OPENAI_KEY and SUMMARY_SERVER are both  empty, at least one is required');
        }
        if (isset($_ENV['SUMMARY_SERVER'])) {
            $this->openAiClient->setBaseURL($_ENV['SUMMARY_SERVER']);
        }

    }

    private const MESSAGES_ANALYZED_PER_BATCH = 2000;

    public function sendChatSummaryWithMessagesSinceLastOne(int $chatId): bool
    {
        $lastSummaryDate = $this->database->getLastChatSummaryDate($chatId);
        if ($lastSummaryDate === null) {
            $lastSummaryDate = 0;
        }

        $summary = $this->provideSummary($chatId, $lastSummaryDate);
        if ($summary === null) {
            return false;
        }
        $summary = preg_replace('/<think>.*?<\/think>/s', '', $summary);
        // Split the summary into chunks of 4000 characters
        $maxSize = 4000;
        $chunks = mb_str_split($summary, $maxSize);
        foreach ($chunks as $chunk) {
            $message = new InternalMessage();
            $message->parseMode = 'Default';
            $message->chatId = $chatId;
            $message->messageText = "#summary\n" . $chunk;
            $message->send();
        }

        return true;
    }

    public function provideSummary(int $chatId, int $startTimestamp, int $maxMessages = 10000): ?string
    {
        $allMessages = $this->database->findMessagesSentAfterTimestampInChat($chatId, $startTimestamp);
        if (count($allMessages) < 10) {
//            echo count($allMessages) . " messages found in chat $chatId in last 24 hours, no summary to provide\n";
            return null;
        }
        $summary = "Анализ чата за последние 24 часа\nСообщений проанализировано: ";
        if (count($allMessages) > $maxMessages) {
            $summary .= $maxMessages;
            $allMessages = array_slice($allMessages, 0, $maxMessages);
        } else {
            $summary .= count($allMessages);
        }
        echo "Generating summary of " . count($allMessages) . " messages found in chat $chatId in last 24 hours\n";

        $offset = 0;
        $numberOfBatches = ceil(count($allMessages) / self::MESSAGES_ANALYZED_PER_BATCH);
        $batchSize = ceil(count($allMessages) / $numberOfBatches);

        $updateCallback = new EchoUpdateCallback();
        while ($offset < count($allMessages)) {
            $messages = array_slice($allMessages, $offset, $batchSize);
            $prompt = '';
            $startingOffset = $offset;
            foreach ($messages as $message) {
                $text = $message->messageText;
                if ($message->photoFileId !== null || $message->messageText === '') {
                    $text = $this->altTextProvider->provide($message, $updateCallback) . "\n" . $text;
                }
                $offset++;
                $text = trim($text);
                if ($text !== '') {
                    $prompt .= $message->userName . ': ' . mb_substr($text, 0, 512) . "\n";
                }
                if (mb_strlen($prompt) > 36000 && (count($allMessages) - $offset) > 30) {
                    break;
                }
            }
            echo $offset - $startingOffset . " messages in this batch ($startingOffset-" . $offset-1 . "). Sending prompt of size " . mb_strlen($prompt) . " to OpenAI API...\n";
            $systemPrompt = "Summarize messages sent in a group chat. Respond only in Russian. Mention all main discussion thread conclusions, one per line. Do not shy away from naming users by name when relevant. Do not add any formatting. Use past tense.";
            if ($offset >= count($allMessages) - 1) {
//                $systemPrompt .= "After summarizing everything, finish by pointing the MVP of discussion and explain why they are the MVP.";
            }
            $systemPrompt .= "Message start below:";
            $result = $this->openAiClient->chat([
                                               'messages' => [
                                                   [
                                                       "role"    => "system",
                                                       "content" => $systemPrompt,
                                                   ],
                                                   [
                                                       "role"    => "user",
                                                       "content" => $prompt,
                                                   ],
                                               ],
//                                               'model'    => 'gpt-4',
//                                           'stream'   => false,
                                           ]);

            $parsedResult = json_decode($result, JSON_THROW_ON_ERROR);
            if (!array_key_exists('choices', $parsedResult)) {
                echo "Unexpected response from OpenAI: $result \n";
            }

            $response = $parsedResult['choices'][0]['message']['content'];
            if (
                preg_match(
                    '/<\|channel\|>final<\|message\|>(.*?)(?=<\|channel\|>|$)/s',
                    $response,
                    $matches
                )
            ) {
                $response = $matches[1];
            }

            $summary .= "\n" . str_replace("\n", " ", $response);
//            sleep(30); //avoid gpt-4 rate limit
        }
        echo $summary;
        $this->database->recordChatSummary($chatId, $summary);

        return $summary;
    }
}
