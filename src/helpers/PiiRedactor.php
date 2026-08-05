<?php

namespace allomambo\fort\helpers;

use Craft;

/**
 * Non-reversible redaction of the two personal-data fields Fort handles: the attempted
 * login (a real username or email) and the client IP.
 *
 * Used at every choke point where that data leaves the request: before persistence
 * (security events, alert rows) and before egress (notification email body, webhook
 * payload). Only applied when the `anonymizePii` setting is on.
 */
final class PiiRedactor
{
    /** Payload / meta keys that carry a client IP address. */
    private const IP_KEYS = ['ip', 'blockedClientIp'];

    private const HASH_PREFIX = 'sha256:';

    /** Hex characters kept from the digest: short enough to stay readable, wide enough to avoid collisions. */
    private const HASH_LENGTH = 12;

    /**
     * Mask an IP down to its network portion: IPv4 keeps the first three octets,
     * IPv6 keeps the first 48 bits. Empty, unspecified, and non-IP values are returned unchanged.
     */
    public static function anonymizeIp(string $ip): string
    {
        $trimmed = trim($ip);
        if ($trimmed === '' || $trimmed === '0.0.0.0') {
            return $ip;
        }

        $canonical = IpHelper::canonicalIp($trimmed);

        if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', $canonical);
            $octets[3] = '0';

            return implode('.', $octets);
        }

        if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = @inet_pton($canonical);
            if ($binary === false || strlen($binary) !== 16) {
                return $ip;
            }

            $masked = @inet_ntop(substr($binary, 0, 6) . str_repeat("\0", 10));

            return $masked === false ? $ip : $masked;
        }

        return $ip;
    }

    /**
     * Turn a login into a stable, non-reversible token so repeated attempts still correlate
     * without storing the identity. Salted with the site security key so tokens cannot be
     * pre-computed or compared across sites.
     */
    public static function hashLogin(string $login): string
    {
        $normalized = mb_strtolower(trim($login));
        if ($normalized === '') {
            return '';
        }

        $digest = hash('sha256', self::salt() . '|' . $normalized);

        return self::HASH_PREFIX . substr($digest, 0, self::HASH_LENGTH);
    }

    /**
     * Copy of $meta with the attempted login hashed and every IP-bearing key masked.
     * Other keys (including the Craft user IDs the CP relies on) are left untouched.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function redactMeta(array $meta): array
    {
        if (isset($meta['attemptedLogin']) && is_scalar($meta['attemptedLogin']) && (string) $meta['attemptedLogin'] !== '') {
            $meta['attemptedLogin'] = self::hashLogin((string) $meta['attemptedLogin']);
        }

        foreach (self::IP_KEYS as $key) {
            if (isset($meta[$key]) && is_scalar($meta[$key]) && (string) $meta[$key] !== '') {
                $meta[$key] = self::anonymizeIp((string) $meta[$key]);
            }
        }

        return $meta;
    }

    /**
     * Site security key, or a constant fallback when Craft is not bootstrapped
     * (console edge cases) so hashing never throws.
     */
    private static function salt(): string
    {
        try {
            $key = Craft::$app !== null
                ? Craft::$app->getConfig()->getGeneral()->securityKey
                : null;
        } catch (\Throwable) {
            $key = null;
        }

        return is_string($key) && $key !== '' ? $key : 'fort';
    }
}
