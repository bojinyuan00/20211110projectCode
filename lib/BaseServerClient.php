<?php
namespace Addons\Short;

use Core\Di\ResourceManager;
use Core\Log\JLogger;
use Core\Utils\HttpJsonRequest;

class BaseServerClient
{

    /**
     * @var JLogger
     */
    private static $logger = null;

    /**
     * @param $api
     * @param $method
     * @param $data
     * @return bool | array | string | int
     * @throws \Exception
     */
    public static function request($api, $method, $data){
        $start = intval(microtime(true)*1000);
        if(!self::$logger){
            self::$logger = ResourceManager::dependencyInjection("log");
        }

        $method = strtolower($method);
        $res = null;
        try{
            if($method == 'get'){
                $res = HttpJsonRequest::get(BEESH_WOLONG_API.$api, $data);
            }
            if($method == 'post'){
                $res = HttpJsonRequest::postBody(BEESH_WOLONG_API.$api, $data, array("Content-type" => "application/json"));
            }
            self::$logger->info("[     ");
            self::$logger->info("----------start-------------");
            self::$logger->info("请求BASE-SERVER-API服务器 {$api}", array( $data, $method, $res));
            $end = intval(microtime(true)*1000);

            self::$logger->info("----------end.cost".((intval($end-$start)))."ms-------------");
            self::$logger->info("]");
        }catch (\Requests_Exception $exception){
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
        if($res && $res['status'] == 200){
            return $res['data'];
        }else{
            return false;
        }
    }

}