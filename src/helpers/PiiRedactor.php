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
    /** Stands in for the attempted login when no security key is available to key the HMAC. */
    public const REDACTION_MARKER = '[redacted]';

    /** Payload / meta keys that carry a client IP address. */
    private const IP_KEYS = ['ip', 'blockedClientIp'];

    private const HASH_PREFIX = 'sha256:';

    /** Hex characters kept from the digest: short enough to stay readable, wide enough to avoid collisions. */
    private const HASH_LENGTH = 12;

    /**
     * Context label for the token subkey. Keeps login tokens in their own key domain, so they cannot
     * be reproduced with Craft's generic data hashing (`Security::hashData()`, the Twig `|hash` filter,
     * `redirectInput()`), which HMACs with the security key directly.
     */
    private const SUBKEY_CONTEXT = 'fort:attempted-login:v1';

    /** Set once a request has already warned about the missing security key. */
    private static bool $missingKeyWarned = false;

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
     * without storing the identity. Keyed with an HMAC on a {@see self::SUBKEY_CONTEXT} subkey
     * derived from the site security key, so tokens cannot be pre-computed, compared across
     * sites, or reproduced through any other feature that hashes with the security key.
     *
     * Without a usable security key there is nothing to key the HMAC with, so the login is
     * replaced by {@see self::REDACTION_MARKER} rather than hashed; never returned as typed.
     */
    public static function hashLogin(string $login): string
    {
        $normalized = mb_strtolower(trim($login));
        if ($normalized === '') {
            return '';
        }

        $key = self::securityKey();
        if ($key === null) {
            self::warnMissingKeyOnce();

            return self::REDACTION_MARKER;
        }

        $subkey = hash_hmac('sha256', self::SUBKEY_CONTEXT, $key, true);

        return self::HASH_PREFIX . substr(hash_hmac('sha256', $normalized, $subkey), 0, self::HASH_LENGTH);
    }

    /**
     * Copy of $meta with the attempted login hashed (or marked redacted) and every IP-bearing
     * key masked. Other keys (including the Craft user IDs the CP relies on) are left untouched.
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
     * Site security key, or null when it is empty or unreadable (Craft not bootstrapped in
     * console edge cases), so reading it never throws.
     */
    private static function securityKey(): ?string
    {
        try {
            $key = Craft::$app !== null
                ? Craft::$app->getConfig()->getGeneral()->securityKey
                : null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Warn at most once per request that attempted logins are redacted instead of hashed.
     */
    private static function warnMissingKeyOnce(): void
    {
        if (self::$missingKeyWarned) {
            return;
        }

        // Only a call that can actually log counts as the one warning, so an earlier call made
        // before Craft was available does not swallow it.
        if (Craft::$app === null) {
            return;
        }

        self::$missingKeyWarned = true;

        try {
            Craft::warning(
                'Fort: no security key available to hash attempted logins; they are redacted instead.',
                __METHOD__
            );
        } catch (\Throwable) {
            // Redaction must not fail because logging did.
        }
    }
}
