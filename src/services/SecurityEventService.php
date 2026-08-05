<?php

namespace allomambo\fort\services;

use allomambo\fort\helpers\IpHelper;
use allomambo\fort\helpers\PiiRedactor;
use allomambo\fort\Plugin;
use allomambo\fort\records\BlockedIpRecord;
use allomambo\fort\records\SecurityEventRecord;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTimeZone;
use craft\base\Component;

class SecurityEventService extends Component
{
    /**
     * @param array<string, mixed> $meta
     */
    public function record(string $type, string $clientIp, ?string $requestPath, array $meta = []): void
    {
        if ($type === 'login_success') {
            return;
        }

        // Only site (non-CP) requests: the logged-in user is the one who provoked the event. CP actions (e.g. manual IP
        // block) must not attribute the current admin session as "triggering user".
        $req = Craft::$app->getRequest();
        if (!$req->getIsConsoleRequest() && !$req->getIsCpRequest()) {
            $identity = Craft::$app->getUser()->getIdentity();
            if ($identity !== null && !isset($meta['triggeringUserId'])) {
                $meta['triggeringUserId'] = $identity->id;
            }
        }

        /** @var \allomambo\fort\models\Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        if ($settings->anonymizePii) {
            $clientIp = PiiRedactor::anonymizeIp($clientIp);
            $meta = PiiRedactor::redactMeta($meta);
        }

        $record = new SecurityEventRecord();
        $record->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $record->type = StringHelper::truncate($type, 64);
        $record->clientIp = StringHelper::truncate($clientIp, 45);
        $record->requestPath = $requestPath !== null ? StringHelper::truncate($requestPath, 1024) : null;
        $record->meta = $meta !== [] ? Json::encode($meta) : null;

        if (!$record->save(false)) {
            Craft::warning('Fort: failed to save security event', __METHOD__);
        }
    }

    /**
     * Record login failure and evaluate failed-login threshold (success is never stored).
     *
     * @param array<string, mixed> $context
     */
    public function recordLoginAttempt(bool $success, string $clientIp, ?string $requestPath, array $context = []): void
    {
        if ($success) {
            return;
        }

        $plugin = Plugin::getInstance();
        $clientIp = $plugin->ipBlocks->normalizeClientIp($clientIp);

        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();

        if (!$settings->authLoggingEnabled) {
            return;
        }

        $this->record('login_failure', $clientIp, $requestPath, $context);

        if (IpHelper::matchesExcluded($clientIp, $settings->excludedIps)) {
            return;
        }

        $windowMinutes = $plugin->runtimeSettings->getFailedLoginWindowMinutes($settings);
        $threshold = $plugin->runtimeSettings->getFailedLoginThreshold($settings);

        // Sliding window is evaluated in the Craft system timezone; DB values are stored/compared as UTC (same as prepareForDb on save).
        $systemTz = new DateTimeZone(Craft::$app->getTimeZone());
        $cutoff = DateTimeHelper::now($systemTz);
        $cutoff->modify('-' . $windowMinutes . ' minutes');
        $since = Db::prepareDateForDb($cutoff);
        if ($since === null) {
            Craft::warning('Fort: could not compute login failure window cutoff.', __METHOD__);

            return;
        }

        // Count against the same string the rows were written with: with anonymization on, stored
        // clientIp values are masked, so the window is evaluated per masked range instead of per host.
        $lookupIp = $settings->anonymizePii ? PiiRedactor::anonymizeIp($clientIp) : $clientIp;
        $ipVariants = IpHelper::equivalentClientIpStrings($lookupIp);

        $failures = (int) SecurityEventRecord::find()
            ->where([
                'type' => 'login_failure',
                'clientIp' => $ipVariants,
            ])
            ->andWhere(['>=', 'dateCreated', $since])
            ->count();

        $threshold = (int) $threshold;
        if ($threshold < 1) {
            $threshold = 1;
        }

        if ($failures < $threshold) {
            return;
        }

        // Debounce duplicate *notifications* per IP per window slot. Key includes a time bucket so a stale key from an
        // older test cannot suppress emails on a new threshold crossing within the same TTL.
        $windowSeconds = max(60, $windowMinutes * 60);
        $debounceSlot = (int) floor(time() / $windowSeconds);
        $flagKey = 'fort:login:debounce:' . md5($clientIp . '|' . $windowMinutes . '|' . $debounceSlot);
        $cache = Craft::$app->getCache();
        // Yii cache returns false for a missing key (not null).
        $debounced = $cache->get($flagKey) !== false;

        if ($debounced && $plugin->ipBlocks->isCurrentlyBlocked($clientIp)) {
            return;
        }

        if ($debounced && !$plugin->ipBlocks->isCurrentlyBlocked($clientIp)) {
            try {
                $retryBlock = $plugin->ipBlocks->applyAutomaticBlock($clientIp, IpBlockService::REASON_LOGIN_THRESHOLD);
                if ($retryBlock !== null) {
                    $payload = $this->loginThresholdPayload(
                        $clientIp,
                        $failures,
                        $windowMinutes,
                        $requestPath,
                        $context,
                        $retryBlock
                    );
                    $plugin->notifications->notifySignificant('login_threshold', $payload);
                    $cache->set($flagKey, 1, $windowSeconds);
                }
            } catch (\Throwable $e) {
                Craft::warning('Fort: debounce retry failed: ' . $e->getMessage(), __METHOD__);
            }

            return;
        }

        $thresholdMeta = array_filter([
            'failures' => $failures,
            'attemptedLogin' => $context['attemptedLogin'] ?? null,
            'authError' => $context['authError'] ?? null,
            'userId' => $context['userId'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');

        try {
            $this->record('login_threshold', $clientIp, $requestPath, $thresholdMeta);

            $blockedRecord = null;
            try {
                $blockedRecord = $plugin->ipBlocks->applyAutomaticBlock($clientIp, IpBlockService::REASON_LOGIN_THRESHOLD);
            } catch (\Throwable $e) {
                Craft::warning('Fort: could not apply login IP block: ' . $e->getMessage(), __METHOD__);
            }

            $payload = $this->loginThresholdPayload(
                $clientIp,
                $failures,
                $windowMinutes,
                $requestPath,
                $context,
                $blockedRecord
            );

            $plugin->notifications->notifySignificant('login_threshold', $payload);

            $cache->set($flagKey, 1, $windowSeconds);
        } catch (\Throwable $e) {
            Craft::warning('Fort: login threshold pipeline failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function loginThresholdPayload(
        string $clientIp,
        int $failures,
        int $windowMinutes,
        ?string $requestPath,
        array $context,
        ?BlockedIpRecord $blockedRecord
    ): array {
        $plugin = Plugin::getInstance();
        $payload = array_filter([
            'ip' => $clientIp,
            'blockedClientIp' => $clientIp,
            'failures' => $failures,
            'windowMinutes' => $windowMinutes,
            'requestPath' => $requestPath,
            'attemptedLogin' => $context['attemptedLogin'] ?? null,
            'authError' => $context['authError'] ?? null,
            'userId' => $context['userId'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');

        if ($blockedRecord !== null) {
            $payload = array_merge($payload, $plugin->ipBlocks->payloadFromBlockedRecord($blockedRecord));
        } else {
            $payload['blockApplyFailed'] = true;
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentEvents(int $limit = 100): array
    {
        return SecurityEventRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->asArray()
            ->all();
    }

    public function pruneOlderThanDays(int $days): int
    {
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $cutoff = DateTimeHelper::now($tz);
        $cutoff->modify('-' . $days . ' days');
        $cutoffDb = Db::prepareDateForDb($cutoff);
        if ($cutoffDb === null) {
            return 0;
        }

        return SecurityEventRecord::deleteAll(['<', 'dateCreated', $cutoffDb]);
    }

    /**
     * Delete all security events (audit log). Does not touch blocked IP rows.
     */
    public function deleteAllEvents(): int
    {
        return SecurityEventRecord::deleteAll();
    }

    /**
     * @return array<string, list<array{date: string, count: int}>>
     *   Keys: login_failure, http_rate_limited, ip_blocked
     *   Each value: array of $days entries (one per day, oldest first), zero-filled for missing days.
     */
    public function dailyCounts(int $days = 30): array
    {
        $types = ['login_failure', 'http_rate_limited', 'ip_blocked'];
        $today = new \DateTimeImmutable('today midnight', new DateTimeZone('UTC'));
        $cutoff = $today->modify("-{$days} days");
        $cutoffDb = Db::prepareDateForDb($cutoff);

        // Build zero-filled template keyed by date string
        $template = [];
        for ($i = 0; $i < $days; $i++) {
            $template[$cutoff->modify("+{$i} days")->format('Y-m-d')] = 0;
        }

        $buckets = [];
        foreach ($types as $t) {
            $buckets[$t] = $template;
        }

        if ($cutoffDb !== null) {
            $rows = Craft::$app->getDb()->createCommand(
                'SELECT DATE([[dateCreated]]) AS [[day]], [[type]], COUNT(*) AS [[cnt]] FROM {{%fort_security_events}} WHERE [[dateCreated]] >= :cutoff GROUP BY [[day]], [[type]] ORDER BY [[day]] ASC',
                [':cutoff' => $cutoffDb]
            )->queryAll();

            foreach ($rows as $row) {
                $type = $row['type'];
                $day = $row['day'];
                if (isset($buckets[$type]) && array_key_exists($day, $buckets[$type])) {
                    $buckets[$type][$day] = (int) $row['cnt'];
                }
            }
        }

        $result = [];
        foreach ($types as $t) {
            $result[$t] = [];
            foreach ($buckets[$t] as $date => $count) {
                $result[$t][] = ['date' => $date, 'count' => $count];
            }
        }

        return $result;
    }

    /**
     * @return array{login_failure:int, http_rate_limited:int, ip_blocked:int}
     */
    public function countsSince(\DateTimeInterface $since): array
    {
        $sinceDb = Db::prepareDateForDb($since);
        if ($sinceDb === null) {
            return [
                'login_failure' => 0,
                'http_rate_limited' => 0,
                'ip_blocked' => 0,
            ];
        }

        return [
            'login_failure' => (int) SecurityEventRecord::find()
                ->where(['>=', 'dateCreated', $sinceDb])
                ->andWhere(['type' => 'login_failure'])
                ->count(),
            'http_rate_limited' => (int) SecurityEventRecord::find()
                ->where(['>=', 'dateCreated', $sinceDb])
                ->andWhere(['type' => 'http_rate_limited'])
                ->count(),
            'ip_blocked' => (int) SecurityEventRecord::find()
                ->where(['>=', 'dateCreated', $sinceDb])
                ->andWhere(['type' => 'ip_blocked'])
                ->count(),
        ];
    }
}
