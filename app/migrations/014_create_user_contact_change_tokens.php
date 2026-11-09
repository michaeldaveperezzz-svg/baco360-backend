<?php

class Create_user_contact_change_tokens
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $userEngine = strtolower((string) $this->_lava->db->raw(
            'SELECT engine FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['users']
        )->fetchColumn());
        if ($userEngine !== 'innodb') {
            throw new RuntimeException('The users table must exist and use InnoDB; no schema changes were made.');
        }

        $userIdType = strtolower((string) $this->_lava->db->raw(
            'SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['users', 'id']
        )->fetchColumn());
        if (!in_array($userIdType, ['int unsigned', 'bigint unsigned'], true)) {
            throw new RuntimeException('The users.id type is unsupported; no schema changes were made.');
        }

        $tableExists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['user_contact_change_tokens']
        )->fetchColumn();
        if ((int) $tableExists !== 0) {
            throw new RuntimeException('The user_contact_change_tokens table already exists; no schema changes were made.');
        }

        $this->_lava->db->raw(
            'CREATE TABLE user_contact_change_tokens (
                user_id ' . $userIdType . ' NOT NULL,
                pending_email VARCHAR(150) NULL,
                pending_phone VARCHAR(30) NULL,
                phone_change_requested TINYINT(1) NOT NULL DEFAULT 0,
                account_code_hash CHAR(64) NOT NULL,
                email_code_hash CHAR(64) NULL,
                expires_at DATETIME NOT NULL,
                sent_at DATETIME NOT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id),
                CONSTRAINT user_contact_change_tokens_user_fk
                    FOREIGN KEY (user_id) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    public function down()
    {
    }
}
