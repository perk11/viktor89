<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\UrlFetch;

/**
 * A URL together with the public IP address it was validated against, so the
 * request can be forced to that address (defeats DNS rebinding between the
 * check and the fetch).
 */
final readonly class PinnedUrl
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {
    }

    /** Value for CURLOPT_RESOLVE. */
    public function curlResolveEntry(): string
    {
        $address = str_contains($this->ip, ':') ? "[$this->ip]" : $this->ip;

        return "$this->host:$this->port:$address";
    }
}
