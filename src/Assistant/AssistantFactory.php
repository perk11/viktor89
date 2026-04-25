<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use Perk11\Viktor89\AbortStreamingResponse\AbortableStreamingResponseGenerator;
use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ReactToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class AssistantFactory
{
    private array $assistantInstanceByName;
    public function __construct(
        private readonly array $assistantConfig,
        private readonly UserPreferenceReaderInterface $defaultSystemPromptProcessor,
        private readonly UserPreferenceReaderInterface $responseStartProcessor,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly AltTextProvider $altTextProvider,
        private readonly ProcessingResultExecutor $processingResultExecutor,
        private readonly ToolCallExecutorInterface $webSearchTool,
        private readonly MessageChainAwareToolCallExecutorInterface $imageFromTextGeneratorTool,
        private readonly MessageChainAwareToolCallExecutorInterface $reactToolCallExecutor,
        private readonly int $telegramBotId
    )
    {
    }

    /** @return string[] */
    public function getSupportedModels(): array
    {
        $models = [];
        foreach ($this->assistantConfig as $modelName => $assistantConfig) {
            if ($assistantConfig['selectableByUser']) {
                $models[] = $modelName;
            }
        }

        return $models;
    }

    public function getDefaultAssistantInstance(): AssistantInterface
    {
        return $this->getAssistantInstanceByName($this->getSupportedModels()[0]);
    }

    public function getAssistantInstanceByName(string $name): AssistantInterface
    {
        if (isset($this->assistantInstanceByName[$name])) {
            return $this->assistantInstanceByName[$name];
        }

        if (!array_key_exists($name, $this->assistantConfig)) {
            throw new UnknownAssistantException("Unknown assistant name passed $name");
        }

        $requestedAssistantConfig = $this->assistantConfig[$name];
        if (!isset($requestedAssistantConfig['class'])) {
            throw new Exception("$name assistant in config is missing class property");
        }

        $systemPromptProcessor = $this->defaultSystemPromptProcessor;
        if (array_key_exists('systemPrompt', $requestedAssistantConfig)) {
            if (!is_string($requestedAssistantConfig['systemPrompt'])) {
                throw new Exception("$name assistant systemPrompt property must be a string");
            }
            $systemPromptProcessor = new PrependingSystemPromptProcessor($systemPromptProcessor, $requestedAssistantConfig['systemPrompt']);
        }
        //todo: use interface for this
        if (is_subclass_of($requestedAssistantConfig['class'], AbstractOpenAIAPICompletingAssistant::class)) {
            $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
                $systemPromptProcessor,
                $this->responseStartProcessor,
                $this->telegramFileDownloader,
                $this->altTextProvider,
                $this->telegramBotId,
                $requestedAssistantConfig['url'],
                $this->openAiCompletionStringParser,
                $requestedAssistantConfig['supportsImages'] ?? false,
            );
        } elseif (is_a($requestedAssistantConfig['class'], OpenAiChatAssistant::class, true)
               || is_a($requestedAssistantConfig['class'], OpenAiPHPClientAssistant::class, true)
        ) {
            $tools = [];
            if ($requestedAssistantConfig['webSearch'] ?? false) {
                $tools['web_search'] =
                    new ToolDefinition(
                        'web_search',
                        $this->webSearchTool,
                        'Search the web',
                        [
                            new ToolParameter('query',    ['type' => 'string'], true),
                            new ToolParameter('max_results', ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], false),
                        ]
                    );
            }
            if ($requestedAssistantConfig['generateImages'] ?? false) {
                $tools['image_gen_tool'] =
                    new ToolDefinition(
                        'image_gen_tool',
                        $this->imageFromTextGeneratorTool,
                        'Generate an image from a text prompt and send it to user. Use as a tool call, not an action',
                        [
                            new ToolParameter('prompt',    ['type' => 'string'], true),
                        ]
                    );
            }
            if ($requestedAssistantConfig['toolReact'] ?? false) {
                $tools['react_with_emoji'] =
                    new ToolDefinition(
                        'react_with_emoji',
                        $this->reactToolCallExecutor,
                        'React to user\'s message with one of the allowed emojis, use to show emotions',
                        [
                            new ToolParameter('reaction',    [
                                'type' => 'string',
                                'allowed_values' => ReactToolCallExecutor::ALLOWED_REACTIONS,
                            ], true),
                        ]
                    );
            }
            $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
                $requestedAssistantConfig['model'] ?? null,
                $systemPromptProcessor,
                $this->responseStartProcessor,
                $this->telegramFileDownloader,
                $this->altTextProvider,
                $this->processingResultExecutor,
                $this->telegramBotId,
                $requestedAssistantConfig['url'],
                $requestedAssistantConfig['api_key'] ?? '',
                $requestedAssistantConfig['supportsImages'] ?? false,
                $tools,
            );
        } elseif(is_a($requestedAssistantConfig['class'], PerplexicaAssistant::class, true)) {
            $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
                $requestedAssistantConfig['url'],
            );
        } else {
            throw new Exception("Unexpected assistant class " . $requestedAssistantConfig['class']);
        }
        if (array_key_exists('abortResponseHandlers', $requestedAssistantConfig)) {
            if (!$this->assistantInstanceByName[$name] instanceof  AbortableStreamingResponseGenerator) {
                throw new Exception("Invalid configuration \"abortResponseHandlers\" value for $name: " . $requestedAssistantConfig['class']. " does not support it");
            }
            if (!is_array($requestedAssistantConfig['abortResponseHandlers'])) {
                throw new Exception("Invalid abortResponseHandlers value for $name: must contain an array of arguments");
            }
            foreach ($requestedAssistantConfig['abortResponseHandlers'] as $abortResponseHandler => $args) {
                $handlerInstance = new $abortResponseHandler(...$args);
                $this->assistantInstanceByName[$name]->addAbortResponseHandler($handlerInstance);
            }
        }

        return $this->assistantInstanceByName[$name];
    }
}
