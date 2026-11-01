<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\UrlFetch;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Decides whether a URL may be fetched on behalf of a chat user. Everything
 * that resolves to a non-public address is refused: the inference servers and
 * other LAN services must never be reachable through the bot's URL tool
 * (server-side request forgery). Every resolved address is checked, and the
 * caller is told which address to pin the request to.
 */
class PublicUrlGuard
{
    private const array BLOCKED_IPV4_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const array BLOCKED_IPV6_RANGES = [
        '::/128',
        '::1/128',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /** Prefixes that embed an IPv4 address in the low 32 bits. */
    private const array IPV4_EMBEDDING_IPV6_RANGES = [
        '::ffff:0:0/96',
        '::/96',
        '64:ff9b::/96',
    ];

    public function __construct(
        private readonly HostAddressResolver $hostAddressResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws BlockedUrlException when the URL is malformed, not http(s), or not public
     */
    public function pin(string $url): PinnedUrl
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new BlockedUrlException('url must be a valid URL');
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new BlockedUrlException('only http and https URLs can be fetched');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedUrlException('URLs with embedded credentials cannot be fetched');
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new BlockedUrlException('url must contain a host');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $bareHost = trim($host, '[]');
        $addresses = $this->hostAddressResolver->resolve($bareHost);
        if ($addresses === []) {
            $this->logger->log(LogLevel::INFO, "URL fetch refused: $host does not resolve");
            throw new BlockedUrlException('the host of the URL could not be resolved');
        }
        foreach ($addresses as $address) {
            if (!self::isPublicIp($address)) {
                $this->logger->log(LogLevel::WARNING, "URL fetch refused: $host resolves to non-public address $address ($url)");
                throw new BlockedUrlException('the URL points to a private or local network address and cannot be fetched');
            }
        }

        return new PinnedUrl($url, $bareHost, $port, $addresses[0]);
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return !self::inAnyRange($packed, self::BLOCKED_IPV4_RANGES);
        }
        if (self::inAnyRange($packed, self::IPV4_EMBEDDING_IPV6_RANGES)) {
            return !self::inAnyRange(substr($packed, 12), self::BLOCKED_IPV4_RANGES);
        }

        return !self::inAnyRange($packed, self::BLOCKED_IPV6_RANGES);
    }

    /** @param list<string> $ranges */
    private static function inAnyRange(string $packedIp, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $prefixLength] = explode('/', $range);
            $packedNetwork = inet_pton($network);
            if (strlen($packedNetwork) !== strlen($packedIp)) {
                continue;
            }
            if (self::matchesPrefix($packedIp, $packedNetwork, (int) $prefixLength)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesPrefix(string $packedIp, string $packedNetwork, int $prefixLength): bool
    {
        $fullBytes = intdiv($prefixLength, 8);
        if (substr($packedIp, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }
        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return ((ord($packedIp[$fullBytes]) ^ ord($packedNetwork[$fullBytes])) & $mask) === 0;
    }
}
