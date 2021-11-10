<?php
return array(
    'plugin' => array(
        'name' => '活码平台',
        "s_name" => '码',
        'version' => '0.1',
        'sign' => 'short',
        "supports" => array("wechat"),
        "shop" => false,//不需要商户系统
        "pay" => array(
            "miniprogram" => false,
            "alipay" => false,
            "wechat" => true
        ),
        "liar" => false,
        "custom_service" => false,
        "copyright" => false,
        "ad" => true,
        "theme" => false,
        "share" => false,
        "area" => false,
    ),
    'interceptor' => array(

    ),
    'ad' => false,
    'include_files' => array(
        'public/Function.php',
        'lib/BaseServerClient.php',
    ),

);
