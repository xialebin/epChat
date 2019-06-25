<?php

require ROOT.'/base/WebSocketBase.php';
require ROOT.'/Bloom/BloomFilterRedis.php';

/**
 * @chartRoom 直播聊天室
 * Class ChatRoom
 */
class  ChatRoomOperation extends WebSocketBase
{

    const CONNECTION_USER = 'chat_room_connection_user';//用户连接信息 哈希 （进程标记=>用户ID）
    const CONNECTION_ADMIN = 'chat_room_connection_admin';//管理者连接信息 哈希 （进程标记=>管理用户ID）
    const CONNECTION_SHARE = 'chat_room_connection_share';//分享连接信息 哈希（进程标记=>分享者的用户ID）
    const NOT_ALLOW = 'char_room_not_allow_user';//被禁言用户 集合（用户ID）
    const MACHINE_IPS = 'chat_room_machine_ips';//机器IPs 集合（机器的IP的整型）
    const SEND_TIME_ROLE = 'user_send_time_role';//用户发送时间规则 哈希（进程标记=>最近一次消息的时间戳）
    const TASK_LIST = 'chat_room_message_task_list';//聊天室消息队列
    const CHAT_ROOM_STATUS = 'chat_room_connection_status';//聊天室状态 字符串
    const LIKE_HEART_NUM = 'chat_room_like_heart_num';//直播间点赞数量
    const STATUS_NORMAL = 1;//正常状态
    const STATUS_ALL_DISABLED = 2;//全体禁言
    private $bloomFilterObj = NULL;//布隆过滤器对象

