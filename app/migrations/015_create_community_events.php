<?php

class Create_community_events
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
        if (!preg_match('/^(?:int|bigint)(?:\(\d+\))? unsigned$/', $userIdType)) {
            throw new RuntimeException('The users.id type is unsupported for community events; no schema changes were made.');
        }

        $tableExists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['community_events']
        )->fetchColumn();
        if ((int) $tableExists !== 0) {
            throw new RuntimeException('The community_events table already exists; no schema changes were made.');
        }

        $this->_lava->db->raw(
            'CREATE TABLE community_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_by ' . $userIdType . ' NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                location VARCHAR(255) NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY community_events_starts_at_idx (starts_at),
                KEY community_events_created_by_idx (created_by),
                CONSTRAINT community_events_created_by_fk
                    FOREIGN KEY (created_by) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB'
        );
    }

    public function down()
    {
    }
}
