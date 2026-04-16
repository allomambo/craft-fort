<?php

namespace allomambo\fort\helpers;

use Craft;
use craft\helpers\Json;

/**
 * CP labels for {@see \allomambo\fort\records\SecurityEventRecord} rows.
 */
final class EventDisplayHelper
{
    public static function eventTypeLabel(string $type): string
    {
        return match ($type) {
            'login_failure' => Craft::t('fort', 'Login failure'),
            'login_threshold' => Craft::t('fort', 'Login failure threshold reached'),
            'http_rate_limited' => Craft::t('fort', 'HTTP rate limit exceeded'),
            'ip_blocked' => Craft::t('fort', 'IP blocked'),
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $row DB row (type, meta, requestPath, …)
     * @return array<int, array{label: string, value: string}>
     */
    public static function metaTableRows(array $row): array
    {
        $meta = [];
        if (!empty($row['meta'])) {
            try {
                $decoded = Json::decode((string) $row['meta'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            } catch (\Throwable) {
                $meta = [];
            }
        }

        $path = $row['requestPath'] ?? null;
        if ($path !== null && $path !== '' && (!isset($meta['requestPath']) || $meta['requestPath'] === '' || $meta['requestPath'] === null)) {
            $meta['requestPath'] = $path;
        }

        $keysOrder = [
            'reason',
            'failures',
            'windowMinutes',
            'attemptedLogin',
            'authError',
            'userId',
            'count',
            'limit',
            'alertsInWindow',
            'alertsBeforeBlock',
            'alertWindowMinutes',
            'blockDurationMinutes',
            'automaticBlockPending',
            'blockedUntil',
            'requestPath',
        ];

        $rows = [];
        foreach ($keysOrder as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $rows[] = [
                'label' => self::metaKeyLabel($key),
                'value' => self::formatValue($key, $meta[$key]),
            ];
        }

        foreach ($meta as $key => $value) {
            if (!is_string($key) || in_array($key, $keysOrder, true) || $key === 'triggeringUserId') {
                continue;
            }
            $rows[] = [
                'label' => (string) $key,
                'value' => self::formatValue($key, $value),
            ];
        }

        return $rows;
    }

    private static function metaKeyLabel(string $key): string
    {
        return match ($key) {
            'reason' => Craft::t('fort', 'Meta: Block reason'),
            'failures' => Craft::t('fort', 'Meta: Failed attempts'),
            'windowMinutes' => Craft::t('fort', 'Meta: Window'),
            'attemptedLogin' => Craft::t('fort', 'Meta: Attempted login'),
            'authError' => Craft::t('fort', 'Meta: Auth error'),
            'userId' => Craft::t('fort', 'Meta: User ID'),
            'count' => Craft::t('fort', 'Meta: Request count'),
            'limit' => Craft::t('fort', 'Meta: Per-minute limit'),
            'alertsInWindow' => Craft::t('fort', 'Meta: Alerts in window'),
            'alertsBeforeBlock' => Craft::t('fort', 'Meta: Alerts required for block'),
            'alertWindowMinutes' => Craft::t('fort', 'Meta: Alert window'),
            'blockDurationMinutes' => Craft::t('fort', 'Meta: Block duration when applied'),
            'automaticBlockPending' => Craft::t('fort', 'Meta: Automatic block this event'),
            'blockedUntil' => Craft::t('fort', 'Meta: Block until'),
            'requestPath' => Craft::t('fort', 'Meta: Path'),
            default => $key,
        };
    }

    private static function formatValue(string $key, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($key === 'reason') {
            return AlertDisplayHelper::blockReasonValue(is_scalar($value) ? (string) $value : '');
        }

        if ($key === 'authError') {
            return AlertDisplayHelper::authErrorValue(is_scalar($value) ? (string) $value : null);
        }

        if (str_ends_with($key, 'Minutes') && is_numeric($value)) {
            return $value . ' min';
        }

        if (is_bool($value)) {
            return $value ? Craft::t('fort', 'Yes') : Craft::t('fort', 'No');
        }

        if (is_array($value)) {
            return Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