    /**
     * 前置操作
     * @return bool|void
     */
    public function init(){

        //清空用户哈希表 防止由于服务重启导致用户登录竞争
        $this->cache->del(self::CONNECTION_USER);
        $this->cache->del(self::CONNECTION_ADMIN);
        $this->cache->del(self::CONNECTION_SHARE);

        //初始化布隆过滤器
        $this->bloomFilterObj = new BloomFilterRedis($this->cache_config,$this->config['bucket_name']);

        $arr = [
            'worker_num' => $this->config['worker_num'],//设置启动的Worker进程数
            'task_worker_num' => $this->config['task_worker_num'],
        ];
        $this->set($arr);

        //新增task任务回调函数，使服务端程序支持多进程异步处理
        $this->server->on('Task',[$this,'onTask']);
        $this->server->on('Finish',[$this,'onFinish']);
        $this->server->on('WorkerStart',[$this,'onWorkerStart']);

        //监听UDP端口，用于接收其它“机器”发来的数据包，应用在集群部署场景下
        $port = $this->server->listen($this->config['udp_host'],$this->config['udp_port'],SWOOLE_SOCK_UDP);

        $port->on('packet',function ($server,$data,$addr) {

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
                    $this->server->push($data_arr['data'],$this->cre_json(3,'您被禁言'));
                    break;
                case 4://全体被禁言
                    $fds_str = $this->cache->hKeys(self::CONNECTION_USER);
                    $rel = $this->cre_json(4,'全体被禁言');

                    //获取当前机器IP
                    $machine_ip_num = $this->get_current_machine();

                    foreach ($fds_str as $v){
                        $arr = explode('_',$v);

                        if (current($arr) == $machine_ip_num) {
                            $this->server->push($arr[1],$rel);
                        }
                    }
                    break;
                case 5://解禁
                    $rel = $this->cre_json(5,'恢复发言');
                    //全员解禁
                    if (!$data_arr['data']) {

                        $fds_str = $this->cache->hKeys(self::CONNECTION_USER);
                        $machine_ip_num = $this->get_current_machine();
                        //推送本机器
                        foreach ($fds_str as $v){
                            $arr = explode('_',$v);

                            if (current($arr) == $machine_ip_num) {
                                $this->server->push($arr[1],$rel);
                            }
                        }
                    }else{//解禁某人
                        $this->server->push($data_arr['data'],$rel);
                    }
                    break;
                case 8://服务器主动中断所有连接
                    $share_ids = $this->cache->hKeys(self::CONNECTION_SHARE);
                    $user_ids = $this->cache->hkeys(self::CONNECTION_USER);

                    $push_ids = array_merge($user_ids,$share_ids);
                    $machine_ip_num = $this->get_current_machine();
                    //本台机器的推送
                    foreach ($push_ids as $v){

                        $arr = explode('_',$v);

                        if (current($arr) != $machine_ip_num) {
                            continue;
                        }
                        $this->server->push($arr[1],$this->cre_json(8));
                        $this->server->close($arr[1],true);
                    }
                    $this->message_cache->del(self::TASK_LIST);
                    break;
                default:
                    $share_ids = $this->cache->hKeys(self::CONNECTION_SHARE);
                    $user_ids = $this->cache->hkeys(self::CONNECTION_USER);
                    $admin_ids = $this->cache->hkeys(self::CONNECTION_ADMIN);

                    $push_ids = array_merge($user_ids,$admin_ids,$share_ids);

                    $machine_ip_num = $this->get_current_machine();

                    //本台机器的推送
                    foreach ($push_ids as $v){

                        $arr = explode('_',$v);

                        if (current($arr) != $machine_ip_num) {
                            continue;
                        }
                        $this->server->push($arr[1],$data);
                    }
                    break;
            }
        });

    }

    /**
     * 进程启动时回调函数
     * @param $server
     * @param $worker_id
     */
    public function onWorkerStart($server,$worker_id){
        //如果不是task新起进程则调用计时器
        if (!$server->taskworker) {
            //20秒后执行此函数
            //延迟几秒钟是确保redis已经成功连接，如果实时调用，因为workerStart与start是同步执行的，否则会报redis连接错误
            swoole_timer_after(20000, function (){
                $this->process_tick();
            });
        }
    }


    //定时器执行
    private function process_tick(){
        swoole_timer_tick($this->config['tick_time'],function ($timer_id){
            //出队操作
            $data = $this->message_cache->rpop(self::TASK_LIST);
            //投递任务
            if ($data) {
                $this->server->task($data);
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

        if (count($data_arr) == 2) {
            $fd = $data_arr[0];
            $data_str = $data_arr[1];
        }else{
            $fd = array_shift($data_arr);
            $data_str = implode('&&',$data_arr);
        }

        if (!$data_arr || !is_numeric($fd) || !$data_str) {
            return;
        }

        //获取机器内网IP
        $machine_ip_num = $this->get_current_machine();
        $fd_str = $machine_ip_num.'_'.$fd;

        $share_ids = $this->cache->hKeys(self::CONNECTION_SHARE);
        $admin_ids = $this->cache->hkeys(self::CONNECTION_ADMIN);

        //分享连接禁止发言
        if (in_array($fd_str,$share_ids)) {
            $server->push($fd,$this->cre_json(7,'您没有权限进行操作'));
            return;
        }

        $flag = 0;
        $data = json_decode($data_str,true);
        switch ($data['code']){
            case 3://禁言某人
                $u_id = $data['data'];
                if (!$u_id || !is_numeric($u_id) || !in_array($fd_str,$admin_ids)) {
                    break;
                }
                $connection_user = $this->cache->hgetall(self::CONNECTION_USER);
                $fd_str = array_search($u_id,$connection_user);
                if ($fd_str) {

                    $fd_arr = explode('_',$fd_str);
                    if (current($fd_arr) == $machine_ip_num) {
                        //在本机器中
                        $server->push($fd_arr[1],$this->cre_json(3,'您被禁言'));
                    }else{
                        //不在本机器中，发送到对应的服务器
                        $this->udp_send(current($fd_arr),$this->config['udp_port'],$this->cre_json(3,'',$fd_arr[1]));
                    }
                    //禁言存入集合
                    $this->cache->sadd(self::NOT_ALLOW,$u_id);
                }
                break;
            case 4://全体被禁言
                if (!in_array($fd_str,$admin_ids)) {
                    break;
                }
                $fds_str = $this->cache->hKeys(self::CONNECTION_USER);
                $rel = $this->cre_json(4,'全体被禁言');

                foreach ($fds_str as $v){
                    $arr = explode('_',$v);

                    if (current($arr) == $machine_ip_num) {
                        $server->push($arr[1],$rel);
                    }
                }

                //更改聊天室状态
                $this->cache->set(self::CHAT_ROOM_STATUS,self::STATUS_ALL_DISABLED);

                //推送给除本机器外的所有其他机器
                $this->push_all_udp($data_str);
                break;
            case 5://解禁
                if (!in_array($fd_str,$admin_ids)) {
                    break;
                }
                $u_id = $data['data'];
                $rel = $this->cre_json(5,'恢复发言');
                //全员解禁
                if (!$u_id) {

                    $fds_str = $this->cache->hKeys(self::CONNECTION_USER);
                    //推送本机器
                    foreach ($fds_str as $v){

                        $arr = explode('_',$v);

                        if (current($arr) == $machine_ip_num) {
                            $server->push($arr[1],$rel);
                        }
                    }

                    //更改聊天室状态
                    $this->cache->set(self::CHAT_ROOM_STATUS,self::STATUS_NORMAL);

                    //推送给除本机器外的所有其他机器
                    $this->push_all_udp($this->cre_json(5));
                }else{//解禁某人
                    $connection_user = $this->cache->hgetall(self::CONNECTION_USER);
                    $fd_str = array_search($u_id,$connection_user);

                    //将用户移除禁言集合
                    $this->cache->srem(self::NOT_ALLOW,$u_id);

                    $fd_arr = explode('_',$fd_str);
                    if (current($fd_arr) == $machine_ip_num) {
                        //在本机器中
                        $server->push($fd_arr[1],$this->cre_json(5));
                    }else{
                        //不在本机器中，发送到对应的服务器
                        $this->udp_send(current($fd_arr),$this->config['udp_port'],$this->cre_json(5,'',$fd_arr[1]));
                    }
                }
                break;
            case 6://心跳包
                $server->push($fd,$data_str);
                break;
            case 8://服务端主动切断所有连接
                if (!in_array($fd_str,$admin_ids)) {
                    break;
                }
                $user_ids = $this->cache->hkeys(self::CONNECTION_USER);
                $push_ids = array_merge($user_ids,$share_ids);

                //本台机器的推送
                foreach ($push_ids as $v){

                    $arr = explode('_',$v);

                    if (current($arr) != $machine_ip_num) {
                        continue;
                    }

                    $server->push($arr[1],$this->cre_json(8));
                    $server->close($arr[1],true);
                }

                //推送给除本机器外的所有其他机器
                $this->push_all_udp($data_str);

                $this->message_cache->del(self::TASK_LIST);
                $this->over_clear();
                break;
            case 9://点赞数量接收
                $like_num = $data['data'];
                if (!$like_num || !is_numeric($like_num)) {
                    return;
                }
                $num = $this->cache->get(self::LIKE_HEART_NUM);

                //超过一定数量同步各端数据
                if ((($num % $this->config['like_num_step']) + $like_num) >= $this->config['like_num_step']) {

                    $user_ids = $this->cache->hkeys(self::CONNECTION_USER);
                    $push_ids = array_merge($user_ids,$admin_ids,$share_ids);

                    //本台机器的推送
                    foreach ($push_ids as $v){

                        $arr = explode('_',$v);

                        if (current($arr) != $machine_ip_num) {
                            continue;
                        }

                        $server->push($arr[1],$this->cre_json(9,'',$like_num+$num));
                    }

                    //推送给除本机器外的所有其他机器
                    $this->push_all_udp($this->cre_json(9,'',$like_num+$num));
                }
                $this->cache->incrby(self::LIKE_HEART_NUM,$like_num);
                break;
            default:
                $flag = 1;
                break;
        }

        //普通转发消息逻辑
        if ($flag) {

            //用户被禁止发言
            if ($this->cache->hexists(self::CONNECTION_USER,$fd_str)) {//该进程ID在用户列表中

                $u_id = $this->cache->hget(self::CONNECTION_USER,$fd_str);
                if ($this->cache->sismember(self::NOT_ALLOW,$u_id)) {
                    $server->push($fd,$this->cre_json(3,'您被禁止发言'));
                    return;
                }
                //全体禁言
                if ($this->cache->get(self::CHAT_ROOM_STATUS) == self::STATUS_ALL_DISABLED) {
                    $server->push($fd,$this->cre_json(4,'全体禁言中'));
                    return;
                }
            }

            //用户发来信息 校验
            if ($this->cache->hexists(self::CONNECTION_USER,$fd_str)) {
                //发送间隔判断
                if (!$this->pro_send_time($fd_str)) {
                    $server->push($fd,$this->cre_json(7,'请稍后再发'));
                    return;
                }
            }

            //长度过滤
            if (!$this->len_filter($data['data']['content'])) {
                $server->push($fd,$this->cre_json(7,'发送字符超长'));
                return;
            }

            //违禁词过滤
            $data['data']['content'] = $this->word_filter($data['data']['content']);
            $data_str = json_encode($data);

            $user_ids = $this->cache->hkeys(self::CONNECTION_USER);
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
            $this->push_all_udp($data_str);
        }

        unset($fd_str);
        unset($machine_ip_num);

        //$server->finish();
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
                $server->push($request->fd,$this->cre_json(7,'非法连接'));
                $server->close($request->fd,true);
                return;
            }

            //根据密钥验证身份
            $data = $this->cache->get($request->get['key']);

            if (!$data) {
                unset($data);
                $server->push($request->fd,$this->cre_json(7,'令牌错误或超时失效'));
                $server->close($request->fd,true);
                return;
            }else{

                //获取机器内网IP
                $machine_ip_num = $this->get_current_machine();

                //用户连接
                if (substr($request->get['key'],0,1) == 'U') {
                    $user_arr = $this->cache->hvals(self::CONNECTION_USER);

                    if ($data == 'test') {
                        $data = '测试机器人';
                    }else{
                        if (in_array($data,$user_arr)) {
                            $server->push($request->fd,$this->cre_json(7,'用户已在线'));
                            $server->close($request->fd,true);
                            return;
                        }
                    }

                    //存入哈希表
                    $this->cache->hset(self::CONNECTION_USER,$machine_ip_num.'_'.$request->fd,$data);
                }

                //分享用户连接
                if (substr($request->get['key'],0,1) == 'S') {
                    $user_arr = $this->cache->hvals(self::CONNECTION_SHARE);

                    $share_connection = 0;

                    //该用户ID分享的链接被点击并在线的数量
                    if ($user_arr) {
                        $share_connection = count($user_arr) - count(array_diff($user_arr,[$data]));
                    }

                    if ($this->config['share_num']) {
                        //满足设置数量
                        if ($this->config['share_num'] > $share_connection) {
                            $this->cache->hset(self::CONNECTION_SHARE,$machine_ip_num.'_'.$request->fd,$data);
                        }else{
                            $server->push($request->fd,$this->cre_json(7,'连接用户过多'));
                            $server->close($request->fd,true);
                            return;
                        }
                    }else{
                        $server->push($request->fd,$this->cre_json(7,'请先登录'));
                        $server->close($request->fd,true);
                        return;
                    }
                    unset($share_connection);
                }

                //管理员连接
                if (substr($request->get['key'],0,1) == 'A') {
                    $user_arr = $this->cache->hvals(self::CONNECTION_ADMIN);
                    if (in_array($data,$user_arr)) {
                        $server->push($request->fd,$this->cre_json(7,'用户已在线'));
                        $server->close($request->fd,true);
                        return;
                    }

                    $this->cache->hset(self::CONNECTION_ADMIN,$machine_ip_num.'_'.$request->fd,$data);
                }
                unset($data);

                //IP不在集合中
                if (!$this->cache->sismember(self::MACHINE_IPS,$machine_ip_num)) {
                    $this->cache->sadd(self::MACHINE_IPS,$machine_ip_num);
                }

                unset($machine_ip_num);
            }
        }else{
            $server->push($request->fd,$this->cre_json(7,'服务器出现错误'));
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
        $this->message_cache->lpush(self::TASK_LIST,$frame->fd.'&&'.$frame->data);
    }

    /**
     * 客户端断开连接
     * @param $server
     * @param $fd
     */
    public function onClose($server,$fd){

        $machine_ip_num = $this->get_current_machine();
        $fd_str = $machine_ip_num.'_'.$fd;

        $is_share = $this->cache->hexists(self::CONNECTION_SHARE,$fd_str);
        if ($is_share) {
            $this->cache->hdel(self::CONNECTION_SHARE,$fd_str);
        }else{
            $is_user = $this->cache->hexists(self::CONNECTION_USER,$fd_str);
            if ($is_user) {
                $this->cache->hdel(self::CONNECTION_USER,$fd_str);
                //清理消息间隔限制
                if ($this->cache->hexists(self::SEND_TIME_ROLE,$fd_str)) {
                    $this->cache->hdel(self::SEND_TIME_ROLE,$fd_str);
                }
            }else{
                $this->cache->hdel(self::CONNECTION_ADMIN,$fd_str);
            }
        }
    }

    /**
     * 获取当前机器的IP，返回整型
     */
    private function get_current_machine(){
        //获取机器内网IP
        return sprintf("%u", ip2long(current(explode(' ',exec('hostname -I')))));
    }


    //全部中断时，清理内存
    private function over_clear(){
        $this->cache->del(self::NOT_ALLOW);
        $this->cache->del(self::MACHINE_IPS);
        $this->cache->del(self::SEND_TIME_ROLE);
    }

    //发送间隔判断
    private function pro_send_time($fd_str){
        
        if (!$fd_str) {
            return false;
        }

        if (!$this->cache->hexists(self::SEND_TIME_ROLE,$fd_str)) {
            $this->cache->hset(self::SEND_TIME_ROLE,$fd_str,time());
            return true;
        }else{
            $time = $this->cache->hget(self::SEND_TIME_ROLE,$fd_str);

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
    public function len_filter($word){
        if (mb_strlen($word,'utf8')>$this->config['data_len']) {
            return false;
        }
        return true;
    }


    /**
     * 关键词过滤
     * @param $word
     */
    public function word_filter($word){
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
                if ($this->bloomFilterObj->exists($data)) {
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
    public function push_all_udp($data){

        $machine_ip_num = $this->get_current_machine();

        //推送给除本机器外的所有其他机器
        $other_machine = array_diff($this->cache->smembers(self::MACHINE_IPS),[$machine_ip_num]);

        if ($other_machine) {
            foreach ($other_machine as $v){
                //推送给其他机器
                $this->udp_send($v,$this->config['udp_port'],$data);
            }
        }
    }


    /**
     * 作为客户端给服务端发送信息
     * @param $host
     * @param int $port
     * @param string $data
     */
    public function udp_send($host,$port=9502,$data=''){

        if (is_numeric($host)) {
            $host = long2ip($host);
        }

        //对udp通道发送的数据进行加密，并携带公钥传送，接收端根据私钥‘udp_key'进行数据的验证
        $data = md5($this->config['udp_key'].$data).'&&'.$data;

        $client = new swoole_client(SWOOLE_SOCK_UDP);
        if (!$client->connect($host,$port,-1)){
            return;
        }

        $client->sendto($host,$port,$data);
        $client->close();
    }

    /**
     * 输出数据到客户端
     * @param int $code
     * @param string $msg
     * @param string $data
     * @param int $type
     */
    protected function cre_json($code=1,$msg='',$data=[]){

        $rel = [
            'code' => $code,
            'msg' => $msg,
            'data' => $data
        ];

        return json_encode($rel);
    }

    /**
     * 开启服务回调函数 仅支持 echo 进行打印log，修改进程名称
     */
    /*public function onStart(){
        \swoole_set_process_name($this->config['process_name']);
    }*/
}