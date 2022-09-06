<?php

namespace icy8\SocketIO;

use icy8\SocketIO\server\Driver;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

/**
 * @method Emitter on($event_name, $listener)
 * @method Emitter once($event_name, $listener)
 * @method Engine to($room)
 * @method Engine leave($room, $id = null)
 * @method Array|Socket fetchRooms($room, $id = null)
 * Class Server
 * @package icy8\SocketIO
 */
class Server
{
    protected $socketio;
    /**
     * @var Engine $engine
     */
    protected $engine;
    protected $token;
    public    $httpHost    = "0.0.0.0:9580"; // http接口
    public    $httpLimitIp = '*';
    public    $globalID    = "socketio";

    public function __construct($socket_name = '', array $opts = array())
    {
        $this->token  = $opts['token'] ?? '';
        $this->engine = new Engine($opts);
        unset($opts['ping_interval'], $opts['ping_timeout'], $opts['token']);
        // websocket
        $worker = new Worker($socket_name, $opts);
        $this->attach($worker);
    }

    protected function attach(Worker $worker)
    {
        $this->engine->on("workerStart", [$this, "onStart"]);
        $this->engine->on("connection", [$this, "onConnection"]);
        $this->engine->attach($worker);
        $worker->onWorkerStart = [$this, "onStart"];
        return $this;
    }

    public function onConnection(Socket $socket)
    {
        if (isset($socket->request->query['channel'])) {
            // 预订阅
            $closure = $this->engine->handleSubcribe($socket);
            $socket->on("connect", function () use ($closure, $socket) {
                return $closure($socket->request->query);
            });
        }
    }

    /**
     * worker启动
     */
    public function onStart(Worker $worker)
    {
        if ($this->httpHost) {
            // http服务器
            $httpServer            = new Worker("http://{$this->httpHost}");
            $httpServer->onMessage = [$this, "onHttpClientMessage"];
            $httpServer->listen();
        }
    }

    /**
     * 获取worker对象
     * @return Worker
     */
    public function worker()
    {
        return $this->engine->worker;
    }

    protected function publishToClients($channel, $event, $data)
    {
        return $this->engine->to($channel)->emit($event, $data);
    }

    /**
     * 接受http消息
     * 使http能推送websocket消息
     * @param TcpConnection $connection
     * @param Request $request
     */
    public function onHttpClientMessage(TcpConnection $connection, Request $request)
    {
        $limitIps = $this->getHttpLimitIp();
        $remoteIp = $connection->getRemoteIp();
        $token    = $request->get("token");
        // 限制ip
        if (
            !in_array("*", $limitIps)
            && (!$remoteIp || !in_array($remoteIp, $limitIps))
        ) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\nBad Request", true);
            return;
        } else if ($this->token && $this->token !== $token) {
            // 验证token
            $connection->close("HTTP/1.1 401 Unauthorized\r\n\r\nUnauthorized", true);
        }
        $req  = new \icy8\SocketIO\Request($connection, $request->rawBuffer());
        $post = $req->getRaw("json");
        if ($post === null) {
            $post = $request->post();
        }
        $type   = trim($request->path(), '/');
        $header = "HTTP/1.1 200 OK\r\n";
        $header .= "Access-Control-Allow-Origin: *\r\n";
        $header .= "Server: http 1.0\r\n";
        $header .= "Connection: keep-alive\r\n";
        $header .= "Content-Type: text/html;charset=utf-8\r\n";
        $header .= "\r\n";
        switch (strtolower($type)) {
            // 事件推送
            case 'event':
                $channel = $post['channel'] ?? '';
                $event   = $post['event'] ?? '';
                $data    = $post['data'] ?? '';
                if (is_string($data)) {
                    $_data = json_decode($data, true);
                    if ($_data) $data = $_data;
                    unset($_data);
                }
                if ($channel) {
                    $this->publishToClients($channel, $event, $data);
                }
                // 不要保持连接，响应了马上关闭
                $connection->close($header . "{}", true);
                break;
            // 广播事件
            case "broadcast":
                $event = $post['event'] ?? '';
                $data  = $post['data'] ?? '';
                $this->engine->broadcast()->emit($event, $data);
                // 不要保持连接，响应了马上关闭
                $connection->close($header . "{}", true);
                break;
            // 获取channel信息
            case 'channel_info':
            default:
                $connection->close($header . "{}", true);
        }
    }

    protected function createsocketID(TcpConnection $connection)
    {
        $socket_id = "{$this->globalID}.{$connection->id}";
        return $socket_id;
    }

    public function getHttpLimitIp()
    {
        $ips = $this->httpLimitIp;
        if (!is_array($ips)) {
            $ips = explode(',', $ips);
            $ips = array_map(function ($v) {
                return trim($v);
            }, $ips);
            $ips = array_filter($ips);
        }
        return $ips;
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->engine, $name], $arguments);
    }
}
