<?php

namespace allomambo\fort\helpers;

use craft\helpers\DateTimeHelper;
use craft\helpers\Db;

/**
 * Fort DB datetimes follow Craft: values in {@see Db::prepareDateForDb()} are UTC wall clocks in naive DATETIME columns.
 * Use this helper anywhere you compare, log, or query those values so PHP default timezone / strtotime cannot skew expiry.
 */
final class FortDbDatetime
{
    /**
     * Unix timestamp for a value read from or written to Craft DATETIME columns (UTC in DB).
     * Uses {@see DateTimeHelper::toDateTime()} with naive datetimes interpreted as UTC (same basis as {@see Db::prepareDateForDb()}).
     */
    public static function utcUnixTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $dt = DateTimeHelper::toDateTime($value, false, false);
        if ($dt === false) {
            return null;
        }

        return $dt->getTimestamp();
    }

    /**
     * UTC datetime string for security-event / notification payloads, matching Craft's DB column format.
     */
    public static function toUtcDbFormat(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Db::prepareDateForDb(DateTimeHelper::toDateTime($value));
    }

    /**
     * Current instant as UTC `Y-m-d H:i:s` for SQL comparisons against Craft-stored datetimes.
     */
    public static function nowUtcForDbCompare(): string
    {
        $s = Db::prepareDateForDb(DateTimeHelper::now());

        return $s ?? gmdate('Y-m-d H:i:s');
    }
}
