<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\ChatAction;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\AbortStreamingResponse\AbortableStreamingResponseGenerator;
use Perk11\Viktor89\AbortStreamingResponse\AbortStreamingResponseHandler;
use Perk11\Viktor89\PreResponseProcessor\PersonalityProcessor;
use Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor;
use Perk11\Viktor89\PreResponseProcessor\PreResponseSupportingGenerator;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;

class SiepatchNonInstruct4 implements TelegramInternalMessageResponderInterface, AbortableStreamingResponseGenerator,
                                      PreResponseSupportingGenerator
{
    private OpenAi $openAi;

    private array $videos = [
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

    /** @var AbortStreamingResponseHandler[] */
    private array $abortResponseHandlers = [];

    /** @var PreResponseProcessor[] */
    private array $preResponseProcessors = [];

    private readonly UserPreferenceSetByCommandProcessor $responseStartProcessor;
    private readonly UserPreferenceSetByCommandProcessor $personalityProcessor;

    public function __construct(
        private readonly HistoryReader $historyReader,
        private readonly Database $database,
    )
    {
        $this->openAi = new OpenAi('');
        $this->openAi->setBaseURL($_ENV['OPENAI_SERVER']);
        $this->personalityProcessor = new UserPreferenceSetByCommandProcessor(
            $this->database,
            ['/personality'],
            'personality',
        );
        $this->addPreResponseProcessor($this->personalityProcessor);
        $this->responseStartProcessor = new UserPreferenceSetByCommandProcessor(
            $this->database,
            ['/responsestart', '/response-start'],
            'response-start',
        );
        $this->addPreResponseProcessor($this->responseStartProcessor);
    }

    public function addAbortResponseHandler(AbortStreamingResponseHandler $abortResponseHandler): void
    {
        $this->abortResponseHandlers[] = $abortResponseHandler;
    }

    public function addPreResponseProcessor(PreResponseProcessor $preResponseProcessor): void
    {
        $this->preResponseProcessors[] = $preResponseProcessor;
    }

    private function getCompletion(string $prompt): string
    {
//        $prompt = mb_substr($prompt, -1024);
        $opts = [
            'prompt'            => "\n\n" . $prompt,
            'temperature'       => 0.6,
            'cache_prompt'      => false,
            'repeat_penalty'    => 1.18,
            'repeat_last_n'     => 4096,
            "penalize_nl"       => true,
            "top_k"             => 40,
            "top_p"             => 0.95,
            "min_p"             => 0.1,
            "tfs_z"             => 1,
//        "max_tokens"        => 150,
            "frequency_penalty" => 0,
            "presence_penalty"  => 0,
            "stream"            => true,
            "stop"              => [
                "<human>",
                "<bot>",
            ],
        ];
        $fullContent = '';
        try {
            $this->openAi->completion($opts, function ($curl_info, $data) use ($prompt, &$fullContent) {
                $parsedData = parse_completion_string($data);
                echo $parsedData['content'];
                $fullContent .= $parsedData['content'];
                foreach ($this->abortResponseHandlers as $abortResponseHandler) {
                    $newResponse = $abortResponseHandler->getNewResponse($prompt, $fullContent);
                    if ($newResponse !== false) {
                        $fullContent = $newResponse;

                        return 0;
                    }
                }

                return strlen($data);
            });
        } catch (\Exception $e) {
        }

        return trim($fullContent);
    }

    public function getResponseByMessage(Message $message): ?InternalMessage
    {
        $internalMessage = new InternalMessage();
        $internalMessage->chatId = $message->getChat()->getId();
        $internalMessage->replyToMessageId = $message->getMessageId();
        foreach ($this->preResponseProcessors as $preResponseProcessor) {
            $replacedMessage = $preResponseProcessor->process($message);
            if ($replacedMessage !== false) {
                if ($replacedMessage === null) {
                    return null;
                }
                $internalMessage->userName = $_ENV['TELEGRAM_BOT_USERNAME'];
                $internalMessage->messageText = $replacedMessage;

                return $internalMessage;
            }
        }
        if ($message->getType() === 'command') {
            //Do not respond to commands other than the ones handled by preresponse processors
            return null;
        }

        Request::sendChatAction([
                                    'chat_id' => $message->getChat()->getId(),
                                    'action'  => ChatAction::TYPING,
                                ]);

        $incomingMessageAsInternalMessage = InternalMessage::fromTelegramMessage($message);
        $previousMessages = $this->historyReader->getPreviousMessages($message, 99, 99, 0);
        $personality = $this->personalityProcessor->getCurrentPreferenceValue($incomingMessageAsInternalMessage->userId);
        $responseStart = $this->responseStartProcessor->getCurrentPreferenceValue($incomingMessageAsInternalMessage->userId);

        $context = $this->generateContext($previousMessages, $incomingMessageAsInternalMessage, $personality, $responseStart);
        echo $context;

        $internalMessage->messageText = $responseStart . $this->getCompletion($context);
        for ($i = 0; $i < 5; $i++) {
            if ($this->doesResponseNeedTobeRegenerated($internalMessage->messageText, $context)) {
                array_shift($previousMessages);
                $context = $this->generateContext($previousMessages, $incomingMessageAsInternalMessage, $personality, $responseStart);
                $internalMessage->messageText = $responseStart . $this->getCompletion($context);
            } else {
                break;
            }
        }

        if ($personality === null) {
            $authorEndPosition = mb_strpos($internalMessage->messageText, ']');
            if ($authorEndPosition === false) {
                $internalMessage->userName = $_ENV['TELEGRAM_BOT_USERNAME'];
            } else {
                $internalMessage->userName = mb_substr(
                    $internalMessage->messageText,
                    0,
                    mb_strpos($internalMessage->messageText, '] ')
                );
            }
            $internalMessage->actualMessageText = '[отвечает ' . $internalMessage->messageText;
            $internalMessage->messageText = mb_substr($internalMessage->messageText, $authorEndPosition + 2);
        } else {
            $internalMessage->userName = $personality;
        }

        $youtube_pattern = '/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/';
        $internalMessage->messageText = preg_replace(
            $youtube_pattern,
            $this->videos[array_rand($this->videos)],
            $internalMessage->messageText
        );

        return $internalMessage;
    }

    private function doesResponseNeedTobeRegenerated(string $response, string $prompt): bool
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

    protected function formatInternalMessageForContext(InternalMessage $internalMessage): string
    {
        $userName = str_replace(' ', '_', $internalMessage->userName);
        return "<bot>: [$userName] {$internalMessage->messageText}\n";
    }

    private function generateContext(
        array $previousMessages,
        InternalMessage $incomingMessageAsInternalMessage,
        ?string $personality,
        ?string $responseStart,
    ): string {
        $context = "";
        foreach ($previousMessages as $previousMessage) {
            $context .= $this->formatInternalMessageForContext($previousMessage);
        }
        $context .= $this->formatInternalMessageForContext($incomingMessageAsInternalMessage);
        $context .= "<bot>: [";
        if ($personality !== null) {
            $context .= "{$personality}] ";
        }
        if ($responseStart !== null) {
            $context .= $responseStart;
        }

        return $context;
    }
}
