<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant;
use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\Assistant\OpenAiChatAssistant;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\IPC\DraftUpdateCallback;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\Test\Support\NullAltTextProvider;
use Perk11\Viktor89\Test\Support\NullMessageRepository;
use Perk11\Viktor89\Test\Support\NullTelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(AssistantFactory::class)]
class AssistantFactoryModelNameTest extends TestCase
{
    public function testFactorySetsModelNameOnAssistant(): void
    {
        $config = [
            'my-model' => [
                'url' => 'http://localhost:8080/',
                'class' => OpenAiChatAssistant::class,
                'model' => 'my-model-id',
                'selectableByUser' => true,
            ],
        ];

        $factory = $this->buildFactory($config);
        $assistant = $factory->getAssistantInstanceByName('my-model');

        $this->assertInstanceOf(AbstractOpenAIAPiAssistant::class, $assistant);
        $reflection = new \ReflectionClass($assistant);
        $property = $reflection->getProperty('modelName');
        $property->setAccessible(true);
        $this->assertSame('my-model', $property->getValue($assistant));
    }

    private function buildFactory(array $assistantConfig): AssistantFactory
    {
        $stubReader = $this->createStub(UserPreferenceReaderInterface::class);
        $executor = new ProcessingResultExecutor(new NullMessageRepository(), logger: new \Psr\Log\NullLogger());
        $nullTool = $this->createStub(ToolCallExecutorInterface::class);
        $nullChainTool = $this->createStub(MessageChainAwareToolCallExecutorInterface::class);
        $draftCallback = $this->createStub(DraftUpdateCallback::class);

        return new AssistantFactory(
            $assistantConfig,
            $stubReader,
            $stubReader,
            $stubReader,
            $this->createStub(OpenAiCompletionStringParser::class),
            new NullTelegramFileDownloader(),
            new NullAltTextProvider(),
            $executor,
            $nullTool,
            $nullChainTool,
            $nullChainTool,
            $nullTool,
            $nullTool,
            $nullChainTool,
            123456789,
            $draftCallback,
            $stubReader,
            $this->createStub(CompactionSummaryStoreInterface::class),
            new \Psr\Log\NullLogger(),
        );
    }
}
