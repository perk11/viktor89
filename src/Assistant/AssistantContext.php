<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\Assistant\Tool\ToolCall;

class AssistantContext
{
    public ?string $systemPrompt = null;

    public ?string $responseStart = null;

    /** @var AssistantContextMessage[] */
    public array $messages = [];

    /**
     * Compact, human-readable description of the context's messages. Independent
     * of the API converters, so it works even when responseStart is set.
     * Intended for diagnostic logging when an LLM request fails.
     */
    public function describeForLog(): string
    {
        $lines = [];
        if ($this->systemPrompt !== null) {
            $lines[] = 'system: ' . $this->preview($this->systemPrompt);
        }
        if ($this->responseStart !== null) {
            $lines[] = 'responseStart: ' . $this->preview($this->responseStart);
        }
        foreach ($this->messages as $i => $message) {
            $role = $message->isUser ? 'user' : 'assistant';
            $parts = [];
            if (trim($message->text ?? '') !== '') {
                $parts[] = $this->preview($message->text);
            }
            if ($message->photo !== null) {
                $parts[] = '[image]';
            }
            if (count($message->toolCalls) > 0) {
                $names = array_map(static fn(ToolCall $tc) => $tc->name, $message->toolCalls);
                $parts[] = '[tool_calls: ' . implode(', ', $names) . ']';
            }
            if ($message->reasoning !== null) {
                $parts[] = '[reasoning]';
            }
            $lines[] = sprintf('#%d %s: %s', $i, $role, implode(' ', $parts));
        }

        return implode("\n", $lines);
    }

    private function preview(string $text): string
    {
        $collapsed = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($collapsed) > 120) {
            $collapsed = mb_substr($collapsed, 0, 120) . '…';
        }

        return $collapsed;
    }
}
