<?php

namespace allomambo\fort\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $clientIp
 * @property bool $blocked
 * @property \DateTime|null $blockedUntil
 * @property int $blockCount
 * @property bool $manual
 * @property string|null $notes
 * @property string|null $lastReason
 * @property \DateTime|null $lastBlockedAt
 * @property bool $isPermanent
 * @property int|null $nextPermanentThreshold
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class BlockedIpRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fort_blocked_ips}}';
    }
}
