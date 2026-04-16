<?php

namespace allomambo\fort\services;

use allomambo\fort\helpers\AlertDisplayHelper;
use allomambo\fort\helpers\FortClientIp;
use allomambo\fort\helpers\FortCpDatetime;
use allomambo\fort\helpers\FortDbDatetime;
use allomambo\fort\helpers\IpHelper;
use allomambo\fort\Plugin;
use allomambo\fort\records\BlockedIpRecord;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\base\Component;
use yii\db\Expression;
use yii\web\ForbiddenHttpException;

class IpBlockService extends Component
{
    public const REASON_HTTP_RATE = 'http_rate';

    public const REASON_LOGIN_THRESHOLD = 'login_threshold';

    public const REASON_MANUAL = 'manual';

    /**
     * Reject the request if this IP is actively blocked.
     *
     * @throws ForbiddenHttpException
     */
    public function enforceRequest(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        $plugin = Plugin::getInstance();
        if ($plugin === null || !Craft::$app->getPlugins()->isPluginEnabled($plugin->id)) {
            return;
        }

        $rawIp = FortClientIp::getEffective();
        $ip = $this->normalizeClientIp($rawIp);
        $settings = $plugin->getSettings();

        $excluded = IpHelper::matchesExcluded($ip, $settings->excludedIps);
        if ($excluded) {
            return;
        }

        $blocked = $this->isCurrentlyBlocked($ip);
        if (!$blocked) {
            return;
        }

        $ref = substr(hash('xxh3', $ip . time()), 0, 8);
        Craft::warning("Fort: access denied for IP {$ip} (ref: {$ref})", __METHOD__);
        throw new ForbiddenHttpException(Craft::t('fort', 'Access denied. Ref: {ref}', ['ref' => $ref]));
    }

