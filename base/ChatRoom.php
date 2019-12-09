<?php
/**
 * @chartRoom Class ChatRoom 直播聊天室
 * @author xialebin@163.com
 */

namespace chat\base;

use chat\base\base\WebSocketBase;
use chat\tra\UnifyMessageTrait;

class ChatRoom extends WebSocketBase
{
    use UnifyMessageTrait;

    //布隆过滤器对象
    private $bloomFilterObj = NULL;

    //前置操作
    public function init(){

        $this->initDefineUnifyMessage();

        //初始化布隆过滤器
        $this->bloomFilterObj = self::getBloomOperationObj($this->config['bucket_name']);

        //设置参数
        $arr = [
            'worker_num' => $this->config['worker_num'],//设置启动的Worker进程数
            'task_worker_num' => $this->config['task_worker_num'],
            'daemonize' => 1,
            'log_file' => ROOT.'/log/chat.log'
        ];
        $this->set($arr);

        //新增task任务回调函数，使服务端程序支持多进程异步处理
        $this->server->on('Task',[$this,'onTask']);
        $this->server->on('Finish',[$this,'onFinish']);
        $this->server->on('WorkerStart',[$this,'onWorkerStart']);

        if ($this->config['is_cluster']) {

            //监听UDP端口，用于接收其它“机器”发来的数据包，应用在集群部署场景下
            $port = $this->server->listen($this->config['udp_host'],$this->config['udp_port'],SWOOLE_SOCK_UDP);

            //绑定UDP接受数据回调函数
            $port->on('packet',[$this,'onPacket']);

        }
    }

    /**
     * udp接收数据回调接口
     * @param object $server 服务对象
     * @param string $data 接收数据
     * @param $addr
     */
    public function onPacket($server,$data,$addr){

        $param = $this->checkClientParam($data,'packet');

        if ($param === false) {
            return;
        }

        //获取参数
        list($msg,$data,$source_connection_id,$target_connection_id,$type) = $param;

        switch ($msg){
            case 'CLOSE':
                $this->cutClientConnection($server,$target_connection_id);
                return;
            default:break;
        }

        if ($type == 'one') {
            $this->pushDataToOneClient($server,$msg,$data,$target_connection_id);
        }elseif ($type == 'all'){
            $room_id = $this->getRoomIdByConnectionId($server,$source_connection_id);
            $this->pushDataToOneMachineClient($server,$msg,$data,$room_id);
        }
    }

    /**
     * 进程启动时回调函数
     * @param $server
     * @param $worker_id
     */
    public function onWorkerStart($server,$worker_id){
        //php socket连接限制超时时间
        ini_set('default_socket_timeout', -1);
        $redis = new \Redis();
        $con = $redis->connect($this->cache_config['host'],$this->cache_config['port']);

        if (!$con) {
            //todo 关闭服务
            return;
        }

        if ('' != $this->cache_config['password']) {
            $redis->auth($this->cache_config['password']);
        }

        $server->redis = $redis;

        //如是work进程而不是task进程，则启动出队操作
        if (!$server->taskworker) {

            //回调父类方法
            $this->workStart($redis);

            //10秒后执行此函数
            swoole_timer_after(10000, function () use($server){
                $this->processTick($server);
            });
        }
    }

    //定时器执行
    private function processTick($server){
        swoole_timer_tick($this->config['tick_time'],function ($timer_id) use($server){
            //出队操作
            $data = $server->redis->rpop(TASK_LIST);
            //投递任务
            if ($data) {
                $server->task($data);
            }
        });
    }

    /**
     * 任务回调函数，支持程序的多进程异步处理机制 在这里执行广播消息的逻辑
     * @param $server
     * @param $task_id
     * @param $from_id
     * @param $data
     */
    public function onTask($server,$task_id,$from_id,$data){

        $param = $this->checkClientParam($data,'task');

        if ($param === false) {
            return;
        }

        //获取参数
        list($msg,$param_data,$source_connection_id) = $param;

        call_user_func_array([$this,strtolower($msg)],[$server,$param_data,$source_connection_id]);

    }

    public function checkMessageIsAllow($server,$message,$room_id,$user_id){

        //被禁言
        $flag = $server->redis->sismember(NOT_ALLOW.'_'.$room_id,$user_id);

        if ($flag) {
            return '您已被禁言';
        }

        $flag = $server->redis->get(CHAT_ROOM_STATUS);
        if ($flag == STATUS_ALL_DISABLED) {
            return '全体禁言中';
        }

        return true;
    }

