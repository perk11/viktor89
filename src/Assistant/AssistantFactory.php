<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Level;
use Monolog\Logger;
use Perk11\Viktor89\AbortStreamingResponse\AbortableStreamingResponseGenerator;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\Assistant\Tool\McpToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ReactToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use Perk11\Viktor89\IPC\DraftUpdateCallback;
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
        private readonly UserPreferenceReaderInterface $editFrequencyProcessor,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly AltTextProvider $altTextProvider,
        private readonly ProcessingResultExecutor $processingResultExecutor,
        private readonly ToolCallExecutorInterface $webSearchTool,
        private readonly MessageChainAwareToolCallExecutorInterface $imageFromTextGeneratorTool,
        private readonly MessageChainAwareToolCallExecutorInterface $reactToolCallExecutor,
        private readonly ToolCallExecutorInterface $getUrlContentsTool,
        private readonly ToolCallExecutorInterface $listSavedImagesTool,
        private readonly MessageChainAwareToolCallExecutorInterface $listChainImagesTool,
       private readonly int $telegramBotId,
       private readonly DraftUpdateCallback $draftUpdateCallback,
       private readonly UserPreferenceReaderInterface $personalityReader,
       private readonly CompactionSummaryStoreInterface $compactionStore,
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

    /**
     * True unless the model is restricted via an `allowedChatIds` list that does
     * not contain the given chat. Compared as strings so config values written
     * as JSON strings ("-100123") match the integer chat ids used at runtime.
     */
    public static function isModelAllowedInChat(array $modelConfig, int $chatId): bool
    {
        $allowedChatIds = $modelConfig['allowedChatIds'] ?? null;
        if ($allowedChatIds === null) {
            return true;
        }
        foreach ($allowedChatIds as $allowedChatId) {
            if ((string) $allowedChatId === (string) $chatId) {
                return true;
            }
        }

        return false;
    }

    public function isModelNameAllowedInChat(string $name, int $chatId): bool
    {
        if (!array_key_exists($name, $this->assistantConfig)) {
            return false;
        }

        return self::isModelAllowedInChat($this->assistantConfig[$name], $chatId);
    }

    /** @return string[] selectable model names permitted in $chatId */
    public function getSupportedModelsForChat(int $chatId): array
    {
        $models = [];
        foreach ($this->assistantConfig as $modelName => $assistantConfig) {
            if ($assistantConfig['selectableByUser'] && self::isModelAllowedInChat($assistantConfig, $chatId)) {
                $models[] = $modelName;
            }
        }

        return $models;
    }

    public function getDefaultAssistantInstance(): AssistantInterface
    {
        return $this->getAssistantInstanceByName($this->getSupportedModels()[0]);
    }

    public function getDefaultAssistantInstanceForChat(int $chatId): AssistantInterface
    {
        $models = $this->getSupportedModelsForChat($chatId);
        if (count($models) === 0) {
            return $this->getDefaultAssistantInstance();
        }

        return $this->getAssistantInstanceByName($models[0]);
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
        if (is_a($requestedAssistantConfig['class'], SiepatchAssistant::class, true)) {
            $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
                $systemPromptProcessor,
                $this->responseStartProcessor,
                $this->editFrequencyProcessor,
                $this->telegramFileDownloader,
                $this->altTextProvider,
                $this->telegramBotId,
                $requestedAssistantConfig['url'],
                $this->openAiCompletionStringParser,
                $this->personalityReader,
            );
        } elseif (is_subclass_of($requestedAssistantConfig['class'], AbstractOpenAIAPICompletingAssistant::class)) {
            $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
                $systemPromptProcessor,
                $this->responseStartProcessor,
                $this->editFrequencyProcessor,
                $this->telegramFileDownloader,
                $this->altTextProvider,
                $this->telegramBotId,
                $requestedAssistantConfig['url'],
                $this->openAiCompletionStringParser,
                $requestedAssistantConfig['supportsImages'] ?? false,
            );
       } elseif (is_a($requestedAssistantConfig['class'], OpenAiPHPClientAssistant::class, true)) {
           $tools = $this->getTools($requestedAssistantConfig);
           $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
               $requestedAssistantConfig['model'] ?? null,
               $systemPromptProcessor,
               $this->responseStartProcessor,
               $this->editFrequencyProcessor,
               $this->telegramFileDownloader,
               $this->altTextProvider,
               $this->processingResultExecutor,
               $this->telegramBotId,
               $requestedAssistantConfig['url'],
               $requestedAssistantConfig['api_key'] ?? '',
               $requestedAssistantConfig['supportsImages'] ?? false,
               $tools,
               $this->compactionStore,
           );
       } elseif (is_a($requestedAssistantConfig['class'], OpenAiChatAssistant::class, true)) {
           $tools = $this->getTools($requestedAssistantConfig);
           $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
               $requestedAssistantConfig['model'] ?? null,
               $systemPromptProcessor,
               $this->responseStartProcessor,
               $this->editFrequencyProcessor,
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

        if ($this->assistantInstanceByName[$name] instanceof AbstractOpenAIAPiAssistant) {
            $this->assistantInstanceByName[$name]->setDraftUpdateCallback($this->draftUpdateCallback);
            $this->assistantInstanceByName[$name]->setModelName($name);
        }

        return $this->assistantInstanceByName[$name];
    }

    protected function getTools(array $requestedAssistantConfig): array
    {
        $tools = [];
        if ($requestedAssistantConfig['webSearch'] ?? false) {
            $tools['web_search'] =
                new ToolDefinition(
                    'web_search',
                    $this->webSearchTool,
                    'Search the web',
                    [
                        new ToolParameter('query', ['type' => 'string'], true),
                        new ToolParameter('max_results', ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], false),
                    ]
                );
        }
        if ($requestedAssistantConfig['generateImages'] ?? false) {
            $tools['image_gen_tool'] =
                new ToolDefinition(
                    'image_gen_tool',
                    $this->imageFromTextGeneratorTool,
                    'Generate an image from a text prompt and send it to user. Use img tag in the prompt to reference images. For saved images: <img>savedImageName</img>. For images in this conversation: <img>#0</img> where #0, #1 etc. are indices from list_chain_images. Use list_chain_images to see available conversation images and their indices. Max 3 images per prompt.',
                    [
                        new ToolParameter('prompt', ['type' => 'string'], true),
                    ]
                );
            $tools['list_chain_images'] =
                new ToolDefinition(
                    'list_chain_images',
                    $this->listChainImagesTool,
                    'List images present in the current conversation (message chain). Each image has a numbered ID like "#0", "#1" that can be used to reference the image in image_gen_tool prompt inside "img" XML tag, e.g. <img>#0</img>. Images are numbered starting from 0 in the order they appear in the conversation. Use this to reference previously sent or generated images in the chat.',
                );
        }
        if ($requestedAssistantConfig['toolReact'] ?? false) {
            $tools['react_with_emoji'] =
                new ToolDefinition(
                    'react_with_emoji',
                    $this->reactToolCallExecutor,
                    'React to user\'s message with one of the allowed emojis, use to show emotions',
                    [
                        new ToolParameter('reaction', [
                            'type'           => 'string',
                            'allowed_values' => ReactToolCallExecutor::ALLOWED_REACTIONS,
                        ],                true),
                    ]
                );
        }
        if ($requestedAssistantConfig['toolGetUrlContents'] ?? false) {
            $tools['get_url_contents'] =
                new ToolDefinition(
                    'get_url_contents',
                    $this->getUrlContentsTool,
                    'Fetch the contents of a URL and return the text with HTML tags stripped, limited to 10000 characters',
                    [
                        new ToolParameter('url', ['type' => 'string'], true),
                    ]
                );
        }
        if ($requestedAssistantConfig['toolListSavedImages'] ?? false) {
            $tools['list_saved_images'] =
                new ToolDefinition(
                    'list_saved_images',
                    $this->listSavedImagesTool,
                    'List names of images saved by user. Saved images are only available to image_gen_tool if used in a prompt inside "img" XML tag, e.g. <img>SavedImageName</img>. No more than 3 images per prompt. When you want to generate an image for a non-common concept mentioned by the user, check this before generating an image. Newly generated images are not automatically added, the user has to save them using /saveas command first.',
                );
        }
        if ($requestedAssistantConfig['mcpServers'] ?? []) {
            foreach ($requestedAssistantConfig['mcpServers'] as $serverName => $serverConfig) {
                if (isset($serverConfig['command'])) {
                    $transport = new StdioTransport(
                        command: $serverConfig['command'],
                        args:    $serverConfig['args'] ?? [],
                        env:     $serverConfig['env'] ?? null,
                    );
                } elseif (isset($serverConfig['url'])) {
                    $transport = new HttpTransport(
                        endpoint: $serverConfig['url'],
                        headers:  $serverConfig['headers'] ?? [],
                    );
                } else {
                    throw new \RuntimeException("Invalid MCP server configuration for $serverName: missing command or url");
                }
                $logger = new Logger('mcp', [new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Level::Debug)]);
                $client = Client::builder()
                    ->setClientInfo('Viktor89', '1.0.0')
                    ->setLogger($logger)
                    ->build();
                $client->connect($transport);
                foreach ($client->listTools()->tools as $tool) {
                    $parameters = [];
                    $properties = $tool->inputSchema['properties'] ?? [];
                    if ($properties instanceof \stdClass) {
                        $properties = (array)$properties;
                    }
                    foreach ($properties as $parameterName => $parameterProperties) {
                        $parameters[] = new ToolParameter(
                            $parameterName,
                            $parameterProperties,
                            in_array($parameterName, $tool->inputSchema['required'] ?? [], true)
                        );
                    }
                    $serverSilent = $serverConfig['silent'] ?? false;
                    $silentTools = (array) ($serverConfig['silentTools'] ?? []);
                    $toolSilent = $serverSilent || in_array($tool->name, $silentTools, true);
                    $tools[$tool->name] = new ToolDefinition(
                        $tool->name,
                        new McpToolCallExecutor($client, $tool->name),
                        $tool->description,
                        $parameters,
                        'function',
                        $toolSilent
                    );
                }
            }
        }

        return $tools;
    }
}
