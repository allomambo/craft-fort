<?php

namespace allomambo\fort\migrations;

use craft\db\Migration;

class m260416_120000_fort_date_created_indexes extends Migration
{
    public function safeUp(): bool
    {
        $this->createIndex(null, '{{%fort_security_events}}', ['dateCreated']);
        $this->createIndex(null, '{{%fort_alerts}}', ['dateCreated']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropIndex($this->db->getIndexName('{{%fort_security_events}}', 'dateCreated'), '{{%fort_security_events}}');
        $this->dropIndex($this->db->getIndexName('{{%fort_alerts}}', 'dateCreated'), '{{%fort_alerts}}');

        return true;
    }
}
