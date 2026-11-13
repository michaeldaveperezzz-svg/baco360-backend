<?php

class Add_session_metadata_to_refresh_tokens
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
            ['refresh_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns || !in_array('user_id', $columns, true) || !in_array('jti', $columns, true)) {
            throw new RuntimeException('The refresh_tokens table is missing required columns; no changes were made.');
        }

        if (!in_array('session_id', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `refresh_tokens` ADD COLUMN `session_id` CHAR(32) NULL AFTER `jti`'
            );
        }
        if (!in_array('created_at', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `refresh_tokens` ADD COLUMN `created_at` DATETIME NULL DEFAULT NULL AFTER `session_id`'
            );
        }
        if (!in_array('ip_address', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `refresh_tokens` ADD COLUMN `ip_address` VARCHAR(45) NULL DEFAULT NULL AFTER `created_at`'
            );
        }
        if (!in_array('user_agent', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `refresh_tokens` ADD COLUMN `user_agent` VARCHAR(1000) NULL DEFAULT NULL AFTER `ip_address`'
            );
        }

        $this->_lava->db->raw(
            'UPDATE `refresh_tokens` SET `session_id` = LEFT(`jti`, 32)
             WHERE `session_id` IS NULL OR `session_id` = \'\''
        );

        $indexes = $this->_lava->db->raw(
            'SELECT INDEX_NAME FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['refresh_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('refresh_tokens_user_session_idx', $indexes, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `refresh_tokens` ADD KEY `refresh_tokens_user_session_idx` (`user_id`, `session_id`)'
            );
        }
    }

    public function down()
    {
        $columns = $this->_lava->db->raw(
            'SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['refresh_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns) return;

        $indexes = $this->_lava->db->raw(
            'SELECT INDEX_NAME FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['refresh_tokens']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('refresh_tokens_user_session_idx', $indexes, true)) {
            $this->_lava->db->raw('ALTER TABLE `refresh_tokens` DROP INDEX `refresh_tokens_user_session_idx`');
        }
        foreach (['user_agent', 'ip_address', 'created_at', 'session_id'] as $column) {
            if (in_array($column, $columns, true)) {
                $this->_lava->db->raw('ALTER TABLE `refresh_tokens` DROP COLUMN `' . $column . '`');
            }
        }
    }
}
