<?php

/**
 * 提供用于长连接的基础抽象类
 * @author xialebin@163.com
 */

require ROOT.'/trait/UtilFunction.php';
require ROOT.'/trait/BloomGroup.php';
abstract class WebSocketBase
{
    use UtilFunction;
    use BloomGroup;
    public $host = '0.0.0.0';
    public $server = NULL;
    public $env_extension = ['redis','swoole'];
    public $env_version = ['php'=>'7.0'];
    public $port = 9501;
    public $run_type = '';
    protected $config = [];
    protected $cache_config = [];
    public static $run_flag = 0;//运行状态

    //回调函数
    abstract function onOpen($server,$request);
    abstract function onMessage($server,$frame);
    abstract function onClose($server,$fd);

    //检查自定义参数
    abstract function checkSelfParam($config);

    /**
     * WebSocketBase constructor.
     * @param string $type 连接类型
     */
    public function __construct($type='')
    {
        //绑定运行环境类型
        $this->run_type = $type;

        //注册操作
        $this->registerOperation();

        //检查运行环境
        $this->checkEnv();
    }


    //注册
    public function registerOperation(){

        //注册错误回调
        set_error_handler(function ($no,$str,$file,$line) {

            $message = "[$no] $str error on line $line in $file";
            $this->submitLog($message);

        },E_ERROR);

        //注册自动装载函数
        spl_autoload_register([$this,'loadClass']);

        //注册配置属性
        $this->registerParam();

        //注册常量
        $this->registerConstant();
    }


    //常量注册
    public function registerConstant(){

        //redis字段统一前缀
        define('REDIS_PRE',$this->config['redis_pre']);
        define('DIR',DIRECTORY_SEPARATOR);
    }

    //注册参数
    private function registerParam(){

        //检查参数配置
        $config = $this->checkParam();

        //加载自定义的配置文件
        $this->cache_config = $config['redis'];
        $this->config = $config[$this->run_type];

        //加载连接配置
        $this->port = $this->config['port'];
        $this->host = $this->config['host'];
    }


    //注册环境
    public function registerEnv($extension=[],$version=[]){
        $this->env_extension = $extension;
        $this->env_version = $version;
    }


    //检查参数
    protected function checkParam(){

        if (!is_file(ROOT.'/config.php')) {
            $this->submitLog('The configuration file does not exist');
        }

        $config = include(ROOT.'/config.php');

        if (!is_array($config)) {
            $this->submitLog('Config file error');
        }

        $message = $this->checkSelfParam($config);

        if ($message) {
            $this->submitLog($message);
        }

        return $config;
    }


    //运行服务
    public final static function run($type=''){

        //设置时区
        date_default_timezone_set('PRC');

        $object = new static($type);

        //服务监听
        $object->listen();

        //执行其他前置操作
        $object->init();

        return $object;
    }


    //服务开启
    public function start(){
        $this->server->start();
    }


    //监听服务
    public function listen(){
        //启用监听事件
        $this->server = new Swoole\WebSocket\Server($this->host,$this->port);
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

    //检查运行环境
    private function checkEnv(){

        foreach ($this->env_extension as $v){

            $extension = extension_loaded($v);

            if (!$extension) {
                $this->submitLog($v.' extensions are essential');
            }
        }

        foreach ($this->env_version as $k => $v){

            if ( ($php = strtolower($k)) == 'php') {
                if ($this->judgeVersion(phpversion(),$v) == -1) {
                    $this->submitLog($php.' version should be at least '.$v);
                }
            }else{
                if ($this->judgeVersion(phpversion($k),$v) == -1) {
                    $this->submitLog($k.' version should be at least '.$v);
                }
            }
        }
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


    //自动加载类
    public function loadClass($class){

        $class_arr = explode('_',self::unCamelize($class));

        //加载工具类
        if ( ($dir_name = array_pop($class_arr)) == 'tool') {
            require ROOT.DIR.$dir_name.DIR.current($class_arr).DIR.$class.'.php';
        }
    }

}