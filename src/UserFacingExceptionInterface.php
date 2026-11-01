<?php

declare(strict_types=1);

namespace Perk11\Viktor89;

/**
 * Marks exceptions whose message is written for the chat user (and the model)
 * and contains no internal detail: no hostnames, ports, paths, tokens or
 * upstream error bodies. Everything else is shown only as an opaque error id
 * that can be looked up in the log.
 */
interface UserFacingExceptionInterface extends \Throwable
{
}
