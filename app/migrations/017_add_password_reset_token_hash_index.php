<?php

class Add_password_reset_token_hash_index
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $indexes = $this->_lava->db->raw(
            'SELECT INDEX_NAME FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['password_reset_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$indexes) {
            throw new RuntimeException('The password_reset_tokens table does not exist; no changes were made.');
        }
        if (!in_array('token_hash_idx', $indexes, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE password_reset_tokens ADD INDEX token_hash_idx (token_hash)'
            );
        }
    }

    public function down()
    {
        $indexes = $this->_lava->db->raw(
            'SELECT INDEX_NAME FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['password_reset_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('token_hash_idx', $indexes, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE password_reset_tokens DROP INDEX token_hash_idx'
            );
        }
    }
}
