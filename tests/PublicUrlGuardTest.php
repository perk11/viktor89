<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\UrlFetch\BlockedUrlException;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\HostAddressResolver;
use Perk11\Viktor89\Assistant\Tool\UrlFetch\PublicUrlGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PublicUrlGuard::class)]
class PublicUrlGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function nonPublicIps(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback end of range' => ['127.255.255.254'];
        yield 'zero' => ['0.0.0.0'];
        yield '10/8' => ['10.1.2.3'];
        yield '172.16/12 start' => ['172.16.0.1'];
        yield '172.16/12 end' => ['172.31.255.255'];
        yield '192.168/16' => ['192.168.1.1'];
        yield 'link-local / metadata' => ['169.254.169.254'];
        yield 'cgnat' => ['100.64.0.1'];
        yield 'benchmark' => ['198.18.0.1'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'ipv6 loopback' => ['::1'];
        yield 'ipv6 unspecified' => ['::'];
        yield 'ipv6 unique local' => ['fd12:3456::1'];
        yield 'ipv6 link local' => ['fe80::1'];
        yield 'ipv6 site local' => ['fec0::1'];
        yield 'ipv6 multicast' => ['ff02::1'];
        yield 'ipv6 documentation' => ['2001:db8::1'];
        yield 'ipv4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'ipv4-mapped private hex' => ['::ffff:c0a8:1'];
        yield 'ipv4-compatible loopback' => ['::127.0.0.1'];
        yield 'nat64 private' => ['64:ff9b::10.0.0.1'];
        yield 'garbage' => ['not-an-ip'];
    }

    #[DataProvider('nonPublicIps')]
    public function testNonPublicIpsAreRejected(string $ip): void
    {
        $this->assertFalse(PublicUrlGuard::isPublicIp($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function publicIps(): iterable
    {
        yield 'google dns' => ['8.8.8.8'];
        yield 'just above 172.16/12' => ['172.32.0.1'];
        yield 'just below 172.16/12' => ['172.15.255.255'];
        yield 'just above 100.64/10' => ['100.128.0.1'];
        yield 'ipv6 public' => ['2606:4700::1111'];
        yield 'ipv4-mapped public' => ['::ffff:8.8.8.8'];
        yield 'nat64 public' => ['64:ff9b::8.8.8.8'];
    }

    #[DataProvider('publicIps')]
    public function testPublicIpsAreAccepted(string $ip): void
    {
        $this->assertTrue(PublicUrlGuard::isPublicIp($ip));
    }

    public function testPinsFirstResolvedAddressAndDefaultPort(): void
    {
        $guard = $this->guardResolving(['example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']]);

        $pinned = $guard->pin('https://example.com/page?x=1');

        $this->assertSame('https://example.com/page?x=1', $pinned->url);
        $this->assertSame('example.com', $pinned->host);
        $this->assertSame(443, $pinned->port);
        $this->assertSame('93.184.216.34', $pinned->ip);
        $this->assertSame('example.com:443:93.184.216.34', $pinned->curlResolveEntry());
    }

    public function testPinsExplicitPortAndIpv6Literal(): void
    {
        $guard = $this->guardResolving(['2606:4700::1111' => ['2606:4700::1111']]);

        $pinned = $guard->pin('http://[2606:4700::1111]:8080/');

        $this->assertSame(8080, $pinned->port);
        $this->assertSame('2606:4700::1111:8080:[2606:4700::1111]', $pinned->curlResolveEntry());
    }

    public function testRejectsHostnameResolvingToLoopback(): void
    {
        $guard = $this->guardResolving(['localhost' => ['127.0.0.1']]);

        $this->expectException(BlockedUrlException::class);
        $this->expectExceptionMessage('private or local network address');
        $guard->pin('http://localhost:8188/history');
    }

    public function testRejectsWhenAnyResolvedAddressIsPrivate(): void
    {
        $guard = $this->guardResolving(['rebind.example' => ['8.8.8.8', '10.0.0.5']]);

        $this->expectException(BlockedUrlException::class);
        $guard->pin('http://rebind.example/');
    }

    public function testRejectsLiteralPrivateIp(): void
    {
        $guard = $this->guardResolving(['192.168.1.10' => ['192.168.1.10']]);

        $this->expectException(BlockedUrlException::class);
        $guard->pin('http://192.168.1.10:7860/sdapi/v1/options');
    }

    public function testRejectsUnresolvableHost(): void
    {
        $guard = $this->guardResolving(['nope.invalid' => []]);

        $this->expectException(BlockedUrlException::class);
        $this->expectExceptionMessage('could not be resolved');
        $guard->pin('http://nope.invalid/');
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $guard = $this->guardResolving([]);

        $this->expectException(BlockedUrlException::class);
        $guard->pin('ftp://example.com/');
    }

    public function testRejectsEmbeddedCredentials(): void
    {
        $guard = $this->guardResolving(['example.com' => ['8.8.8.8']]);

        $this->expectException(BlockedUrlException::class);
        $guard->pin('http://user:pass@example.com/');
    }

    /** @param array<string, list<string>> $hosts */
    private function guardResolving(array $hosts): PublicUrlGuard
    {
        $resolver = $this->createMock(HostAddressResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (string $host): array => $hosts[$host] ?? [],
        );

        return new PublicUrlGuard($resolver, new NullLogger());
    }
}
