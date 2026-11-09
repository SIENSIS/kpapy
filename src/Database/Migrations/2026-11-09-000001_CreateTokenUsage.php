<?php

namespace Kpapy\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Darrera adreça IP de cada token d'accés (php spark migrate -n Kpapy). Només s'omple amb
 * Config\Kpapy::$trackTokenIps = true.
 */
class CreateTokenUsage extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'token_id'   => ['type' => 'INT', 'unsigned' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45],
            'used_at'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('token_id');
        $this->forge->addKey('user_id');
        $this->forge->createTable('kpapy_token_usage', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_token_usage', true);
    }
}
