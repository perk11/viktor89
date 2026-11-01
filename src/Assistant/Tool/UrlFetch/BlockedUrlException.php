<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\UrlFetch;

/**
 * The URL must not be fetched: it points at a loopback, private, link-local or
 * otherwise non-public address, or cannot be resolved. The message is written
 * for the model and the user and never includes resolved addresses.
 */
class BlockedUrlException extends \RuntimeException
{
}
