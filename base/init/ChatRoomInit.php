<?php

namespace chat\base\init;

class ChatRoomInit implements WebSocketInit
{
    private $check = [
        'port',
        'host',
        'share_num',
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
        'redis_pre',
    ];

    private $param = [];
    private $default = [];

    public function __construct($config,$default)
    {
        $this->param = $config;
        $this->default = $default;
    }

    //检查参数
    public final function checkParam()
    {
        $diff = array_diff($this->check,array_keys($this->param));

        if ($diff) {
            return implode(',',$diff).' is not existed';
        }

        return $this;
    }

    //初始化参数
    public final function initParam()
    {

        foreach ($this->param as $k => $v){

            if ($k ==  'message_flag') {
                $this->param[$k] = array_merge($this->default[$k],$v);
                continue;
            }

            if ($v == 'default') {
                $this->param[$k] = $this->default[$k];
            }
        }

        return $this->param;
    }

}