<?php

namespace allomambo\fort\helpers;

/**
 * IPv4 / IPv6 CIDR and exact IP matching, plus SSRF-oriented helpers
 * (private-range detection and hostname-to-public-IP resolution).
 */
final class IpHelper
{
    /**
     * Collapse IPv4-mapped IPv6 (::ffff:a.b.c.d) to dotted IPv4 so the same client is not keyed twice (common behind Docker/proxies).
     */
    public static function canonicalIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '0.0.0.0';
        }
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m)) {
            return $m[1];
        }

        return $ip;
    }

    /**
     * Strings that may appear in {@see fort_security_events} / {@see fort_blocked_ips} for the same IPv4 host.
     *
     * @return list<string>
     */
    public static function equivalentClientIpStrings(string $ip): array
    {
        $ip = self::canonicalIp($ip);
        $variants = [$ip];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $mapped = '::ffff:' . $ip;
            if (strlen($mapped) <= 45) {
                $variants[] = $mapped;
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * @param string[] $excluded List of IPs or IPv4/IPv6 CIDR strings
     */
    public static function matchesExcluded(string $ip, array $excluded): bool
    {
        $ip = self::canonicalIp($ip);
        $ipIsV4 = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $ipIsV6 = !$ipIsV4 && (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        foreach ($excluded as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if (!str_contains($rule, '/')) {
                if (strcasecmp($ip, self::canonicalIp($rule)) === 0) {
                    return true;
                }
                continue;
            }

            // Family dispatch: only compare when client IP family matches subnet family.
            $parts = explode('/', $rule, 2);
            $subnet = $parts[0] ?? '';
            $subnetIsV4 = (bool) filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            $subnetIsV6 = !$subnetIsV4 && (bool) filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

            if ($ipIsV4 && $subnetIsV4 && self::ipv4InCidr($ip, $rule)) {
                return true;
            }
            if ($ipIsV6 && $subnetIsV6 && self::ipv6InCidr($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    public static function ipv4InCidr(string $ip, string $cidr): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$subnet, $maskBits] = $parts;
        if (!ctype_digit((string) $maskBits) && !((string) $maskBits === '0')) {
            // allow plain integers only
            if (!preg_match('/^\d+$/', (string) $maskBits)) {
                return false;
            }
        }
        $maskBits = (int) $maskBits;
        if ($maskBits < 0 || $maskBits > 32) {
            return false;
        }
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = $maskBits === 0 ? 0 : (~((1 << (32 - $maskBits)) - 1)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function ipv6InCidr(string $ip, string $cidr): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$subnet, $maskBits] = $parts;
        if (!preg_match('/^\d+$/', (string) $maskBits)) {
            return false;
        }
        $maskBits = (int) $maskBits;
        if ($maskBits < 0 || $maskBits > 128) {
            return false;
        }
        if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== 16 || strlen($subnetBin) !== 16) {
            return false;
        }

        $mask = '';
        $fullBytes = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;
        for ($i = 0; $i < $fullBytes; $i++) {
            $mask .= chr(0xFF);
        }
        if ($remainderBits !== 0) {
            $mask .= chr((0xFF << (8 - $remainderBits)) & 0xFF);
        }
        while (strlen($mask) < 16) {
            $mask .= chr(0);
        }

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    /**
     * True when the given string is a private, reserved, loopback, link-local, CGNAT,
     * multicast, broadcast, or IPv4/IPv6 metadata address — i.e., anything SSRF must not reach.
     * Unparseable input returns true (fail closed).
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        $ip = self::canonicalIp(trim($ip));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        // FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE covers:
        // - RFC1918 (10/8, 172.16/12, 192.168/16)
        // - loopback (127/8, ::1)
        // - link-local (169.254/16, fe80::/10)
        // - multicast, 0.0.0.0/8, unspecified
        // - IPv6 ULA (fc00::/7) and other PHP-reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // Belt-and-suspenders: CGNAT (100.64.0.0/10) — not always covered by FILTER_FLAG_NO_RES_RANGE.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && self::ipv4InCidr($ip, '100.64.0.0/10')) {
            return true;
        }

        // Cloud metadata service (AWS, GCP, Azure, DO): 169.254.169.254 (technically link-local and already caught,
        // but kept explicit for clarity and defense against filter_flag edge cases).
        if ($ip === '169.254.169.254') {
            return true;
        }

        // IPv4 limited broadcast.
        if ($ip === '255.255.255.255') {
            return true;
        }

        return false;
    }

    /**
     * True only when every resolved IP for $host is a routable public address.
     * Returns false (fail closed) on empty resolution, any private/reserved result,
     * or DNS errors. If the caller passes a literal IP, no DNS lookup is performed.
     *
     * @param list<string>|null $resolvedIps out param for the set of resolved addresses
     */
    public static function hostnameResolvesToPublicOnly(string $host, ?array &$resolvedIps = null): bool
    {
        $host = trim($host);
        $resolvedIps = [];
        if ($host === '') {
            return false;
        }

        // If the host is itself an IP literal, skip DNS and just evaluate that IP.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $resolvedIps = [$host];
            return !self::isPrivateOrReservedIp($host);
        }

        $a = @gethostbynamel($host);
        if ($a === false) {
            $a = [];
        }

        $aaaaRaw = @dns_get_record($host, DNS_AAAA);
        $aaaa = [];
        if (is_array($aaaaRaw)) {
            foreach ($aaaaRaw as $rec) {
                if (isset($rec['ipv6']) && is_string($rec['ipv6']) && $rec['ipv6'] !== '') {
                    $aaaa[] = $rec['ipv6'];
                }
            }
        }

        $merged = array_values(array_unique(array_merge($a, $aaaa)));
        $resolvedIps = $merged;

        if ($merged === []) {
            return false;
        }

        foreach ($merged as $resolved) {
            if (self::isPrivateOrReservedIp($resolved)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the entry is a valid bare IPv4 or IPv6 address.
     *
     * Centralized so every caller (controllers, services, validators) shares
     * the same flag combination and we never accidentally reject a valid IP
     * because of an incorrect FILTER_FLAG_IPV4 / FILTER_FLAG_IPV6 mix.
     */
    public static function isValidIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        // FILTER_VALIDATE_IP without family flags accepts both IPv4 and IPv6.
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * True when the entry is a valid bare IP (IPv4 or IPv6) or a valid IPv4/IPv6 CIDR.
     */
    public static function isValidCidrOrIp(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (str_contains($entry, '/')) {
            $parts = explode('/', $entry, 2);
            if (count($parts) !== 2) {
                return false;
            }
            [$subnet, $bits] = $parts;
            if (!preg_match('/^\d+$/', (string) $bits)) {
                return false;
            }
            $bits = (int) $bits;
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $bits >= 0 && $bits <= 32;
            }
            if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $bits >= 0 && $bits <= 128;
            }
            return false;
        }

        return self::isValidIp($entry);
    }
}
