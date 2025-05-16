<?php

namespace Perk11\Viktor89\Assistant;

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
        } catch (\JsonException $e) {
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
}
