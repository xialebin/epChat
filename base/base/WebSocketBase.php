<?php
/**
 * 提供用于长连接的基础抽象类
 * @author xialebin@163.com
 */

namespace chat\base\base;

use chat\tra\BloomGroupTrait;
use chat\tra\UtilFunctionTrait;

abstract class WebSocketBase
{
    use UtilFunctionTrait;
    use BloomGroupTrait;

    public $server = NULL;
    protected $config = [];
    protected $cache_config = [];


    public static $run_flag = 0;//运行状态

    //回调函数
    abstract function onOpen($server,$request);
    abstract function onMessage($server,$frame);
    abstract function onClose($server,$fd);


    /**
     * WebSocketBase constructor.
     * @param string $type 连接类型
     */
    public function __construct(WebSocketInit $init_obj)
    {

        //加载自定义的配置文件
        $this->cache_config = $init_obj->initParam()['redis'];
        $this->config = $init_obj->initParam();

        //注册操作
        $this->registerOperation();
    }


    //注册
    public function registerOperation(){

        //注册错误回调
        set_error_handler(function ($no,$str,$file,$line) {

            $message = "[$no] $str error on line $line in $file";
            $this->submitLog($message);

        },E_ERROR);

        //注册常量
        $this->registerConstant();
    }


    //常量注册
    public function registerConstant(){

        //redis字段统一前缀
        define('REDIS_PRE',$this->config['redis_pre']);
        define('DIR',DIRECTORY_SEPARATOR);
    }

    //注册环境
    public function registerEnv($extension=[],$version=[]){
        $this->env_extension = $extension;
        $this->env_version = $version;
    }


    //运行服务
    public final function run(){

        //服务监听
        $this->listen();

        //执行其他前置操作
        $this->init();

        //服务启动
        $this->start();
    }


    //监听服务
    public function listen(){
        //启用监听事件
        $this->server = new Swoole\WebSocket\Server($this->config['host'],$this->config['port']);
        $this->server->on('open',[$this,'onOpen']);
        $this->server->on('message',[$this,'onMessage']);
        $this->server->on('close',[$this,'onClose']);
    }

    //前置操作 防止无调用报错
    protected function init(){
        return true;
    }

    //对服务进行参数配置
    protected function set($arr=[]){
        $this->server->set($arr);
    }

    //进程开启回调
    public function workStart($redis_obj=null){

        if (self::$run_flag == 1) {
            return;
        }

        self::$run_flag = 1;

        //$this->submitLog('进程成功开启','RUN');

        //导入违禁词
        if (!$redis_obj->exists($this->config['bucket_name'])) {

            $this->initIllegalWord($this->config['bucket_name'],$redis_obj);

        }else{
            return;
        }
    }


}