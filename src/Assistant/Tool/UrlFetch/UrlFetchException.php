<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\UrlFetch;

use Perk11\Viktor89\UserFacingExceptionInterface;

/**
 * A URL was fetched but cannot be used (status, size, content type, redirect
 * loop) or the transport failed. Messages carry no transport detail; the
 * cause is logged where it is thrown.
 */
class UrlFetchException extends \RuntimeException implements UserFacingExceptionInterface
{
}
