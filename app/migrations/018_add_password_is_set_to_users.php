<?php

class Add_password_is_set_to_users
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
        if (!$columns || !in_array('password', $columns, true)) {
            throw new RuntimeException('The users table or its password column does not exist; no changes were made.');
        }
        if (!in_array('password_is_set', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `users` ADD COLUMN `password_is_set` TINYINT(1) NULL DEFAULT NULL AFTER `password`'
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
        if (in_array('password_is_set', $columns, true)) {
            $this->_lava->db->raw('ALTER TABLE `users` DROP COLUMN `password_is_set`');
        }
    }
}
