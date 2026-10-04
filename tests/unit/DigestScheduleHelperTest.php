<?php

declare(strict_types=1);

namespace allomambo\fort\tests\unit;

use allomambo\fort\helpers\DigestScheduleHelper;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DigestScheduleHelperTest extends TestCase
{
    public function testDailyDigestIsNotDueBeforeTodaysSlot(): void
    {
        $nowUtc = new DateTimeImmutable('2026-07-15 12:59:59 UTC');

        self::assertFalse(DigestScheduleHelper::isDailyDigestDue(null, $nowUtc, 'America/Toronto', 9));
    }

    public function testDailyDigestIsDueAtTodaysSlotWhenNeverSent(): void
    {
        $nowUtc = new DateTimeImmutable('2026-07-15 13:00:00 UTC');

        self::assertTrue(DigestScheduleHelper::isDailyDigestDue(null, $nowUtc, 'America/Toronto', 9));
    }

    public function testForcedDailySendEarlierTodayPreventsScheduledDuplicate(): void
    {
        $lastSentUtc = new DateTimeImmutable('2026-07-15 11:00:00 UTC');
        $nowUtc = new DateTimeImmutable('2026-07-15 14:00:00 UTC');

        self::assertFalse(DigestScheduleHelper::isDailyDigestDue($lastSentUtc, $nowUtc, 'America/Toronto', 9));
    }

    public function testDailyDigestIsDueWhenLastSentOnPreviousLocalDay(): void
    {
        $lastSentUtc = new DateTimeImmutable('2026-07-15 03:59:59 UTC');
        $nowUtc = new DateTimeImmutable('2026-07-15 13:00:00 UTC');

        self::assertTrue(DigestScheduleHelper::isDailyDigestDue($lastSentUtc, $nowUtc, 'America/Toronto', 9));
    }

    public function testDailyDigestHourIsClampedToValidRange(): void
    {
        $nowUtc = new DateTimeImmutable('2026-07-15 22:59:59 UTC');

        self::assertFalse(DigestScheduleHelper::isDailyDigestDue(null, $nowUtc, 'UTC', 24));
        self::assertTrue(DigestScheduleHelper::isDailyDigestDue(null, $nowUtc, 'UTC', -1));
    }

    public function testWeeklyDigestIsNotDueBeforeThisWeeksSlot(): void
    {
        $nowUtc = new DateTimeImmutable('2026-07-15 12:59:59 UTC');

        self::assertFalse(DigestScheduleHelper::isWeeklyDigestDue(null, $nowUtc, 'America/Toronto', 9, 3));
    }

    public function testWeeklyDigestIsDueAtThisWeeksSlotWhenNeverSent(): void
    {
        $nowUtc = new DateTimeImmutable('2026-07-15 13:00:00 UTC');

        self::assertTrue(DigestScheduleHelper::isWeeklyDigestDue(null, $nowUtc, 'America/Toronto', 9, 3));
    }

    public function testForcedWeeklySendEarlierThisWeekPreventsScheduledDuplicate(): void
    {
        $lastSentUtc = new DateTimeImmutable('2026-07-13 14:00:00 UTC');
        $nowUtc = new DateTimeImmutable('2026-07-15 14:00:00 UTC');

        self::assertFalse(DigestScheduleHelper::isWeeklyDigestDue($lastSentUtc, $nowUtc, 'America/Toronto', 9, 3));
    }

    public function testWeeklyDigestIsDueWhenLastSentInPreviousWeek(): void
    {
        $lastSentUtc = new DateTimeImmutable('2026-07-11 14:00:00 UTC');
        $nowUtc = new DateTimeImmutable('2026-07-15 14:00:00 UTC');

        self::assertTrue(DigestScheduleHelper::isWeeklyDigestDue($lastSentUtc, $nowUtc, 'America/Toronto', 9, 3));
    }

    public function testWeeklyDayAndHourAreClampedToValidRanges(): void
    {
        $saturdayAtLastSlot = new DateTimeImmutable('2026-07-18 23:00:00 UTC');
        $sundayAtFirstSlot = new DateTimeImmutable('2026-07-19 00:00:00 UTC');

        self::assertTrue(DigestScheduleHelper::isWeeklyDigestDue(null, $saturdayAtLastSlot, 'UTC', 24, 7));
        self::assertTrue(DigestScheduleHelper::isWeeklyDigestDue(null, $sundayAtFirstSlot, 'UTC', -1, -1));
    }
}
