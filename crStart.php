<?php
/**
 * 聊天室服务启动入口，仅支持cli模式下运行启动
 * Date: 2019/5/29
 * Time: 15:57
 */
define('ROOT',__DIR__);//定义初始目录

require ROOT.'/swoole/ChatRoomOperation.php';


ChatRoomOperation::run('chat_room')->start();


/**
 * todo
 * 支持多聊天室
 * 更优雅的集成组件 布隆
 */