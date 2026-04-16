<?php

namespace allomambo\fort\records;

use craft\db\ActiveRecord;

/**
 * Single-row runtime tunables (editable in CP when plugin settings are read-only).
 *
 * @property int $id
 * @property int|null $defaultBlockDurationMinutes
 * @property int|null $permanentBlockAfterAutomaticBlocks
 * @property int|null $failedLoginThreshold
 * @property int|null $failedLoginWindowMinutes
 * @property int|null $maxRequestsPerIpPerMinute
 * @property int|null $httpRateLimitAlertsBeforeBlock
 * @property int|null $httpRateLimitAlertWindowMinutes
 * @property \DateTime|null $lastDailyDigestSentAt
 * @property \DateTime|null $lastWeeklyDigestSentAt
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class FortRuntimeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fort_runtime}}';
    }
}
