<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\AssistedImageGenerator;
use Perk11\Viktor89\ExternallySetValuePreferenceProvider;
use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class MultipleModelsImageGenerateProcessor implements MessageChainProcessor
{
    public function __construct(
        private readonly ProcessingResultExecutor $processingResultExecutor,
        private readonly PhotoResponder $photoResponder,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly ImgTagExtractor $imgTagExtractor,
        private readonly UserPreferenceReaderInterface $denoisingStrengthPreference,
        private readonly UserPreferenceReaderInterface $seedPreference,
        private readonly UserPreferenceReaderInterface $imageSizeProcessor,
        private readonly AltTextProvider $altTextProvider,
        private readonly array $modelConfig,
        private readonly ?ContextCompletingAssistantInterface $visionAssistant,
    ) {
    }

    public function processMessageChain(
        MessageChain $messageChain,
        ProgressUpdateCallback $progressUpdateCallback
    ): ProcessingResult {
        if (trim($messageChain->last()->messageText) === '') {
            return new ProcessingResult(
                InternalMessage::asResponseTo(
                    $messageChain->last(),
                    'Укажите запрос после команды, например: /image A brutal man riding a motorcycle',
                ),
                true
            );
        }

        $modelPreference = new ExternallySetValuePreferenceProvider();
            $apiClient = new Automatic1111APiClient(
                denoisingStrengthPreference: $this->denoisingStrengthPreference,
                stepsPreference:             new FixedValuePreferenceProvider(null),
                seedPreference:              $this->seedPreference,
                imageModelPreference:        $modelPreference,
                modelConfig:                 $this->modelConfig,
                imageSizeProcessor:          $this->imageSizeProcessor,
            );
        if ($this->visionAssistant !== null) {
            $apiClient = new AssistedImageGenerator($apiClient, $this->visionAssistant, $modelPreference, $this->modelConfig);
        }

        $imageGenerateProcessor = new ImageGenerateProcessor(
            $apiClient,
            $this->photoResponder,
            $this->telegramFileDownloader,
            $this->imgTagExtractor,
            $modelPreference,
            $this->altTextProvider,
        );

        foreach ($this->getOrderedModelKeys() as $modelKeyGroup) {
            foreach ($modelKeyGroup as $modelKey) {
                $modelPreference->value = $modelKey;
                $result = $imageGenerateProcessor->processMessageChain($messageChain, $progressUpdateCallback);
                $this->processingResultExecutor->execute($result);
            }
        }
    }

    private function getOrderedModelKeys(): array
    {
        $modelKeysByUrl = [];
        foreach ($this->modelConfig as $name => $model) {
            if (array_key_exists('customUrl', $model)) {
                if (!array_key_exists($model['customUrl'], $modelKeysByUrl)) {
                    $modelKeysByUrl[$model['customUrl']] = [];
                }
                $modelKeysByUrl[$model['customUrl']][] = $name;
            } else {
                $modelKeysByUrl['blank'][] = $name;
            }
        }

        return $modelKeysByUrl;
    }

}
