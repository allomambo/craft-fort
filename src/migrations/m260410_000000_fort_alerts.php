<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;

/**
 * Adds {{%fort_alerts}} for persisted significant-event notifications.
 */
class m260410_000000_fort_alerts extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%fort_alerts}}')) {
            return true;
        }

        $this->createTable('{{%fort_alerts}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->null(),
            'alertType' => $this->string(64)->notNull(),
            'clientIp' => $this->string(45)->notNull(),
            'requestPath' => $this->string(1024)->null(),
            'payload' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%fort_alerts}}', ['alertType', 'dateCreated']);
        $this->createIndex(null, '{{%fort_alerts}}', ['clientIp', 'dateCreated']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%fort_alerts}}');

        return true;
    }
}
