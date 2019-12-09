<?php

function autoLoad($class){
    
    $class = strtr($class,"\\",DS);
    $file = strpos($class,PSR);

    if ($file === 0) {
        $file = ROOT.str_replace(PSR,'',$class).EXT;
    }else if ($file === false){

        $_class = strtolower(preg_replace('/([a-z])([A-Z])/', "$1_$2", $class));
        $class_arr = explode('_',$_class);

        $end = array_pop($class_arr);


        switch ($end){
            case 'init':
                $file = ROOT.DS.'base'.DS.'init'.DS.$class.EXT;
                break;
            default:break;
        }
    }

    if (is_file($file)) {
        require_once $file;
    }
}


define('DS',DIRECTORY_SEPARATOR);
define('EXT','.php');
define('PSR','chat');

spl_autoload_register('autoLoad',false);
