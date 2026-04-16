<?php

namespace allomambo\fort\helpers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Pure schedule checks for Fort digest emails (app timezone + stored UTC last-sent).
 */
final class DigestScheduleHelper
{
    /**
     * Daily digest is due when local "now" is on or after today's slot (hour:00) and we have not
     * already sent a daily digest on this local calendar day (any time — avoids a forced send
     * before the hour blocking or duplicating the scheduled send after the hour).
     */
    public static function isDailyDigestDue(
        ?DateTimeInterface $lastSentUtc,
        DateTimeImmutable $nowUtc,
        string $appTimeZone,
        int $dailyDigestHour,
    ): bool {
        $tz = new DateTimeZone($appTimeZone);
        $now = $nowUtc->setTimezone($tz);
        $slotStart = $now->setTime(max(0, min(23, $dailyDigestHour)), 0, 0);

        if ($now < $slotStart) {
            return false;
        }

        if ($lastSentUtc === null) {
            return true;
        }

        $last = DateTimeImmutable::createFromInterface($lastSentUtc)->setTimezone($tz);

        return $last->format('Y-m-d') !== $now->format('Y-m-d');
    }

    /**
     * Weekly digest uses the same hour as daily. Week bucket is Sunday 00:00–Saturday 23:59 in the
     * app timezone (PHP `w` 0–6). Due when now is on or after this week's digest slot and we have
     * not already sent a weekly digest in that same week (avoids duplicating after a forced send).
     */
    public static function isWeeklyDigestDue(
        ?DateTimeInterface $lastSentUtc,
        DateTimeImmutable $nowUtc,
        string $appTimeZone,
        int $dailyDigestHour,
        int $weeklyDigestDayOfWeek,
    ): bool {
        $tz = new DateTimeZone($appTimeZone);
        $weeklyDigestDayOfWeek = max(0, min(6, $weeklyDigestDayOfWeek));
        $hour = max(0, min(23, $dailyDigestHour));

        $now = $nowUtc->setTimezone($tz);
        $dow = (int) $now->format('w');
        $weekSunday = $now->setTime(0, 0, 0)->modify("-{$dow} days");
        $slot = $weekSunday->modify("+{$weeklyDigestDayOfWeek} days")->setTime($hour, 0, 0);

        if ($now < $slot) {
            return false;
        }

        if ($lastSentUtc === null) {
            return true;
        }

        $last = DateTimeImmutable::createFromInterface($lastSentUtc)->setTimezone($tz);
        $weekStart = fn (DateTimeImmutable $local): DateTimeImmutable => $local->setTime(0, 0, 0)->modify('-' . (int) $local->format('w') . ' days');

        return $weekStart($last)->format('Y-m-d') !== $weekStart($now)->format('Y-m-d');
    }
}
