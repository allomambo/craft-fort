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
        if ($path !== null && $path !== '' && (!isset($meta['requestPath']) || $meta['requestPath'] === '')) {
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
                'label' => MetaDisplayHelper::keyLabel($key),
                'value' => MetaDisplayHelper::formatValue($key, $meta[$key]),
            ];
        }

        foreach ($meta as $key => $value) {
            if (!is_string($key) || in_array($key, $keysOrder, true) || $key === 'triggeringUserId') {
                continue;
            }
            $rows[] = [
                'label' => (string) $key,
                'value' => MetaDisplayHelper::formatValue($key, $value),
            ];
        }

        return $rows;
    }
}
