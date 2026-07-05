<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Automatic1111ImageApiResponse::class)]
class Automatic1111ImageApiResponseTest extends TestCase
{
    private function createValidResponseData(): array
    {
        return [
            'images' => [base64_encode('image1_data'), base64_encode('image2_data')],
            'parameters' => ['prompt' => 'a cute cat'],
            'info' => json_encode(['infotexts' => ['A generated cat image']]),
        ];
    }

    public function testFromStringWithValidData(): void
    {
        $data = $this->createValidResponseData();
        $json = json_encode($data);

        $response = Automatic1111ImageApiResponse::fromString($json);

        $this->assertCount(2, $response->images);
        $this->assertSame(['prompt' => 'a cute cat'], $response->parameters);
        $this->assertSame('A generated cat image', $response->info['infotexts'][0]);
    }

    public function testGetFirstImageAsPng(): void
    {
        $original = 'binary png data';
        $data = [
            'images' => [base64_encode($original)],
            'parameters' => [],
            'info' => json_encode(['infotexts' => []]),
        ];

        $response = Automatic1111ImageApiResponse::fromString(json_encode($data));

        $this->assertSame($original, $response->getFirstImageAsPng());
    }

    public function testGetCaptionReturnsFirstInfotext(): void
    {
        $data = $this->createValidResponseData();
        $response = Automatic1111ImageApiResponse::fromString(json_encode($data));

        $this->assertSame('A generated cat image', $response->getCaption());
    }

    public function testGetCaptionReturnsNullWhenNoInfotexts(): void
    {
        $data = [
            'images' => [base64_encode('data')],
            'parameters' => [],
            'info' => json_encode([]),
        ];

        $response = Automatic1111ImageApiResponse::fromString(json_encode($data));

        $this->assertNull($response->getCaption());
    }

    public function testGetCaptionReturnsNullWhenInfotextsIsEmpty(): void
    {
        $data = [
            'images' => [base64_encode('data')],
            'parameters' => [],
            'info' => json_encode(['infotexts' => []]),
        ];

        $response = Automatic1111ImageApiResponse::fromString(json_encode($data));

        $this->assertNull($response->getCaption());
    }

    public function testFromStringRejectsMissingImages(): void
    {
        $this->expectException(\RuntimeException::class);

        Automatic1111ImageApiResponse::fromString(json_encode([
            'parameters' => [],
            'info' => json_encode([]),
        ]));
    }

    public function testFromStringRejectsMissingParameters(): void
    {
        $this->expectException(\RuntimeException::class);

        Automatic1111ImageApiResponse::fromString(json_encode([
            'images' => [],
            'info' => json_encode([]),
        ]));
    }

    public function testFromStringRejectsMissingInfo(): void
    {
        $this->expectException(\RuntimeException::class);

        Automatic1111ImageApiResponse::fromString(json_encode([
            'images' => [],
            'parameters' => [],
        ]));
    }

    public function testFromStringRejectsNonStringInfo(): void
    {
        $this->expectException(\RuntimeException::class);

        Automatic1111ImageApiResponse::fromString(json_encode([
            'images' => [],
            'parameters' => [],
            'info' => ['not' => 'a string'],
        ]));
    }

    public function testConstructorStoresValues(): void
    {
        $response = new Automatic1111ImageApiResponse(
            [base64_encode('img')],
            ['prompt' => 'test'],
            ['infotexts' => ['caption']]
        );

        $this->assertCount(1, $response->images);
        $this->assertSame(['prompt' => 'test'], $response->parameters);
        $this->assertSame('caption', $response->getCaption());
    }

    public function testSendAsFileDefaultsToFalse(): void
    {
        $response = new Automatic1111ImageApiResponse([], [], []);

        $this->assertFalse($response->sendAsFile);
    }

    public function testSendAsFileCanBeSet(): void
    {
        $response = new Automatic1111ImageApiResponse([], [], []);
        $response->sendAsFile = true;

        $this->assertTrue($response->sendAsFile);
    }
}
