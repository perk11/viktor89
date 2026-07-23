<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Log;

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
    public function __construct()
    {
        parent::__construct('viktor89', [new ErrorLogHandler()]);
    }
}
