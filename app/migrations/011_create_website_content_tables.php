<?php

class Create_website_content_tables
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $tableNames = [
            'website_content_settings',
            'website_competencies',
            'website_landing_announcements',
            'website_gallery_images',
            'website_faqs',
        ];
        $existingTables = $this->_lava->db->raw(
            'SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?, ?)',
            $tableNames
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($existingTables) {
            if (count($existingTables) === count($tableNames)) {
                return;
            }
            throw new RuntimeException('Some website content tables already exist; no schema changes were made.');
        }

        $userIdType = strtolower((string) $this->_lava->db->raw(
            'SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['users', 'id']
        )->fetchColumn());
        if (!in_array($userIdType, ['int unsigned', 'bigint unsigned'], true)) {
            throw new RuntimeException('The users.id type is unsupported for website content; no schema changes were made.');
        }

        $this->_lava->db->raw(
            'CREATE TABLE website_content_settings (
                id TINYINT UNSIGNED NOT NULL DEFAULT 1,
                site_name VARCHAR(120) NOT NULL DEFAULT \'Baco360\',
                logo_path VARCHAR(500) NULL,
                primary_color CHAR(7) NULL,
                hero_title VARCHAR(255) NULL,
                hero_description TEXT NULL,
                hero_image_path VARCHAR(500) NULL,
                maintenance_enabled TINYINT(1) NOT NULL DEFAULT 0,
                maintenance_message VARCHAR(500) NULL,
                maintenance_starts_at DATETIME NULL,
                maintenance_ends_at DATETIME NULL,
                about_title VARCHAR(255) NULL,
                about_content TEXT NULL,
                contact_email VARCHAR(254) NULL,
                contact_phone VARCHAR(50) NULL,
                contact_address TEXT NULL,
                footer_text TEXT NULL,
                copyright_text VARCHAR(255) NULL,
                updated_by ' . $userIdType . ' NULL,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY website_content_settings_updated_by_idx (updated_by),
                CONSTRAINT website_content_settings_updated_by_fk
                    FOREIGN KEY (updated_by) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB'
        );
        $this->_lava->db->raw(
            'CREATE TABLE website_competencies (
                id ' . $userIdType . ' NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                description TEXT NULL,
                icon_name VARCHAR(80) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY website_competencies_active_order_idx (is_active, sort_order)
            ) ENGINE=InnoDB'
        );
        $this->_lava->db->raw(
            'CREATE TABLE website_landing_announcements (
                id ' . $userIdType . ' NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                image_path VARCHAR(500) NULL,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY website_landing_announcements_active_dates_idx (is_active, starts_at, ends_at),
                KEY website_landing_announcements_order_idx (sort_order)
            ) ENGINE=InnoDB'
        );
        $this->_lava->db->raw(
            'CREATE TABLE website_gallery_images (
                id ' . $userIdType . ' NOT NULL AUTO_INCREMENT,
                image_path VARCHAR(500) NOT NULL,
                alt_text VARCHAR(255) NOT NULL DEFAULT \'\',
                caption TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY website_gallery_images_active_order_idx (is_active, sort_order)
            ) ENGINE=InnoDB'
        );
        $this->_lava->db->raw(
            'CREATE TABLE website_faqs (
                id ' . $userIdType . ' NOT NULL AUTO_INCREMENT,
                question VARCHAR(500) NOT NULL,
                answer TEXT NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY website_faqs_active_order_idx (is_active, sort_order)
            ) ENGINE=InnoDB'
        );
    }

    public function down()
    {
    }
}
