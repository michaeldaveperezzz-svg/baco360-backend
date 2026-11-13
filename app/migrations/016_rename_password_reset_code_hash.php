<?php

class Rename_password_reset_code_hash
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
            ['password_reset_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns) {
            throw new RuntimeException('The password_reset_tokens table does not exist; no changes were made.');
        }
        if (in_array('code_hash', $columns, true) && !in_array('token_hash', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE password_reset_tokens CHANGE COLUMN code_hash token_hash CHAR(64) NOT NULL'
            );
        } elseif (!in_array('token_hash', $columns, true)) {
            throw new RuntimeException('The password reset token hash column is missing; no changes were made.');
        }

    }

    public function down()
    {
        $columns = $this->_lava->db->raw(
            'SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['password_reset_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('token_hash', $columns, true) && !in_array('code_hash', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE password_reset_tokens CHANGE COLUMN token_hash code_hash CHAR(64) NOT NULL'
            );
        }
    }
}
