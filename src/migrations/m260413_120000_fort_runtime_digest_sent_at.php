<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;

/**
 * Tracks last automatic digest sends for activity-based (pseudo-cron) scheduling.
 */
class m260413_120000_fort_runtime_digest_sent_at extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%fort_runtime}}')) {
            return true;
        }

        if (!$this->db->columnExists('{{%fort_runtime}}', 'lastDailyDigestSentAt')) {
            $this->addColumn('{{%fort_runtime}}', 'lastDailyDigestSentAt', $this->dateTime()->null()->after('httpRateLimitAlertWindowMinutes'));
        }
        if (!$this->db->columnExists('{{%fort_runtime}}', 'lastWeeklyDigestSentAt')) {
            $this->addColumn('{{%fort_runtime}}', 'lastWeeklyDigestSentAt', $this->dateTime()->null()->after('lastDailyDigestSentAt'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%fort_runtime}}')) {
            if ($this->db->columnExists('{{%fort_runtime}}', 'lastWeeklyDigestSentAt')) {
                $this->dropColumn('{{%fort_runtime}}', 'lastWeeklyDigestSentAt');
            }
            if ($this->db->columnExists('{{%fort_runtime}}', 'lastDailyDigestSentAt')) {
                $this->dropColumn('{{%fort_runtime}}', 'lastDailyDigestSentAt');
            }
        }

        return true;
    }
}
