<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Assistant\Tool\ImageUploader::class)]
class ImageUploaderTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\Tool\ImageUploader::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasUploadPngMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\Tool\ImageUploader::class);
        $method = $reflection->getMethod('uploadPng');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('pngBytes', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testUploadPngReturnsUploadedGeneratedImage(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\Tool\ImageUploader::class);
        $method = $reflection->getMethod('uploadPng');
        $returnType = $method->getReturnType();
        $this->assertSame(\Perk11\Viktor89\Assistant\Tool\UploadedGeneratedImage::class, $returnType->getName());
    }

    public function testHasConstructor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\Tool\ImageUploader::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(6, $params);
        $this->assertSame('scpTarget', $params[0]->getName());
        $this->assertSame('publicUrlPrefix', $params[1]->getName());
        $this->assertSame('privateKeyPath', $params[2]->getName());
    }

    public function testHasPrivateParseScpTargetMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\Tool\ImageUploader::class);
        $method = $reflection->getMethod('parseScpTarget');
        $this->assertTrue($method->isPrivate());
        $this->assertSame('array', $method->getReturnType()->getName());
    }

    public function testHasPrivateUploadFileViaScpMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\Tool\ImageUploader::class);
        $method = $reflection->getMethod('uploadFileViaScp');
        $this->assertTrue($method->isPrivate());
        $this->assertSame('void', $method->getReturnType()->getName());
    }
}
