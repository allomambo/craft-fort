<?php

namespace allomambo\fort\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 * @property int|null $siteId
 * @property string $type
 * @property string $clientIp
 * @property string|null $requestPath
 * @property string|null $meta
 */
class SecurityEventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fort_security_events}}';
    }
}
