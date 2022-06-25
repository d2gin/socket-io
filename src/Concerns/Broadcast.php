<?php

namespace icy8\SocketIO\Concerns;

use Workerman\Connection\TcpConnection;

trait Broadcast
{
    protected $to          = [];
    protected $isBroadcast = false;

    /**
     * 推送给谁
     * 对内的推送是按socketid
     * engine推送是按chennel
     * @param $values
     * @return $this
     */
    public function to($values)
    {
        $values = is_string($values) || is_int($values) ? func_get_args() : $values;
        foreach ($values as $value) {
            if (!in_array($value, $this->to)) {
                $this->to[] = $value;
            }
        }
        return $this;
    }

    /**
     * 向客户端推送消息
     * @param $data
     */
    protected function broadcast($data)
    {
        try {
            $to = array_filter($this->to, function ($v) {
                return $v !== $this->id;
            });
            foreach ($to as $recv) {
                if (!isset($this->engine->clients[$recv])) continue;
                $socket = $this->engine->clients[$recv];
                $socket->emit($data);
            }
            $this->reset();
        } catch (\Exception $e) {
            $this->reset();
            return false;
        }
        return true;
    }

    /**
     * 恢复为初始值
     */
    protected function reset()
    {
        $this->to          = [];
        $this->isBroadcast = false;
    }
}