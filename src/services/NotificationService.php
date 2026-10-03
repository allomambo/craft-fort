<?php

namespace allomambo\fort\services;

use allomambo\fort\helpers\AlertDisplayHelper;
use allomambo\fort\helpers\DigestScheduleHelper;
use allomambo\fort\helpers\IpHelper;
use allomambo\fort\helpers\PiiRedactor;
use allomambo\fort\models\Settings;
use allomambo\fort\Plugin;
use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\mail\Message;
use craft\web\View;
use DateTimeImmutable;
use DateTimeZone;
use craft\base\Component;

class NotificationService extends Component
{
    /** Resolved webroot favicon. Lives until Utilities → Caches clears it. */
    public const SITE_ICON_CACHE_KEY = 'fort:site-icon';

    private const PSEUDO_CRON_CACHE_KEY = 'fort:digest:throttle';

    private const PSEUDO_CRON_CACHE_TTL_SECONDS = 45;

    private const PSEUDO_CRON_MUTEX_NAME = 'fort-digest-pseudo-cron';

    private const SIGNIFICANT_EMAIL_THROTTLE_KEY = 'fort:sig-email-throttle';

    private const SIGNIFICANT_EMAIL_MAX_PER_HOUR = 20;

    private const SIGNIFICANT_EMAIL_THROTTLE_TTL = 3600;

    private const SIGNIFICANT_WEBHOOK_THROTTLE_KEY = 'fort:sig-webhook-throttle';

    private const SIGNIFICANT_WEBHOOK_MAX_PER_HOUR = 60;

    private const SIGNIFICANT_WEBHOOK_THROTTLE_TTL = 3600;

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

