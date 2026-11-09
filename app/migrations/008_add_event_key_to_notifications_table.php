<?php

class Add_event_key_to_notifications_table
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
            ['notifications']
        )->fetchAll(PDO::FETCH_COLUMN);
        $requiredColumns = ['channel', 'recipient', 'subject', 'message', 'status', 'attempts'];
        if (array_diff($requiredColumns, $columns)) {
            throw new RuntimeException('The notifications table does not match the email delivery schema; no changes were made.');
        }

        if (!in_array('event_key', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE `notifications` ADD COLUMN `event_key` VARCHAR(191) NULL DEFAULT NULL'
            );
        }

        $indexExists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ?
               AND index_name = ? AND column_name = ? AND non_unique = 0',
            ['notifications', 'notifications_event_key_unique', 'event_key']
        )->fetchColumn();
        if ((int) $indexExists === 0) {
            $this->_lava->db->raw(
                'ALTER TABLE `notifications` ADD UNIQUE KEY `notifications_event_key_unique` (`event_key`)'
            );
        }
    }

    public function down()
    {
    }
}