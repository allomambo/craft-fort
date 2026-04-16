<?php

namespace allomambo\fort\services;

use allomambo\fort\helpers\FortClientIp;
use allomambo\fort\helpers\IpHelper;
use allomambo\fort\Plugin;
use Craft;
use craft\helpers\Json;
use craft\base\Component;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

/**
 * HTTP rate limiter using a calendar-minute fixed bucket (not a rolling 60s window).
 * Bursts split across minute boundaries may briefly exceed the configured limit.
 */
class RateLimitService extends Component
{
    /** Cache TTL for per-minute request counter and per-minute debounce (seconds). */
    private const MINUTE_CACHE_TTL = 120;

    private const MINUTE_SECONDS = 60;

    /**
     * Enforce global per-IP HTTP rate limit (calendar-minute request bucket).
     */
    public function enforce(): void
    {
        $plugin = Plugin::getInstance();
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();

        if (!$settings->httpRateLimitEnabled) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($settings->excludeCpFromHttpRateLimit && $request->getIsCpRequest()) {
            return;
        }

        if ($settings->excludeCpResourcesFromHttpRateLimit) {
            $path = $request->getPathInfo();
            if ($path !== '' && str_starts_with($path, 'cpresources/')) {
                return;
            }
        }

        $ip = $plugin->ipBlocks->normalizeClientIp(FortClientIp::getEffective($request) ?: '0.0.0.0');
        $isExcluded = IpHelper::matchesExcluded($ip, $settings->excludedIps);

        $minuteBucket = (int) floor(time() / self::MINUTE_SECONDS);
        $cache = Craft::$app->getCache();
        $key = 'fort:ratelimit:counter:' . md5($ip . ':' . $minuteBucket);

        // Calendar-minute bucket counter. The get→increment→set pattern is not atomic;
        // under high concurrency the count may drift slightly. This is an accepted
        // trade-off for cache-backend portability (not all backends support INCR).
        $count = (int) $cache->get($key);
        $count++;
        $cache->set($key, $count, self::MINUTE_CACHE_TTL);

        $effectiveLimit = $plugin->runtimeSettings->getMaxRequestsPerIpPerMinute($settings);

        if ($count <= $effectiveLimit) {
            return;
        }

        $sigKey = 'fort:ratelimit:sig:' . md5($ip . ':' . $minuteBucket);
        if ($cache->add($sigKey, 1, self::MINUTE_CACHE_TTL)) {
            $pathInfo = $request->getPathInfo() ?: null;

            $meta = array_filter([
                'count' => $count,
                'limit' => $effectiveLimit,
            ], static fn($v) => $v !== null && $v !== '');

            try {
                $plugin->securityEvents->record('http_rate_limited', $ip, $pathInfo, $meta);
            } catch (\Throwable $e) {
                Craft::warning('Fort: could not record HTTP rate limit security event: ' . $e->getMessage(), __METHOD__);
            }

            if (!$isExcluded) {
                $alertsBeforeBlock = $plugin->runtimeSettings->getHttpRateLimitAlertsBeforeBlock($settings);
                $alertWindowMinutes = $plugin->runtimeSettings->getHttpRateLimitAlertWindowMinutes($settings);
                $blockDurationMinutes = $plugin->runtimeSettings->getDefaultBlockDurationMinutes();

                [$alertsInWindow, $shouldApplyBlock] = $this->registerAlertInSlidingWindow(
                    $cache,
                    $ip,
                    $alertWindowMinutes,
                    $alertsBeforeBlock
                );

                $payload = array_filter([
                    'ip' => $ip,
                    'blockedClientIp' => $ip,
                    'count' => $count,
                    'limit' => $effectiveLimit,
                    'requestPath' => $pathInfo,
                    'alertsInWindow' => $alertsInWindow,
                    'alertsBeforeBlock' => $alertsBeforeBlock,
                    'alertWindowMinutes' => $alertWindowMinutes,
                    'blockDurationMinutes' => $blockDurationMinutes,
                    'automaticBlockPending' => $shouldApplyBlock,
                ], static fn($v) => $v !== null && $v !== '');

                try {
                    $plugin->notifications->notifySignificant('http_rate_limited', $payload);
                } catch (\Throwable $e) {
                    Craft::warning('Fort: could not notify HTTP rate limit event: ' . $e->getMessage(), __METHOD__);
                }

                if ($shouldApplyBlock) {
                    $blockedRecord = null;
                    try {
                        $blockedRecord = $plugin->ipBlocks->applyAutomaticBlock($ip, IpBlockService::REASON_HTTP_RATE);
                    } catch (\Throwable $e) {
                        Craft::warning('Fort: could not apply automatic HTTP rate IP block: ' . $e->getMessage(), __METHOD__);
                    }

                    if ($blockedRecord !== null) {
                        $this->clearSlidingWindowForIp($cache, $ip);
                    }
                }
            }
        }

        if ($isExcluded) {
            return;
        }

        $retryAfter = self::MINUTE_SECONDS - (time() % self::MINUTE_SECONDS);
        if ($retryAfter < 1) {
            $retryAfter = self::MINUTE_SECONDS;
        }

        Craft::$app->getResponse()->format = Response::FORMAT_RAW;
        Craft::$app->getResponse()->headers->set('Retry-After', (string) $retryAfter);
        throw new TooManyRequestsHttpException(Craft::t('fort', 'Too many requests.'));
    }

    /**
     * Append one alert timestamp and return [count in window, whether automatic block threshold is met].
     *
     * @return array{0: int, 1: bool}
     */
    private function registerAlertInSlidingWindow(
        \yii\caching\CacheInterface $cache,
        string $ip,
        int $alertWindowMinutes,
        int $alertsBeforeBlock
    ): array {
        $alertWindowMinutes = max(1, $alertWindowMinutes);
        $alertsBeforeBlock = max(1, $alertsBeforeBlock);

        $now = time();
        $cutoff = $now - $alertWindowMinutes * self::MINUTE_SECONDS;
        $rwKey = 'fort:ratelimit:window:' . md5($ip);

        $raw = $cache->get($rwKey);
        $timestamps = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = Json::decode($raw, true);
                if (is_array($decoded)) {
                    $timestamps = $decoded;
                }
            } catch (\Throwable) {
                $timestamps = [];
            }
        }

        $timestamps = array_values(array_filter($timestamps, static function ($ts) use ($cutoff) {
            return is_numeric($ts) && (int) $ts >= $cutoff;
        }));

        $timestamps[] = $now;
        $count = count($timestamps);

        $ttl = min(86400, $alertWindowMinutes * self::MINUTE_SECONDS + 300);
        $cache->set($rwKey, Json::encode($timestamps), $ttl);

        return [$count, $count >= $alertsBeforeBlock];
    }

    private function clearSlidingWindowForIp(\yii\caching\CacheInterface $cache, string $ip): void
    {
        $cache->delete('fort:ratelimit:window:' . md5($ip));
    }
}
