<?php

namespace allomambo\fort\records;

use craft\db\ActiveRecord;

/**
 * Persisted Fort alert (email/webhook drivers); distinct from audit {@see SecurityEventRecord}.
 *
 * @property int $id
 * @property int|null $siteId
 * @property string $alertType
 * @property string $clientIp
 * @property string|null $requestPath
 * @property string|null $payload
 * @property \DateTime $dateCreated
 * @property string $uid
 */
class AlertRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fort_alerts}}';
    }
}
