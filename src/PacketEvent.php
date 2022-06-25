<?php

namespace icy8\SocketIO;
/**
 * 代码来自think-swoole
 * Class PacketEvent
 * @package icy8\SocketIO
 */
class PacketEvent
{
    public $type;
    public $data;

    public function __construct($type, $data)
    {
        $this->type = $type;
        $this->data = $data;
    }
}