        $counts = $this->digestCounts('-24 hours');
        $dashboardUrl = UrlHelper::cpUrl('fort/dashboard');
        if ($this->dispatchToRecipients(fn() => $this->renderDigest('daily', $counts, $dashboardUrl))) {
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

        $counts = $this->digestCounts('-7 days');
        $dashboardUrl = UrlHelper::cpUrl('fort/dashboard');
        if ($this->dispatchToRecipients(fn() => $this->renderDigest('weekly', $counts, $dashboardUrl))) {
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
                    $counts = $this->digestCounts('-24 hours');
                    $dashboardUrl = UrlHelper::cpUrl('fort/dashboard');
                    if ($this->dispatchToRecipients(fn() => $this->renderDigest('daily', $counts, $dashboardUrl))) {
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
                    $counts = $this->digestCounts('-7 days');
                    $dashboardUrl = UrlHelper::cpUrl('fort/dashboard');
                    if ($this->dispatchToRecipients(fn() => $this->renderDigest('weekly', $counts, $dashboardUrl))) {
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

        // Single redaction point: everything below (alert row, webhook, email body and its JSON dump)
        // is derived from $payload, so there is no path left carrying the raw values.
        if ($settings->anonymizePii) {
            $payload = PiiRedactor::redactMeta($payload);
        }

        $clientIp = (string) ($payload['blockedClientIp'] ?? $payload['ip'] ?? '0.0.0.0');
        if ($settings->anonymizePii) {
            $clientIp = PiiRedactor::anonymizeIp($clientIp);
        }

        $requestPath = isset($payload['requestPath']) && $payload['requestPath'] !== null && $payload['requestPath'] !== ''
            ? (string) $payload['requestPath']
            : null;

        // Persist the alert row before any outbound email/webhook so a delivery failure does not lose the audit trail.
        $alertSaved = $plugin->alerts->record($eventType, $clientIp, $requestPath, $payload);
        if (!$alertSaved) {
            Craft::warning('Fort: could not persist alert before notifications; email/webhook may still be sent.', __METHOD__);
        }

        if ($settings->webhookOnSignificantEvent && $settings->webhookUrl !== '') {
            $cache = Craft::$app->getCache();
            $webhookThrottleCount = (int) $cache->get(self::SIGNIFICANT_WEBHOOK_THROTTLE_KEY);
            if ($webhookThrottleCount >= self::SIGNIFICANT_WEBHOOK_MAX_PER_HOUR) {
                Craft::warning("Fort: significant event webhook throttled ({$webhookThrottleCount} sent in the last hour).", __METHOD__);
            } else {
                $cache->set(self::SIGNIFICANT_WEBHOOK_THROTTLE_KEY, $webhookThrottleCount + 1, self::SIGNIFICANT_WEBHOOK_THROTTLE_TTL);
                $this->postWebhook([
                    'event' => $eventType,
                    'time' => gmdate('Y-m-d H:i:s'),
                    'payload' => $payload,
                ]);
            }
        }

        if (!$settings->significantEventEmailEnabled) {
            return;
        }

        $cache = Craft::$app->getCache();
        $throttleCount = (int) $cache->get(self::SIGNIFICANT_EMAIL_THROTTLE_KEY);
        if ($throttleCount >= self::SIGNIFICANT_EMAIL_MAX_PER_HOUR) {
            Craft::warning("Fort: significant event email throttled ({$throttleCount} sent in the last hour).", __METHOD__);
            return;
        }
        $cache->set(self::SIGNIFICANT_EMAIL_THROTTLE_KEY, $throttleCount + 1, self::SIGNIFICANT_EMAIL_THROTTLE_TTL);

        $alertsUrl = UrlHelper::cpUrl('fort/alerts');
        $this->dispatchToRecipients(fn() => $this->renderSignificantEvent($eventType, $payload, $alertsUrl));
    }

    /**
     * @param array{login_failure: int, http_rate_limited: int, ip_blocked: int} $counts
     * @return array{subject: string, text: string, html: string}
     */
    private function renderDigest(string $which, array $counts, string $dashboardUrl): array
    {
        $daily = $which === 'daily';
        $subject = Craft::t('fort', $daily ? '[Fort] {site}: Daily security digest' : '[Fort] {site}: Weekly security digest', [
            'site' => $this->siteIdentity()['label'],
        ]);
        $heading = $daily
            ? Craft::t('fort', 'Daily security digest')
            : Craft::t('fort', 'Weekly security digest');
        $intro = Craft::t('fort', $daily ? 'Summary for the last 24 hours.' : 'Summary for the last 7 days.');
        $stats = [
            ['label' => Craft::t('fort', 'Login failures'), 'value' => (string) $counts['login_failure']],
            ['label' => Craft::t('fort', 'HTTP rate limits'), 'value' => (string) $counts['http_rate_limited']],
            ['label' => Craft::t('fort', 'IPs blocked (events)'), 'value' => (string) $counts['ip_blocked']],
        ];
        $critical = ((int) $counts['login_failure'] + (int) $counts['http_rate_limited'] + (int) $counts['ip_blocked']) > 0;

        return $this->composeMessage($subject, [
            'heading' => $heading,
            'intro' => $intro,
            'critical' => $critical,
            'rows' => [],
            'stats' => $stats,
            'notes' => [],
            'detailsLabel' => null,
            'details' => null,
            'dateNote' => null,
            'actionUrl' => $dashboardUrl,
            'actionLabel' => Craft::t('fort', 'Open dashboard'),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{subject: string, text: string, html: string}
     */
    private function renderSignificantEvent(string $eventType, array $payload, string $alertsUrl): array
    {
        $label = AlertDisplayHelper::alertTypeLabel($eventType);
        $subject = Craft::t('fort', '[Fort] {site}: {event}', [
            'site' => $this->siteIdentity()['label'],
            'event' => $label,
        ]);
        $displayPayload = $payload;
        $notes = [];
        $intro = $this->eventSummary($eventType, $payload);

        if (!empty($payload['isPermanent'])) {
            $notes[] = Craft::t('fort', 'Permanent block (no automatic expiry).');
            unset($displayPayload['isPermanent']);
        }
        if (!empty($payload['blockApplyFailed'])) {
            $notes[] = Craft::t('fort', 'Note: automatic IP block could not be applied; check logs.');
            unset($displayPayload['blockApplyFailed']);
        }

        $emailPayload = $payload;
        unset($emailPayload['attemptedLogin']);
        $json = json_encode($emailPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $rows = AlertDisplayHelper::emailSummaryRows($eventType, $displayPayload);
        array_unshift($rows, [
            'key' => 'type',
            'label' => Craft::t('fort', 'Type'),
            'value' => $eventType,
            'code' => true,
        ]);

        return $this->composeMessage($subject, [
            'heading' => $label,
            'intro' => $intro,
            'critical' => true,
            'rows' => $rows,
            'stats' => [],
            'notes' => $notes,
            'detailsLabel' => Craft::t('fort', 'Technical details'),
            'details' => is_string($json) ? $json : '',
            'dateNote' => Craft::t('fort', 'Dates use the system timezone ({tz}).', ['tz' => Craft::$app->getTimeZone()]),
            'actionUrl' => $alertsUrl,
            'actionLabel' => Craft::t('fort', 'View alerts'),
        ]);
    }

    /**
     * Craft system name plus the primary site, so a maintainer with several Fort installs can tell them apart.
     *
     * @return array{name: string, host: ?string, url: ?string, label: string}
     */
    private function siteIdentity(): array
    {
        $name = (string) Craft::$app->getSystemName();
        $url = (string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : null;
        $label = $host !== null && strcasecmp($host, $name) !== 0 ? $name . ' · ' . $host : $name;

        return [
            'name' => $name,
            'host' => $host,
            'url' => $url !== '' ? $url : null,
            'label' => $label,
        ];
    }

    /**
     * One sentence above the button. The full field table comes after it.
     *
     * @param array<string, mixed> $payload
     */
    private function eventSummary(string $eventType, array $payload): ?string
    {
        if ($eventType === 'login_threshold' && isset($payload['failures'], $payload['windowMinutes'])) {
            return Craft::t('fort', '{failures} failed login attempts in {window} minutes.', [
                'failures' => $payload['failures'],
                'window' => $payload['windowMinutes'],
            ]);
        }

        if ($eventType === 'http_rate_limited' && isset($payload['count'], $payload['limit'])) {
            return Craft::t('fort', '{count} requests in one minute, past the limit of {limit}.', [
                'count' => $payload['count'],
                'limit' => $payload['limit'],
            ]);
        }

        return null;
    }

    /**
     * Square mark for the site row. Craft's uploaded CP icon wins; otherwise a favicon in the webroot.
     *
     * @return array{path: string, mime: string, name: string, temp: bool}|null
     */
    private function siteIconFile(): ?array
    {
        return $this->rebrandIconFile() ?? $this->webrootFaviconFile();
    }

    /**
     * @return array{path: string, mime: string, name: string, temp: bool}|null
     */
    private function rebrandIconFile(): ?array
    {
        $dir = Craft::$app->getPath()->getRebrandPath(false) . DIRECTORY_SEPARATOR . 'icon';
        if (!is_dir($dir)) {
            return null;
        }

        $mime = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!is_file($path) || !isset($mime[$ext])) {
                continue;
            }

            return [
                'path' => $path,
                'mime' => $mime[$ext],
                'name' => 'site-icon.' . ($ext === 'jpeg' ? 'jpg' : $ext),
                'temp' => false,
            ];
        }

        return null;
    }

    /**
     * Known filenames at the web root, then any favicon file under it. The path is cached until caches are cleared.
     *
     * @return array{path: string, mime: string, name: string, temp: bool}|null
     */
    private function webrootFaviconFile(): ?array
    {
        $cache = Craft::$app->getCache();
        $hit = $cache->get(self::SITE_ICON_CACHE_KEY);
        if ($hit === '') {
            return null;
        }
        if (is_string($hit)) {
            return is_file($hit) ? $this->iconFromPath($hit) : null;
        }

        $path = $this->findWebrootFavicon();
        $icon = $path !== null ? $this->iconFromPath($path) : null;
        $cache->set(self::SITE_ICON_CACHE_KEY, $icon !== null ? $path : '', 0);

        return $icon;
    }

    private function findWebrootFavicon(): ?string
    {
        $root = Craft::getAlias('@webroot');
        if (!is_string($root) || !is_dir($root)) {
            return null;
        }

        foreach (['favicon.ico', 'favicon.png', 'apple-touch-icon.png', 'apple-touch-icon-precomposed.png'] as $name) {
            $path = $root . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && filesize($path) <= 262144) {
                return $path;
            }
        }

        $skip = ['cpresources', 'node_modules', '.git'];
        $rank = ['png' => 0, 'webp' => 1, 'jpg' => 2, 'jpeg' => 2, 'gif' => 3, 'ico' => 4];
        $best = null;
        $bestScore = PHP_INT_MAX;
        $queue = [[$root, 0]];
        while ($queue !== []) {
            [$dir, $depth] = array_shift($queue);
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || in_array($name, $skip, true)) {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_dir($path)) {
                    if ($depth < 3) {
                        $queue[] = [$path, $depth + 1];
                    }
                    continue;
                }
                if (!is_file($path) || stripos($name, 'favicon') === false || filesize($path) > 262144) {
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!isset($rank[$ext])) {
                    continue;
                }
                $score = $depth * 10 + $rank[$ext];
                if ($score < $bestScore) {
                    $best = $path;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    /**
     * @return array{path: string, mime: string, name: string, temp: bool}|null
     */
    private function iconFromPath(string $path): ?array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        if (isset($mime[$ext])) {
            return [
                'path' => $path,
                'mime' => $mime[$ext],
                'name' => 'site-icon.' . ($ext === 'jpeg' ? 'jpg' : $ext),
                'temp' => false,
            ];
        }
        if ($ext !== 'ico') {
            return null;
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            return null;
        }
        $image = $this->asPng($bytes, 'image/x-icon');

        return $image === null ? null : $this->writeIconTemp($image['bytes'], $image['mime'], $image['ext']);
    }

    /**
     * @return array{bytes: string, mime: string, ext: string}|null
     */
    private function asPng(string $bytes, string $mime): ?array
    {
        if ($mime === 'image/png') {
            return ['bytes' => $bytes, 'mime' => 'image/png', 'ext' => 'png'];
        }
        if (!class_exists(\Imagick::class)) {
            return match ($mime) {
                'image/jpeg' => ['bytes' => $bytes, 'mime' => $mime, 'ext' => 'jpg'],
                'image/gif' => ['bytes' => $bytes, 'mime' => $mime, 'ext' => 'gif'],
                'image/webp' => ['bytes' => $bytes, 'mime' => $mime, 'ext' => 'webp'],
                default => null,
            };
        }

        try {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->setImageFormat('png');

            return ['bytes' => $image->getImageBlob(), 'mime' => 'image/png', 'ext' => 'png'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{path: string, mime: string, name: string, temp: bool}|null
     */
    private function writeIconTemp(string $bytes, string $mime, string $ext): ?array
    {
        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'fort-site-icon-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents($path, $bytes) === false) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => $mime,
            'name' => 'site-icon.' . $ext,
            'temp' => true,
        ];
    }

    /**
     * @param array<string, mixed> $view
     * @return array{subject: string, text: string, html: string, siteIcon: ?array}
     */
    private function composeMessage(string $subject, array $view): array
    {
        $site = $this->siteIdentity();
        $siteIcon = $this->siteIconFile();

        $view['language'] = Craft::$app->language;
        $view['siteName'] = $site['name'];
        $view['siteHost'] = $site['host'];
        $view['siteUrl'] = $site['url'];
        $view['iconSrc'] = '%%FORT_ICON%%';
        $view['siteIconSrc'] = $siteIcon !== null ? '%%FORT_SITE_ICON%%' : null;
        $view['preheader'] = $site['label'] . ' — ' . (string) ($view['heading'] ?? '');
        $view['footer'] = Craft::t('fort', 'You are receiving this because you are a Fort maintainer for {site}.', [
            'site' => $site['label'],
        ]);

        return [
            'subject' => $subject,
            'text' => $this->plainTextMessage($subject, $view),
            'html' => $this->renderHtmlMessage($view),
            'siteIcon' => $siteIcon,
        ];
    }

    /**
     * @param array<string, mixed> $view
     */
    private function plainTextMessage(string $subject, array $view): string
    {
        $lines = [$subject, '', 'Fort', '', (string) $view['siteName']];
        if (!empty($view['siteUrl'])) {
            $lines[] = (string) $view['siteUrl'];
        }
        $lines[] = '';
        $lines[] = (string) ($view['heading'] ?? '');
        if (!empty($view['intro'])) {
            $lines[] = (string) $view['intro'];
        }
        foreach ($view['notes'] ?? [] as $note) {
            $lines[] = $note;
        }
        $lines[] = '';
        $lines[] = $view['actionLabel'] . ': ' . $view['actionUrl'];
        $lines[] = '';
        foreach ($view['stats'] ?? [] as $stat) {
            $lines[] = $stat['label'] . ': ' . $stat['value'];
        }
        foreach ($view['rows'] ?? [] as $row) {
            $value = (string) $row['value'];
            if (!empty($row['code'])) {
                $value = '`' . $value . '`';
            }
            $lines[] = $row['label'] . ': ' . $value;
        }
        if (!empty($view['stats']) || !empty($view['rows'])) {
            $lines[] = '';
        }
        if (!empty($view['details'])) {
            $lines[] = (string) $view['detailsLabel'];
            $lines[] = (string) $view['details'];
            $lines[] = '';
        }
        if (!empty($view['dateNote'])) {
            $lines[] = (string) $view['dateNote'];
            $lines[] = '';
        }
        $lines[] = (string) $view['footer'];

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderHtmlMessage(array $variables): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $view->renderTemplate('fort/_emails/notification', $variables);
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    /**
     * @return array{login_failure: int, http_rate_limited: int, ip_blocked: int}
     */
    private function digestCounts(string $modify): array
    {
        $since = (new DateTimeImmutable())->modify($modify);

        return Plugin::getInstance()->securityEvents->countsSince($since);
    }

    /**
     * Resolve recipients and send one {@see Message} per user, rendered in that user's
     * Control Panel language. Returns true only when every send succeeded.
     *
     * @param callable(): array{subject: string, text: string, html: string, siteIcon?: ?array} $render
     */
    private function dispatchToRecipients(callable $render): bool
    {
        $plugin = Plugin::getInstance();
        /** @var Settings $settings */
        $settings = $plugin->getSettings();

        $users = $this->resolveNotificationRecipients($settings);

        if ($users === []) {
            Craft::warning('Fort: no email recipients for notification.', __METHOD__);

            return false;
        }

        $previousLanguage = Craft::$app->language;
        $allOk = true;
        foreach ($users as $user) {
            try {
                Craft::$app->language = $this->recipientLanguage($user);
                $mail = $render();
                $message = new Message();
                $message->setTo($user->email);
                $message->language = Craft::$app->language;
                $message->setSubject($mail['subject']);
                $message->setTextBody($mail['text']);
                $iconPath = dirname(__DIR__) . '/resources/fort-icon.png';
                if (is_file($iconPath)) {
                    $cid = $message->embed($iconPath, [
                        'fileName' => 'fort-icon.png',
                        'contentType' => 'image/png',
                    ]);
                    $mail['html'] = str_replace('%%FORT_ICON%%', $cid, $mail['html']);
                }
                $siteIcon = $mail['siteIcon'] ?? null;
                if (is_array($siteIcon) && is_file($siteIcon['path'])) {
                    $cid = $message->embed($siteIcon['path'], [
                        'fileName' => $siteIcon['name'],
                        'contentType' => $siteIcon['mime'],
                    ]);
                    $mail['html'] = str_replace('%%FORT_SITE_ICON%%', $cid, $mail['html']);
                    if (!empty($siteIcon['temp'])) {
                        @unlink($siteIcon['path']);
                    }
                }
                $message->setHtmlBody($mail['html']);
                $sent = Craft::$app->getMailer()->send($message);
                if (!$sent) {
                    $allOk = false;
                    Craft::warning('Fort: mailer send returned false for user ID ' . $user->id . ' (check email settings / transport).', __METHOD__);
                }
            } catch (\Throwable $e) {
                $allOk = false;
                Craft::warning('Fort: could not send email: ' . $e->getMessage(), __METHOD__);
            } finally {
                Craft::$app->language = $previousLanguage;
            }
        }

        return $allOk;
    }

    /**
     * CP language for this recipient: their preference, then defaultCpLanguage, then the primary site.
     * Independent of the request that triggered the send.
     */
    private function recipientLanguage(User $user): string
    {
        $preferred = $user->getPreferredLanguage();
        if (is_string($preferred) && $preferred !== '') {
            return $preferred;
        }

        $defaultCp = Craft::$app->getConfig()->getGeneral()->defaultCpLanguage;
        if (is_string($defaultCp) && $defaultCp !== '') {
            return $defaultCp;
        }

        return Craft::$app->getSites()->getPrimarySite()->language;
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
                'allow_redirects' => false,
            ]);
            $response = $client->post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($json, JSON_THROW_ON_ERROR),
                'allow_redirects' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                Craft::warning(
                    'Fort webhook refused: target ' . (string) $parts['host']
                    . ' attempted to redirect (status ' . $status . '); redirect not followed.',
                    __METHOD__
                );
            }
        } catch (\Throwable $e) {
            Craft::warning('Fort webhook failed: ' . $e->getMessage(), __METHOD__);
        }
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
