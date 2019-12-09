<?php
namespace chat\tra;

trait UnifyMessageTrait{


    public function initDefineUnifyMessage(){

        define('CONNECTION_USER',REDIS_PRE.'chat_room_connection_user');//用户连接信息 哈希 （进程标记=>用户ID）

        define('CONNECTION_ROOM',REDIS_PRE.'chat_room_connection_room');//用户连接信息 哈希 （进程标记=>用户ID）


        define('NOT_ALLOW',REDIS_PRE.'char_room_not_allow_user');//被禁言用户 集合（用户ID）
        define('MACHINE_IPS',REDIS_PRE.'chat_room_machine_ips');//机器IPs 集合（机器的IP的整型）
        define('SEND_TIME_ROLE',REDIS_PRE.'user_send_time_role');//用户发送时间规则 哈希（进程标记=>最近一次消息的时间戳）
        define('TASK_LIST',REDIS_PRE.'chat_room_task_list_message_1');//聊天室消息队列
        define('CHAT_ROOM_STATUS',REDIS_PRE.'chat_room_connection_status');//聊天室状态 字符串
        define('LIKE_HEART_NUM',REDIS_PRE.'chat_room_like_heart_num');//直播间点赞数量
        define('STATUS_NORMAL',1);//正常状态
        define('STATUS_ALL_DISABLED',2);//全体禁言
        define('E','ERROR');//错误

        define('E_1','用户已在线');
        define('E_2','连接用户过多');
        define('E_3','请先进行登录');
        define('E_4','非法连接');
        define('E_5','令牌错误或超时失效');
        define('E_6','您没有权限进行操作');
        define('E_7','用户已在线');
        define('E_8','连接参数错误');
        define('E_9','发送信息参数错误');
    }


    //创建统一信息交互 服务端<=>客户端
    public function unifyMess($msg,$data,$source_connection_id=false,$target_connection_id=false,$type=false){

        $info['msg'] = $msg;
        $info['data'] = $data;

        if ($source_connection_id !== false) {
            $info['source_connection_id'] = $source_connection_id;
        }
        if ($target_connection_id !== false) {
            $info['target_connection_id'] = $target_connection_id;
        }
        if ($type !== false) {
            $info['type'] = $type;
        }

        return json_encode($info);
    }


}