<?php

class Add_archived_at_to_announcements
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
            ['announcements']
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$columns) {
            throw new RuntimeException('The announcements table does not exist; no changes were made.');
        }
        if (!in_array('archived_at', $columns, true)) {
            $this->_lava->db->raw(
                'ALTER TABLE announcements ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER updated_at'
            );
        }
    }

    public function down()
    {
    }
}