    //推送消息
    public function pushDataToOneClient($server,$msg,$data,$target_connection_id){

        //发送单条信息
        $target_connection = explode('_',$target_connection_id);

        if ( ($count = count($target_connection)) == 1) {

            $message = $this->unifyMess($msg,$data);
            //直接推送
            $server->push($target_connection_id,$message);

        }else if($count == 2){

            $target_machine = current($target_connection);
            $target_fp = $target_connection[1];

            //在本台机器中
            if ($target_machine == $this->getCurrentMachineIp()) {

                $message = $this->unifyMess($msg,$data);
                $server->push($target_fp,$message);
            }else{

                $message = $this->unifyMess($msg,$data,'',$target_connection_id,'one');
                $this->udpSend($target_machine,$this->config['udp_port'],$message);
            }
        }
    }

    //推送消息
    public function pushDataToAllClient($server,$msg,$data,$source_connection_id){

        $room_id = $this->getRoomIdByConnectionId($server,$source_connection_id);

        //发送全局广播信息
        if ($this->config['is_cluster']) {

            $current_ip = $this->getCurrentMachineIp();

            $current_fp_arr = (array) $server->redis->smembers(CONNECTION_USER.'_'.$current_ip.'_'.$room_id);

            $message = $this->unifyMess($msg,$data,$source_connection_id,'','all');
            //UDP转播
            $this->pushAllUdp($server,$message);
        }else{
            $current_fp_arr = (array) $server->redis->smembers(CONNECTION_USER.'_0_'.$room_id);
        }

        $message = $this->unifyMess($msg,$data);

        //本机广播
        foreach ($current_fp_arr as $v){
            $server->push($v,$message);
        }
    }

    //推送消息
    private function pushDataToOneMachineClient($server,$msg,$data,$room_id){

        //发送单机广播消息
        $current_ip = $this->config['is_cluster'] ? $this->getCurrentMachineIp() : 0;

        $current_fp_arr = (array) $server->redis->smembers(CONNECTION_USER.'_'.$current_ip.'_'.$room_id);
        //本机广播
        $message = $this->unifyMess($msg,$data);
        foreach ($current_fp_arr as $v){
            $server->push($v,$message);
        }
    }

    //切断客户端连接
    public function cutClientConnection($server,$target_connection_id){

        //发送单条信息
        $target_connection = explode('_',$target_connection_id);

        if ( ($count = count($target_connection)) == 1) {
            //直接切断
            $server->close($target_connection_id,true);

        }else if($count == 2){

            $target_machine = current($target_connection);
            $target_fp = $target_connection[1];

            //在本台机器中
            if ($target_machine == $this->getCurrentMachineIp()) {

                $server->close($target_fp,true);
            }else{
                $message = $this->unifyMess('CLOSE','','',$target_connection_id,'one');
                $this->udpSend($target_machine,$this->config['udp_port'],$message);
            }
        }
    }


    //根据用户ID和聊天室ID 获取连接ID
    public function getConIdByUserAndRoom($server,$user_id,$room_id){
        return $server->redis->hget(CONNECTION_ROOM.'_'.$room_id,$user_id) ? : false;
    }

    //根据连接ID获取聊天室ID
    public function getRoomIdByConnectionId($server,$connection_id){
        return $server->redis->hget(CONNECTION_ROOM,$connection_id) ? : false;
    }

    public function getUserIdByConnectionId($server,$connection_id){
        return $server->redis->hget(CONNECTION_USER,$connection_id) ? : false;
    }


    /**
     * 服务端推送消息到客户端
     * @param $server
     * @param $ip int 目标IP
     * @machine_ip int 机器IP
     * @fd int 进程标识
     * @param $data string 推送的数据
     */
    public function pushData($server,$ip,$machine_ip,$fd,$data){

        if (!$ip || !$machine_ip || !$data || !$fd) {
            return false;
        }

        //本台机器
        if ($ip == $machine_ip) {
            $server->push($fd,$data);
        }

        //发送到目标机器
        $this->udpSend($ip,$this->config['udp_port'],$data);
        return true;
    }

