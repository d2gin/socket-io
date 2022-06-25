<?php
include "vendor/autoload.php";

use \icy8\SocketIO\Server;

$server = new Server("websocket://0.0.0.0:9001", ['token' => '']);
// 自定义http服务器 这部分功能实现写得不是很好，后续再优化。
$server->httpHost = '0.0.0.0:9002';
// 限制http接口的访问限制，星号不限制
$server->httpLimitIp = '*';
$server->on("connection", function (\icy8\SocketIO\Socket $socket, $server) {
    // 服务器和前端完成握手后
    // 主动发消息最好是在socketio握手完成之后
    // $socket->request可以获取当前url的所有信息，websocket通常不需要关注这个属性
    $socket->on("connect", function () use ($socket) {
        $socket->emit("reply", "客户端连接成功");
        // 通过engine广播，广播的对象包含“自己”
        $server->broadcast()->emit('engine_broadcast', '引擎广播');
        // 通过socket链接广播，广播的对象不包含“自己”
        $socket->broadcast->emit("socket_broadcast", '链接广播');

    });
});
$server->on('unsubcribe', function ($channel, $data) {
    // 用户离线通知
});
$server->on('subcribe', function ($channel, $data) {
    // 用户上线通知
});
$server->on('disconnect', function (){
    // 客户端断开连接
});
$server->on('message', function (){
    // 客户端消息 这个是原始数据 就是workderman的onmessage事件
    // 在这个事件你可以接收到包含握手在内的所有消息
});
// 开启服务
\Workerman\Worker::runAll();
