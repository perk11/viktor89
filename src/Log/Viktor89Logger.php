<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Log;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;

/**
 * Default Psr\Log\LoggerInterface implementation for the bot.
 *
 * Extends Monolog and ships with an ErrorLogHandler so log output is routed
 * through PHP's error log, replacing the ad-hoc echo statements that were
 * scattered through the codebase.
 *
 * Autowired automatically by the DI container: it is the single class in the
 * Perk11\Viktor89 namespace implementing LoggerInterface, so the container's
 * interface-implementation scan resolves every LoggerInterface constructor
 * argument to this class.
 */
class Viktor89Logger extends Logger
{
    public function __construct(string $name='viktor89')
    {
        $handler = new ErrorLogHandler();
        // Drop the trailing %context% %extra% placeholders (always empty here,
        // since every call passes a single string message) so lines don't end
        // with the noisy "[] []".
        $handler->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message%\n",
        ));
        parent::__construct($name, [$handler]);
    }
}
