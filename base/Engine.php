<?php
/**
 * Created by PhpStorm.
 * User: 夏
 * Date: 2019/11/29
 * Time: 21:38
 */

namespace chat\base;

use chat\tra\AutoloadClassTrait;
use chat\tra\UtilFunctionTrait;

class Engine
{
    use UtilFunctionTrait;

    private $config = [];
    private $default = [];


    private $run_env = [
        'env_extension' => ['redis','swoole'],
        'env_version' => ['php'=>'7.0']
    ];
    private $container = [
        'ChatRoom'
    ];


    public function __construct()
    {
        //设置时区
        date_default_timezone_set('PRC');

        $this->register();
    }


    //注册
    public function register(){
        //注册配置文件
        define('DIR',DIRECTORY_SEPARATOR);

        //配置文件目录
        define('CONFIG_DIR',ROOT.'/config'.DIR);

        $this->registerConfig();
    }


    //注册配置文件
    public function registerConfig(){

        $default = include(CONFIG_DIR.'defaultConfig.php');
        $config = include(CONFIG_DIR.'config.php');

        $this->default = $default;
        $this->config = $config;

        $this->run_env['env_extension'] = $default['extension'];
        $this->run_env['env_version'] = $default['version'];
    }


    public static final function run(){

        $object = new static();

        //检测参数 与 运行环境
        $object->initCheck();

        return $object;

    }


    public function initCheck(){
        //检测端口
        $this->checkPort();
        //检测运行环境
        $this->checkEnv();
    }


    public function checkPort(){
        $port_arr = [];
        $udp_port_arr = [];
        foreach ($this->config as $v){

            if ($v['status'] === false) {
                continue;
            }

            if (!in_array($v['type'],$this->container)) {
                $this->submitLog('param key is not allow');
            }

            $port = $v['port'] == 'default' ? $this->default['port'] : $v['port'];

            if (in_array($port,$port_arr)) {
                $this->submitLog($port.' is existed');
            }else{
                $port_arr[] = $port;
            }

            if ($v['is_cluster']) {
                $udp_port = $v['udp_port'] == 'default' ? $this->default['udp_port'] : $v['udp_port'];

                if (in_array($udp_port,$udp_port_arr)) {
                    $this->submitLog($udp_port.' is existed');
                }else{
                    $udp_port_arr[] = $udp_port;
                }
            }
        }
    }

    //检查运行环境
    private function checkEnv(){

        foreach ($this->run_env['env_extension'] as $v){

            $extension = extension_loaded($v);

            if (!$extension) {
                $this->submitLog($v.' extensions are essential');
            }
        }

        foreach ($this->run_env['env_version'] as $k => $v){

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


    public final function start(){

        foreach ($this->config as $v){

            if ($v['status'] === true) {
                //pcntl_fork();

                $class = PSR."\\base\\init\\".$v['type'].'Init';
                $reflection = new \ReflectionClass($class);
                $object = $reflection->newInstanceArgs([$v,$this->default]);

                $init_rel = $object->checkParam();

                if (is_object($init_rel)) {
                    $this->forkEngineStart($v['class_name'],$init_rel);
                }

                if (is_string($init_rel)) {
                    $this->submitLog($init_rel);
                }
            }
        }
    }


    public function forkEngineStart($app_name,$param_obj){

        $app_engine = PSR."\\exec\\".$app_name;
        $reflection = new \ReflectionClass($app_engine);

        $param = call_user_func([$param_obj,'initParam']);

        foreach($param['message_flag'] as $v){
            $is_has = $reflection->hasMethod($v);
            if (!$is_has) {
                return "$v is not existed";
            }
        }

        echo "success";return;

        ($reflection->newInstanceArgs([$param_obj]))->run();
    }

}