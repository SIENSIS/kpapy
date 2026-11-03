<?php

namespace Kpapy\Demo\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBookTag extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'book_id' => ['type' => 'INT', 'unsigned' => true],
            'tag_id'  => ['type' => 'INT', 'unsigned' => true],
        ]);
        $this->forge->addPrimaryKey(['book_id', 'tag_id']);
        $this->forge->addForeignKey('book_id', 'kpapy_demo_books', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('tag_id', 'kpapy_demo_tags', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('kpapy_demo_book_tag');
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_demo_book_tag', true);
    }
}
