<?php

namespace chat\exec;

use chat\base\ChatRoom;

class test extends ChatRoom
{

    //发送普通消息
    public function message($server,$message,$connection_id){

        $room_id = $this->getRoomIdByConnectionId($server,$connection_id);
        $user_id = $this->getUserIdByConnectionId($server,$connection_id);

        $allow_flag = $this->checkMessageIsAllow($server,$message,$room_id,$user_id);

        if (is_string($allow_flag)) {
            $this->pushClientWarning($server,$allow_flag,$connection_id);
            return;
        }

        $data = [
            'message' => $message,
            'user_id' => $user_id
        ];

        $this->pushDataToAllClient($server,'MESSAGE',$data,$connection_id);
    }


    //禁言
    public function ban($server,$data,$connection_id){

        //禁言某人
        if ($data != 0) {
            //根据发送者的身份获取 聊天室ID
            $room_id = $this->getRoomIdByConnectionId($server,$connection_id);

            $target_connection_id = $this->getConIdByUserAndRoom($server,$data,$room_id);
            //推送消息
            $this->pushDataToOneClient($server,'BAN','',$target_connection_id);
            $server->redis->sadd(NOT_ALLOW.'_'.$room_id,$data);

        }else{ //全员禁言
            $this->pushDataToAllClient($server,'BAN','',$connection_id);
            //更改聊天室状态
            $server->redis->set(CHAT_ROOM_STATUS,STATUS_ALL_DISABLED);
        }
    }


    //解禁
    public function banned($server,$data,$connection_id){

        if ($data != 0) {
            //根据发送者的身份获取 聊天室ID
            $room_id = $this->getRoomIdByConnectionId($server,$connection_id);
            $target_connection_id = $this->getConIdByUserAndRoom($server,$data,$room_id);
            //推送消息
            $this->pushDataToOneClient($server,'BANNED','',$target_connection_id);
            $server->redis->srem(NOT_ALLOW.'_'.$room_id,$data);

        }else{
            $this->pushDataToAllClient($server,'BANNED','',$connection_id);
            //更改聊天室状态
            $server->redis->set(CHAT_ROOM_STATUS,STATUS_NORMAL);
        }
    }


    //服务端中断连接
    public function cut($server,$data,$connection_id){

        $room_id = $this->getRoomIdByConnectionId($server,$connection_id);

        $target_connection_id = $this->getConIdByUserAndRoom($server,$data,$room_id);
        $this->cutClientConnection($server,$target_connection_id);
    }


}