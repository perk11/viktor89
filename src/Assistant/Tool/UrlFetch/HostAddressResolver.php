<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\UrlFetch;

/**
 * Thin wrapper around DNS resolution so PublicUrlGuard can be unit-tested
 * without touching the network.
 */
class HostAddressResolver
{
    /**
     * @return list<string> every A and AAAA address of the host, empty when it does not resolve
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        foreach (gethostbynamel($host) ?: [] as $address) {
            $addresses[] = $address;
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
