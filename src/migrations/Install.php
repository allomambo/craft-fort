<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;
use craft\helpers\StringHelper;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%fort_security_events}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->null(),
            'type' => $this->string(64)->notNull(),
            'clientIp' => $this->string(45)->notNull(),
            'requestPath' => $this->string(1024)->null(),
            'meta' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%fort_security_events}}', ['type', 'dateCreated']);
        $this->createIndex(null, '{{%fort_security_events}}', ['clientIp', 'dateCreated']);
        $this->createIndex(null, '{{%fort_security_events}}', ['dateCreated']);

        $this->createTable('{{%fort_runtime}}', [
            'id' => $this->primaryKey(),
            'defaultBlockDurationMinutes' => $this->integer()->null(),
            'failedLoginThreshold' => $this->integer()->null(),
            'failedLoginWindowMinutes' => $this->integer()->null(),
            'maxRequestsPerIpPerMinute' => $this->integer()->null(),
            'httpRateLimitAlertsBeforeBlock' => $this->integer()->null(),
            'httpRateLimitAlertWindowMinutes' => $this->integer()->null(),
            'lastDailyDigestSentAt' => $this->dateTime()->null(),
            'lastWeeklyDigestSentAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->insert('{{%fort_runtime}}', [
            'id' => 1,
            'defaultBlockDurationMinutes' => null,
            'failedLoginThreshold' => null,
            'failedLoginWindowMinutes' => null,
            'maxRequestsPerIpPerMinute' => null,
            'httpRateLimitAlertsBeforeBlock' => null,
            'httpRateLimitAlertWindowMinutes' => null,
            'lastDailyDigestSentAt' => null,
            'lastWeeklyDigestSentAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        $this->createTable('{{%fort_blocked_ips}}', [
            'id' => $this->primaryKey(),
            'clientIp' => $this->string(45)->notNull(),
            'blocked' => $this->boolean()->notNull()->defaultValue(true),
            'blockedUntil' => $this->dateTime()->null(),
            'blockCount' => $this->integer()->notNull()->unsigned()->defaultValue(0),
            'manual' => $this->boolean()->notNull()->defaultValue(false),
            'notes' => $this->text()->null(),
            'lastReason' => $this->string(32)->null(),
            'lastBlockedAt' => $this->dateTime()->null(),
            'isPermanent' => $this->boolean()->notNull()->defaultValue(false),
            'nextPermanentThreshold' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%fort_blocked_ips}}', ['clientIp'], true);
        $this->createIndex(null, '{{%fort_blocked_ips}}', ['blocked', 'blockedUntil']);
        $this->createIndex(null, '{{%fort_blocked_ips}}', ['lastBlockedAt']);

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
        $this->createIndex(null, '{{%fort_alerts}}', ['dateCreated']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%fort_alerts}}');
        $this->dropTableIfExists('{{%fort_blocked_ips}}');
        $this->dropTableIfExists('{{%fort_runtime}}');
        $this->dropTableIfExists('{{%fort_security_events}}');

        return true;
    }
}
