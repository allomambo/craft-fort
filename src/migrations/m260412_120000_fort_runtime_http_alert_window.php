<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;

/**
 * Adds HTTP rate-limit alert window overrides to {@see \allomambo\fort\records\FortRuntimeRecord}.
 */
class m260412_120000_fort_runtime_http_alert_window extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%fort_runtime}}', 'httpRateLimitAlertsBeforeBlock')) {
            return true;
        }

        $this->addColumn('{{%fort_runtime}}', 'httpRateLimitAlertsBeforeBlock', $this->integer()->null()->after('maxRequestsPerIpPerMinute'));
        $this->addColumn('{{%fort_runtime}}', 'httpRateLimitAlertWindowMinutes', $this->integer()->null()->after('httpRateLimitAlertsBeforeBlock'));

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%fort_runtime}}', 'httpRateLimitAlertWindowMinutes')) {
            $this->dropColumn('{{%fort_runtime}}', 'httpRateLimitAlertWindowMinutes');
        }
        if ($this->db->columnExists('{{%fort_runtime}}', 'httpRateLimitAlertsBeforeBlock')) {
            $this->dropColumn('{{%fort_runtime}}', 'httpRateLimitAlertsBeforeBlock');
        }

        return true;
    }
}
