<?php

namespace allomambo\fort\services;

use allomambo\fort\models\Settings;
use allomambo\fort\Plugin;
use allomambo\fort\records\FortRuntimeRecord;
use Craft;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use DateTimeInterface;
use craft\base\Component;

/**
 * DB-backed runtime options (editable in CP when project config locks plugin settings).
 */
class RuntimeSettingsService extends Component
{
    private ?FortRuntimeRecord $_row = null;

    public function getRow(): FortRuntimeRecord
    {
        if ($this->_row !== null) {
            return $this->_row;
        }

        $row = FortRuntimeRecord::findOne(['id' => 1]);
        if ($row === null) {
            $now = new \DateTime();
            $row = new FortRuntimeRecord();
            $row->id = 1;
            $row->defaultBlockDurationMinutes = null;
            $row->permanentBlockAfterAutomaticBlocks = null;
            $row->failedLoginThreshold = null;
            $row->failedLoginWindowMinutes = null;
            $row->maxRequestsPerIpPerMinute = null;
            $row->httpRateLimitAlertsBeforeBlock = null;
            $row->httpRateLimitAlertWindowMinutes = null;
            $row->lastDailyDigestSentAt = null;
            $row->lastWeeklyDigestSentAt = null;
            $row->uid = StringHelper::UUID();
            $row->dateCreated = $now;
            $row->dateUpdated = $now;
            if (!$row->save(false)) {
                Craft::warning('Fort: could not seed fort_runtime row.', __METHOD__);
            }
        }

        return $this->_row = $row;
    }

    public function invalidateCache(): void
    {
        $this->_row = null;
    }

    public function getDefaultBlockDurationMinutes(): int
    {
        $rowVal = $this->getRow()->defaultBlockDurationMinutes;
        if ($rowVal !== null && (int) $rowVal > 0) {
            return (int) $rowVal;
        }

        $plugin = Plugin::getInstance();

        return max(1, (int) $plugin->getSettings()->defaultBlockDurationMinutes);
    }

    public function getPermanentBlockAfterAutomaticBlocks(Settings $settings): int
    {
        $o = $this->getRow()->permanentBlockAfterAutomaticBlocks;

        if ($o !== null && (int) $o > 0) {
            return (int) $o;
        }

        return max(1, (int) $settings->permanentBlockAfterAutomaticBlocks);
    }

    public function getFailedLoginThreshold(Settings $settings): int
    {
        $o = $this->getRow()->failedLoginThreshold;

        if ($o !== null && (int) $o > 0) {
            return (int) $o;
        }

        return max(1, (int) $settings->failedLoginThresholdPerIp);
    }

    public function getFailedLoginWindowMinutes(Settings $settings): int
    {
        $o = $this->getRow()->failedLoginWindowMinutes;

        if ($o !== null && (int) $o > 0) {
            return (int) $o;
        }

        return max(1, (int) $settings->failedLoginWindowMinutes);
    }

    public function getMaxRequestsPerIpPerMinute(Settings $settings): int
    {
        $o = $this->getRow()->maxRequestsPerIpPerMinute;

        return $o !== null && $o > 0 ? $o : $settings->maxRequestsPerIpPerMinute;
    }

    public function getHttpRateLimitAlertsBeforeBlock(Settings $settings): int
    {
        $o = $this->getRow()->httpRateLimitAlertsBeforeBlock;

        if ($o !== null && (int) $o > 0) {
            return (int) $o;
        }

        return max(1, (int) $settings->httpRateLimitAlertsBeforeBlock);
    }

    public function getHttpRateLimitAlertWindowMinutes(Settings $settings): int
    {
        $o = $this->getRow()->httpRateLimitAlertWindowMinutes;

        if ($o !== null && (int) $o > 0) {
            return (int) $o;
        }

        return max(1, (int) $settings->httpRateLimitAlertWindowMinutes);
    }

    public function getLastDailyDigestSentAtUtc(): ?DateTimeImmutable
    {
        $v = $this->getRow()->lastDailyDigestSentAt;

        return self::toUtcImmutable($v);
    }

    public function getLastWeeklyDigestSentAtUtc(): ?DateTimeImmutable
    {
        $v = $this->getRow()->lastWeeklyDigestSentAt;

        return self::toUtcImmutable($v);
    }

