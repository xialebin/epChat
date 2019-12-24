<?php
/**
 * 服务启动入口，仅支持cli模式下运行启动
 * @author xialebin@163.com
 */

use chat\base\Engine;
define('ROOT',__DIR__);//定义初始目录

require_once ROOT."/base/init/autoload.php";

Engine::run()->start();