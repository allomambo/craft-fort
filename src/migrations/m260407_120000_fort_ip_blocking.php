<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;
use craft\helpers\StringHelper;

/**
 * Adds {@see \allomambo\fort\records\FortRuntimeRecord} and {@see \allomambo\fort\records\BlockedIpRecord} tables.
 */
class m260407_120000_fort_ip_blocking extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%fort_runtime}}')) {
            $this->createTable('{{%fort_runtime}}', [
                'id' => $this->primaryKey(),
                'defaultBlockDurationMinutes' => $this->integer()->notNull()->defaultValue(60),
                'failedLoginThreshold' => $this->integer()->null(),
                'failedLoginWindowMinutes' => $this->integer()->null(),
                'maxRequestsPerIpPerMinute' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->insert('{{%fort_runtime}}', [
                'id' => 1,
                'defaultBlockDurationMinutes' => 60,
                'failedLoginThreshold' => null,
                'failedLoginWindowMinutes' => null,
                'maxRequestsPerIpPerMinute' => null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        if (!$this->db->tableExists('{{%fort_blocked_ips}}')) {
            $this->createTable('{{%fort_blocked_ips}}', [
                'id' => $this->primaryKey(),
                'clientIp' => $this->string(45)->notNull(),
                'blocked' => $this->boolean()->notNull()->defaultValue(true),
                'blockedUntil' => $this->dateTime()->null(),
                'blockCount' => $this->integer()->notNull()->unsigned()->defaultValue(0),
                'manual' => $this->boolean()->notNull()->defaultValue(false),
                'notes' => $this->text()->null(),
                'lastReason' => $this->string(32)->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%fort_blocked_ips}}', ['clientIp'], true);
            $this->createIndex(null, '{{%fort_blocked_ips}}', ['blocked', 'blockedUntil']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%fort_blocked_ips}}');
        $this->dropTableIfExists('{{%fort_runtime}}');

        return true;
    }
}
