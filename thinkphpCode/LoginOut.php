<?php

declare (strict_types = 1);
namespace app\admin\controller;

/**
 * 登录退出逻辑
 * Class LoginOut
 * @package app\admin\controller
 */
class LoginOut extends AdminBase
{

    public function index()
    {
        //清除session
        session(config("admin.session_admin"), null);
        //执行跳转
        return redirect((string)url("login/index"));
    }
}