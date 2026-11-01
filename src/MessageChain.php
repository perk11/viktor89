<?php

namespace Perk11\Viktor89;

use LogicException;
use Perk11\Viktor89\Assistant\MessageChainTextDocumentLoader;
use Perk11\Viktor89\Assistant\Tool\ImageSearch\SearchedImageRestorer;

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

    /** Injected statically for the same reason as $textDocumentLoader. */
    private static ?SearchedImageRestorer $searchedImageRestorer = null;

    public static function setSearchedImageRestorer(?SearchedImageRestorer $searchedImageRestorer): void
    {
        self::$searchedImageRestorer = $searchedImageRestorer;
    }

    /** Images generated during the current turn, kept out of $messages. */
    private array $generatedImages = [];

    /** @var array<int, list<InternalMessage>> searched images restored from each message's tool calls, by message index */
    private array $restoredImagesByMessageIndex = [];

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

    /**
     * All images referenceable as #N, where N is the index in the returned list.
     *
     * Images found by image_search on earlier turns are not messages, so they are
     * restored from the persisted tool calls of the bot message that ran the
     * search and placed right after the message it replied to. That is where
     * they were numbered when found (they were appended after the triggering
     * message), so #N references in the replayed tool result keep pointing at
     * the same images.
     *
     * @return list<InternalMessage>
     */
    public function getImages(): array
    {
        $indexById = [];
        foreach ($this->messages as $index => $message) {
            if ($message->id !== null) {
                $indexById[$message->id] = $index;
            }
        }
        $restoredImagesAfterIndex = [];
        foreach ($this->messages as $index => $message) {
            $restoredImages = $this->getRestoredImages($index);
            if ($restoredImages === []) {
                continue;
            }
            $parentIndex = $indexById[$message->replyToMessageId ?? -1] ?? null;
            $anchorIndex = $parentIndex !== null && $parentIndex < $index ? $parentIndex : $index - 1;
            $restoredImagesAfterIndex[$anchorIndex] = array_merge($restoredImagesAfterIndex[$anchorIndex] ?? [], $restoredImages);
        }

        $images = $restoredImagesAfterIndex[-1] ?? [];
        foreach ($this->messages as $index => $message) {
            if (self::hasImage($message)) {
                $images[] = $message;
            }
            array_push($images, ...$restoredImagesAfterIndex[$index] ?? []);
        }

        return array_merge($images, array_values(array_filter($this->generatedImages, self::hasImage(...))));
    }

    /** @return list<InternalMessage> */
    private function getRestoredImages(int $messageIndex): array
    {
        return $this->restoredImagesByMessageIndex[$messageIndex]
            ??= self::$searchedImageRestorer?->restore($this->messages[$messageIndex]) ?? [];
    }

    private static function hasImage(InternalMessage $message): bool
    {
        return $message->photoFileId !== null || $message->photoContents !== null;
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
