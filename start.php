<?php
/**
 * 服务启动入口，仅支持cli模式下运行启动
 * @author xialebin@163.com
 */

use chat\base\Engine;
define('ROOT',__DIR__);//定义初始目录

require_once ROOT."/base/init/autoload.php";

Engine::run()->start();


/**
 * todo
 *
 * 引入命名空间
 * 重写导入敏感词
 * 测试通过 推送到远端
 * 支持多聊天室
 * 支持单人聊天
 * 支持消息推送中间件
 *
 * 连接池
 * MongoDB保存聊天记录
 * 优化消息队列机制，rabbitMQ  支持单条转发 和 多条转发两种模式
 * 更加稳定的UPD连接 与 加密
 * 优化关键词过滤
 *
 * 支持多聊天室
 * 更优雅的集成组件 布隆
 */