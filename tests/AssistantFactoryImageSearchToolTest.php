<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantFactory;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
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
class AssistantFactoryImageSearchToolTest extends TestCase
{
    public function testImageSearchFlagRegistersTool(): void
    {
        $imageSearchTool = $this->createStub(MessageChainAwareToolCallExecutorInterface::class);

        $tools = $this->getTools($this->buildFactory($imageSearchTool), ['imageSearch' => true]);

        $this->assertArrayHasKey('image_search', $tools);
        $this->assertInstanceOf(ToolDefinition::class, $tools['image_search']);
        $definition = $tools['image_search']->toArray();
        $this->assertSame('image_search', $definition['function']['name']);
        $this->assertSame(['query'], $definition['function']['parameters']['required']);
        $this->assertArrayHasKey('max_results', $definition['function']['parameters']['properties']);
    }

    public function testImageSearchToolIsNotRegisteredWithoutFlag(): void
    {
        $imageSearchTool = $this->createStub(MessageChainAwareToolCallExecutorInterface::class);

        $tools = $this->getTools($this->buildFactory($imageSearchTool), ['webSearch' => true]);

        $this->assertArrayNotHasKey('image_search', $tools);
    }

    public function testImageSearchFlagWithoutExecutorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('zAiWebSearchApiKey');

        $this->getTools($this->buildFactory(null), ['imageSearch' => true]);
    }

    private function getTools(AssistantFactory $factory, array $assistantConfig): array
    {
        return (new \ReflectionMethod($factory, 'getTools'))->invoke($factory, $assistantConfig);
    }

    private function buildFactory(?MessageChainAwareToolCallExecutorInterface $imageSearchTool): AssistantFactory
    {
        $stubReader = $this->createStub(UserPreferenceReaderInterface::class);
        $nullTool = $this->createStub(ToolCallExecutorInterface::class);
        $nullChainTool = $this->createStub(MessageChainAwareToolCallExecutorInterface::class);

        return new AssistantFactory(
            [],
            $stubReader,
            $stubReader,
            $stubReader,
            $this->createStub(OpenAiCompletionStringParser::class),
            new NullTelegramFileDownloader(),
            new NullAltTextProvider(),
            new ProcessingResultExecutor(new NullMessageRepository(), logger: new \Psr\Log\NullLogger()),
            $nullTool,
            $nullChainTool,
            $nullChainTool,
            $nullChainTool,
            $nullTool,
            $nullTool,
            $nullChainTool,
            123456789,
            $this->createStub(DraftUpdateCallback::class),
            $stubReader,
            $this->createStub(CompactionSummaryStoreInterface::class),
            new \Psr\Log\NullLogger(),
            $imageSearchTool,
        );
    }
}
