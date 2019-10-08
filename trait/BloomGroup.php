<?php
/**
 * 布隆过滤器组件执行入口
 */

trait BloomGroup
{

    //初始化违禁词
    public function initIllegalWord($key='',$redis_obj=null){

        if (!$key || !is_object($redis_obj) || !is_file(ROOT.'/tool/bloom/word.php')) {
            return 0;
        }

        $word = include(ROOT.'/tool/bloom/word.php');

        if (!is_array($word) || count($word) == 0) {
            return 0;
        }

        $word = array_unique($word);
        $operation = new BloomActionTool([],$key);

        $s = 0;$e = 0;
        foreach ($word as $v){

            $arr = explode(' ',$v);

            if (count($arr) == 1) {
                $operation->add($v,$redis_obj) ? $s++ : $e++;
            }else{
                foreach ($arr as $value){
                    $operation->add($value,$redis_obj) ? $s++ : $e++;
                }
            }
        }

        unset($operation);
        return ['success' => $s,'error' => $e];
    }


    //获取操作对象
    public static function getBloomOperationObj($buck_name,$config=[]){
        return new BloomActionTool($config,$buck_name);
    }


}