<?php

namespace Perk11\Viktor89;

use LogicException;
use Perk11\Viktor89\Assistant\MessageChainTextDocumentLoader;

class MessageChain
{
    /**
     * Document loader shared by all chains. MessageChain is a plain value
     * object created all over the codebase, so the loader is injected
     * statically from the composition root instead of through a constructor
     * (same approach as InternalMessage::setLogger()) — consumers can then
     * fold attached text documents into the chain via loadTextDocuments()
     * without each of them being wired with the loader individually.
     */
    private static ?MessageChainTextDocumentLoader $textDocumentLoader = null;

    public static function setTextDocumentLoader(?MessageChainTextDocumentLoader $textDocumentLoader): void
    {
        self::$textDocumentLoader = $textDocumentLoader;
    }

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

    /**
     * Fold the contents of attached text documents into the messages' text,
     * so any prompt-driven consumer (assistants, /image, /video, …) treats the
     * files as part of the prompt. No-op when no loader was injected.
     *
     * @return ProcessingResult|null an error reply when a document exceeds the
     *                               size cap (the chain must not be forwarded
     *                               further), null on success
     */
    public function loadTextDocuments(): ?ProcessingResult
    {
        return self::$textDocumentLoader?->loadIntoChain($this);
    }
}
