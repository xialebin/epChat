<?php

return [

    'redis' => [
        // 缓存前缀
        'prefix' => '',
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        // 缓存有效期 0表示永久缓存
        'expire' => 0,
    ],

    'message_flag' => [
        'MESSAGE',
        'BANNED',
        'BAN',
        'CUT',
    ],

    'status' => true,
    'port' => 9501,
    'host' => '0.0.0.0',
    'share_num' => 1, //每个用户分享直播能被多少未登录用户同时连接
    'process_name' => 'chat_room',//进程别名
    'udp_host' => '0.0.0.0',
    'udp_port' => 9502,//udp协议 接包服务监听端口
    'udp_key' => '123456;;',//服务端udp接收包时的密钥
    'send_time' => 1,//消息发送时间间隔 单位为秒
    'tick_time' => 50,//毫米定时器的时间间隔，用于消息队列出队时候的控制 单位毫秒
    'worker_num' => 2,//服务占用多少个主进程
    'task_worker_num' => 10,//用于异步转发消息时的进程数量
    'bucket_name' => 'chat_room_filter_word_bit',//存放违禁词的key
    'data_len' => 30,//每句话字符长度的限制
    'like_num_step' => 1000,//新增多少点赞的数量会同步一次数据
    'debug' => false,//是否开启调试，允许测试通达打开
    'max_chat_room' => 100,//最多同时开启多少个聊天室
    //'error_log_path' => ROOT.'/log',//错误日志路径
    'redis_pre' => 'bin_',//Redis字段统一前缀
    'extension' => ['redis','swoole'],
    'version' => ['php'=>'7.0']
];