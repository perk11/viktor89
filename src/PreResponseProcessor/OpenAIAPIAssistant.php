<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramResponderInterface;

class OpenAIAPIAssistant implements PreResponseProcessor, TelegramResponderInterface
{
    private const MAX_CONTEXT_MESSAGES_COUNT = 100;

    private OpenAi $openAi;

    public function __construct(
        private readonly array $triggeringCommands,
        private readonly Database $database,
    ) {
        $this->openAi = new OpenAi('');
        $this->openAi->setBaseURL($_ENV['OPENAI_ASSISTANT_SERVER']);
    }

    public function process(Message $message): false|string|null
    {
        $messageRespondedTo = $message->getReplyToMessage();
        if ($messageRespondedTo !== null) {
            while ($messageRespondedTo->getReplyToMessage() !== null) {
                $messageRespondedTo = $messageRespondedTo->getReplyToMessage();
            }
            $messageText = $messageRespondedTo->getText();
        } else {
            $messageText = $message->getText();
        }
        foreach ($this->triggeringCommands as $triggeringCommand) {
            if (str_starts_with($messageText, $triggeringCommand)) {
                $prompt = $messageText;
                if ($messageRespondedTo !== null) {
                    $prompt = str_replace($triggeringCommand, '', $prompt);
                }
                $prompt = trim($prompt);
                break;
            }
        }
        if (!isset($prompt)) {
            return false;
        }
        if ($prompt === '') {
            return 'Непонятно, на что отвечать';
        }

        return $this->getResponseByMessage($message);
    }

    private function getCompletion(string $prompt, string $personality): string
    {
        $opts = [
            'prompt' => $prompt,
            'stream' => true,
            "stop"   => [
                "</s>",
                "$personality:",
                "User:",
            ],
        ];
        $fullContent = '';
        try {
            $this->openAi->completion($opts, function ($curl_info, $data) use (&$fullContent, $prompt) {
                $parsedData = parse_completion_string($data);
                echo $parsedData['content'];
                $fullContent .= $parsedData['content'];
                if (mb_strlen($fullContent) > 8192) {
                    echo "Max length reached, aborting response\n";

                    return 0;
                }

                return strlen($data);
            });
        } catch (\Exception $e) {
            echo "Got error when accessing OpenAI API: ";
            echo $e->getMessage();

            return 'Ошибка подключения к llama.cpp';
        }

        return trim($fullContent);
    }

    public function getResponseByMessage(Message $message): string
    {
        $incomingMessageText = $message->getText();
        if ($incomingMessageText === null) {
            echo "Warning, empty message text!\n";

            return 'Твое сообщение было пустым';
        }
        $previousMessages = $this->getPreviousMessages($message);
        $incomingMessageText = trim(
            str_replace(
                '@' . $_ENV['TELEGRAM_BOT_USERNAME'],
                '',
                $incomingMessageText
            )
        );
        if (count($previousMessages) === 0) {
            foreach ($this->triggeringCommands as $triggeringCommand) {
                if (str_starts_with($incomingMessageText, $triggeringCommand)) {
                    $incomingMessageText = str_replace($triggeringCommand, '', $incomingMessageText);
                    break;
                }
            }
        }
        $personality = 'Gemma';

        $prompt = "This is a conversation between User and $personality, a friendly assistant chatbot. $personality is helpful, kind, honest, good at writing, knows everything, and never fails to answer any requests immediately and with precision.\n\n";


        $human = count($previousMessages) % 2 === 0;
        foreach ($previousMessages as $previousMessage) {
            $previousMessageUserName = $human ? 'User' : $personality;
            $messageText = trim(
                str_replace('@' . $_ENV['TELEGRAM_BOT_USERNAME'], '', $previousMessage->messageText)
            );
            if ($human) {
                foreach ($this->triggeringCommands as $triggeringCommand) {
                    if (str_starts_with($messageText, $triggeringCommand)) {
                        $messageText = str_replace($triggeringCommand, '', $prompt);
                        break;
                    }
                }
            }
            $prompt .= "$previousMessageUserName: {$messageText}\n";
            $human = !$human;
        }
        $incomingMessageConvertedToPrompt = "User: $incomingMessageText\n$personality: ";
        $prompt .= $incomingMessageConvertedToPrompt;
        echo $prompt;

        $response = trim($this->getCompletion($prompt, $personality));

        return $response;
    }

    /** @return InternalMessage[] */
    private function getPreviousMessages(Message $message): array
    {
        $messages = [];
        if ($message->getReplyToMessage() !== null) {
            $responseMessage = $this->database->findMessageByIdInChat(
                $message->getReplyToMessage()->getMessageId(),
                $message->getChat()->getId()
            );
            if ($responseMessage !== null) {
                $messages[] = $responseMessage;
                while (count(
                        $messages
                    ) < self::MAX_CONTEXT_MESSAGES_COUNT - 2 && $responseMessage?->replyToMessageId !== null) {
                    $responseMessage = $this->database->findMessageByIdInChat(
                        $responseMessage->replyToMessageId,
                        $message->getChat()->getId()
                    );
                    if ($responseMessage === null) {
                        echo "Reference to message not found in database in current response chain, skipping\n";
                    }
                    $messages[] = $responseMessage;
                }
            }
        }


        return array_reverse($messages);
    }
}
