<?php

namespace allomambo\fort\services;

use allomambo\fort\models\Settings;
use allomambo\fort\Plugin;
use allomambo\fort\records\FortRuntimeRecord;
use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * DB-backed runtime options (editable in CP when project config locks plugin settings).
 */
class RuntimeSettingsService extends Component
{
    /**
     * Runtime override column => the {@see Settings} attribute holding its default and limits.
     */
    private const LIMIT_ATTRIBUTES = [
        'defaultBlockDurationMinutes' => 'defaultBlockDurationMinutes',
        'permanentBlockAfterAutomaticBlocks' => 'permanentBlockAfterAutomaticBlocks',
        'failedLoginThreshold' => 'failedLoginThresholdPerIp',
        'failedLoginWindowMinutes' => 'failedLoginWindowMinutes',
        'maxRequestsPerIpPerMinute' => 'maxRequestsPerIpPerMinute',
        'httpRateLimitAlertsBeforeBlock' => 'httpRateLimitAlertsBeforeBlock',
        'httpRateLimitAlertWindowMinutes' => 'httpRateLimitAlertWindowMinutes',
    ];

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
        return $this->effectiveLimit('defaultBlockDurationMinutes', Plugin::getInstance()->getSettings());
    }

    public function getPermanentBlockAfterAutomaticBlocks(Settings $settings): int
    {
        return $this->effectiveLimit('permanentBlockAfterAutomaticBlocks', $settings);
    }

    public function getFailedLoginThreshold(Settings $settings): int
    {
        return $this->effectiveLimit('failedLoginThreshold', $settings);
    }

    public function getFailedLoginWindowMinutes(Settings $settings): int
    {
        return $this->effectiveLimit('failedLoginWindowMinutes', $settings);
    }

    public function getMaxRequestsPerIpPerMinute(Settings $settings): int
    {
        return $this->effectiveLimit('maxRequestsPerIpPerMinute', $settings);
    }

    public function getHttpRateLimitAlertsBeforeBlock(Settings $settings): int
    {
        return $this->effectiveLimit('httpRateLimitAlertsBeforeBlock', $settings);
    }

    public function getHttpRateLimitAlertWindowMinutes(Settings $settings): int
    {
        return $this->effectiveLimit('httpRateLimitAlertWindowMinutes', $settings);
    }

    /**
     * The runtime override when positive, otherwise the settings value clamped into its allowed range
     * (`config/fort.php` bypasses {@see Settings::rules()}, so a 0 there must never reach the callers).
     *
     * @param string $column Runtime column name, a key of {@see self::LIMIT_ATTRIBUTES}.
     */
    private function effectiveLimit(string $column, Settings $settings): int
    {
        $override = (int) $this->getRow()->$column;
        if ($override > 0) {
            return $override;
        }

        $attribute = self::LIMIT_ATTRIBUTES[$column];
        $limits = Settings::LIMITS[$attribute];

        return min($limits['max'], max($limits['min'], (int) $settings->$attribute));
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
        return $this->setDigestSentAt('lastDailyDigestSentAt', $utc);
    }

    public function setLastWeeklyDigestSentAtUtc(?DateTimeInterface $utc): bool
    {
        return $this->setDigestSentAt('lastWeeklyDigestSentAt', $utc);
    }

    private function setDigestSentAt(string $column, ?DateTimeInterface $utc): bool
    {
        $row = $this->getRow();
        if ($utc === null) {
            $row->$column = null;
        } else {
            $immutable = $utc instanceof DateTimeImmutable ? $utc : DateTimeImmutable::createFromInterface($utc);
            $row->$column = \DateTime::createFromImmutable($immutable->setTimezone(new \DateTimeZone('UTC')));
        }

        $ok = $row->save(false);
        if ($ok) {
            $this->invalidateCache();
        }

        return $ok;
    }

    /**
     * Reset all seven user-facing override columns to null (use plugin Settings defaults).
     * Does not clear operational state (lastDailyDigestSentAt, lastWeeklyDigestSentAt).
     */
    public function clearAllOverrides(): bool
    {
        $row = $this->getRow();
        foreach (array_keys(self::LIMIT_ATTRIBUTES) as $column) {
            $row->$column = null;
        }

        $ok = $row->save(false);
        if ($ok) {
            $this->invalidateCache();
        }

        return $ok;
    }

    /**
     * @param array{
     *   defaultBlockDurationMinutes?: int|string|null,
     *   permanentBlockAfterAutomaticBlocks?: int|string|null,
     *   failedLoginThreshold?: int|string|null,
     *   failedLoginWindowMinutes?: int|string|null,
     *   maxRequestsPerIpPerMinute?: int|string|null,
     *   httpRateLimitAlertsBeforeBlock?: int|string|null,
     *   httpRateLimitAlertWindowMinutes?: int|string|null,
     * } $attributes
     */
    public function save(array $attributes): bool
    {
        $row = $this->getRow();

        foreach (self::LIMIT_ATTRIBUTES as $column => $attribute) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }
            $val = $attributes[$column];
            if ($val === '' || $val === null) {
                $row->$column = null;
            } else {
                $limits = Settings::LIMITS[$attribute];
                $row->$column = min($limits['max'], max($limits['min'], (int) $val));
            }
        }

        $ok = $row->save(false);
        if ($ok) {
            $this->invalidateCache();
        }

        return $ok;
    }
}
