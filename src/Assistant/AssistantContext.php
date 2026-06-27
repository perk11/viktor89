<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use JsonException;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
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
            $messageContentParts = [];
            $messageHasToolCalls = !$message->isUser && count($message->toolCalls) > 0;
            $messageHasReasoning = $message->reasoning !== null;

            $previousMessageKey = array_key_last($openAiMessages);
            $previousMessage = $previousMessageKey !== null ? $openAiMessages[$previousMessageKey] : null;

            if (
                $previousMessage !== null
                && $previousMessage['role'] === $role
                && !isset($previousMessage['tool_calls'])
                && !array_key_exists('reasoning_content', $previousMessage)
                && !$messageHasReasoning
            ) {
                array_pop($openAiMessages);

                if (is_string($previousMessage['content'])) {
                    if (trim($previousMessage['content']) !== '') {
                        $messageContentParts[] = [
                            'type' => 'text',
                            'text' => $previousMessage['content'],
                        ];
                    }
                } elseif (is_array($previousMessage['content'])) {
                    foreach ($previousMessage['content'] as $previousMessageContentPart) {
                        $messageContentParts[] = $previousMessageContentPart;
                    }
                }
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
                $url = null;

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

            if (empty($messageContentParts) && !$messageHasToolCalls && !$messageHasReasoning) {
                continue;
            }

            if (empty($messageContentParts)) {
                $finalContent = '';
            } elseif (count($messageContentParts) === 1 && $messageContentParts[0]['type'] === 'text') {
                $finalContent = $messageContentParts[0]['text'];
            } else {
                $finalContent = $messageContentParts;
            }

            $openAiMessage = [
                'role'    => $role,
                'content' => $finalContent,
            ];

            if ($messageHasReasoning) {
                $openAiMessage['reasoning_content'] = $message->reasoning;
            }

            if ($messageHasToolCalls) {
                $openAiMessage['tool_calls'] = array_map(
                    static fn(ToolCall $toolCall): array => [
                        'id' => $toolCall->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolCall->name,
                            'arguments' => $toolCall->arguments,
                        ],
                    ],
                    $message->toolCalls
                );
            }

            $openAiMessages[] = $openAiMessage;

            if ($messageHasToolCalls) {
                foreach ($message->toolCalls as $toolCall) {
                    $openAiMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall->id,
                        'content' => $toolCall->result,
                    ];
                }
            }
        }

        return $openAiMessages;
    }

}
