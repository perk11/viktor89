<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\AbortStreamingResponse\AbortableStreamingResponseGenerator;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class AssistantFactory
{
    private array $assistantInstanceByName;
    public function __construct(
        private readonly array $assistantConfig,
        private readonly UserPreferenceReaderInterface $defaultSystemPromptProcessor,
        private readonly UserPreferenceReaderInterface $responseStartProcessor,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
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

    public function getDefaultAssistantInstance(): AbstractOpenAIAPiAssistant
    {
        return $this->getAssistantInstanceByName($this->getSupportedModels()[0]);
    }

    public function getAssistantInstanceByName(string $name): AbstractOpenAIAPiAssistant
    {
        if (isset($this->assistantInstanceByName[$name])) {
            return $this->assistantInstanceByName[$name];
        }

        if (!array_key_exists($name, $this->assistantConfig)) {
            throw new UnknownAssistantException("Unknown assistant name passed $name");
        }

        $requestedAssistantConfig = $this->assistantConfig[$name];
        if (!isset($requestedAssistantConfig['class'])) {
            throw new \Exception("$name assistant in config is missing class property");
        }

        $systemPromptProcessor = $this->defaultSystemPromptProcessor;
        if (array_key_exists('systemPrompt', $requestedAssistantConfig)) {
            if (!is_string($requestedAssistantConfig['systemPrompt'])) {
                throw new \Exception("$name assistant systemPrompt property must be a string");
            }
            $systemPromptProcessor = new PrependingSystemPromptProcessor($systemPromptProcessor, $requestedAssistantConfig['systemPrompt']);
        }
        //todo: use interface for this
        if (is_subclass_of($requestedAssistantConfig['class'], AbstractOpenAIAPICompletingAssistant::class)) {
            $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
                $systemPromptProcessor,
                $this->responseStartProcessor,
                $requestedAssistantConfig['url'],
                $this->openAiCompletionStringParser,
            );
        } else {
            $this->assistantInstanceByName[$name] = new $requestedAssistantConfig['class'](
                $systemPromptProcessor,
                $this->responseStartProcessor,
                $requestedAssistantConfig['url'],
            );
        }
        if (array_key_exists('abortResponseHandlers', $requestedAssistantConfig)) {
            if (!$this->assistantInstanceByName[$name] instanceof  AbortableStreamingResponseGenerator) {
                throw new \Exception("Invalid configuration \"abortResponseHandlers\" value for $name: " . $requestedAssistantConfig['class']. " does not support it");
            }
            if (!is_array($requestedAssistantConfig['abortResponseHandlers'])) {
                throw new \Exception("Invalid abortResponseHandlers value for $name: must contain an array of arguments");
            }
            foreach ($requestedAssistantConfig['abortResponseHandlers'] as $abortResponseHandler => $args) {
                $handlerInstance = new $abortResponseHandler(...$args);
                $this->assistantInstanceByName[$name]->addAbortResponseHandler($handlerInstance);
            }
        }

        return $this->assistantInstanceByName[$name];
    }
}
