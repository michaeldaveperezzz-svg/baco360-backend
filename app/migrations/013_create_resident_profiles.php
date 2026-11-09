<?php

class Create_resident_profiles
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

        $profileTableExists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['resident_profiles']
        )->fetchColumn();
        if ((int) $profileTableExists !== 0) {
            throw new RuntimeException('The resident_profiles table already exists; no schema changes were made.');
        }

        $profilePhotoExists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['users', 'profile_photo']
        )->fetchColumn();
        if ((int) $profilePhotoExists === 0) {
            $this->_lava->db->raw(
                'ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL'
            );
        }

        $this->_lava->db->raw(
            'CREATE TABLE resident_profiles (
                id ' . $userIdType . ' NOT NULL AUTO_INCREMENT,
                user_id ' . $userIdType . ' NOT NULL,
                birth_date DATE NULL,
                gender VARCHAR(100) NULL,
                street VARCHAR(255) NULL,
                barangay VARCHAR(150) NULL,
                municipality VARCHAR(150) NOT NULL DEFAULT \'Baco\',
                province VARCHAR(150) NOT NULL DEFAULT \'Oriental Mindoro\',
                postal_code VARCHAR(20) NULL,
                landmark VARCHAR(255) NULL,
                preferred_contact_method ENUM(\'Email\', \'SMS\', \'Both\') NULL,
                is_profile_complete TINYINT(1) NOT NULL DEFAULT 0,
                address_verification_status ENUM(\'Pending\', \'Verified\', \'Rejected\') NOT NULL DEFAULT \'Pending\',
                verified_by ' . $userIdType . ' NULL,
                verified_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY resident_profiles_user_unique (user_id),
                KEY resident_profiles_verification_status_idx (address_verification_status),
                KEY resident_profiles_verified_by_idx (verified_by),
                CONSTRAINT resident_profiles_user_fk
                    FOREIGN KEY (user_id) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT resident_profiles_verified_by_fk
                    FOREIGN KEY (verified_by) REFERENCES users (id)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB'
        );
    }

    public function down()
    {
    }
}
