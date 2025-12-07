<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use JsonException;
use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;

class AssistantContext
{
    public ?string $systemPrompt = null;

    public ?string $responseStart = null;

    /** @var AssistantContextMessage[] */
    public array $messages = [];

    public static function fromOpenAiMessagesJson(string $json): self
    {
        try {
            $parsedJson = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenAiContextParsingException($e->getMessage());
        }
        $context = new self();
        foreach ($parsedJson as $item) {
            if (!is_array($item)) {
                throw new OpenAiContextParsingException("Non-array item encountered while parsing json\n$json");
            }
            if (!array_key_exists('content', $item)) {
                throw new OpenAiContextParsingException("Item is missing content\n$json");
            }
            $message = new AssistantContextMessage();
            if (array_key_exists('role', $item)) {
                switch ($item['role']) {
                    case 'user':
                        $message->isUser = true;
                        break;
                    case 'assistant':
                        $message->isUser = false;
                        break;
                    case 'system':
                        $context->systemPrompt = $item['content'];
                        continue 2;
                    default:
                        throw new OpenAiContextParsingException("Unknown message role: " . $item['role']);
                }
            } else {
                $message->isUser = true;
            }
            $message->text = $item['content'];
            $context->messages[] = $message;
        }

        return $context;
    }
    public function toOpenAiMessagesArray(): array
    {
        if ($this->responseStart !== null) {
            throw new Exception('responseStart specified, but it can not be converted to OpenAi array');
        }
        $openAiMessages = [];
        if ($this->systemPrompt !== null) {
            $openAiMessages[] = [
                'role'    => 'system',
                'content' => $this->systemPrompt,
            ];
        }

        foreach ($this->messages as $message) {
            $role = $message->isUser ? 'user' : 'assistant';
            $previousMessage = $openAiMessages[array_key_last($openAiMessages)];
            if ($previousMessage['role'] === $role) {
                array_pop($openAiMessages);
                $messageContentParts = $previousMessage['content'];
            } else {
                $messageContentParts = [];
            }

            $messageText = $message->text ?? '';
            if (is_string($messageText) && trim($messageText) !== '') {
                $messageContentParts[] = [
                    'type' => 'text',
                    'text' => $messageText,
                ];
            }

            if ($message->photo !== null) {
                $extension = ContentTypeGuesser::guessFileExtension($message->photo);
                switch ($extension) {
                    case 'jpg':
                        $url = 'data:image/jpeg;base64,' . base64_encode($message->photo);
                        break;
                    case 'png':
                        $url = 'data:image/png;base64,' . base64_encode($message->photo);
                        break;
                    case 'webp':
                        $gdImageFromWebpString = imagecreatefromstring($message->photo);
                        if ($gdImageFromWebpString === false) {
                            echo "Failed to create image from webp\n";
                            $url = null;
                        } else {
                            ob_start();
                            imagepng($gdImageFromWebpString);
                            $pngImageBinaryString = ob_get_clean();
                            imagedestroy($gdImageFromWebpString);
                            $url = 'data:image/png;base64,' . base64_encode($pngImageBinaryString);
                        }
                        break;
                    default:
                        $url = null;

                }
                if ($url !== null) {
                    $messageContentParts[] = [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url' => $url,
                        ],
                    ];
                }
            }

            if (empty($messageContentParts)) {
                // Skip messages that have neither text nor image.
                continue;
            }

            $finalContent = $messageContentParts;

            $openAiMessages[] = [
                'role'    => $role,
                'content' => $finalContent,
            ];
        }

        return $openAiMessages;
    }

}
