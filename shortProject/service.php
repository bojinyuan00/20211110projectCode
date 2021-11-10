<?php
return array(
    //短链接平台 -- 企业信息存储service
    'companyService' => array(
        'name' => 'Addons\Short\Service\CompanyService',
        'path' => dirname(__FILE__).'/service/CompanyService.php'
    ),
    //短链接平台 -- 码信息存储service
    'codeService' => array(
        'name' => 'Addons\Short\Service\CodeService',
        'path' => dirname(__FILE__).'/service/CodeService.php'
    ),
    //短链接平台 -- 码信息存储service旧有数据
    'codeOldService' => array(
        'name' => 'Addons\Short\Service\CodeOldService',
        'path' => dirname(__FILE__).'/service/CodeOldService.php'
    ),
    //短链接平台 -- 转码任务存储service
    'taskService' => array(
        'name' => 'Addons\Short\Service\TaskService',
        'path' => dirname(__FILE__).'/service/TaskService.php'
    ),
    //短链接平台 -- 转码子任务存储service
    'taskSubService' => array(
        'name' => 'Addons\Short\Service\TaskSubService',
        'path' => dirname(__FILE__).'/service/TaskSubService.php'
    ),


);