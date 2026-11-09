<?php

class Create_email_verification_tokens_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('email_verification_tokens')) {
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
                'user_id' => [
                    'type' => 'INT',
                    'unsigned' => TRUE,
                    'null' => FALSE,
                ],
                'code_hash' => [
                    'type' => 'CHAR',
                    'constraint' => 64,
                    'null' => FALSE,
                ],
                'expires_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                ],
                'sent_at' => [
                    'type' => 'DATETIME',
                    'null' => FALSE,
                ],
                'attempts' => [
                    'type' => 'TINYINT',
                    'unsigned' => TRUE,
                    'null' => FALSE,
                    'default' => 0,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('user_id', unique: TRUE, name: 'user_id_unique')
            ->create_table('email_verification_tokens');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('email_verification_tokens');
    }
}
