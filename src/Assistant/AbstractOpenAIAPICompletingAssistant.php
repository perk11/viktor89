<?php

namespace Perk11\Viktor89\Assistant;

use JsonException;
use Perk11\Viktor89\AbortStreamingResponse\AbortableStreamingResponseGenerator;
use Perk11\Viktor89\AbortStreamingResponse\AbortStreamingResponseHandler;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

abstract class AbstractOpenAIAPICompletingAssistant extends AbstractOpenAIAPiAssistant implements AbortableStreamingResponseGenerator
{
    /** @var AbortStreamingResponseHandler[] */
    private array $abortResponseHandlers = [];

    public function __construct(
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        int $telegramBotId,
        string $url,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
    )
    {
        parent::__construct($systemPromptProcessor, $responseStartProcessor, $telegramFileDownloader, $telegramBotId, $url);
    }

    public function addAbortResponseHandler(AbortStreamingResponseHandler $abortResponseHandler): void
    {
        $this->abortResponseHandlers[] = $abortResponseHandler;
    }

    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string
    {
        $prompt = $this->convertContextToPrompt($assistantContext);
        echo $prompt;

        return $this->getCompletion($prompt);
    }

    abstract protected function convertContextToPrompt(AssistantContext $assistantContext): string;

    protected function getCompletionOptions(string $prompt): array
    {
        return [
            'prompt' => $prompt,
            'stream' => true,
        ];

    }

    protected function getCompletion(string $prompt): string
    {
        $opts = $this->getCompletionOptions($prompt);
        $aborted = false;
        $fullContent = '';
        $jsonPart = null;
        try {
            $this->openAi->completion(
                $opts,
                function ($curl_info, $data) use (&$fullContent, &$jsonPart, &$aborted, $prompt) {
                if ($jsonPart === null) {
                    $dataToParse = $data;
                } else {
                    $dataToParse = $jsonPart . $data;
                }
                try {
                    $parsedData = $this->openAiCompletionStringParser->parse($dataToParse);
                    if ($parsedData === null) {
                        //[DONE] string
                        return strlen($data);
                    }
                    $jsonPart = null;
                } catch (JSONException $e) {
                    echo "\nIncomplete JSON received, postponing parsing until more is received\n";
                    $jsonPart = $dataToParse;

                    return strlen($data);
                }
                if (array_key_exists('content', $parsedData)) {
                    //this worked with llama.cpp prior to ~February 2025
                    $content = $parsedData['content'];
                } else {
                    if (!isset( $parsedData['choices'][0]['text'])) {
                        throw new \RuntimeException("Unexpected JSON received: " . $dataToParse);
                    }
                    $content = $parsedData['choices'][0]['text'];
                }
                echo $content;
                $fullContent .= $content;
                    foreach ($this->abortResponseHandlers as $abortResponseHandler) {
                        $newResponse = $abortResponseHandler->getNewResponse($prompt, $fullContent);
                        if ($newResponse !== false) {
                            $fullContent = $newResponse;
                            $aborted = true;

                            return 0;
                        }
                }

                return strlen($data);
            });
        } catch (\Exception $e) {
            if ($aborted) {
                return trim($fullContent);
            }
            throw $e;
        }

        return rtrim($fullContent);
    }
}
