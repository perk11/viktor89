<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Perk11\Viktor89\Log\SecretRedactingProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretRedactingProcessor::class)]
class SecretRedactingProcessorTest extends TestCase
{
    public function testRegisteredSecretIsRedactedFromLogMessages(): void
    {
        SecretRedactingProcessor::registerSecret('123456:AAHtokentokentoken');
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler], [new SecretRedactingProcessor()]);

        $logger->error('GET https://api.telegram.org/file/bot123456:AAHtokentokentoken/videos/a.mp4 failed');

        $this->assertTrue($handler->hasErrorThatContains('https://api.telegram.org/file/bot***/videos/a.mp4 failed'));
        $this->assertFalse($handler->hasErrorThatContains('AAHtokentokentoken'));
    }

    public function testEmptySecretIsIgnored(): void
    {
        SecretRedactingProcessor::registerSecret('');

        $this->assertSame('nothing changes', SecretRedactingProcessor::redact('nothing changes'));
    }
}
