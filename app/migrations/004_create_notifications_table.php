<?php

class Create_notifications_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('notifications')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'auto_increment' => TRUE,
                    'null' => FALSE,
                ],
                'recipient_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'report_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => TRUE,
                ],
                'actor_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => TRUE,
                ],
                'event_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 40,
                    'null' => FALSE,
                ],
                'priority' => [
                    'type' => 'VARCHAR',
                    'constraint' => 10,
                    'null' => FALSE,
                    'default' => 'medium',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'read_at' => [
                    'type' => 'DATETIME',
                    'null' => TRUE,
                    'default' => NULL,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key(['recipient_id', 'id'], name: 'recipient_notifications_idx')
            ->add_key('report_id', name: 'report_notifications_idx')
            ->create_table('notifications');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('notifications');
    }
}
