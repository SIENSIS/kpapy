<?php

namespace Kpapy\Demo\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBookTranslations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'book_id' => ['type' => 'INT', 'unsigned' => true],
            'locale'  => ['type' => 'VARCHAR', 'constraint' => 5],
            'summary' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['book_id', 'locale']);
        $this->forge->addForeignKey('book_id', 'kpapy_demo_books', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('kpapy_demo_book_translations');
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_demo_book_translations', true);
    }
}