    /**
     * 任务执行完成回调
     * @param $server
     * @param $task_id
     * @param $data
     */
    public function onFinish($server,$task_id,$data){
        return;
    }

    /**
     * 监听server事件
     * 当WebSocket客户端与服务器建立连接并完成握手后会回调此函数
     * @param $server
     * @param $request object 是一个HTTP请求对象，包含了客户端发来的握手请求信息
     */
    public function onOpen($server,$request){

        $fd = $request->fd;
        $key = isset($request->get['key']) ? $request->get['key'] : '';

        //判断密钥
        if (!$key) {
            $this->pushClientError($server,$fd,E_4);
            return;
        }
        //根据密钥验证身份
        $data_json = $server->redis->get($key);

        if (!$data_json) {
            $this->pushClientError($server,$fd,E_5);
            return;
        }

        $data = json_decode($data_json,true);

        $user_id = isset($data['user_id']) ? $data['user_id'] : false;
        $room_id = isset($data['room_id']) ? $data['room_id'] : false;

        if ($user_id === false || $room_id === false) {
            $this->pushClientError($server,$fd,E_8);
            return;
        }

        if ($user_id === 0) {

            if (!$this->config['debug']) {
                $this->pushClientError($server,$fd,E_4);
                return;
            }
        }else{

            //在线用户信息
            $is_already = $server->redis->sismember(CONNECTION_USER.'_'.$room_id,$user_id);

            //判断用户是否在线
            if ($is_already) {
                $this->pushClientError($server,$fd,E_1);
                return;
            }
        }

        $current_ip = 0;
        $connection_id = $this->getConnectionId($request->fd,$current_ip);

        //连接信息 - 用户信息
        $server->redis->hset(CONNECTION_USER,$connection_id,$user_id);

        //连接信息 - 聊天室ID
        $server->redis->hset(CONNECTION_ROOM,$connection_id,$room_id);
        $server->redis->sadd(CONNECTION_USER.'_'.$room_id,$user_id);

        $server->redis->hset(CONNECTION_ROOM.'_'.$room_id,$user_id,$connection_id);

        //某个机器某个聊天室的进程ID
        $server->redis->sadd(CONNECTION_USER.'_'.$current_ip.'_'.$room_id,$fd);

        if ($current_ip) {
            //IP不在集合中
            if (!$server->redis->sismember(MACHINE_IPS,$current_ip)) {
                $server->redis->sadd(MACHINE_IPS,$current_ip);
            }
        }
    }

    //返回客户端错误提示
    public function pushClientError($server,$fd,$message){
        $server->push($fd,$this->unifyMess('ERROR',$message));
        $server->close($fd,true);
    }

    public function pushClientWarning($server,$message,$target_connection_id){
        $this->pushDataToOneClient($server,'WARNING',$message,$target_connection_id);
    }


    //获取 连接ID
    public function getConnectionId($fd,&$current_ip=0){
        if ($this->config['is_cluster']) {

            //获取机器内网IP
            $machine_ip_num = $this->getCurrentMachineIp();
            $current_ip = $machine_ip_num;
            $hash_key = $machine_ip_num.'_'.$fd;
        }else{
            $hash_key = $fd;
        }

        return $hash_key;
    }

    /**
     * 监听来自客户端的数据
     * 当服务器收到来自客户端的数据帧时会回调此函数
     * @param $server
     * @param $frame object $frame 是websocket_frame对象，包含了客户端发来的数据帧信息
     */
    public function onMessage($server,$frame){

        $param = $this->checkClientParam($frame->data);

        if ($param === false) {
            $this->pushClientError($server,$frame->fd,E_9);
            return;
        }

        //接收客户端心跳检测
        if ($param['msg'] == 'HEART') {
            return;
        }

        $connection_id = $this->getConnectionId($frame->fd);
        $room_id = $server->redis->hget(CONNECTION_ROOM,$connection_id);

        $param['connection_id'] = $connection_id;

        //入队操作
        $server->redis->lpush(TASK_LIST.'_'.$room_id,json_encode($param));
    }

