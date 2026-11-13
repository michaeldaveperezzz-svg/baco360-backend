<?php

class Create_emergency_contacts_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $tableExists = $this->_lava->db->raw(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            ['emergency_contacts']
        )->fetchColumn();
        if (!$tableExists) {
            $this->_lava->db->raw(
                'CREATE TABLE emergency_contacts (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(120) NOT NULL,
                    description VARCHAR(255) NULL,
                    phone_number VARCHAR(50) NOT NULL,
                    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY emergency_contacts_order_idx (sort_order, id)
                ) ENGINE=InnoDB'
            );
        }

        $contactCount = (int) $this->_lava->db->raw(
            'SELECT COUNT(*) FROM emergency_contacts'
        )->fetchColumn();
        if ($contactCount > 0) return;

        $settingsColumns = $this->_lava->db->raw(
            'SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['website_content_settings']
        )->fetchAll(PDO::FETCH_COLUMN);
        $legacyFields = [
            'emergency_national_phone',
            'emergency_municipal_phone',
            'emergency_red_cross_phone',
        ];
        $legacySettings = count(array_intersect($legacyFields, $settingsColumns)) === count($legacyFields)
            ? $this->_lava->db->raw(
                'SELECT emergency_national_phone, emergency_municipal_phone, emergency_red_cross_phone
                 FROM website_content_settings WHERE id = 1 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC)
            : [];
        $numbers = [
            $legacySettings['emergency_national_phone'] ?? '911',
            $legacySettings['emergency_municipal_phone'] ?? '+63 966 028 2662',
            $legacySettings['emergency_red_cross_phone'] ?? '143',
        ];
        $defaults = [
            ['National Emergency Hotline', 'Police, fire, and medical emergencies', $numbers[0]],
            ['Baco Municipal Hall', 'Contact the municipal office', $numbers[1]],
            ['Philippine Red Cross', 'Red Cross hotline', $numbers[2]],
        ];
        foreach ($defaults as $index => $contact) {
            $this->_lava->db->raw(
                'INSERT INTO emergency_contacts (name, description, phone_number, sort_order, created_at)
                 VALUES (?, ?, ?, ?, NOW())',
                [$contact[0], $contact[1], $contact[2], $index + 1]
            );
        }
    }

    public function down()
    {
        $this->_lava->db->raw('DROP TABLE IF EXISTS emergency_contacts');
    }
}
