<?php
/**
 * Created by PhpStorm.
 * User: 夏
 * Date: 2019/12/2
 * Time: 15:34
 */

namespace chat\base\init;


interface WebSocketInit
{
    //检查运行参数
    public function checkParam();
    //初始化运行参数
    public function initParam();
}