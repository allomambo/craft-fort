<?php

namespace allomambo\fort\helpers;

use Craft;
use craft\helpers\Json;

/**
 * Labels and plain-string values for alert and security event metadata keys.
 */
final class MetaDisplayHelper
{
    public static function keyLabel(string $key): string
    {
        return match ($key) {
            'blockedClientIp' => Craft::t('fort', 'Meta: Blocked IP'),
            'ip' => Craft::t('fort', 'Meta: IP'),
            'blockedUntil' => Craft::t('fort', 'Meta: Block until'),
            'blockReason', 'reason' => Craft::t('fort', 'Meta: Block reason'),
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

    public static function formatValue(string $key, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($key === 'blockReason' || $key === 'reason') {
            return AlertDisplayHelper::blockReasonValue(is_scalar($value) ? (string) $value : '');
        }

        if ($key === 'authError') {
            return AlertDisplayHelper::authErrorValue(is_scalar($value) ? (string) $value : null);
        }

        if ($key === 'blockedUntil') {
            return FortCpDatetime::parts($value)['main'];
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
