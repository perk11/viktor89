<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\ImageGeneration\ImageGenerationType;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Automatic1111APiClient::class)]
class Automatic1111APiClientModelNameTest extends TestCase
{
    private const array MODELS = [
        'sdxl'      => ['model' => 'sdxl'],
        'flux-edit' => ['model' => 'flux-edit', 'txt2img' => false, 'img2img' => true],
    ];

    public function testSelectedModelIsUsed(): void
    {
        $this->assertSame('flux-edit', $this->client('flux-edit')->resolveModelName(1, ImageGenerationType::img2img));
    }

    public function testFirstModelIsUsedWithoutAValidPreference(): void
    {
        $this->assertSame('sdxl', $this->client(null)->resolveModelName(1, ImageGenerationType::txt2img));
        $this->assertSame('sdxl', $this->client('removed-model')->resolveModelName(1, ImageGenerationType::txt2img));
    }

    public function testFirstModelSupportingTheGenerationTypeIsUsedWhenTheSelectedOneDoesNot(): void
    {
        $this->assertSame('sdxl', $this->client('flux-edit')->resolveModelName(1, ImageGenerationType::txt2img));
        $this->assertSame('flux-edit', $this->client('sdxl')->resolveModelName(1, ImageGenerationType::img2img));
    }

    private function client(?string $selectedModel): Automatic1111APiClient
    {
        $_ENV['AUTOMATIC1111_API_URL'] ??= 'http://localhost:7860';
        $noPreference = $this->createStub(UserPreferenceReaderInterface::class);
        $modelPreference = $this->createStub(UserPreferenceReaderInterface::class);
        $modelPreference->method('getCurrentPreferenceValue')->willReturn($selectedModel);

        return new Automatic1111APiClient($noPreference, $noPreference, $noPreference, $modelPreference, self::MODELS, $noPreference);
    }
}
