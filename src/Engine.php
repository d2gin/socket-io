<?php

namespace icy8\SocketIO;

use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * @method Emitter on($event_name, $listener)
 * @method Emitter once($event_name, $listener)
 * Class Engine
 * @package icy8\SocketIO
 */
class Engine
{
    public    $pingInterval = 20;  // 心跳包发包周期
    public    $pingTimeout  = 40;  // 心跳包响应超时时间
    public    $token        = '';
    public    $clients      = [];
    public    $rooms        = [];
    protected $to           = [];
    /* @var Worker $worker */
    public    $worker;
    public    $config;
    protected $event;

    public function __construct($config = [])
    {
        $this->pingInterval = $config['ping_interval'] ?? 20;
        $this->pingTimeout  = $config['ping_timeout'] ?? 40;
        $this->token        = $config['token'] ?? '';
        $this->config       = $config;
        $this->event        = new Emitter();
    }

    /**
     * 加入房间/频道
     * @param $room
     * @param $id
     * @param bool $data
     * @return $this
     */
    public function join($room, $id, $data = true)
    {
        // 同一个客户端只能加入一次
        if (!isset($this->rooms[$room][$id])) {
            $this->rooms[$room][$id] = $data;
        }
        return $this;
    }

    /**
     * 退出房间
     * @param $room
     * @param null $id
     * @return $this
     */
    public function leave($room, $id = null)
    {
        if (!isset($this->rooms[$room])) {
            return $this;
        } else if ($id) {// 注销某个客户端
            unset($this->rooms[$room][$id]);
        } else {// 注销房间所有客户端
            unset($this->rooms[$room]);
        }
        return $this;
    }

    /**
     * 给某一组客户端发送消息
     * @param $room
     * @return $this
     */
    public function to($room)
    {
        $list = is_array($room) ? $room : [$room];
        foreach ($list as $item) {
            if (!in_array($item, $this->to)) {
                $this->to[] = $item;
            }
        }
        return $this;
    }

    /**
     * 获取组员客户端
     * @param $room
     * @param null $id
     * @return mixed
     */
    public function fetchRooms($room, $id = null)
    {
        if ($id) {
            return $this->rooms[$room][$id] ?? null;
        }
        return $this->rooms[$room] ?? [];
    }

    public function broadcast()
    {
        $this->to(array_keys($this->rooms));
        return $this;
    }

    /**
     * 解绑事件
     * @param $event
     * @param null $listener
     * @return $this
     */
    public function off($event, $listener = null)
    {
        if ($listener === null) {
            $this->event->removeAllListeners($event);
        } else $this->event->removeListener($event, $listener);
        return $this;
    }

    public function emit($event_name = null)
    {
        $args = func_get_args();
        if (!empty($this->to)) {
            foreach ($this->to as $room) {
                // 获取所有组员
                $members = $this->rooms[$room] ?? [];
                // 给组员推送消息
                foreach ($members as $socketid => $member) {
                    $socket = $this->clients[$socketid] ?? null;
                    if (!$socket) continue;
                    call_user_func_array([$socket, "emit"], $args);
                }
            }
        } else {
            foreach ($this->clients as $socket) {
                call_user_func_array([$socket, "emit"], $args);
            }
        }
        $this->to = [];
        return true;
    }

    public function attach($server)
    {
        $this->worker                = $server;
        $this->worker->onConnect     = [$this, "handleConnect"];
        $this->worker->onWorkerStart = [$this, "handleWorkerStart"];
        $this->worker->onWorkerStop  = [$this, "handleWorkerStop"];
    }

    /**
     * worker启动事件
     * @return mixed
     */
    public function handleWorkerStart()
    {
        return call_user_func_array([$this, "emit"], ["workerStart"] + func_get_args());
    }

    /**
     * worker停止事件
     * @return mixed
     */
    public function handleWorkerStop()
    {
        return call_user_func_array([$this, "emit"], ["workerStop"] + func_get_args());
    }

    /**
     * 客户端连接
     * @param TcpConnection $connection
     */
    public function handleConnect(TcpConnection $connection)
    {
        $connection->onWebSocketConnect = [$this, "handleWebSocketConnect"];
    }

    /**
     * websocket连接
     * @param TcpConnection $connection
     * @param $request_buffer
     */
    public function handleWebSocketConnect(TcpConnection $connection, $request_buffer)
    {
        $request             = new Request($connection, $request_buffer);
        $connection->onClose = function ($connection) {
            unset($this->clients[$connection->id]);
        };
        $this->handshake($connection, $request);
    }

    /**
     * 订阅
     * @param Socket $socket
     * @return \Closure
     */
    public function handleSubcribe(Socket $socket)
    {
        return function ($data) use ($socket) {
            $channel = $data['channel'] ?? '';
            $data    = $data['data'] ?? '';
            if ($channel) {
                $this->join($channel, $socket->id, $data);
                $socket->emit("subcribe_success", $channel, $data);
                $this->event->emit("subcribe", $channel, $data);
            } else {
                $socket->emit("subcribe_error", ['channel' => $channel, 'data' => $data, 'reason' => "Channel cannot be empty."]);
            }
        };
    }

    /**
     * @param Socket $socket
     * @return \Closure
     */
    protected function handleMessage(Socket $socket)
    {
        return function ($connection, $data) use ($socket) {
            $this->event->emit("message", $connection, $data, $socket);
            $socket->onMessage($data);
        };
    }

    public function handleDisconnect($socket)
    {
        return function () use ($socket) {
            $this->event->emit('disconnect', $socket);
            // 客户端离线 注销订阅
            foreach ($this->rooms as $room => $members) {
                foreach ($members as $socketid => $data) {
                    if ($socketid === $socket->id) {
                        $this->leave($room, $socketid);
                        $this->event->emit("unsubcribe", $room, $data);
                    }
                }
            }
        };
    }

    public function handshake(TcpConnection $connection, Request $request)
    {
        $socket = new Socket(
            $connection->id,
            $this,
            $this->worker,
            $request
        );
        // 缓存客户端连接
        $this->clients[$connection->id] = $socket;

        $connection->onMessage = $this->handleMessage($socket);
        $socket->on("subcribe", $this->handleSubcribe($socket));
        $socket->on("unsubcribe", $this->handleDisconnect($socket));
        $socket->on("disconnect", $this->handleDisconnect($socket));
        $socket->onOpen();
        $this->event->emit("connection", $socket);
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->event, $name)) {
            return call_user_func_array([$this->event, $name], $arguments);
        }
    }
}
