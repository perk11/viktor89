<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Util;

use Amp\Future;

use function Amp\async;

/**
 * Runs work in a fiber of its own and marks that fiber as one in which
 * blocking I/O may hand control back to the event loop (see
 * FiberYieldingCurlHandler), so several of them make progress at once.
 *
 * Only fibers started here are marked: libraries that drive fibers of their
 * own (the MCP SDK) would break if the event loop resumed them instead.
 */
final class BackgroundFiber
{
    /** @var \WeakMap<\Fiber, true>|null */
    private static ?\WeakMap $fibers = null;

    /**
     * @template T
     * @param \Closure(): T $task
     * @return Future<T>
     */
    public static function start(\Closure $task): Future
    {
        return async(static function () use ($task): mixed {
            $fiber = \Fiber::getCurrent();
            self::$fibers ??= new \WeakMap();
            self::$fibers[$fiber] = true;
            try {
                return $task();
            } finally {
                // Revolt reuses the fiber for other callbacks once this one returns.
                unset(self::$fibers[$fiber]);
            }
        });
    }

    public static function isCurrent(): bool
    {
        $fiber = \Fiber::getCurrent();

        return $fiber !== null && isset(self::$fibers[$fiber]);
    }
}
