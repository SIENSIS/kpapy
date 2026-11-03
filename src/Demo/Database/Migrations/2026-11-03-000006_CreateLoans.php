<?php

namespace Kpapy\Demo\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Loans of the demo users ("my loans": where(user_id)).
 */
class CreateLoans extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'book_id'     => ['type' => 'INT', 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true],
            'loaned_at'   => ['type' => 'DATETIME'],
            'due_at'      => ['type' => 'DATE'],
            'returned_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('user_id');
        $this->forge->addForeignKey('book_id', 'kpapy_demo_books', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('kpapy_demo_loans');
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_demo_loans', true);
    }
}
