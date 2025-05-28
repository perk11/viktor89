<?php

namespace Perk11\Viktor89;

class MessageChain
{
    /** @param InternalMessage[] $messages */
    public function __construct(private readonly array $messages)
    {
        if (count($this->messages) === 0) {
            throw new \LogicException('Message chain initialized with no messages');
        }
    }

    public function first(): InternalMessage
    {
        return $this->messages[0];
    }

    public function last(): InternalMessage
    {
        return $this->messages[count($this->messages) - 1];
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
