<?php

namespace Kpapy\Demo\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTags extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 40],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('kpapy_demo_tags');
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_demo_tags', true);
    }
}
