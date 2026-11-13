<?php

class Add_auth_version_to_users
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $columns = $this->_lava->db->raw(
            'SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['users']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns || !in_array('is_active', $columns, true)) {
            throw new RuntimeException('The users table or its is_active column does not exist; no changes were made.');
        }
        if (!in_array('auth_version', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `users` ADD COLUMN `auth_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_active`'
            );
        }
    }

    public function down()
    {
        $columns = $this->_lava->db->raw(
            'SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['users']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('auth_version', $columns, true)) {
            $this->_lava->db->raw('ALTER TABLE `users` DROP COLUMN `auth_version`');
        }
    }
}
