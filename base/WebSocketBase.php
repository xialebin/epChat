<?php

/**
 * @websocket 基础类
 * Class WebSocketBase
 */
abstract class WebSocketBase
{
    public $host = '0.0.0.0';
    public $server = NULL;
    public $port = 9501;
    protected $cache = NULL;//存放缓存对象
    protected $message_cache = NULL;//处理消息缓存对象
    protected $config = [];
    protected $cache_config = [];

    abstract function onOpen($server,$request);
    abstract function onMessage($server,$frame);
    abstract function onClose($server,$fd);

    public function __construct($type = '')
    {
        $this->cache = new \Redis();
        $this->message_cache = $this->cache;

        //加载自定义的配置文件
        $config = include(ROOT.'/config.php');
        $this->cache_config = $config['redis']['share_redis'];
        $this->config = $config[$type];

        //加载连接配置
        $this->port = $this->config['port'];
        $this->host = $this->config['host'];

        //缓存连接
        $this->cache->connect($this->cache_config['host'],$this->cache_config['port']);
        $this->message_cache->connect($this->config['redis']['message_redis']['host'],$this->config['redis']['message_redis']['port']);

        if ('' !=  $this->cache_config['password']) {
            $this->cache->auth( $this->cache_config['password']);
        }

        if ('' !=  $this->config['redis']['message_redis']['password']) {
            $this->message_cache->auth($this->config['redis']['message_redis']['password']);
        }

        unset($config);

        //监听事件并开启服务
        $this->server = new Swoole\WebSocket\Server($this->host,$this->port);
        $this->server->on('open',[$this,'onOpen']);
        $this->server->on('message',[$this,'onMessage']);
        $this->server->on('close',[$this,'onClose']);

        //执行其他前置操作
        $this->init();

        $this->server->start();
    }

    //前置操作 防止无调用报错
    protected function init(){
        return true;
    }

    //对服务进行参数配置
    protected function set($arr=[]){
        $this->server->set($arr);
    }

}