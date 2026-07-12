<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;

/**
 * Siepatch responder exposed as a selectable assistant model.
 *
 * Reproduces the behaviour of the legacy SiepatchNonInstruct4 fallback
 * responder (raw completion in the "<bot>: [author] text" format with author
 * extraction, response regeneration and YouTube link replacement), but is
 * built by AssistantFactory from the assistantModels config and chosen by the
 * user via /assistant instead of acting as the always-on fallback. Unlike the
 * legacy responder it relies on the non-deprecated MessageChainProcessor /
 * AssistantInterface pipeline and reuses the abort-handler machinery from
 * AbstractOpenAIAPICompletingAssistant.
 */
class SiepatchAssistant extends AbstractOpenAIAPICompletingAssistant
{
    private const string YOUTUBE_PATTERN = '/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/';

    /** @var string[] */
    private const array VIDEOS = [
        'https://www.youtube.com/watch?v=JdGgys-QQdE',
        'https://www.youtube.com/watch?v=2oe_7IRb_rI',
        'https://www.youtube.com/watch?v=_L0QyGE4nJM',
        'https://www.youtube.com/watch?v=KvHSQkTQpX8',
        'https://www.youtube.com/watch?v=krt2AXyXHHE',
        'https://www.youtube.com/watch?v=WDaNJW_jEBo',
        'https://www.youtube.com/watch?v=8EM5R3VkaWI',
        'https://www.youtube.com/watch?v=HvGsbZ1e2sw',
        'https://www.youtube.com/watch?v=qCljI3cIObU',
        'https://www.youtube.com/watch?v=5P6ADakiwcg',
    ];

    private const string ERROR_MESSAGE = '] Ошибка подключения к llama.cpp по-сипатчевски';

    private const int REGENERATION_ATTEMPTS = 5;

    private const int CONTINUE_ATTEMPTS = 10;

    public function __construct(
        UserPreferenceReaderInterface $systemPromptProcessor,
        private readonly UserPreferenceReaderInterface $responseStartReader,
        UserPreferenceReaderInterface $editFrequencyProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        AltTextProvider $altTextProvider,
        int $telegramBotId,
        string $url,
        OpenAiCompletionStringParser $openAiCompletionStringParser,
        private readonly UserPreferenceReaderInterface $personalityReader,
    ) {
        parent::__construct(
            $systemPromptProcessor,
            $responseStartReader,
            $editFrequencyProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            $telegramBotId,
            $url,
            $openAiCompletionStringParser,
            false,
        );
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $incomingMessage = $messageChain->last();
        $previousMessages = array_slice($messageChain->getMessages(), 0, -1);

        $responseMessage = new InternalMessage();
        $responseMessage->chatId = $incomingMessage->chatId;
        $responseMessage->replyToMessageId = $incomingMessage->id;

        $continueMode = trim($incomingMessage->messageText) === '/continue';

        $progressUpdateCallback(
            static::class,
            'Generating Siepatch response',
            new ChatAction($incomingMessage->chatId, ChatActionEnum::typing),
        );

        [$personality, $responseStart] = $this->resolvePersonalityAndResponseStart(
            $incomingMessage->userId,
            $continueMode,
        );

        $context = $this->generateContext($previousMessages, $incomingMessage, $personality, $responseStart, $continueMode);
        echo $context;

        $responseMessage->messageText = $responseStart . $this->complete($context);

        if ($continueMode) {
            $responseMessage->userName = $incomingMessage->userName;
            for ($i = 0; $i < self::CONTINUE_ATTEMPTS; $i++) {
                if (trim($responseMessage->messageText) === '') {
                    $responseMessage->messageText = $responseStart . $this->complete($context);
                } else {
                    break;
                }
            }
            if (trim($responseMessage->messageText) === '') {
                return new ProcessingResult(null, true, '🤔', $incomingMessage);
            }

            return new ProcessingResult($responseMessage, true);
        }

        for ($i = 0; $i < self::REGENERATION_ATTEMPTS; $i++) {
            if ($this->doesResponseNeedTobeRegenerated($responseMessage->messageText, $context)) {
                array_shift($previousMessages);
                $context = $this->generateContext($previousMessages, $incomingMessage, $personality, $responseStart, $continueMode);
                $responseMessage->messageText = $responseStart . $this->complete($context);
            } else {
                break;
            }
        }

        if ($personality === null) {
            $this->applyAuthorExtraction($responseMessage);
        } else {
            $responseMessage->userName = $personality;
        }

        $responseMessage->messageText = $this->replaceYouTubeLinks($responseMessage->messageText);

        return new ProcessingResult($responseMessage, true);
    }

