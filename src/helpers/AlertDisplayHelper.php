<?php

namespace allomambo\fort\helpers;

use Craft;
use craft\helpers\Json;

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
     * @return array<int, array{label: string, value: string}>
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
                'label' => self::metaKeyLabel($key),
                'value' => self::formatMetaValue($key, $payload[$key]),
            ];
        }

        // Any extra keys not in the canonical order (forward compatible)
        foreach ($payload as $key => $value) {
            if (!is_string($key) || in_array($key, $keysOrder, true) || self::isCpMetaHiddenKey($key)) {
                continue;
            }
            $rows[] = [
                'label' => (string) $key,
                'value' => self::formatMetaValue($key, $value),
            ];
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

    private static function metaKeyLabel(string $key): string
    {
        return match ($key) {
            'blockedClientIp' => Craft::t('fort', 'Meta: Blocked IP'),
            'ip' => Craft::t('fort', 'Meta: IP'),
            'blockedUntil' => Craft::t('fort', 'Meta: Block until'),
            'blockReason' => Craft::t('fort', 'Meta: Block reason'),
            'blockCount' => Craft::t('fort', 'Meta: Block count'),
            'isPermanent' => Craft::t('fort', 'Meta: Permanent'),
            'nextPermanentThreshold' => Craft::t('fort', 'Meta: Next permanent at block count'),
            'blockedIpRowId' => Craft::t('fort', 'Meta: Block row ID'),
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
            'requestPath' => Craft::t('fort', 'Meta: Path'),
            'blockApplyFailed' => Craft::t('fort', 'Meta: Block apply failed'),
            '_demo' => Craft::t('fort', 'Meta: Demo row'),
            default => $key,
        };
    }

    private static function formatMetaValue(string $key, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($key === 'blockReason') {
            return self::blockReasonValue(is_scalar($value) ? (string) $value : '');
        }

        if ($key === 'authError') {
            return self::authErrorValue(is_scalar($value) ? (string) $value : null);
        }

        if ($key === 'blockedUntil') {
            $p = FortCpDatetime::parts($value);

            return $p['main'];
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

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
