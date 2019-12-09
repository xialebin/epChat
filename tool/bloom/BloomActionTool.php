<?php
/**
 * 布隆过滤器
 * @author xialebin@163.com
 */
namespace chat\tool\bloom;


class BloomActionTool
{
    protected $bucket = 'bucket_name';
    protected $hashFunction = ['ELFHash','DJBHash','DEKHash'];
    protected $Hash = NULL;
    protected $redis = NULL;

    public function __construct($config=[],$bucket_name='')
    {
        $this->Hash = new BloomHashTool();

        if ($bucket_name) {
            $this->bucket = $bucket_name;
        }

        if ($config) {
            $redis = new \Redis();
            $this->redis = $redis;

            //缓存连接
            $this->redis->connect($config['host'],$config['port']);

            if ('' != $config['password']) {
                $this->redis->auth($config['password']);
            }
        }
    }

    /**
     * 添加到集合中
     */
    public function add($string,$redis_obj=null)
    {

        if (!$redis_obj) {
            if (!$this->redis) {
                return false;
            }
            $redis_obj = $this->redis;
        }

        //开启redis事务
        $pipe = $redis_obj->multi();
        foreach ($this->hashFunction as $function) {
            $hash = $this->Hash->$function($string);
            $pipe->setBit($this->bucket,$hash,1);
        }
        return $pipe->exec();
    }

    /**
     * 查询是否存在, 存在的一定会存在, 不存在有一定几率会误判
     */
    public function exists($string,$redis_obj=null)
    {

        if (!$redis_obj) {
            if (!$this->redis) {
                return false;
            }
            $redis_obj = $this->redis;
        }

        $pipe = $redis_obj->multi();
        $len = strlen($string);
        foreach ($this->hashFunction as $function) {
            $hash = $this->Hash->$function($string, $len);
            $pipe = $pipe->getBit($this->bucket,$hash);
        }
        $res = $pipe->exec();
        foreach ($res as $bit) {
            if ($bit == 0) {
                return false;
            }
        }
        return true;
    }
}