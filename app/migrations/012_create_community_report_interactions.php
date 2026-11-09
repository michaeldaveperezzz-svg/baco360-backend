<?php

class Create_community_report_interactions
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $tableNames = ['community_report_reactions', 'community_report_comments'];
        $existingTables = $this->_lava->db->raw(
            'SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
            $tableNames
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($existingTables) {
            if (count($existingTables) === count($tableNames)) return;
            throw new RuntimeException('Some community report tables already exist; no schema changes were made.');
        }

        $reportIdType = strtolower((string) $this->_lava->db->raw(
            'SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['reports', 'id']
        )->fetchColumn());
        $userIdType = strtolower((string) $this->_lava->db->raw(
            'SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['users', 'id']
        )->fetchColumn());
        if (!in_array($reportIdType, ['int unsigned', 'bigint unsigned'], true)
            || !in_array($userIdType, ['int unsigned', 'bigint unsigned'], true)) {
            throw new RuntimeException('The report or user ID type is unsupported; no schema changes were made.');
        }

        $this->_lava->db->raw(
            'CREATE TABLE community_report_reactions (
                report_id ' . $reportIdType . ' NOT NULL,
                user_id ' . $userIdType . ' NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (report_id, user_id),
                KEY community_report_reactions_user_idx (user_id),
                CONSTRAINT community_report_reactions_report_fk
                    FOREIGN KEY (report_id) REFERENCES reports (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT community_report_reactions_user_fk
                    FOREIGN KEY (user_id) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
        $this->_lava->db->raw(
            'CREATE TABLE community_report_comments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                report_id ' . $reportIdType . ' NOT NULL,
                user_id ' . $userIdType . ' NOT NULL,
                comment TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY community_report_comments_report_created_idx (report_id, created_at, id),
                KEY community_report_comments_user_idx (user_id),
                CONSTRAINT community_report_comments_report_fk
                    FOREIGN KEY (report_id) REFERENCES reports (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT community_report_comments_user_fk
                    FOREIGN KEY (user_id) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    public function down()
    {
    }
}
