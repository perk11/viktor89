<?php

namespace Perk11\Viktor89;

use LogicException;

class MessageChain
{
    /** Images generated during the current turn, kept out of $messages. */
    private array $generatedImages = [];

    /** @param InternalMessage[] $messages */
    public function __construct(private array $messages)
    {
        if (count($this->messages) === 0) {
            throw new LogicException('Message chain initialized with no messages');
        }
    }

    /**
     * Track an image generated during the current turn (e.g. by image_gen_tool)
     * so later tool calls in the same completion (list_chain_images,
     * image_gen_tool edits) can reference it by its #N index. Stored separately
     * from the persisted message history on purpose: a generated image must never
     * become last()/previous(), since those drive reply targets, reactions and
     * user-preference lookups that expect the real triggering message.
     */
    public function appendGeneratedImage(InternalMessage $image): void
    {
        $this->generatedImages[] = $image;
    }

    /**
     * Images generated earlier in the current turn, in the order produced. They
     * share the #N index space with the chain's photos but follow them (they are
     * the newest).
     *
     * @return InternalMessage[]
     */
    public function getGeneratedImages(): array
    {
        return $this->generatedImages;
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
