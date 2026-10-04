<?php

namespace allomambo\fort\helpers;

use Craft;
use craft\helpers\UrlHelper;

/**
 * CP labels and payload flattening for {@see \allomambo\fort\records\AlertRecord}.
 */
class AlertDisplayHelper
{
    public static function alertTypeLabel(string $type): string
    {
        return match ($type) {
            'login_threshold' => Craft::t('fort', 'Login failure threshold reached'),
            'http_rate_limited' => Craft::t('fort', 'HTTP rate limit exceeded'),
            default => $type,
        };
    }

    public static function blockReasonValue(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return '—';
        }

        return match ($reason) {
            'login_threshold' => Craft::t('fort', 'Login failure'),
            'http_rate' => Craft::t('fort', 'HTTP rate'),
            'manual' => Craft::t('fort', 'Manual'),
            default => $reason,
        };
    }

    public static function authErrorValue(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        return match ($code) {
            'invalid_credentials' => Craft::t('fort', 'Invalid username or password'),
            'account_locked' => Craft::t('fort', 'Account locked'),
            default => $code,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public static function metaTableRows(array $payload): array
    {
        $blocked = isset($payload['blockedClientIp']) ? (string) $payload['blockedClientIp'] : null;
        $ipAlt = isset($payload['ip']) ? (string) $payload['ip'] : null;

        // CP popover: omit internal/debug-only keys (still available in stored payload / notifications)
        $keysOrder = [
            'blockedClientIp',
            'blockedUntil',
            'blockReason',
            'blockCount',
            'isPermanent',
            'nextPermanentThreshold',
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
            'requestPath',
            'blockApplyFailed',
            'ip',
        ];

        $rows = [];
        foreach ($keysOrder as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            if ($key === 'ip' && $blocked !== null && $ipAlt === $blocked) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => MetaDisplayHelper::keyLabel($key),
                'value' => MetaDisplayHelper::formatValue($key, $payload[$key]),
            ];
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || in_array($key, $keysOrder, true) || self::isCpMetaHiddenKey($key)) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => (string) $key,
                'value' => MetaDisplayHelper::formatValue($key, $value),
            ];
        }

        return $rows;
    }

    /**
     * Human-readable rows for notification emails.
     *
     * The attempted login is omitted: it is often an email address. For `login_threshold`,
     * a known Craft user ID is listed first instead.
     *
     * @param array<string, mixed> $payload
     * @return array<int, array{key: string, label: string, value: string, url?: string}>
     */
    public static function emailSummaryRows(string $eventType, array $payload): array
    {
        unset($payload['attemptedLogin']);
        $rows = self::metaTableRows($payload);

        if ($eventType === 'login_threshold') {
            $userIdRow = null;
            $rest = [];
            foreach ($rows as $row) {
                if ($row['key'] === 'userId') {
                    $userIdRow = $row;
                    continue;
                }
                $rest[] = $row;
            }
            $rows = $userIdRow === null ? $rows : array_merge([$userIdRow], $rest);
        }

        foreach ($rows as $i => $row) {
            if ($row['key'] !== 'userId' || !ctype_digit($row['value']) || (int) $row['value'] < 1) {
                continue;
            }
            $rows[$i]['url'] = UrlHelper::cpUrl('users/' . $row['value']);
        }

        return $rows;
    }

    /**
     * Keys stored on the alert payload but not shown in the CP info HUD.
     */
    private static function isCpMetaHiddenKey(string $key): bool
    {
        // triggeringUserId is shown as a User column (microcard), not in the metadata HUD.
        return in_array($key, ['blockedIpRowId', '_demo', 'triggeringUserId'], true);
    }
}
