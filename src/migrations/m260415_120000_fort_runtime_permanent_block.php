<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;

/**
 * Adds permanent-block threshold runtime override to {@see \allomambo\fort\records\FortRuntimeRecord}.
 */
class m260415_120000_fort_runtime_permanent_block extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%fort_runtime}}', 'permanentBlockAfterAutomaticBlocks')) {
            return true;
        }

        $this->addColumn('{{%fort_runtime}}', 'permanentBlockAfterAutomaticBlocks', $this->integer()->null()->after('defaultBlockDurationMinutes'));

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%fort_runtime}}', 'permanentBlockAfterAutomaticBlocks')) {
            $this->dropColumn('{{%fort_runtime}}', 'permanentBlockAfterAutomaticBlocks');
        }

        return true;
    }
}
