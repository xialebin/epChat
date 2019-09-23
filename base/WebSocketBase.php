<?php

/**
 * @websocket 基础类
 * Class WebSocketBase
 */
abstract class WebSocketBase
{
    public $host = '0.0.0.0';
    public $server = NULL;
    public $error_log_path = ROOT.DIRECTORY_SEPARATOR.'log';
    public $env_extension = ['redis','swoole'];
    public $env_version = ['php'=>'7.0'];
    public $port = 9501;
    public $run_type = '';
    protected $config = [];
    protected $cache_config = [];

    //回调函数
    abstract function onOpen($server,$request);
    abstract function onMessage($server,$frame);
    abstract function onClose($server,$fd);

    //检查自定义参数
    abstract function checkSelfParam($config);

    public function __construct($type='')
    {
        //绑定运行环境类型
        $this->run_type = $type;

        //注册操作
        $this->registerOperation();

        //检查运行环境
        $this->checkEnv();
    }


    //注册操作
    public function registerOperation(){

        //注册错误回调
        set_error_handler(function ($no,$str,$file,$line) {

            $message = "[$no] $str error on line $line in $file";
            $this->proError($message);

        },E_ERROR);

        //注册配置属性
        $this->registerParam();

        //注册常量
        $this->registerConstant();
    }


    //注册常量
    public function registerConstant(){

        //redis字段统一前缀
        define('REDIS_PRE',$this->config['redis_pre']);
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
            $this->proError('The configuration file does not exist');
        }

        $config = include(ROOT.'/config.php');

        if (!is_array($config)) {
            $this->proError('Config file error');
        }

        $message = $this->checkSelfParam($config);

        if ($message) {
            $this->proError($message);
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
                $this->proError($v.' extensions are essential');
            }
        }

        foreach ($this->env_version as $k => $v){

            if ( ($php = strtolower($k)) == 'php') {
                if ($this->judgeVersion(phpversion(),$v) == -1) {
                    $this->proError($php.' version should be at least '.$v);
                }
            }else{
                if ($this->judgeVersion(phpversion($k),$v) == -1) {
                    $this->proError($k.' version should be at least '.$v);
                }
            }
        }
    }

    //处理报错信息
    public function proError($message,$type=0){

        $this->writeLog($message,$this->getLogPath('ERROR'));

        switch ($type){
            case 0:
                $pre = 'ERROR';
                break;
            case 1:
                $pre = 'NOTICE';
                break;
            default:
                $pre = 'ERROR';
                break;
        }

        echo $pre.": ".$message.PHP_EOL;
        exit();
    }


    //获取日志路径
    public function getLogPath($type){

        switch ($type){
            //错误日志
            case 'ERROR':
                $path = $this->error_log_path.DIRECTORY_SEPARATOR.date('Ymd').DIRECTORY_SEPARATOR.'error_log';
                if (!is_dir($path)) {
                    mkdir($path,0777,true);
                }
                return $path.DIRECTORY_SEPARATOR.'error_log.txt';
            default:
                break;
        }
        return '';
    }


    //写入日志
    public function writeLog($content='',$path=''){

        if (!$content || !$path) {
            return false;
        }

        $content = date('Y-m-d H:i:s').' - '.$content.PHP_EOL;

        try{

            $fp = fopen($path,'a+');
            fwrite($fp,$content);
            fclose($fp);
            return true;

        }catch (Exception $e){
            return false;
        }

    }

    //比较版本号
    public function judgeVersion($version_1='',$version_2=''){

        $arr_1 = explode('.',$version_1);
        $arr_2 = explode('.',$version_2);

        $num_1 = count($arr_1);
        $num_2 = count($arr_2);

        $num = max($num_1,$num_2);
        $rec = $num_1 > $num_2 ? ($num_1 - $num_2) : ($num_2 - $num_1);


        foreach (array_merge($arr_1,$arr_2) as $v){

            if (!is_numeric($v)) {
                return 0;
            }
        }


        for ($i=0;$i<$num;$i++){

            if ($i < ($num - $rec)) {

                if ($arr_1[$i] == $arr_2[$i]) {
                    continue;
                }else{
                    return $arr_1[$i] > $arr_2[$i] ? 1 : -1;
                }

            }else{
                return isset($arr_1[$i]) ? 1 : -1;
            }
        }

        return 0;
    }

}