<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\GenericWebSearchToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\OllamaWebSearchToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\WebSearchToolFactory;
use Perk11\Viktor89\Assistant\Tool\ZaiWebSearchToolCallExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSearchToolFactory::class)]
class WebSearchToolFactoryTest extends TestCase
{
    public function testIncludesBothProvidersWhenBothKeysPresent(): void
    {
        $zai = $this->createMock(ZaiWebSearchToolCallExecutor::class);
        $factory = new WebSearchToolFactory(fn (string $key) => $zai, logger: new \Psr\Log\NullLogger());

        $providers = $factory->buildProviderList([
            'ollamaWebSearchApiKey' => 'ollama-key',
            'zAiWebSearchApiKey'    => 'zai-key',
        ]);

        $this->assertCount(2, $providers);
        // Ollama is preferred and listed first.
        $this->assertInstanceOf(OllamaWebSearchToolCallExecutor::class, $providers[0]);
        $this->assertSame($zai, $providers[1]);
    }

    public function testIncludesOnlyOllamaWhenZaiKeyAbsent(): void
    {
        $factory = new WebSearchToolFactory(logger: new \Psr\Log\NullLogger());

        $providers = $factory->buildProviderList([
            'ollamaWebSearchApiKey' => 'ollama-key',
        ]);

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(OllamaWebSearchToolCallExecutor::class, $providers[0]);
    }

    public function testIncludesOnlyZaiWhenOllamaKeyAbsent(): void
    {
        $zai = $this->createMock(ZaiWebSearchToolCallExecutor::class);
        $factory = new WebSearchToolFactory(fn (string $key) => $zai, logger: new \Psr\Log\NullLogger());

        $providers = $factory->buildProviderList([
            'zAiWebSearchApiKey' => 'zai-key',
        ]);

        $this->assertCount(1, $providers);
        $this->assertSame($zai, $providers[0]);
    }

    public function testThrowsWhenNoProviderConfigured(): void
    {
        $factory = new WebSearchToolFactory(logger: new \Psr\Log\NullLogger());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No web search provider configured');
        $factory->buildProviderList([]);
    }

    public function testTreatsBlankKeysAsAbsent(): void
    {
        $factory = new WebSearchToolFactory(logger: new \Psr\Log\NullLogger());

        $this->expectException(\InvalidArgumentException::class);
        $factory->buildProviderList([
            'ollamaWebSearchApiKey' => '   ',
            'zAiWebSearchApiKey'    => '',
        ]);
    }

    public function testTreatsNonStringKeysAsAbsent(): void
    {
        $factory = new WebSearchToolFactory(logger: new \Psr\Log\NullLogger());

        $this->expectException(\InvalidArgumentException::class);
        $factory->buildProviderList([
            'ollamaWebSearchApiKey' => 123,
            'zAiWebSearchApiKey'    => null,
        ]);
    }

    public function testBuildFromConfigReturnsGenericExecutor(): void
    {
        $zai = $this->createMock(ZaiWebSearchToolCallExecutor::class);
        $factory = new WebSearchToolFactory(fn (string $key) => $zai, logger: new \Psr\Log\NullLogger());

        $executor = $factory->buildFromConfig([
            'ollamaWebSearchApiKey' => 'ollama-key',
            'zAiWebSearchApiKey'    => 'zai-key',
        ]);

        $this->assertInstanceOf(GenericWebSearchToolCallExecutor::class, $executor);
        $this->assertInstanceOf(ToolCallExecutorInterface::class, $executor);
    }

    public function testPassesApiKeyToZaiFactory(): void
    {
        $receivedKey = null;
        $zai = $this->createMock(ZaiWebSearchToolCallExecutor::class);
        $factory = new WebSearchToolFactory(function (string $key) use ($zai, &$receivedKey): ZaiWebSearchToolCallExecutor {
            $receivedKey = $key;
            return $zai;
        }, logger: new \Psr\Log\NullLogger());

        $factory->buildProviderList(['zAiWebSearchApiKey' => 'my-zai-key']);

        $this->assertSame('my-zai-key', $receivedKey);
    }

    /**
     * Wiring the providers up must not perform any network I/O: the default
     * Z.ai factory produces a lazily-connecting executor, and building the
     * provider list with only a Z.ai key present must succeed without throwing
     * or connecting.
     */
    public function testDoesNotConnectToZaiWhenBuildingProviderList(): void
    {
        $factory = new WebSearchToolFactory(logger: new \Psr\Log\NullLogger());

        $providers = $factory->buildProviderList(['zAiWebSearchApiKey' => 'zai-key']);

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(ZaiWebSearchToolCallExecutor::class, $providers[0]);
    }
}
