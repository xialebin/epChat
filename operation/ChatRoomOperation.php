<?php
require ROOT.'/base/WebSocketBase.php';

/**
 * @chartRoom Class ChatRoom 直播聊天室
 * @author xialebin@163.com
 */

class ChatRoomOperation extends WebSocketBase
{
    //布隆过滤器对象
    private $bloomFilterObj = NULL;

    //聊天室配置文件
    public $chat_room_param = [
        'port',
        'host',
        'share_num',
        'process_name',
        'udp_host',
        'udp_port',
        'udp_key',
        'send_time',
        'tick_time',
        'worker_num',
        'task_worker_num',
        'bucket_name',
        'data_len',
        'like_num_step',
        'debug',
        'max_chat_room',
        //'error_log_path',
        'redis_pre',
    ];

    //redis配置文件
    public $redis_param = [
        'prefix',
        'host',
        'port',
        'password',
        'expire',
    ];

    //注册常量
    public function registerConstant()
    {
        parent::registerConstant();
        define('CONNECTION_USER',REDIS_PRE.'chat_room_connection_user');//用户连接信息 哈希 （进程标记=>用户ID）
        define('CONNECTION_ADMIN',REDIS_PRE.'chat_room_connection_admin');//管理者连接信息 哈希 （进程标记=>管理用户ID）
        define('CONNECTION_SHARE',REDIS_PRE.'chat_room_connection_share');//分享连接信息 哈希（进程标记=>分享者的用户ID）
        define('NOT_ALLOW',REDIS_PRE.'char_room_not_allow_user');//被禁言用户 集合（用户ID）
        define('MACHINE_IPS',REDIS_PRE.'chat_room_machine_ips');//机器IPs 集合（机器的IP的整型）
        define('SEND_TIME_ROLE',REDIS_PRE.'user_send_time_role');//用户发送时间规则 哈希（进程标记=>最近一次消息的时间戳）
        define('TASK_LIST',REDIS_PRE.'chat_room_task_list_message_1');//聊天室消息队列
        define('CHAT_ROOM_STATUS',REDIS_PRE.'chat_room_connection_status');//聊天室状态 字符串
        define('LIKE_HEART_NUM',REDIS_PRE.'chat_room_like_heart_num');//直播间点赞数量
        define('STATUS_NORMAL',1);//正常状态
        define('STATUS_ALL_DISABLED',2);//全体禁言
    }


    //检查自定义参数
    public function checkSelfParam($config=[])
    {
        if ($this->run_type != 'chat_room') {
            return 'Config run_type error';
        }
        if (array_diff(['redis','chat_room'],array_keys($config))) {
            return 'Config file error';
        }

        if (array_diff($this->redis_param,array_keys($config['redis']))) {
            return 'Redis param error';
        }

        if (array_diff($this->chat_room_param,array_keys($config['chat_room']))) {
            return 'ChatRoom param error';
        }

        $extension = ['redis'];
        $version = ['php'=>'7.0'];

        $this->registerEnv($extension,$version);

        return '';
    }

    //前置操作
    public function init(){

        //初始化布隆过滤器
        $this->bloomFilterObj = self::getBloomOperationObj($this->config['bucket_name']);

        //设置参数
        $arr = [
            'worker_num' => $this->config['worker_num'],//设置启动的Worker进程数
            'task_worker_num' => $this->config['task_worker_num'],
            'daemonize' => 1,
            'log_file' => ROOT.'/chat.log'
        ];
        $this->set($arr);

        //新增task任务回调函数，使服务端程序支持多进程异步处理
        $this->server->on('Task',[$this,'onTask']);
        $this->server->on('Finish',[$this,'onFinish']);
        $this->server->on('WorkerStart',[$this,'onWorkerStart']);

        //监听UDP端口，用于接收其它“机器”发来的数据包，应用在集群部署场景下
        $port = $this->server->listen($this->config['udp_host'],$this->config['udp_port'],SWOOLE_SOCK_UDP);

        //绑定UDP接受数据回调函数
        $port->on('packet',[$this,'onPacket']);

    }

