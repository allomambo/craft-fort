<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;
use yii\db\Expression;

/**
 * Block metadata: last block time (sort), permanent escalation, nullable runtime duration override.
 */
class m260411_120000_fort_blocking_meta extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%fort_blocked_ips}}')) {
            if (!$this->db->columnExists('{{%fort_blocked_ips}}', 'lastBlockedAt')) {
                $this->addColumn('{{%fort_blocked_ips}}', 'lastBlockedAt', $this->dateTime()->null()->after('lastReason'));
            }
            if (!$this->db->columnExists('{{%fort_blocked_ips}}', 'isPermanent')) {
                $this->addColumn('{{%fort_blocked_ips}}', 'isPermanent', $this->boolean()->notNull()->defaultValue(false)->after('lastBlockedAt'));
            }
            if (!$this->db->columnExists('{{%fort_blocked_ips}}', 'nextPermanentThreshold')) {
                $this->addColumn('{{%fort_blocked_ips}}', 'nextPermanentThreshold', $this->integer()->null()->after('isPermanent'));
            }

            if ($this->db->columnExists('{{%fort_blocked_ips}}', 'lastBlockedAt')) {
                $this->update('{{%fort_blocked_ips}}', ['lastBlockedAt' => new Expression('[[dateUpdated]]')], ['lastBlockedAt' => null]);
            }

            $this->createIndex(null, '{{%fort_blocked_ips}}', ['lastBlockedAt']);
        }

        if ($this->db->tableExists('{{%fort_runtime}}') && $this->db->columnExists('{{%fort_runtime}}', 'defaultBlockDurationMinutes')) {
            $this->alterColumn('{{%fort_runtime}}', 'defaultBlockDurationMinutes', $this->integer()->null());
            $this->update('{{%fort_runtime}}', ['defaultBlockDurationMinutes' => null], ['id' => 1], [], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%fort_blocked_ips}}')) {
            if ($this->db->columnExists('{{%fort_blocked_ips}}', 'nextPermanentThreshold')) {
                $this->dropColumn('{{%fort_blocked_ips}}', 'nextPermanentThreshold');
            }
            if ($this->db->columnExists('{{%fort_blocked_ips}}', 'isPermanent')) {
                $this->dropColumn('{{%fort_blocked_ips}}', 'isPermanent');
            }
            if ($this->db->columnExists('{{%fort_blocked_ips}}', 'lastBlockedAt')) {
                $this->dropColumn('{{%fort_blocked_ips}}', 'lastBlockedAt');
            }
        }

        if ($this->db->tableExists('{{%fort_runtime}}')) {
            $this->alterColumn('{{%fort_runtime}}', 'defaultBlockDurationMinutes', $this->integer()->notNull()->defaultValue(60));
        }

        return true;
    }
}
