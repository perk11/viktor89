<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\VoiceGeneration\TargetAndResidualResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetAndResidualResponse::class)]
class TargetAndResidualResponseTest extends TestCase
{
    private function createValidJson(): string
    {
        return json_encode([
            'target' => base64_encode('target_data'),
            'residual' => base64_encode('residual_data'),
        ]);
    }

    public function testFromStringWithValidData(): void
    {
        $response = TargetAndResidualResponse::fromString($this->createValidJson());
        $this->assertSame('target_data', $response->target);
        $this->assertSame('residual_data', $response->residual);
    }

    public function testConstructorStoresValues(): void
    {
        $response = new TargetAndResidualResponse('t', 'r');
        $this->assertSame('t', $response->target);
        $this->assertSame('r', $response->residual);
    }

    public function testFromStringRejectsMissingTarget(): void
    {
        $this->expectException(\RuntimeException::class);
        TargetAndResidualResponse::fromString(json_encode(['residual' => 'data']));
    }

    public function testFromStringRejectsMissingResidual(): void
    {
        $this->expectException(\RuntimeException::class);
        TargetAndResidualResponse::fromString(json_encode(['target' => 'data']));
    }

    public function testFromStringRejectsNonStringTarget(): void
    {
        $this->expectException(\RuntimeException::class);
        TargetAndResidualResponse::fromString(json_encode([
            'target' => 123,
            'residual' => 'data',
        ]));
    }

    public function testFromStringRejectsNonStringResidual(): void
    {
        $this->expectException(\RuntimeException::class);
        TargetAndResidualResponse::fromString(json_encode([
            'target' => 'data',
            'residual' => ['array'],
        ]));
    }

    public function testPropertiesAreReadonly(): void
    {
        $response = new TargetAndResidualResponse('t', 'r');
        $this->expectException(\Error::class);
        $response->target = 'changed';
    }

    public function testFromStringDecodesBase64(): void
    {
        $original = 'binary audio content';
        $json = json_encode([
            'target' => base64_encode($original),
            'residual' => base64_encode('res'),
        ]);
        $response = TargetAndResidualResponse::fromString($json);
        $this->assertSame($original, $response->target);
    }
}
