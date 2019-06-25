<?php
/**
 * 初始化违禁词库
 * Date: 2019/6/12
 * Time: 10:21
 */

define('ROOT',__DIR__);//定义初始目录

require ROOT.'/Bloom/BloomFilterRedis.php';

$word = include(ROOT.'/Bloom/word.php');
$config = include(ROOT.'/config.php');

$operation = new BloomFilterRedis($config['redis'],$config['chat_room']['bucket_name']);

$word = array_unique($word);

$i = 0;
foreach ($word as $v){
    $arr = explode(' ',$v);
    if (count($arr) == 1) {
        $operation->add($v);
    }else{
        $str = implode('',$arr);
        $operation->add($str);
    }
    $i++;
}
echo '成功导入'.$i.'条违禁词';





