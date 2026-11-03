<?php

namespace Kpapy\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Reptes de segon factor de l'inici de sessió de les apps (php spark migrate -n Kpapy).
 */
class CreateLoginChallenges extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'VARCHAR', 'constraint' => 64],
            'user_id'     => ['type' => 'INT', 'unsigned' => true],
            'method'      => ['type' => 'VARCHAR', 'constraint' => 20],
            'device'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45],
            'scopes'      => ['type' => 'TEXT', 'null' => true],
            'code_hash'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'code_secret' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'attempts'    => ['type' => 'INT', 'constraint' => 3, 'default' => 0],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'pending'],
            'created_at'  => ['type' => 'DATETIME'],
            'expires_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['user_id', 'status']);
        $this->forge->createTable('kpapy_login_challenges', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('kpapy_login_challenges', true);
    }
}
