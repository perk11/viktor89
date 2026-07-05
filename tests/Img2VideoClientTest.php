<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\VideoGeneration\Img2VideoClient::class)]
class Img2VideoClientTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VideoGeneration\Img2VideoClient::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasGenerateByPromptImg2VidMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VideoGeneration\Img2VideoClient::class);
        $method = $reflection->getMethod('generateByPromptImg2Vid');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('imageContent', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('prompt', $params[1]->getName());
        $this->assertSame('userId', $params[2]->getName());
        $this->assertSame('int', $params[2]->getType()->getName());
    }

    public function testGenerateByPromptImg2VidReturnsVideoApiResponse(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VideoGeneration\Img2VideoClient::class);
        $method = $reflection->getMethod('generateByPromptImg2Vid');
        $returnType = $method->getReturnType();
        $this->assertSame(\Perk11\Viktor89\VideoGeneration\VideoApiResponse::class, $returnType->getName());
    }

    public function testConstructorTakesPreferences(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VideoGeneration\Img2VideoClient::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(5, $params);
        $this->assertSame(\Perk11\Viktor89\UserPreferenceReaderInterface::class, $params[0]->getType()->getName());
    }

    public function testHasPrivateRequestMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VideoGeneration\Img2VideoClient::class);
        $method = $reflection->getMethod('request');
        $this->assertTrue($method->isPrivate());
    }

    public function testHasHttpClientProperty(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VideoGeneration\Img2VideoClient::class);
        $property = $reflection->getProperty('httpClient');
        $this->assertSame(\GuzzleHttp\Client::class, $property->getType()->getName());
    }
}
