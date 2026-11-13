<?php

class Add_emergency_contact_numbers_to_website_content_settings
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
            ['website_content_settings']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns) {
            throw new RuntimeException('The website_content_settings table is missing; no schema changes were made.');
        }

        $definitions = [
            'emergency_national_phone' => "VARCHAR(50) NOT NULL DEFAULT '911'",
            'emergency_municipal_phone' => "VARCHAR(50) NOT NULL DEFAULT '+63 966 028 2662'",
            'emergency_red_cross_phone' => "VARCHAR(50) NOT NULL DEFAULT '143'",
        ];
        foreach ($definitions as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                $this->_lava->db->raw(
                    'ALTER TABLE `website_content_settings` ADD COLUMN `' . $column . '` ' . $definition
                );
            }
        }
    }

    public function down()
    {
        $columns = $this->_lava->db->raw(
            'SELECT COLUMN_NAME FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            ['website_content_settings']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns) return;

        foreach (['emergency_red_cross_phone', 'emergency_municipal_phone', 'emergency_national_phone'] as $column) {
            if (in_array($column, $columns, true)) {
                $this->_lava->db->raw(
                    'ALTER TABLE `website_content_settings` DROP COLUMN `' . $column . '`'
                );
            }
        }
    }
}
