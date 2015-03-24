<?php

if(!defined('__ROOT__')){
    define('__ROOT__', dirname(__FILE__));
}

function error_handler($errno, $errstr, $errfile, $errline)
{
    if($errno != E_NOTICE)
        throw new Exception($errstr, $errno);
}

set_error_handler('error_handler');
?>