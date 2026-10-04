<?php

declare(strict_types=1);

namespace allomambo\fort\tests\unit;

use allomambo\fort\helpers\IpHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpHelperTest extends TestCase
{
    #[DataProvider('canonicalIpProvider')]
    public function testCanonicalIpNormalizesClientAddresses(string $input, string $expected): void
    {
        self::assertSame($expected, IpHelper::canonicalIp($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function canonicalIpProvider(): iterable
    {
        yield 'IPv4' => [' 192.0.2.42 ', '192.0.2.42'];
        yield 'IPv6' => [' 2001:db8::42 ', '2001:db8::42'];
        yield 'IPv4-mapped IPv6' => ['::ffff:192.0.2.42', '192.0.2.42'];
        yield 'empty input' => [' ', '0.0.0.0'];
    }

    public function testMatchesExcludedAcceptsExactAndCidrRulesForBothFamilies(): void
    {
        self::assertTrue(IpHelper::matchesExcluded('192.0.2.42', ['192.0.2.0/24']));
        self::assertFalse(IpHelper::matchesExcluded('192.0.3.42', ['192.0.2.0/24']));
        self::assertTrue(IpHelper::matchesExcluded('2001:db8:abcd::42', ['2001:db8:abcd::/48']));
        self::assertFalse(IpHelper::matchesExcluded('2001:db8:abce::42', ['2001:db8:abcd::/48']));
        self::assertTrue(IpHelper::matchesExcluded('::ffff:192.0.2.42', ['192.0.2.42']));
    }

    public function testIpv4CidrSupportsBoundaryMasks(): void
    {
        self::assertTrue(IpHelper::ipv4InCidr('203.0.113.9', '0.0.0.0/0'));
        self::assertTrue(IpHelper::ipv4InCidr('203.0.113.9', '203.0.113.9/32'));
        self::assertFalse(IpHelper::ipv4InCidr('203.0.113.10', '203.0.113.9/32'));
    }

    #[DataProvider('invalidIpv4CidrProvider')]
    public function testIpv4CidrRejectsInvalidInput(string $ip, string $cidr): void
    {
        self::assertFalse(IpHelper::ipv4InCidr($ip, $cidr));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIpv4CidrProvider(): iterable
    {
        yield 'invalid client IP' => ['not-an-ip', '192.0.2.0/24'];
        yield 'missing mask' => ['192.0.2.42', '192.0.2.0'];
        yield 'invalid subnet' => ['192.0.2.42', 'not-an-ip/24'];
        yield 'negative mask' => ['192.0.2.42', '192.0.2.0/-1'];
        yield 'oversized mask' => ['192.0.2.42', '192.0.2.0/33'];
        yield 'mask with trailing newline' => ['192.0.2.42', "192.0.2.0/24\n"];
    }

    #[DataProvider('privateOrInvalidIpProvider')]
    public function testPrivateAndInvalidAddressesFailClosed(string $ip): void
    {
        self::assertTrue(IpHelper::isPrivateOrReservedIp($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateOrInvalidIpProvider(): iterable
    {
        yield 'invalid' => ['not-an-ip'];
        yield 'IPv4 private' => ['10.0.0.1'];
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv4 CGNAT' => ['100.64.0.1'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 ULA' => ['fd00::1'];
        yield 'IPv4-mapped hex loopback' => ['::ffff:7f00:1'];
        yield 'IPv4-mapped hex private' => ['::ffff:c0a8:1'];
        yield 'IPv4 multicast' => ['224.0.0.1'];
        yield 'IPv4 multicast top' => ['239.255.255.255'];
        yield 'documentation' => ['192.0.2.1'];
        yield 'benchmarking' => ['198.18.0.1'];
        yield 'benchmarking top' => ['198.19.255.255'];
        yield 'NAT64' => ['64:ff9b::8.8.8.8'];
        yield 'NAT64 embedded private' => ['64:ff9b::192.168.1.1'];
        yield '6to4' => ['2002:808:808::'];
        yield '6to4 embedded private' => ['2002:c0a8:101::'];
        yield 'IPv4-mapped hex documentation' => ['::ffff:c000:201'];
    }

    public function testPublicAddressesAreAccepted(): void
    {
        self::assertFalse(IpHelper::isPrivateOrReservedIp('8.8.8.8'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('2606:4700:4700::1111'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('::ffff:8.8.8.8'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('::ffff:0808:0808'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('223.255.255.255'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('192.0.3.1'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('198.17.0.1'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('198.20.0.1'));
        self::assertFalse(IpHelper::isPrivateOrReservedIp('64:ff9b:1::808:808'));
    }

    #[DataProvider('validCidrOrIpProvider')]
    public function testValidCidrOrIpParsing(string $entry, bool $expected): void
    {
        self::assertSame($expected, IpHelper::isValidCidrOrIp($entry));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validCidrOrIpProvider(): iterable
    {
        yield 'IPv4' => ['192.0.2.42', true];
        yield 'IPv6' => ['2001:db8::42', true];
        yield 'IPv4 CIDR' => ['192.0.2.0/24', true];
        yield 'IPv6 CIDR' => ['2001:db8::/32', true];
        yield 'empty' => ['', false];
        yield 'hostname' => ['example.com', false];
        yield 'invalid IPv4 mask' => ['192.0.2.0/33', false];
        yield 'invalid IPv6 mask' => ['2001:db8::/129', false];
        yield 'non-numeric mask' => ['192.0.2.0/abc', false];
    }
}
