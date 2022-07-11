# socket-io

#### 介绍

基于php的socket.io服务端，因为`workerman/phpsocket.io`只支持到`socket.io 3.0`，所以需要自己动手写轮子。

目前只支持 socket.io 4.0 版本，后续有时间会做兼容。因为最开始是为了对接websocket写的，没有详细对比过官方的server端代码，所以不确定有哪些功能是缺失的。

#### 软件架构
1. `workerman`
2. `php>=7.0`


#### 安装教程

```shell
composer require icy8/socket-io
```

#### 使用说明

新建命令行文件：`websocket.php`

如果在linux中运行，通常建议加上`-d`选项守护进程。

```php
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

```

#### 其他工具

结合事件发布器，即可通过http向websocket客户端发送消息：

```shell
composer require icy8/event-pusher
```

#### 关于性能

目前还没有针对性能做过测试，所以相关性能依然是未知的。

#### 后续版本

1. 兼容旧版握手协议。
2. 还原更多官方功能，如长轮询等。
3. 优化http事件发布功能。
4. 增加webhook功能。

