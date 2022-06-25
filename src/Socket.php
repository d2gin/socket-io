<?php

namespace icy8\SocketIO;

use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;

include __DIR__ . '/Concerns/Broadcast.php';

/**
 * @method Emitter on($event_name, $listener)
 * @method Emitter once($event_name, $listener)
 * Class Socket
 * @package icy8\SocketIO
 */
class Socket
{
    use \icy8\SocketIO\Concerns\Broadcast;

    public    $id;
    protected $pingIntervalTimer;
    protected $pingTimeoutTimer;
    protected $engine;
    /* @var Worker $worker */
    protected $worker;
    /* @var TcpConnection $client */
    public $client;
    /* @var Request $request */
    public $request;
    public $event;

    public function __construct($id, Engine $engine, Worker $worker, Request $request)
    {
        $this->id      = $id;
        $this->worker  = $worker;
        $this->engine  = $engine;
        $this->request = $request;
        $this->client  = $worker->connections[$id];
        $this->event   = new Emitter();
    }

    /**
     * 解析消息
     * 代码借鉴自think-swoole
     * @param $data
     */
    public function onMessage($data)
    {
        $enginePacket = EnginePacket::fromString($data);
        $this->setPingTimeout();
        switch ($enginePacket->type) {
            case EnginePacket::MESSAGE:
                $packet = Packet::fromString($enginePacket->data);
                switch ($packet->type) {
                    case Packet::CONNECT:
                        $this->onConnect($packet->data);
                        break;
                    case Packet::EVENT:
                        $result = call_user_func_array([$this->event, "emit"], $packet->data);
                        if ($packet->id !== null) {
                            $responsePacket = Packet::create(Packet::ACK, [
                                'id'   => $packet->id,
                                'nsp'  => $packet->nsp,
                                'data' => $result,
                            ]);
                            $this->push($responsePacket);
                        }
                        break;
                    case Packet::DISCONNECT:
                    default:
                        $this->close();
                        break;
                }
                break;
            case EnginePacket::PING:
                $this->push(EnginePacket::pong($enginePacket->data));
                break;
            case EnginePacket::PONG:
                $this->event->emit("pong", $enginePacket->data);
                break;
            default:
                $this->close();
                break;
        }
    }

    /**
     * 生成socketio消息体
     * 代码借鉴自think-swoole
     * @param $message
     * @return EnginePacket|Packet|string
     */
    public function encodeMessage($message)
    {
        if ($message instanceof PacketEvent) {
            $message = Packet::create(Packet::EVENT, [
                'data' => array_merge([$message->type], $message->data),
            ]);
        }

        if ($message instanceof Packet) {
            $message = EnginePacket::message($message->toString());
        }

        if ($message instanceof EnginePacket) {
            $message = $message->toString();
        }
        return $message;
    }

    /**
     * 客户端连接后发送一条握手信息
     * @return $this
     */
    public function onOpen()
    {
        $payload = json_encode([
            'sid'          => $this->id,
            'upgrades'     => ["websocket"],
            'pingInterval' => $this->engine->pingInterval * 1000,
            'pingTimeout'  => $this->engine->pingTimeout * 1000,
        ]);
        $this->push(EnginePacket::open($payload));
        $this->client->onClose = function () {
            $this->event->emit("disconnect");
        };
        return $this;
    }

    /**
     * 向客户端回复一条确认握手信息
     * @return $this
     */
    public function onConnect($data = null)
    {
        try {
            if ($this->engine->token && $this->engine->token !== @$data['token']) {
                // token验证
                throw new \Exception("Token authentication failed .");
            }
            $packet = Packet::create(Packet::CONNECT);
            if ($this->request->query['EIO'] >= 4) {
                $packet->data = ['sid' => $this->id];
            }
            // 让前端事件先响应
            $this->push($packet);
            // 心跳计划
            if ($this->pingIntervalTimer) {
                Timer::del($this->pingIntervalTimer);
            }
            Timer::add($this->engine->pingInterval, [$this, "schedulePing"]);
            // 后端事件后响应
            $this->event->emit("connect", $data);
        } catch (\Exception $exception) {
            $packet = Packet::create(Packet::CONNECT_ERROR, [
                'data' => ['message' => $exception->getMessage()],
            ]);
            $this->push($packet);
            $this->event->emit("connect_error");
        }
        return $this;
    }

    /**
     * 向客户端发送一个事件
     * @param $event
     */
    public function emit($event = null)
    {
        $args   = func_get_args();
        $packet = Packet::create(Packet::EVENT, [
            'data' => $args,
        ]);
        if ($this->isBroadcast) {
            return $this->broadcast($packet);
        }
        return $this->push($packet);
    }

    /**
     * 定时向客户端发送心跳包
     */
    public function schedulePing()
    {
        // 发送心跳包
        $this->push(EnginePacket::ping());
    }

    public function setPingTimeout()
    {
        if ($this->pingTimeoutTimer) {
            Timer::del($this->pingTimeoutTimer);
        }
        $this->pingTimeoutTimer = Timer::add($this->engine->pingInterval + $this->engine->pingTimeout, [$this, 'close'], ["ping timeout"]);
    }

    public function close($reason = '')
    {
        if ($this->pingIntervalTimer) {
            Timer::del($this->pingIntervalTimer);
        }
        if ($this->pingTimeoutTimer) {
            Timer::del($this->pingTimeoutTimer);
        }
        $this->push(Packet::create(Packet::DISCONNECT, ['data' => $reason]));
        $this->event->emit("disconnect", $reason);
        $this->event->emit("close", $reason);
        $this->client->close();
    }

    /**
     * 解绑socketio事件
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

    /**
     * 向客户端推送消息
     * @param $data
     */
    public function push($data)
    {
        try {
            $payload = $this->encodeMessage($data);
            $this->client->send($payload);
            $this->reset();
            unset($pyload);
        } catch (\Exception $e) {
//            var_dump($e->getMessage());
            return false;
        }
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->event, $name)) {
            return call_user_func_array([$this->event, $name], $arguments);
        }
    }

    public function __get($name)
    {
        if ($name == 'broadcast') {
            $this->isBroadcast = true;
        }
        return $this;
    }
}
