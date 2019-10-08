<?php
/**
 * 工具方法
 * @author xialebin@163.com
 */

trait UtilFunction
{

    //比较版本号
    public function judgeVersion($version_1='',$version_2=''){

        $arr_1 = explode('.',$version_1);
        $arr_2 = explode('.',$version_2);

        $num_1 = count($arr_1);
        $num_2 = count($arr_2);

        $num = max($num_1,$num_2);
        $rec = $num_1 > $num_2 ? ($num_1 - $num_2) : ($num_2 - $num_1);


        foreach (array_merge($arr_1,$arr_2) as $v){

            if (!is_numeric($v)) {
                return 0;
            }
        }


        for ($i=0;$i<$num;$i++){

            if ($i < ($num - $rec)) {

                if ($arr_1[$i] == $arr_2[$i]) {
                    continue;
                }else{
                    return $arr_1[$i] > $arr_2[$i] ? 1 : -1;
                }

            }else{
                return isset($arr_1[$i]) ? 1 : -1;
            }
        }

        return 0;
    }


    //处理报错信息
    public function submitLog($message,$type='ERROR'){

        $this->writeLog($message,$this->getLogPath($type));

        if ($type == 'ERROR') {
            echo "ERROR : ".$message.PHP_EOL;
            exit();
        }
    }


    //获取日志路径
    public function getLogPath($type,$base_log=''){

        if (!$base_log) {
            $base_log = ROOT.DIRECTORY_SEPARATOR.'log';
        }

        switch ($type){
            //错误日志
            case 'ERROR':
                $path = $base_log.DIRECTORY_SEPARATOR.'error-log'.DIRECTORY_SEPARATOR.date('Ymd');
                if (!is_dir($path)) {
                    mkdir($path,0777,true);
                }
                return $path.DIRECTORY_SEPARATOR.'error_log.txt';
            case 'RUN':
                $path = $base_log.DIRECTORY_SEPARATOR.'run-log';
                if (!is_dir($path)) {
                    mkdir($path,0777,true);
                }
                return $path.DIRECTORY_SEPARATOR.'run_log.txt';
            default:
                break;
        }
        return '';
    }


    //写入日志
    public function writeLog($content='',$path=''){

        if (!$content || !$path) {
            return false;
        }

        $content = date('Y-m-d H:i:s').' - '.$content.PHP_EOL;

        try{

            $fp = fopen($path,'a+');

            if (flock($fp,LOCK_EX)) {

                fwrite($fp, $content);
                // 解除锁定
                flock($fp, LOCK_UN);
            }else{
                return false;
            }

            fclose($fp);
            return true;

        }catch (Exception $e){
            return false;
        }

    }

    //驼峰转换
    function unCamelize($camelCaps,$separator='_'){

        return strtolower(preg_replace('/([a-z])([A-Z])/', "$1" . $separator . "$2", $camelCaps));
    }

}