    private static function toUtcImmutable(mixed $v): ?DateTimeImmutable
    {
        if ($v === null) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        if ($v instanceof DateTimeImmutable) {
            return $v->setTimezone($utc);
        }

        if ($v instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($v)->setTimezone($utc);
        }

        if (is_string($v) && $v !== '') {
            $dt = new DateTimeImmutable($v, $utc);

            return $dt->setTimezone($utc);
        }

        return null;
    }

    public function setLastDailyDigestSentAtUtc(?DateTimeInterface $utc): bool
    {
        $row = $this->getRow();
        if ($utc === null) {
            $row->lastDailyDigestSentAt = null;
        } else {
            $immutable = $utc instanceof DateTimeImmutable ? $utc : DateTimeImmutable::createFromInterface($utc);
            $row->lastDailyDigestSentAt = \DateTime::createFromImmutable($immutable->setTimezone(new \DateTimeZone('UTC')));
        }

        $ok = $row->save(false);
        if ($ok) {
            $this->invalidateCache();
        }

        return $ok;
    }

    public function setLastWeeklyDigestSentAtUtc(?DateTimeInterface $utc): bool
    {
        $row = $this->getRow();
        if ($utc === null) {
            $row->lastWeeklyDigestSentAt = null;
        } else {
            $immutable = $utc instanceof DateTimeImmutable ? $utc : DateTimeImmutable::createFromInterface($utc);
            $row->lastWeeklyDigestSentAt = \DateTime::createFromImmutable($immutable->setTimezone(new \DateTimeZone('UTC')));
        }

        $ok = $row->save(false);
        if ($ok) {
            $this->invalidateCache();
        }

        return $ok;
    }

    /**
     * Reset all six user-facing override columns to null (use plugin Settings defaults).
     * Does not clear operational state (lastDailyDigestSentAt, lastWeeklyDigestSentAt).
     */
    public function clearAllOverrides(): bool
    {
        $row = $this->getRow();
        $row->defaultBlockDurationMinutes = null;
        $row->permanentBlockAfterAutomaticBlocks = null;
        $row->failedLoginThreshold = null;
        $row->failedLoginWindowMinutes = null;
        $row->maxRequestsPerIpPerMinute = null;
        $row->httpRateLimitAlertsBeforeBlock = null;
        $row->httpRateLimitAlertWindowMinutes = null;

        $ok = $row->save(false);
        if ($ok) {
            $this->invalidateCache();
        }

        return $ok;
    }

    /**
     * @param array{
     *   defaultBlockDurationMinutes?: int,
     *   permanentBlockAfterAutomaticBlocks?: int|null,
     *   failedLoginThreshold?: int|null,
     *   failedLoginWindowMinutes?: int|null,
     *   maxRequestsPerIpPerMinute?: int|null,
     *   httpRateLimitAlertsBeforeBlock?: int|null,
     *   httpRateLimitAlertWindowMinutes?: int|null,
     * } $attributes
     */
    public function save(array $attributes): bool
    {
        $row = $this->getRow();

        if (array_key_exists('defaultBlockDurationMinutes', $attributes)) {
            $val = $attributes['defaultBlockDurationMinutes'];
            if ($val === '' || $val === null) {
                $row->defaultBlockDurationMinutes = null;
            } else {
                $row->defaultBlockDurationMinutes = min(525600, max(1, (int) $val));
            }
        }
        $caps = [
            'permanentBlockAfterAutomaticBlocks' => 100000,
            'failedLoginThreshold' => 10000,
            'failedLoginWindowMinutes' => 10080,
            'maxRequestsPerIpPerMinute' => 1000000,
            'httpRateLimitAlertsBeforeBlock' => 100000,
            'httpRateLimitAlertWindowMinutes' => 10080,
        ];
        foreach ($caps as $key => $maxVal) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $val = $attributes[$key];
            if ($val === '' || $val === null) {
                $row->$key = null;
            } else {
                $row->$key = min($maxVal, max(1, (int) $val));
            }
        }

        $ok = $row->save(false);
        if ($ok) {
            $this->invalidateCache();
        }

        return $ok;
    }
}
