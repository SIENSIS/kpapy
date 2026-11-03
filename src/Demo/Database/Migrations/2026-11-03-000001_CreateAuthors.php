<?php

namespace Kpapy\Demo\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAuthors extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'country'    => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'born'       => ['type' => 'DATE', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('kpapy_demo_authors');
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_demo_authors', true);
    }
}