    //检查客户端参数
    public function checkClientParam($json,$type='message'){

        $arr = json_decode($json,true);

        if (!$arr || !is_array($arr)) {
            return false;
        }

        $arr_key = array_keys($arr);

        $param = [$arr['msg'],$arr['data']];

        switch ($type){
            case 'message':
                if (array_diff(['msg','data'],$arr_key)) {
                    return false;
                }
                if (!in_array($arr['msg'],$this->config['message_flag'])) {
                    return false;
                }
                break;
            case 'task':
                if (array_diff(['msg','data','source_connection_id'],$arr_key)) {
                    return false;
                }
                $param[] = $arr['source_connection_id'];
                break;
            case 'packet':
                if (array_diff(['msg','data','source_connection_id','target_connection_id','type'],$arr_key)) {
                    return false;
                }
                $param = $arr;
                break;
            default:
                return $arr;
                break;
        }

        return $param;
    }


    /**
     * 客户端断开连接
     * @param $server
     * @param $fd
     */
    public function onClose($server,$fd){

        $machine_ip_num = $this->getCurrentMachineIp();
        $fd_str = $machine_ip_num.'_'.$fd;

        $is_share = $server->redis->hexists(CONNECTION_SHARE,$fd_str);
        if ($is_share) {
            $server->redis->hdel(CONNECTION_SHARE,$fd_str);
        }else{
            $is_user = $server->redis->hexists(CONNECTION_USER,$fd_str);
            if ($is_user) {
                $server->redis->hdel(CONNECTION_USER,$fd_str);
                //清理消息间隔限制
                if ($server->redis->hexists(SEND_TIME_ROLE,$fd_str)) {
                    $server->redis->hdel(SEND_TIME_ROLE,$fd_str);
                }
            }else{
                $server->redis->hdel(CONNECTION_ADMIN,$fd_str);
            }
        }
    }

    /**
     * 获取当前机器的IP，返回整型
     */
    public function getCurrentMachineIp(){
        //获取机器内网IP
        return $this->convertIpToLong(current(explode(' ',exec('hostname -I'))));
    }

    //全部中断时，清理内存
    private function overClear($server){
        $server->redis->del(NOT_ALLOW);
        $server->redis->del(MACHINE_IPS);
        $server->redis->del(SEND_TIME_ROLE);
        $server->redis->del(TASK_LIST);
    }

    /**
     * 判断信息的长度
     * @param $word
     * @return bool
     */
    public function lenFilter($word){
        if (mb_strlen($word,'utf8')>$this->config['data_len']) {
            return false;
        }
        return true;
    }


    /**
     * 关键词过滤
     * @param $word
     */
    public function wordFilter($server,$word){
        if (!$word) {
            return $word;
        }

        $len = mb_strlen($word,'utf8');
        $rel_arr = [];
        for($i=0;$i<$len-1;$i++){
            $sub_str = mb_substr($word,$i,null,'utf8');

            for ($j=2;$j<=$len-$i;$j++){
                $data = mb_substr($sub_str,0,$j,'utf8');
                //判断是否存在于违禁词库
                if ($this->bloomFilterObj->exists($data,$server->redis)) {
                    $rel_arr[] = $data;
                }
            }
        }

        $rel = '';
        if ($rel_arr) {
            foreach ($rel_arr as $v){
                if (!$rel) {
                    $rel = str_replace($v,'**',$word);
                }else{
                    $rel = str_replace($v,'**',$rel);
                }
            }
            return $rel;
        }else{
            return $word;
        }
    }


    /**
     * 推送给所有已经连接的服务器
     * @param $data
     */
    public function pushAllUdp($server,$data){

        $machine_ip_num = $this->getCurrentMachineIp();

        //推送给除本机器外的所有其他机器
        $other_machine = array_diff( (array) $server->redis->smembers(MACHINE_IPS),[$machine_ip_num]);

        if ($other_machine) {
            foreach ($other_machine as $v){
                //推送给其他机器
                $this->udpSend($v,$this->config['udp_port'],$data);
            }
        }
    }


    /**
     * 作为客户端给服务端发送信息
     * @param $host
     * @param int $port
     * @param string $data
     */
    public function udpSend($host,$port=9502,$data=''){

        if (is_numeric($host)) {
            $host = $this->convertIpToString($host);
        }

        //对udp通道发送的数据进行加密，并携带公钥传送，接收端根据私钥‘udp_key'进行数据的验证
        $data = md5($this->config['udp_key'].$data).'&&'.$data;

        $client = new swoole_client(SWOOLE_SOCK_UDP);
        if (!$client->connect($host,$port,-1)){
            return;
        }

        $client->send($data);
        $client->close();
    }

}