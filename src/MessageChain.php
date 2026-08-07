<?php

namespace Perk11\Viktor89;

use LogicException;

class MessageChain
{
    /** @param InternalMessage[] $messages */
    public function __construct(private array $messages)
    {
        if (count($this->messages) === 0) {
            throw new LogicException('Message chain initialized with no messages');
        }
    }

    /**
     * Append a message generated during the current turn (e.g. an image produced
     * by a tool call) so that subsequent tool calls in the same completion
     * (list_chain_images, image_gen_tool edits) can see and reference it.
     */
    public function appendMessage(InternalMessage $message): void
    {
        $this->messages[] = $message;
    }

    public function first(): InternalMessage
    {
        return $this->messages[0];
    }

    public function last(): InternalMessage
    {
        return $this->messages[count($this->messages) - 1];
    }

    /**
     * The most recent message not authored by $userId (e.g. the bot). Needed to
     * find the message currently being responded to when transient bot messages
     * have been appended to the chain during a turn (e.g. images just generated
     * by a tool call). Falls back to last() if every message is by $userId.
     */
    public function lastMessageByOtherThan(int $userId): InternalMessage
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]->userId !== $userId) {
                return $this->messages[$i];
            }
        }

        return $this->last();
    }

    public function withReplacedLastMessage(InternalMessage $message): MessageChain
    {
        return $this->withReplacedMessage($message, count($this->messages) - 1);
    }

    public function withReplacedMessage(InternalMessage $message, int $messageIndex): MessageChain
    {
        $messagesCopy = $this->messages;
        $messagesCopy[$messageIndex] = $message;
        return new MessageChain($messagesCopy);
    }

    public function previous(): ?InternalMessage
    {
        if (count($this->messages) < 2) {
            return null;
        }
        return $this->messages[count($this->messages) - 2];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /** @return InternalMessage[] */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
