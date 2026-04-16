<?php

namespace allomambo\fort\helpers;

use Craft;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Formats stored UTC datetimes for Control Panel display in the app timezone.
 */
final class FortCpDatetime
{
    /**
     * @return array{main: string, tzInfo: string}
     */
    public static function parts(mixed $value): array
    {
        $tzName = Craft::$app->getTimeZone();
        $tzInfo = Craft::t('fort', 'Dates use the system timezone ({tz}).', ['tz' => $tzName]);

        if ($value === null || $value === '') {
            return ['main' => '—', 'tzInfo' => $tzInfo];
        }

        try {
            if ($value instanceof \DateTimeInterface) {
                $utc = new DateTimeImmutable('@' . $value->getTimestamp());
            } elseif (is_string($value)) {
                $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } else {
                return ['main' => (string) $value, 'tzInfo' => $tzInfo];
            }

            $local = $utc->setTimezone(new DateTimeZone($tzName));

            return [
                'main' => $local->format('Y-m-d H:i'),
                'tzInfo' => $tzInfo,
            ];
        } catch (\Throwable) {
            return ['main' => is_string($value) ? $value : '—', 'tzInfo' => $tzInfo];
        }
    }
}
