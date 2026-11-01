<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\UserFacingExceptionInterface;

/** The model passed missing, unknown or malformed arguments to a tool. */
class ToolArgumentException extends \InvalidArgumentException implements UserFacingExceptionInterface
{
}
