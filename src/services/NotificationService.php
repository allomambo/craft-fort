<?php

namespace allomambo\fort\services;

use allomambo\fort\helpers\AlertDisplayHelper;
use allomambo\fort\helpers\DigestScheduleHelper;
use allomambo\fort\helpers\IpHelper;
use allomambo\fort\models\Settings;
use allomambo\fort\Plugin;
use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use DateTimeImmutable;
use DateTimeZone;
use craft\base\Component;

class NotificationService extends Component
{
    private const PSEUDO_CRON_CACHE_KEY = 'fort:digest:throttle';

    private const PSEUDO_CRON_CACHE_TTL_SECONDS = 45;

    private const PSEUDO_CRON_MUTEX_NAME = 'fort-digest-pseudo-cron';

    private const SIGNIFICANT_EMAIL_THROTTLE_KEY = 'fort:sig-email-throttle';

    private const SIGNIFICANT_EMAIL_MAX_PER_HOUR = 20;

    private const SIGNIFICANT_EMAIL_THROTTLE_TTL = 3600;

    /**
     * Console / server cron: send daily digest when enabled (ignores hour schedule).
     */
    public function sendDailyDigestForced(): void
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        if (!$settings->dailyDigestEmailEnabled) {
            return;
        }

        [$subject, $body] = $this->buildDailyDigestMail();
        if ($this->queueDigestEmails($subject, $body)) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $plugin->runtimeSettings->setLastDailyDigestSentAtUtc($now);
        }
    }

    /**
     * Console / server cron: send weekly digest when enabled (ignores day/hour schedule).
     */
    public function sendWeeklyDigestForced(): void
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        if (!$settings->weeklyDigestEmailEnabled) {
            return;
        }

        [$subject, $body] = $this->buildWeeklyDigestMail();
        if ($this->queueDigestEmails($subject, $body)) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $plugin->runtimeSettings->setLastWeeklyDigestSentAtUtc($now);
        }
    }

    /**
     * Web pseudo-cron: throttled + mutex; sends only when the digest is due per settings and runtime.
     */
    public function maybeSendDigestsIfDue(): void
    {
        $cache = Craft::$app->getCache();
        if ($cache->get(self::PSEUDO_CRON_CACHE_KEY) !== false) {
            return;
        }
        $cache->set(self::PSEUDO_CRON_CACHE_KEY, '1', self::PSEUDO_CRON_CACHE_TTL_SECONDS);

        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::PSEUDO_CRON_MUTEX_NAME, 60)) {
            return;
        }

        try {
            $plugin = Plugin::getInstance();
            /** @var Settings $settings */
            $settings = $plugin->getSettings();
            $tz = Craft::$app->getTimeZone();
            $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $runtime = $plugin->runtimeSettings;

            if ($settings->dailyDigestEmailEnabled) {
                $last = $runtime->getLastDailyDigestSentAtUtc();
                if (DigestScheduleHelper::isDailyDigestDue($last, $nowUtc, $tz, $settings->dailyDigestHour)) {
                    [$subject, $body] = $this->buildDailyDigestMail();
                    if ($this->queueDigestEmails($subject, $body)) {
                        $runtime->setLastDailyDigestSentAtUtc($nowUtc);
                    }
                }
            }

            if ($settings->weeklyDigestEmailEnabled) {
                $last = $runtime->getLastWeeklyDigestSentAtUtc();
                if (DigestScheduleHelper::isWeeklyDigestDue(
                    $last,
                    $nowUtc,
                    $tz,
                    $settings->dailyDigestHour,
                    $settings->weeklyDigestDayOfWeek,
                )) {
                    [$subject, $body] = $this->buildWeeklyDigestMail();
                    if ($this->queueDigestEmails($subject, $body)) {
                        $runtime->setLastWeeklyDigestSentAtUtc($nowUtc);
                    }
                }
            }
        } finally {
            $mutex->release(self::PSEUDO_CRON_MUTEX_NAME);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function notifySignificant(string $eventType, array $payload): void
    {
        $plugin = Plugin::getInstance();
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();

        $req = Craft::$app->getRequest();
        if (!$req->getIsConsoleRequest() && !$req->getIsCpRequest()) {
            $identity = Craft::$app->getUser()->getIdentity();
            if ($identity !== null && !isset($payload['triggeringUserId'])) {
                $payload['triggeringUserId'] = $identity->id;
            }
        }

        $clientIp = (string) ($payload['blockedClientIp'] ?? $payload['ip'] ?? '0.0.0.0');
        $requestPath = isset($payload['requestPath']) && $payload['requestPath'] !== null && $payload['requestPath'] !== ''
            ? (string) $payload['requestPath']
            : null;

        // Persist the alert row before any outbound email/webhook so a delivery failure does not lose the audit trail.
        $alertSaved = $plugin->alerts->record($eventType, $clientIp, $requestPath, $payload);
        if (!$alertSaved) {
            Craft::warning('Fort: could not persist alert before notifications; email/webhook may still be sent.', __METHOD__);
        }

        if ($settings->webhookOnSignificantEvent && $settings->webhookUrl !== '') {
            $this->postWebhook([
                'event' => $eventType,
                'time' => gmdate('Y-m-d H:i:s'),
                'payload' => $payload,
            ]);
        }

        if (!$settings->significantEventEmailEnabled) {
            return;
        }

        $subject = Craft::t('fort', '[Fort] Significant event: {type}', ['type' => $eventType]);
        $body = $this->significantEventTextBody($eventType, $payload);

        $cache = Craft::$app->getCache();
        $throttleCount = (int) $cache->get(self::SIGNIFICANT_EMAIL_THROTTLE_KEY);
        if ($throttleCount >= self::SIGNIFICANT_EMAIL_MAX_PER_HOUR) {
            Craft::warning("Fort: significant event email throttled ({$throttleCount} sent in the last hour).", __METHOD__);
            return;
        }
        $cache->set(self::SIGNIFICANT_EMAIL_THROTTLE_KEY, $throttleCount + 1, self::SIGNIFICANT_EMAIL_THROTTLE_TTL);

        $this->queueEmails($subject, $body);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function significantEventTextBody(string $eventType, array $payload): string
    {
        $lines = [];
        $lines[] = Craft::t('fort', 'Event: {type}', ['type' => $eventType]);
        $lines[] = '';

        $blockedIp = $payload['blockedClientIp'] ?? $payload['ip'] ?? null;
        if ($blockedIp) {
            $lines[] = Craft::t('fort', 'Blocked IP: {ip}', ['ip' => $blockedIp]);
        }

        if (!empty($payload['isPermanent'])) {
            $lines[] = Craft::t('fort', 'Permanent block (no automatic expiry).');
        } elseif (!empty($payload['blockedUntil'])) {
            $lines[] = Craft::t('fort', 'Block until: {time}', ['time' => (string) $payload['blockedUntil']]);
        }

        if (!empty($payload['blockReason'])) {
            $lines[] = Craft::t('fort', 'Block reason: {reason}', ['reason' => AlertDisplayHelper::blockReasonValue((string) $payload['blockReason'])]);
        }

        if ($eventType === 'login_threshold' && !empty($payload['attemptedLogin'])) {
            $lines[] = Craft::t('fort', 'Attempted login: {login}', ['login' => (string) $payload['attemptedLogin']]);
        }

        if (isset($payload['failures'], $payload['windowMinutes'])) {
            $lines[] = Craft::t('fort', 'Failed attempts (window): {n} in {m} minutes', [
                'n' => $payload['failures'],
                'm' => $payload['windowMinutes'],
            ]);
        }

        if (isset($payload['count'], $payload['limit'])) {
            $lines[] = Craft::t('fort', 'Request rate: {count} (limit {limit} per minute)', [
                'count' => $payload['count'],
                'limit' => $payload['limit'],
            ]);
        }

        if ($eventType === 'http_rate_limited') {
            if (isset($payload['alertsInWindow'], $payload['alertsBeforeBlock'], $payload['alertWindowMinutes'])) {
                $lines[] = Craft::t('fort', 'Rate-limit alerts in rolling window: {current} of {needed} within {y} minutes', [
                    'current' => $payload['alertsInWindow'],
                    'needed' => $payload['alertsBeforeBlock'],
                    'y' => $payload['alertWindowMinutes'],
                ]);
            }
            if (!empty($payload['blockDurationMinutes'])) {
                $lines[] = Craft::t('fort', 'Automatic block duration when applied (minutes): {z}', [
                    'z' => $payload['blockDurationMinutes'],
                ]);
            }
            if (array_key_exists('automaticBlockPending', $payload)) {
                $lines[] = Craft::t('fort', 'Automatic IP block applied this event: {yes}', [
                    'yes' => !empty($payload['automaticBlockPending']) ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
                ]);
            }
        }

        if (!empty($payload['requestPath'])) {
            $lines[] = Craft::t('fort', 'Path: {path}', ['path' => (string) $payload['requestPath']]);
        }

        if (!empty($payload['blockApplyFailed'])) {
            $lines[] = Craft::t('fort', 'Note: automatic IP block could not be applied; check logs.');
        }

        $lines[] = '';
        $lines[] = Craft::t('fort', 'Full details (JSON):');
        $lines[] = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", $lines);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildDailyDigestMail(): array
    {
        $plugin = Plugin::getInstance();
        $since = (new \DateTimeImmutable())->modify('-24 hours');
        $counts = $plugin->securityEvents->countsSince($since);
        $dashboardUrl = UrlHelper::cpUrl('fort/dashboard');

        $subject = Craft::t('fort', '[Fort] Daily security digest');
        $body = Craft::t('fort', 'Summary (last 24 hours):' . "\n\n" .
            'Login failures: {lf}' . "\n" .
            'HTTP rate limits: {hr}' . "\n" .
            'IPs blocked (events): {ib}' . "\n\n" .
            'Dashboard: {url}', [
            'lf' => $counts['login_failure'],
            'hr' => $counts['http_rate_limited'],
            'ib' => $counts['ip_blocked'],
            'url' => $dashboardUrl,
        ]);

        return [$subject, $body];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildWeeklyDigestMail(): array
    {
        $plugin = Plugin::getInstance();
        $since = (new \DateTimeImmutable())->modify('-7 days');
        $counts = $plugin->securityEvents->countsSince($since);
        $dashboardUrl = UrlHelper::cpUrl('fort/dashboard');

        $subject = Craft::t('fort', '[Fort] Weekly security digest');
        $body = Craft::t('fort', 'Summary (last 7 days):' . "\n\n" .
            'Login failures: {lf}' . "\n" .
            'HTTP rate limits: {hr}' . "\n" .
            'IPs blocked (events): {ib}' . "\n\n" .
            'Dashboard: {url}', [
            'lf' => $counts['login_failure'],
            'hr' => $counts['http_rate_limited'],
            'ib' => $counts['ip_blocked'],
            'url' => $dashboardUrl,
        ]);

        return [$subject, $body];
    }

    /**
     * Thin wrapper: digest sends need a boolean return to drive the runtime lastDigestSentAt write.
     */
    private function queueDigestEmails(string $subject, string $textBody): bool
    {
        return $this->dispatchToRecipients($subject, $textBody);
    }

    /**
     * Resolve recipients and send one {@see Message} per user. Returns true only when every send succeeded.
     * Shared by significant-event emails and digest emails so a fix to one code path cannot drift from the other.
     */
    private function dispatchToRecipients(string $subject, string $textBody): bool
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $users = $this->resolveNotificationRecipients($settings);

        if ($users === []) {
            Craft::warning('Fort: no email recipients for notification.', __METHOD__);

            return false;
        }

        $allOk = true;
        foreach ($users as $user) {
            try {
                $message = new Message();
                $message->setTo($user->email);
                $message->setSubject($subject);
                $message->setTextBody($textBody);
                $sent = Craft::$app->getMailer()->send($message);
                if (!$sent) {
                    $allOk = false;
                    Craft::warning('Fort: mailer send returned false for user ID ' . $user->id . ' (check email settings / transport).', __METHOD__);
                }
            } catch (\Throwable $e) {
                $allOk = false;
                Craft::warning('Fort: could not send email: ' . $e->getMessage(), __METHOD__);
            }
        }

        return $allOk;
    }

    /**
     * Post the alert JSON to the configured HTTPS webhook, with save-time + send-time SSRF hardening.
     *
     * DNS-rebinding TOCTOU note: there is a window between our {@see IpHelper::hostnameResolvesToPublicOnly()}
     * check and Guzzle's own resolution in which the same host could flip from public to private. Closing that
     * gap fully would require a custom resolver that forces Guzzle to connect to the exact IP we validated.
     * This is an accepted tradeoff because Fort webhooks carry alert JSON only — no session cookies, no bearer
     * tokens, no credentials — so the worst-case rebind leaks an already-public alert payload to an internal host.
     *
     * @param array<string, mixed> $json
     */
    private function postWebhook(array $json): void
    {
        $plugin = Plugin::getInstance();
        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();
        $url = $settings->webhookUrl;
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            Craft::warning('Fort webhook blocked: could not parse URL host.', __METHOD__);
            return;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            Craft::warning('Fort webhook blocked: URL contains credentials.', __METHOD__);
            return;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            Craft::warning('Fort webhook blocked: non-default HTTPS port ' . (int) $parts['port'] . '.', __METHOD__);
            return;
        }

        $resolved = [];
        try {
            $public = IpHelper::hostnameResolvesToPublicOnly((string) $parts['host'], $resolved);
        } catch (\Throwable $e) {
            Craft::warning('Fort webhook blocked: host resolution failed: ' . $e->getMessage(), __METHOD__);
            return;
        }
        if (!$public) {
            Craft::warning(
                'Fort webhook blocked: host ' . (string) $parts['host']
                . ' resolved to private/reserved address(es): '
                . implode(',', $resolved ?? []),
                __METHOD__
            );
            return;
        }

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 5,
                'connect_timeout' => 3,
            ]);
            $client->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($json, JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable $e) {
            Craft::warning('Fort webhook failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Thin wrapper: significant-event notifications do not need the aggregated boolean result.
     */
    private function queueEmails(string $subject, string $textBody): void
    {
        $this->dispatchToRecipients($subject, $textBody);
    }

    /**
     * @return User[]
     */
    private function resolveNotificationRecipients(Settings $settings): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $settings->maintainerUserIds))));

        if ($ids === []) {
            return User::find()->admin()->status(User::STATUS_ACTIVE)->all();
        }

        $users = [];
        foreach ($ids as $userId) {
            $user = User::find()->id($userId)->status(null)->one();
            if ($user && $user->email) {
                $users[] = $user;
            }
        }

        if ($users === []) {
            Craft::warning(
                'Fort: maintainer user IDs did not resolve to any users with an email; falling back to all active admins.',
                __METHOD__
            );

            return User::find()->admin()->status(User::STATUS_ACTIVE)->all();
        }

        return $users;
    }
}
