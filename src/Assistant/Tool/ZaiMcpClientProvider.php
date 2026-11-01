<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Level;
use Monolog\Logger;

final class ZaiMcpClientProvider
{
    /**
     * @return \Closure(): Client Connects to the Z.ai MCP endpoint when invoked.
     */
    public static function create(string $endpoint, string $apiKey, string $loggerName, Level $logLevel = Level::Debug): \Closure
    {
        return static function () use ($endpoint, $apiKey, $loggerName, $logLevel): Client {
            $transport = new HttpTransport(
                endpoint: $endpoint,
                headers:  ['Authorization' => 'Bearer ' . $apiKey],
            );

            $logger = new Logger(
                $loggerName,
                [new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $logLevel)],
            );

            $client = Client::builder()
                ->setClientInfo('Viktor89', '1.0.0')
                ->setLogger($logger)
                ->build();
            $client->connect($transport);

            return $client;
        };
    }
}
