<?php

namespace Kpapy\Demo\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Books: 1-N author, N-N tags, translated summary, multiselect audience, a private
 * cover image, soft deletes.
 */
class CreateBooks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'author_id'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'isbn'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'year'       => ['type' => 'INT', 'null' => true],
            'pages'      => ['type' => 'INT', 'null' => true],
            'price'      => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0],
            'available'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'audience'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'cover'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('author_id', 'kpapy_demo_authors', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('kpapy_demo_books');
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_demo_books', true);
    }
}