    public function normalizeClientIp(?string $ip): string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '0.0.0.0';
        }

        $ip = IpHelper::canonicalIp($ip);

        return StringHelper::truncate($ip, 45);
    }

    /**
     * Find a blocked-IP row whether it was stored as dotted IPv4 or IPv4-mapped IPv6.
     */
    private function findBlockedRecordForClientIp(string $canonicalIp): ?BlockedIpRecord
    {
        foreach (IpHelper::equivalentClientIpStrings($canonicalIp) as $candidate) {
            $record = BlockedIpRecord::findOne(['clientIp' => $candidate]);
            if ($record !== null) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Find an existing blocked-IP row (keyed by any of the equivalent representations of the IP)
     * or seed a fresh {@see BlockedIpRecord} with sane defaults. Does not set reason / expiry /
     * threshold — those diverge between automatic and manual blocks and are left to the caller.
     */
    private function loadOrCreateBlockedRecord(string $normalizedIp): BlockedIpRecord
    {
        $record = $this->findBlockedRecordForClientIp($normalizedIp);
        if ($record === null) {
            $record = new BlockedIpRecord();
            $record->clientIp = $normalizedIp;
            $record->uid = StringHelper::UUID();
            $record->blockCount = 0;
            $record->isPermanent = false;
        } elseif ($record->clientIp !== $normalizedIp) {
            // Canonicalize the stored value if we hit via an equivalent string (e.g., ::ffff:1.2.3.4 row queried as 1.2.3.4).
            $record->clientIp = $normalizedIp;
        }

        return $record;
    }

    /**
     * Returns true if the IP should be denied (blocked flag + not expired for temporary blocks).
     */
    public function isCurrentlyBlocked(string $clientIp): bool
    {
        $clientIp = $this->normalizeClientIp($clientIp);
        $record = $this->findBlockedRecordForClientIp($clientIp);
        if ($record === null || !$record->blocked) {
            return false;
        }

        if ($record->isPermanent) {
            return true;
        }

        $until = $record->blockedUntil;
        // Craft stores naive DATETIME as UTC; strtotime()/default TZ must not interpret blockedUntil.
        if ($until === null || $until === '') {
            $record->blocked = false;
            $record->dateUpdated = DateTimeHelper::now();
            $record->save(false);

            return false;
        }

        $untilTs = FortDbDatetime::utcUnixTimestamp($until);
        if ($untilTs === null) {
            Craft::warning('Fort: could not parse blockedUntil for IP ' . $clientIp, __METHOD__);

            return true;
        }

        if ($untilTs < DateTimeHelper::currentTimeStamp()) {
            $record->blocked = false;
            $record->dateUpdated = DateTimeHelper::now();
            $record->save(false);

            return false;
        }

        return true;
    }

    /**
     * Build notification/alert payload fields from a saved blocked-IP row.
     *
     * @return array<string, mixed>
     */
    public function payloadFromBlockedRecord(BlockedIpRecord $record): array
    {
        $untilIso = FortDbDatetime::toUtcDbFormat($record->blockedUntil);

        $payload = [
            'blockedIpRowId' => (int) $record->id,
            'blockedClientIp' => $record->clientIp,
            'ip' => $record->clientIp,
            'blockReason' => $record->lastReason,
            'blockedUntil' => $untilIso,
            'blockCount' => (int) $record->blockCount,
            'isPermanent' => (bool) $record->isPermanent,
        ];

        if ($record->nextPermanentThreshold !== null) {
            $payload['nextPermanentThreshold'] = (int) $record->nextPermanentThreshold;
        }

        return $payload;
    }

    /**
     * Apply or extend a block from HTTP rate limiting or login threshold (increments blockCount).
     */
    public function applyAutomaticBlock(string $clientIp, string $reason): ?BlockedIpRecord
    {
        $clientIp = $this->normalizeClientIp($clientIp);
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return null;
        }

        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();

        if (IpHelper::matchesExcluded($clientIp, $settings->excludedIps)) {
            return null;
        }

        $minutes = $plugin->runtimeSettings->getDefaultBlockDurationMinutes();
        $now = DateTimeHelper::now();

        try {
            return Craft::$app->getDb()->transaction(function () use ($plugin, $settings, $clientIp, $reason, $minutes, $now) {
                $record = $this->loadOrCreateBlockedRecord($clientIp);
                $record->manual = false;

                // Permanent block still active: tally and refresh metadata only (no expiry).
                if ($record->blocked && $record->isPermanent) {
                    $record->blockCount = (int) $record->blockCount + 1;
                    $record->lastReason = $reason;
                    $record->lastBlockedAt = $now;
                    $record->dateUpdated = $now;
                    if ($record->dateCreated === null) {
                        $record->dateCreated = $now;
                    }

                    if (!$record->save(false)) {
                        Craft::warning('Fort: could not save blocked IP row for ' . $clientIp, __METHOD__);

                        return null;
                    }

                    $this->recordIpBlockedEvent($plugin, $clientIp, $record, $reason);

                    return $record;
                }

                $record->blockCount = (int) $record->blockCount + 1;
                $record->blocked = true;
                $record->lastReason = $reason;
                $record->lastBlockedAt = $now;
                $record->dateUpdated = $now;

                $baseThreshold = $plugin->runtimeSettings->getPermanentBlockAfterAutomaticBlocks($settings);
                $threshold = $record->nextPermanentThreshold !== null && (int) $record->nextPermanentThreshold > 0
                    ? (int) $record->nextPermanentThreshold
                    : $baseThreshold;

                if ($record->blockCount >= $threshold) {
                    $record->isPermanent = true;
                    $record->blockedUntil = null;
                    $record->nextPermanentThreshold = $threshold + $baseThreshold;
                } else {
                    $record->isPermanent = false;
                    $until = (clone $now)->modify('+' . (int) $minutes . ' minutes');
                    $record->blockedUntil = $until;
                }

                if ($record->dateCreated === null) {
                    $record->dateCreated = $now;
                }

                if (!$record->save(false)) {
                    Craft::warning('Fort: could not save blocked IP row for ' . $clientIp, __METHOD__);

                    return null;
                }

                // {@see recordIpBlockedEvent()} is log-and-continue: a transient event-table failure does not
                // roll back a legitimate IP block. The enclosing transaction still guards against DB-level
                // failures (connection drops mid-save) that would otherwise leave the block row orphaned.
                $this->recordIpBlockedEvent($plugin, $clientIp, $record, $reason);

                return $record;
            });
        } catch (\Throwable $e) {
            Craft::warning('Fort: automatic block transaction failed for ' . $clientIp . ': ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    private function recordIpBlockedEvent(Plugin $plugin, string $clientIp, BlockedIpRecord $record, string $reason): void
    {
        $untilIso = FortDbDatetime::toUtcDbFormat($record->blockedUntil);

        try {
            $plugin->securityEvents->record(
                'ip_blocked',
                $clientIp,
                Craft::$app->getRequest()->getPathInfo() ?: null,
                array_filter([
                    'reason' => $reason,
                    'blockedUntil' => $untilIso,
                    'isPermanent' => $record->isPermanent ? true : null,
                ], static fn($v) => $v !== null && $v !== '')
            );
        } catch (\Throwable $e) {
            Craft::warning('Fort: could not record ip_blocked event: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Manual block from CP. Counts toward the permanent-block threshold the same way automatic blocks do.
     */
    public function applyManualBlock(string $clientIp, ?string $notes = null, bool $forcePermanent = false): bool
    {
        $clientIp = $this->normalizeClientIp($clientIp);
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return false;
        }

        /** @var \allomambo\fort\models\Settings $settings */
        $settings = $plugin->getSettings();

        if (IpHelper::matchesExcluded($clientIp, $settings->excludedIps)) {
            return false;
        }

        $minutes = $plugin->runtimeSettings->getDefaultBlockDurationMinutes();
        $now = DateTimeHelper::now();

        try {
            return (bool) Craft::$app->getDb()->transaction(function () use ($plugin, $settings, $clientIp, $notes, $minutes, $now, $forcePermanent) {
                $record = $this->loadOrCreateBlockedRecord($clientIp);
                $record->blockCount = (int) $record->blockCount + 1;

                $record->blocked = true;
                $record->manual = true;
                $record->lastReason = self::REASON_MANUAL;
                $record->lastBlockedAt = $now;

                // Permanent threshold check (same logic as automatic blocks).
                $baseThreshold = $plugin->runtimeSettings->getPermanentBlockAfterAutomaticBlocks($settings);
                $threshold = $record->nextPermanentThreshold !== null && (int) $record->nextPermanentThreshold > 0
                    ? (int) $record->nextPermanentThreshold
                    : $baseThreshold;

                if ($forcePermanent || $record->blockCount >= $threshold) {
                    $record->isPermanent = true;
                    $record->blockedUntil = null;
                    if (!$forcePermanent) {
                        $record->nextPermanentThreshold = $threshold + $baseThreshold;
                    }
                } else {
                    $record->isPermanent = false;
                    $record->blockedUntil = (clone $now)->modify('+' . (int) $minutes . ' minutes');
                }

                if ($notes !== null && $notes !== '') {
                    $newNote = StringHelper::truncate($notes, 65535);
                    $existing = trim((string) ($record->notes ?? ''));
                    $record->notes = $existing !== ''
                        ? StringHelper::truncate($existing . "\n\n---\n" . $newNote, 65535)
                        : $newNote;
                }
                $record->dateUpdated = $now;
                if ($record->dateCreated === null) {
                    $record->dateCreated = $now;
                }

                if (!$record->save(false)) {
                    return false;
                }

                // Log-and-continue: see note in applyAutomaticBlock().
                $this->recordIpBlockedEvent($plugin, $clientIp, $record, self::REASON_MANUAL);

                return true;
            });
        } catch (\Throwable $e) {
            Craft::warning('Fort: manual block transaction failed for ' . $clientIp . ': ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }

    public function updateNotes(string $clientIp, ?string $notes): bool
    {
        $clientIp = $this->normalizeClientIp($clientIp);
        $record = $this->findBlockedRecordForClientIp($clientIp);
        if ($record === null) {
            return false;
        }

        $record->notes = ($notes !== null && $notes !== '') ? StringHelper::truncate($notes, 65535) : null;
        $record->dateUpdated = DateTimeHelper::now();

        return $record->save(false);
    }

    public function unblock(string $clientIp): bool
    {
        $clientIp = $this->normalizeClientIp($clientIp);
        $record = $this->findBlockedRecordForClientIp($clientIp);
        if ($record === null) {
            return false;
        }

        $record->blocked = false;
        $record->blockedUntil = null;
        $record->isPermanent = false;
        $record->dateUpdated = DateTimeHelper::now();

        return $record->save(false);
    }

    private const SWEEP_CACHE_KEY = 'fort-ip-block-sweep';

    private const SWEEP_CACHE_TTL_SECONDS = 86400;

    /**
     * Bulk-clear expired temporary blocks so the CP list and active count stay accurate
     * without relying on each IP to make a new request. Uses a single UPDATE with the same
     * UTC wall-clock basis as {@see countActiveBlocked()} and {@see isCurrentlyBlocked()}.
     *
     * @return int Number of rows swept (0 if nothing to do).
     */
    public function sweepExpiredTemporaryBlocks(): int
    {
        $now = FortDbDatetime::nowUtcForDbCompare();

        return (int) Craft::$app->getDb()->createCommand()->update(
            '{{%fort_blocked_ips}}',
            [
                'blocked' => false,
                'blockedUntil' => null,
                'dateUpdated' => $now,
            ],
            [
                'and',
                ['blocked' => true],
                ['isPermanent' => false],
                [
                    'or',
                    ['blockedUntil' => null],
                    ['<=', 'blockedUntil', $now],
                ],
            ],
        )->execute();
    }

    /**
     * Cache-throttled wrapper: runs the sweep at most once per day.
     */
    public function maybeSweepExpiredBlocks(): void
    {
        $cache = Craft::$app->getCache();
        if ($cache->get(self::SWEEP_CACHE_KEY) !== false) {
            return;
        }

        $swept = $this->sweepExpiredTemporaryBlocks();

        $cache->set(self::SWEEP_CACHE_KEY, '1', self::SWEEP_CACHE_TTL_SECONDS);

        if ($swept > 0) {
            Craft::info("Fort: swept {$swept} expired temporary IP block(s).", __METHOD__);
        }
    }

    public function countActiveBlocked(): int
    {
        $now = FortDbDatetime::nowUtcForDbCompare();

        return (int) BlockedIpRecord::find()
            ->where(['blocked' => true])
            ->andWhere([
                'or',
                ['isPermanent' => true],
                ['>', 'blockedUntil', $now],
            ])
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBlockedRowsForCp(int $limit = 500): array
    {
        $rows = BlockedIpRecord::find()
            ->orderBy(new Expression('([[lastBlockedAt]] IS NULL) ASC, [[lastBlockedAt]] DESC'))
            ->limit($limit)
            ->asArray()
            ->all();

        foreach ($rows as &$row) {
            $row = $this->enrichBlockedRowForCp($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function enrichBlockedRowForCp(array $row): array
    {
        $row['lastReasonLabel'] = AlertDisplayHelper::blockReasonValue($row['lastReason'] ?? null);
        $row['blockedUntilParts'] = FortCpDatetime::parts($row['blockedUntil'] ?? null);
        $row['lastBlockedAtParts'] = FortCpDatetime::parts($row['lastBlockedAt'] ?? null);

        return $row;
    }
}