    /**
     * udp接收数据回调接口
     * @param object $server 服务对象
     * @param string $data 接收数据
     * @param $addr
     */
    public function onPacket($server,$data,$addr){

        if ($data == 'Healthcheck udp check') {
            return;
        }

        $arr = explode('&&',$data);

        if (!$arr || count($arr) == 1) {
            return;
        }

        //根据私钥'udp_key'进行验证，防止数据被篡改和非法发送数据
        if (count($arr) == 2) {

            if ($arr[0] != md5($this->config['udp_key'].$arr[1])) {
                return;
            }

            $data_arr = json_decode($arr[1],true);

        }else{

            $key = array_shift($arr);
            $data_json = implode('&&',$arr);
            if ($key != md5($this->config['udp_key'].$data_json)) {
                return;
            }

            $data_arr = json_decode($data_json,true);
        }

        switch ($data_arr['code']){
            case 3://禁言某人
                $server->push($data_arr['data'],$this->creJson(3,'您被禁言'));
                break;
            case 4://全体被禁言
                $fds_str = (array) $server->redis->hKeys(CONNECTION_USER);
                $rel = $this->creJson(4,'全体被禁言');

                //获取当前机器IP
                $machine_ip_num = $this->getCurrentMachine();

                foreach ($fds_str as $v){
                    $arr = explode('_',$v);

                    if (current($arr) == $machine_ip_num) {
                        $server->push($arr[1],$rel);
                    }
                }
                break;
            case 5://解禁
                $rel = $this->creJson(5,'恢复发言');
                //全员解禁
                if (!$data_arr['data']) {

                    $fds_str = (array) $server->redis->hKeys(CONNECTION_USER);
                    $machine_ip_num = $this->getCurrentMachine();
                    //推送本机器
                    foreach ($fds_str as $v){
                        $arr = explode('_',$v);

                        if (current($arr) == $machine_ip_num) {
                            $server->push($arr[1],$rel);
                        }
                    }
                }else{//解禁某人
                    $server->push($data_arr['data'],$rel);
                }
                break;
            case 6://心跳包
                if (isset($data_arr['data']) && is_numeric($data_arr['data'])) {
                    $server->push($data_arr['data'],$this->creJson(6));
                }
                break;
            case 7://错误提示
                if (isset($data_arr['data']) && is_numeric($data_arr['data'])) {
                    $server->push($data_arr['data'],$this->creJson(7,$data_arr['msg']));
                }
                break;
            case 8://服务器主动中断所有连接
                $share_ids = (array) $server->redis->hKeys(CONNECTION_SHARE);
                $user_ids = (array) $server->redis->hkeys(CONNECTION_USER);

                $push_ids = array_merge($user_ids,$share_ids);
                $machine_ip_num = $this->getCurrentMachine();
                //本台机器的推送
                foreach ($push_ids as $v){

                    $arr = explode('_',$v);

                    if (current($arr) != $machine_ip_num) {
                        continue;
                    }
                    $server->push($arr[1],$this->creJson(8));
                    $server->close($arr[1],true);
                }
                break;
            default:
                $share_ids = (array) $server->redis->hKeys(CONNECTION_SHARE);
                $user_ids = (array) $server->redis->hkeys(CONNECTION_USER);
                $admin_ids = (array) $server->redis->hkeys(CONNECTION_ADMIN);

                $push_ids = array_merge($user_ids,$admin_ids,$share_ids);

                $machine_ip_num = $this->getCurrentMachine();

                //本台机器的推送
                foreach ($push_ids as $v){

                    $arr = explode('_',$v);

                    if (current($arr) != $machine_ip_num) {
                        continue;
                    }

                    $server->push($arr[1],json_encode($data_arr));
                }
                break;
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

        $data_arr = explode('&&',$data);

        if (!$data_arr || count($data_arr) == 1) {
            return;
        }

        if (count($data_arr) == 3) {
            $fd = $data_arr[0];
            $ip = $data_arr[1];
            $data_str = $data_arr[2];
        }else{
            $fd = array_shift($data_arr);
            $ip = array_shift($data_arr);
            $data_str = implode('&&',$data_arr);
        }

        if (!$data_arr || !is_numeric($fd) || !$data_str || !$ip) {
            return;
        }

        //获取机器内网IP
        $machine_ip_num = $this->getCurrentMachine();
        $fd_str = $ip.'_'.$fd;

        $share_ids = (array) $server->redis->hKeys(CONNECTION_SHARE);
        $admin_ids = (array) $server->redis->hkeys(CONNECTION_ADMIN);

        //分享连接禁止发言
        if (in_array($fd_str,$share_ids)) {
            $this->pushData($server,$ip,$machine_ip_num,$fd,$this->creJson(7,'您没有权限进行操作',$fd));
            return;
        }

        $flag = 0;
        $data = json_decode($data_str,true);
        switch ($data['code']){
            case 3://禁言某人
                $u_id = $data['data'];
                //用户篡改数据，使其变为指令非法操作功能
                if (!$u_id || !is_numeric($u_id) || !in_array($fd_str,$admin_ids)) {
                    break;
                }
                $connection_user = (array) $server->redis->hgetall(CONNECTION_USER);
                $fd_str = array_search($u_id,$connection_user);
                if ($fd_str) {

                    $fd_arr = explode('_',$fd_str);
                    if (current($fd_arr) == $machine_ip_num) {
                        //在本机器中
                        $server->push($fd_arr[1],$this->creJson(3,'您被禁言'));
                    }else{
                        //不在本机器中，发送到对应的服务器
                        $this->udpSend(current($fd_arr),$this->config['udp_port'],$this->creJson(3,'',$fd_arr[1]));
                    }
                    //禁言存入集合
                    $server->redis->sadd(NOT_ALLOW,$u_id);
                }
                break;
            case 4://全体被禁言
                if (!in_array($fd_str,$admin_ids)) {
                    break;
                }
                $fds_str = (array) $server->redis->hKeys(CONNECTION_USER);
                $rel = $this->creJson(4,'全体被禁言');

                foreach ($fds_str as $v){
                    $arr = explode('_',$v);

                    if (current($arr) == $machine_ip_num) {
                        $server->push($arr[1],$rel);
                    }
                }

                //更改聊天室状态
                $server->redis->set(CHAT_ROOM_STATUS,STATUS_ALL_DISABLED);

                //推送给除本机器外的所有其他机器
                $this->pushAllUdp($server,$data_str);
                break;
            case 5://解禁
                if (!in_array($fd_str,$admin_ids)) {
                    break;
                }
                $u_id = $data['data'];
                $rel = $this->creJson(5,'恢复发言');
                //全员解禁
                if (!$u_id) {

                    $fds_str = (array) $server->redis->hKeys(CONNECTION_USER);
                    //推送本机器
                    foreach ($fds_str as $v){

                        $arr = explode('_',$v);

                        if (current($arr) == $machine_ip_num) {
                            $server->push($arr[1],$rel);
                        }
                    }

                    //更改聊天室状态
                    $server->redis->set(CHAT_ROOM_STATUS,STATUS_NORMAL);

                    //推送给除本机器外的所有其他机器
                    $this->pushAllUdp($server,$this->creJson(5));
                }else{//解禁某人
                    $connection_user = (array) $server->redis->hgetall(CONNECTION_USER);
                    $fd_str = array_search($u_id,$connection_user);

                    //将用户移除禁言集合
                    $server->redis->srem(NOT_ALLOW,$u_id);

                    $fd_arr = explode('_',$fd_str);
                    if (current($fd_arr) == $machine_ip_num) {
                        //在本机器中
                        $server->push($fd_arr[1],$this->creJson(5));
                    }else{
                        //不在本机器中，发送到对应的服务器
                        $this->udpSend(current($fd_arr),$this->config['udp_port'],$this->creJson(5,'',$fd_arr[1]));
                    }
                }
                break;
            case 6:
                $this->pushData($server,$ip,$machine_ip_num,$fd,$this->creJson(6,'',$fd));
                break;
            case 8://服务端主动切断所有连接
                if (!in_array($fd_str,$admin_ids)) {
                    break;
                }
                $user_ids = (array) $server->redis->hkeys(CONNECTION_USER);
                $push_ids = array_merge($user_ids,$share_ids);

                //本台机器的推送
                foreach ($push_ids as $v){

                    $arr = explode('_',$v);

                    if (current($arr) != $machine_ip_num) {
                        continue;
                    }

                    $server->push($arr[1],$this->creJson(8));
                    $server->close($arr[1],true);
                }

                //推送给除本机器外的所有其他机器
                $this->pushAllUdp($server,$data_str);
                $this->overClear($server);
                break;
            case 9://点赞数量接收
                $like_num = $data['data'];
                if (!$like_num || !is_numeric($like_num)) {
                    return;
                }
                $num = $server->redis->get(LIKE_HEART_NUM);

                //超过一定数量同步各端数据
                if ((($num % $this->config['like_num_step']) + $like_num) >= $this->config['like_num_step']) {

                    $user_ids = (array) $server->redis->hkeys(CONNECTION_USER);
                    $push_ids = array_merge($user_ids,$admin_ids,$share_ids);

                    //本台机器的推送
                    foreach ($push_ids as $v){

                        $arr = explode('_',$v);

                        if (current($arr) != $machine_ip_num) {
                            continue;
                        }

                        $server->push($arr[1],$this->creJson(9,'',$like_num+$num));
                    }

                    //推送给除本机器外的所有其他机器
                    $this->pushAllUdp($server,$this->creJson(9,'',$like_num+$num));
                }
                $server->redis->incrby(LIKE_HEART_NUM,$like_num);
                break;
            default:
                $flag = 1;
                break;
        }

        //普通转发消息逻辑
        if ($flag) {

            //用户被禁止发言
            if ($server->redis->hexists(CONNECTION_USER,$fd_str)) {//该进程ID在用户列表中

                $u_id = $server->redis->hget(CONNECTION_USER,$fd_str);
                if ($server->redis->sismember(NOT_ALLOW,$u_id)) {
                    $this->pushData($server,$ip,$machine_ip_num,$fd,$this->creJson(7,'您被禁止发言',$fd));
                    return;
                }
                //全体禁言
                if ($server->redis->get(CHAT_ROOM_STATUS) == STATUS_ALL_DISABLED) {
                    $this->pushData($server,$ip,$machine_ip_num,$fd,$this->creJson(7,'全体禁言中',$fd));
                    return;
                }

                //发送间隔判断
                if (!$this->proSendTime($server,$fd_str)) {

                    $this->pushData($server,$ip,$machine_ip_num,$fd,$this->creJson(7,'请稍后再发',$fd));
                    return;
                }
            }

            //长度过滤
            if (!$this->lenFilter($data['data']['content'])) {
                $this->pushData($server,$ip,$machine_ip_num,$fd,$this->creJson(7,'发送字符超长'),$fd);
                return;
            }

            //违禁词过滤
            $data['data']['content'] = $this->wordFilter($server,$data['data']['content']);
            $data_str = json_encode($data);

            $user_ids = (array) $server->redis->hkeys(CONNECTION_USER);
            $push_ids = array_merge($user_ids,$admin_ids,$share_ids);

            //本台机器的推送
            foreach ($push_ids as $v){

                $arr = explode('_',$v);

                if (current($arr) != $machine_ip_num) {
                    continue;
                }

//                if ($arr[1] == $fd) {
//                    continue;
//                }
                $server->push($arr[1],$data_str);
            }

            //推送给除本机器外的所有其他机器
            $this->pushAllUdp($server,$data_str);
        }
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
        if (extension_loaded('redis')) {

            //判断密钥
            if (!isset($request->get['key'])) {
                $server->push($request->fd,$this->creJson(7,'非法连接'));
                $server->close($request->fd,true);
                return;
            }

            //根据密钥验证身份
            $data = $server->redis->get($request->get['key']);

            if (!$data) {
                unset($data);
                $server->push($request->fd,$this->creJson(7,'令牌错误或超时失效'));
                $server->close($request->fd,true);
                return;
            }else{

                //获取机器内网IP
                $machine_ip_num = $this->getCurrentMachine();

                //用户连接
                if (substr($request->get['key'],0,1) == 'U') {
                    $user_arr = (array) $server->redis->hvals(CONNECTION_USER);

                    if ($data == 'test') {

                        if (!$this->config['debug']) {
                            $server->push($request->fd,$this->creJson(7,'非法连接'));
                            $server->close($request->fd,true);
                            return;
                        }
                        $data = '测试机器人';

                    }else{
                        if (in_array($data,$user_arr)) {
                            $server->push($request->fd,$this->creJson(7,'用户已在线'));
                            $server->close($request->fd,true);
                            return;
                        }
                    }

                    //存入哈希表
                    $server->redis->hset(CONNECTION_USER,$machine_ip_num.'_'.$request->fd,$data);
                }

                //分享用户连接
                if (substr($request->get['key'],0,1) == 'S') {
                    $user_arr = (array) $server->redis->hvals(CONNECTION_SHARE);

                    $share_connection = 0;

                    //该用户ID分享的链接被点击并在线的数量
                    if ($user_arr) {
                        $share_connection = count($user_arr) - count(array_diff($user_arr,[$data]));
                    }

                    if ($this->config['share_num']) {
                        //满足设置数量
                        if ($this->config['share_num'] > $share_connection) {
                            $server->redis->hset(CONNECTION_SHARE,$machine_ip_num.'_'.$request->fd,$data);
                        }else{
                            $server->push($request->fd,$this->creJson(7,'连接用户过多'));
                            $server->close($request->fd,true);
                            return;
                        }
                    }else{
                        $server->push($request->fd,$this->creJson(7,'请先登录'));
                        $server->close($request->fd,true);
                        return;
                    }
                    unset($share_connection);
                }

                //管理员连接
                if (substr($request->get['key'],0,1) == 'A') {
                    $user_arr = (array) $server->redis->hvals(CONNECTION_ADMIN);
                    if (in_array($data,$user_arr)) {
                        $server->push($request->fd,$this->creJson(7,'用户已在线'));
                        $server->close($request->fd,true);
                        return;
                    }

                    $server->redis->hset(CONNECTION_ADMIN,$machine_ip_num.'_'.$request->fd,$data);
                }
                unset($data);

                //IP不在集合中
                if (!$server->redis->sismember(MACHINE_IPS,$machine_ip_num)) {
                    $server->redis->sadd(MACHINE_IPS,$machine_ip_num);
                }

                unset($machine_ip_num);
            }
        }else{
            $server->push($request->fd,$this->creJson(7,'服务器出现错误'));
            $server->close($request->fd,true);
            return;
        }
    }


    /**
     * 监听来自客户端的数据
     * 当服务器收到来自客户端的数据帧时会回调此函数
     * @param $server
     * @param $frame object $frame 是websocket_frame对象，包含了客户端发来的数据帧信息
     */
    public function onMessage($server,$frame){
        //入队操作
        $server->redis->lpush(TASK_LIST,$frame->fd.'&&'.$this->getCurrentMachine().'&&'.$frame->data);
    }

    /**
     * 客户端断开连接
     * @param $server
     * @param $fd
     */
    public function onClose($server,$fd){

        $machine_ip_num = $this->getCurrentMachine();
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
    private function getCurrentMachine(){
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

    //发送间隔判断
    private function proSendTime($server,$fd_str){

        if (!$fd_str) {
            return false;
        }

        if (!$server->redis->hexists(SEND_TIME_ROLE,$fd_str)) {
            $server->redis->hset(SEND_TIME_ROLE,$fd_str,time());
            return true;
        }else{
            $time = $server->redis->hget(SEND_TIME_ROLE,$fd_str);

            if ((time()-$time) <= $this->config['send_time']) {
                return false;
            }
            unset($time);
        }
        return true;
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

        $machine_ip_num = $this->getCurrentMachine();

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

    //IP转化为IPv2字符串
    public function convertIpToString($ip)
    {
        $long = 4294967295 - ($ip - 1);
        return long2ip(-$long);
    }
    //字符串转化为整型
    public function convertIpToLong($ip)
    {
        return sprintf("%u", ip2long($ip));
    }

    /**
     * 输出数据到客户端
     * @param int $code
     * @param string $msg
     * @param string $data
     * @param int $type
     */
    protected function creJson($code=1,$msg='',$data=[]){

        $rel = [
            'code' => $code,
            'msg' => $msg,
            'data' => $data
        ];

        return json_encode($rel);
    }
}