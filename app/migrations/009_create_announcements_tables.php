<?php

class Create_announcements_tables
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $existingTables = $this->_lava->db->raw(
            'SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
            ['announcements', 'announcement_recipients']
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($existingTables) {
            if (count($existingTables) === 2) {
                return;
            }
            throw new RuntimeException('Only one announcement table exists; no schema changes were made.');
        }

        $userIdType = strtolower((string) $this->_lava->db->raw(
            'SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['users', 'id']
        )->fetchColumn());
        if (!in_array($userIdType, ['int unsigned', 'bigint unsigned'], true)) {
            throw new RuntimeException('The users.id type is unsupported for announcement tables; no schema changes were made.');
        }

        $this->_lava->db->raw(
            'CREATE TABLE announcements (
                id ' . $userIdType . ' NOT NULL AUTO_INCREMENT,
                created_by ' . $userIdType . ' NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY announcements_created_by_idx (created_by),
                CONSTRAINT announcements_created_by_fk
                    FOREIGN KEY (created_by) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB'
        );
        $this->_lava->db->raw(
            'CREATE TABLE announcement_recipients (
                announcement_id ' . $userIdType . ' NOT NULL,
                user_id ' . $userIdType . ' NOT NULL,
                read_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (announcement_id, user_id),
                KEY announcement_recipients_user_read_idx (user_id, read_at),
                CONSTRAINT announcement_recipients_announcement_fk
                    FOREIGN KEY (announcement_id) REFERENCES announcements (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT announcement_recipients_user_fk
                    FOREIGN KEY (user_id) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    public function down()
    {
    }
}
