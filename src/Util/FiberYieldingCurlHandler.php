<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Util;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Psr\Http\Message\RequestInterface;

use function Amp\delay;

/**
 * Guzzle handler that lets a synchronous request made inside a BackgroundFiber
 * wait without blocking the process: the transfer runs on a curl multi handle
 * that is polled between event-loop ticks, so other background fibers (e.g.
 * the other tool calls of the same model turn) run in the meantime.
 *
 * Every other request (async, streamed, or outside a background fiber) goes
 * to Guzzle's default handler unchanged.
 */
final class FiberYieldingCurlHandler
{
    private const float MIN_POLL_INTERVAL_SECONDS = 0.005;
    private const float MAX_POLL_INTERVAL_SECONDS = 0.1;

    /** @var callable(RequestInterface, array): PromiseInterface */
    private $defaultHandler;

    public function __construct(?callable $defaultHandler = null)
    {
        $this->defaultHandler = $defaultHandler ?? Utils::chooseHandler();
    }

    public static function createStack(): HandlerStack
    {
        return HandlerStack::create(new self());
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (empty($options['synchronous']) || !empty($options['stream']) || !BackgroundFiber::isCurrent()) {
            return ($this->defaultHandler)($request, $options);
        }

        $multiHandler = new CurlMultiHandler(['select_timeout' => 0]);
        $promise = $multiHandler($request, $options);
        $pollInterval = self::MIN_POLL_INTERVAL_SECONDS;
        while (true) {
            $multiHandler->tick();
            if ($promise->getState() !== PromiseInterface::PENDING) {
                return $promise;
            }
            delay($pollInterval);
            $pollInterval = min($pollInterval * 2, self::MAX_POLL_INTERVAL_SECONDS);
        }
    }
}
