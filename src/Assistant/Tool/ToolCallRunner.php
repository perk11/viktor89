<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\ImagePartBuilderInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\UserFacingExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Executes the tool calls requested by an LLM for the assistants that run the
 * tool-call loop themselves (OpenAI chat completions and the Anthropic
 * Messages API), keeping the user-facing progress notices consistent.
 *
 * Notices are added to the Telegram display track only, never to the clean
 * track that is persisted and replayed to the model: the model already sees
 * the call via the structured tool call / tool-result messages.
 */
final class ToolCallRunner
{
    public function __construct(
        private readonly AltTextProvider $altTextProvider,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Validates the arguments, dispatches to the tool's executor, moves
     * automatic_output_markdown to the accumulator and turns returned images
     * into content parts.
     *
     * @param ?ToolDefinition $toolDefinition null when the model called an unknown tool
     * @param ?ImagePartBuilderInterface $imagePartBuilder null when the model can't see
     *        images; they are described with alt text instead
     */
    public function run(
        ToolCall $toolCall,
        ?ToolDefinition $toolDefinition,
        ToolCallContext $context,
        ?ImagePartBuilderInterface $imagePartBuilder,
    ): ToolCallRunResult {
        $functionName = $toolCall->name;
        if (!($toolDefinition?->silent ?? false)) {
            $context->notify("\n>Executing `$functionName` with arguments `$toolCall->arguments`\n\n");
        }

        $failed = true;
        $arguments = self::decodeArguments($toolDefinition, $toolCall);
        if (is_string($arguments)) {
            $this->logger?->log(LogLevel::WARNING, "Invalid tool call $functionName: $arguments");
            $toolResult = ['content' => "Error: $arguments"];
            $context->notify("\n> ==Error in $functionName: $arguments==\n");
        } else {
            $this->logger?->log(LogLevel::DEBUG, "Executing tool $functionName with args $toolCall->arguments");
            if ($context->progressUpdateCallback !== null) {
                ($context->progressUpdateCallback)(self::class, "Executing $functionName");
            }
            $executor = $toolDefinition->toolCallClass;
            try {
                $toolResult = $executor instanceof MessageChainAwareToolCallExecutorInterface
                    ? $executor->executeToolCall($arguments, $context->messageChain)
                    : $executor->executeToolCall($arguments);
                $failed = false;
            } catch (\Throwable $e) {
                $userMessage = $this->describeFailure($functionName, $e);
                $toolResult = ['content' => "Error: $userMessage"];
                $context->notify("\n> ==Error in $functionName: $userMessage==\n");
            }
        }

        if (isset($toolResult['automatic_output_markdown'])) {
            if (!is_string($toolResult['automatic_output_markdown'])) {
                throw new \RuntimeException('Tool call result automatic_output_markdown must be a string');
            }
            $context->accumulator->appendAutomaticOutput("\n\n" . $toolResult['automatic_output_markdown'] . "\n\n");
            unset($toolResult['automatic_output_markdown']);
        }

        $imageParts = $this->processContextImages($toolResult, $context->progressUpdateCallback, $imagePartBuilder);

        $content = json_encode($toolResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->logger?->log(LogLevel::DEBUG, 'Tool call result: ' . mb_substr($content, 0, 1000));

        return new ToolCallRunResult(
            new ToolCall($toolCall->id, $functionName, $toolCall->arguments, $content),
            $imageParts,
            $failed,
        );
    }

    /**
     * Logs the failure in full and returns what the user and the model may see.
     * Exception messages are shown only for exceptions written for users; any
     * other exception (HTTP client errors with hostnames or tokens in the URL,
     * filesystem paths, upstream API bodies) is replaced by an error id that
     * is also in the log line.
     */
    private function describeFailure(string $functionName, \Throwable $e): string
    {
        if ($e instanceof UserFacingExceptionInterface) {
            $this->logger?->log(LogLevel::WARNING, "Tool call $functionName failed: " . $e->getMessage());

            return $e->getMessage();
        }

        $errorId = bin2hex(random_bytes(4));
        $this->logger?->log(
            LogLevel::ERROR,
            "Tool call $functionName failed (error id $errorId): " . get_class($e) . ': ' . $e->getMessage()
            . "\n" . $e->getTraceAsString(),
        );

        return "tool call failed (error id $errorId)";
    }

    /**
     * Malformed input (e.g. truncated by eager input streaming, which the API
     * does not validate) is reported back to the model instead of executed.
     *
     * @return array<string, mixed>|string the decoded arguments, or the error to report
     */
    private static function decodeArguments(?ToolDefinition $toolDefinition, ToolCall $toolCall): array|string
    {
        if ($toolDefinition === null) {
            return "Unknown tool call: $toolCall->name";
        }
        try {
            $decoded = json_decode($toolCall->arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 'tool input is not valid JSON: ' . $e->getMessage();
        }

        return is_array($decoded) ? $decoded : 'tool input must be a JSON object';
    }

    /**
     * Make images returned by a tool visible to the model so it has feedback
     * about what it produced or found instead of guessing and looping. Tools
     * return either 'context_image' (a single generated image) or
     * 'context_images' (reference => bytes, e.g. image_search results):
     *  - vision assistants: returns image content parts to embed in the tool result;
     *  - non-vision assistants: adds auto-generated alt-text descriptions to the tool
     *    result ('generated_image_description' / 'image_descriptions') so the model
     *    at least knows what the images contain.
     * The raw bytes are always stripped from $toolResult so they never reach json_encode.
     *
     * @return list<array<string, mixed>>
     */
    private function processContextImages(array &$toolResult, ?ProgressUpdateCallback $progressUpdateCallback, ?ImagePartBuilderInterface $imagePartBuilder): array
    {
        $image = $toolResult['context_image'] ?? null;
        $images = $toolResult['context_images'] ?? null;
        unset($toolResult['context_image'], $toolResult['context_images']);

        $parts = [];
        if (is_string($image) && $image !== '') {
            if ($imagePartBuilder !== null) {
                $imagePart = $imagePartBuilder->buildImagePart($image);
                if ($imagePart !== null) {
                    $parts[] = $imagePart;
                }
            } else {
                $altText = $this->describeContextImage($image, $progressUpdateCallback);
                if ($altText !== '') {
                    $toolResult['generated_image_description'] = $altText;
                }
            }
        }

        if (!is_array($images)) {
            return $parts;
        }
        foreach ($images as $reference => $referencedImage) {
            if (!is_string($referencedImage) || $referencedImage === '') {
                continue;
            }
            if ($imagePartBuilder !== null) {
                $imagePart = $imagePartBuilder->buildImagePart($referencedImage);
                if ($imagePart !== null) {
                    $parts[] = ['type' => 'text', 'text' => "Image $reference:"];
                    $parts[] = $imagePart;
                }
            } else {
                $altText = $this->describeContextImage($referencedImage, $progressUpdateCallback);
                if ($altText !== '') {
                    $toolResult['image_descriptions'][$reference] = $altText;
                }
            }
        }

        return $parts;
    }

    private function describeContextImage(string $image, ?ProgressUpdateCallback $progressUpdateCallback): string
    {
        try {
            return $this->altTextProvider->generateAltTextForImageString($image, $progressUpdateCallback);
        } catch (\Throwable $e) {
            $this->logger?->log(LogLevel::ERROR, 'Failed to generate alt text for tool image: ' . $e->getMessage());
            return '';
        }
    }
}