    protected function convertContextToPrompt(AssistantContext $assistantContext): string
    {
        $prompt = '';
        foreach ($assistantContext->messages as $contextMessage) {
            $author = $contextMessage->isUser ? 'User' : 'Assistant';
            $prompt .= "<bot>: [$author] {$contextMessage->text}\n";
        }
        $prompt .= '<bot>: [';
        if ($assistantContext->responseStart !== null) {
            $prompt .= $assistantContext->responseStart;
        }

        return $prompt;
    }

    protected function getCompletionOptions(string $prompt): array
    {
        return [
            'prompt'            => "\n\n" . $prompt,
            'temperature'       => 0.6,
            'cache_prompt'      => false,
            'repeat_penalty'    => 1.18,
            'repeat_last_n'     => 4096,
            'penalize_nl'       => true,
            'top_k'             => 40,
            'top_p'             => 0.95,
            'min_p'             => 0.1,
            'tfs_z'             => 1,
            'frequency_penalty' => 0,
            'presence_penalty'  => 0,
            'stream'            => true,
            'stop'              => [
                '<human>',
                '<bot>',
            ],
        ];
    }

    /**
     * @param InternalMessage[] $previousMessages
     */
    protected function generateContext(
        array $previousMessages,
        InternalMessage $incomingMessage,
        ?string $personality,
        ?string $responseStart,
        bool $continueMode,
    ): string {
        $context = '';
        foreach ($previousMessages as $previousMessage) {
            $context .= $this->formatInternalMessageForContext($previousMessage);
        }
        if ($continueMode) {
            return rtrim($context);
        }
        $context .= $this->formatInternalMessageForContext($incomingMessage);
        $context .= '<bot>: [';
        if ($personality !== null) {
            $context .= "{$personality}] ";
        }
        if ($responseStart !== null) {
            $context .= $responseStart;
        }

        return $context;
    }

    protected function formatInternalMessageForContext(InternalMessage $internalMessage): string
    {
        $userName = str_replace(' ', '_', $internalMessage->userName);

        return "<bot>: [$userName] {$internalMessage->messageText}\n";
    }

    protected function doesResponseNeedTobeRegenerated(string $response, string $prompt): bool
    {
        if (str_ends_with($response, ']')) {
            return true;
        }
        $responseAfterAuthor = mb_substr($response, mb_strpos($response, '] ') + 2);
        if (str_contains($prompt, $responseAfterAuthor)) {
            echo "Repeat response detected, restarting with fewer messages in context\n";

            return true;
        }
        if (
            mb_strlen($responseAfterAuthor) < 30 && (
                str_contains(mb_strtolower($responseAfterAuthor), 'не умею') ||
                str_contains(mb_strtolower($responseAfterAuthor), 'не могу') ||
                str_contains(mb_strtolower($responseAfterAuthor), 'не знаю'))
        ) {
            echo "Invalid response detected, restarting with fewer messages in context\n";

            return true;
        }

        return false;
    }

    /**
     * Extracts the author tag from the start of the response (the "[author] "
     * prefix the model produces) and turns the message into a Siepatch-style
     * "answers as <author>" reply. Faithfully mirrors SiepatchNonInstruct4,
     * including its handling of responses that contain no "]".
     */
    protected function applyAuthorExtraction(InternalMessage $message): void
    {
        $authorEndPosition = mb_strpos($message->messageText, ']');
        if ($authorEndPosition === false) {
            $message->userName = $_ENV['TELEGRAM_BOT_USERNAME'] ?? '';
        } else {
            $message->userName = mb_substr($message->messageText, 0, mb_strpos($message->messageText, '] '));
        }
        $message->rawMessageText = '[отвечает ' . $message->messageText;
        $message->messageText = mb_substr($message->messageText, $authorEndPosition + 2);
    }

    protected function replaceYouTubeLinks(string $text): string
    {
        return preg_replace(self::YOUTUBE_PATTERN, $this->randomVideo(), $text) ?? $text;
    }

    protected function randomVideo(): string
    {
        return self::VIDEOS[array_rand(self::VIDEOS)];
    }

    /**
     * @return array{0: ?string, 1: ?string} [personality, responseStart]
     */
    private function resolvePersonalityAndResponseStart(int $userId, bool $continueMode): array
    {
        $personality = $this->personalityReader->getCurrentPreferenceValue($userId);
        if ($personality === '') {
            $personality = null;
        }
        if ($personality !== null) {
            $personality = str_replace(']', '_', $personality);
        }
        $responseStart = $continueMode ? null : $this->responseStartReader->getCurrentPreferenceValue($userId);
        if ($personality === null && $responseStart !== null) {
            $personality = 'Nanak0n';
        }

        return [$personality, $responseStart];
    }

    private function complete(string $context): string
    {
        try {
            return $this->getCompletion($context, null);
        } catch (\Throwable $e) {
            echo "Got error when accessing Siepatch OpenAI API: " . $e->getMessage() . "\n";

            return self::ERROR_MESSAGE;
        }
    }
}
