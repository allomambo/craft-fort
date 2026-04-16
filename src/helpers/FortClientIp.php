<?php

namespace allomambo\fort\helpers;

use Craft;
use craft\web\Request;

/**
 * Optional dev-only remap of the client IP for Fort enforcement (see config/fort.php devIpSpoof).
 *
 * Use when you need to hammer the public API or site login without locking yourself out of the CP:
 * map your real LAN IP to a throwaway IP for Fort; CP requests can keep the real IP via excludeCp.
 */
final class FortClientIp
{
    private static bool $nullIpWarned = false;

    /**
     * IP string Fort should use for blocks, rate limits, and security events (not for CP dashboard "your IP" hints).
     */
    public static function getEffective(?Request $request = null): string
    {
        $request ??= Craft::$app->getRequest();
        $raw = $request->getUserIP();
        if ($raw === null || $raw === '') {
            self::warnNullIpOnce();
            $raw = '0.0.0.0';
        }

        $config = Craft::$app->getConfig()->getConfigFromFile('fort');
        $spoof = is_array($config) ? ($config['devIpSpoof'] ?? null) : null;
        if (!is_array($spoof) || empty($spoof['enabled'])) {
            return $raw;
        }

        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            return $raw;
        }

        if (!empty($spoof['excludeCp']) && $request->getIsCpRequest()) {
            return $raw;
        }

        $from = trim((string) ($spoof['from'] ?? ''));
        $to = trim((string) ($spoof['to'] ?? ''));
        if ($from === '' || $to === '') {
            return $raw;
        }

        $rawCanon = IpHelper::canonicalIp($raw);
        $fromCanon = IpHelper::canonicalIp($from);
        if (strcasecmp($rawCanon, $fromCanon) === 0) {
            return $to;
        }

        return $raw;
    }

    /**
     * Emit a single warning per PHP request (and at most once per 5 minutes across requests)
     * when getUserIP() returns null/empty — usually a sign of bad trustedHosts / proxy configuration.
     */
    private static function warnNullIpOnce(): void
    {
        if (self::$nullIpWarned) {
            return;
        }
        self::$nullIpWarned = true;

        try {
            $cache = Craft::$app->getCache();
            $key = 'fort:null-ip-warn';
            if ($cache->get($key) === false) {
                Craft::warning(
                    'Fort: getUserIP() returned null/empty; falling back to 0.0.0.0. Check trustedHosts / proxy config.',
                    'fort'
                );
                $cache->set($key, 1, 300);
            }
        } catch (\Throwable) {
            // If the cache layer itself is broken, swallow — we do not want IP resolution to throw.
        }
    }
}
