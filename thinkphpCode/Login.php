<?php

namespace app\admin\controller;


use app\common\model\AdminUser;
use think\facade\View;
use think\Request;
use app\admin\validate\AdminUser as VAdminUser;
use think\facade\Log;

/**
 * 登录的逻辑处理
 * Class Login
 * @package app\admin\controller
 */
class Login extends AdminBase
{
    //初始化
    public function initialize()
    {
        //若已经登录了，跳转至后台首页
        if ($this->isLogin()) {
            return $this->redirect(url("index/index"));
        }
    }

    /**
     * 渲染登录页面
     * @return string
     * @author bjy
     */
    public function index()
    {
        return View::fetch();
    }

    public function getInfo()
    {
        session('adminUser', null);
        dd(session('adminUser'));
    }

    /**
     * 检测是否登录
     * @param Request $request
     * @return object
     * @author bjy
     */
    public function check(Request $request)
    {
        try {
            // 参数检验 1、原生方式  2、TP6 验证机制
            $username = $this->request->param("username", "", "trim");//用户名
            $password = $this->request->param("password", "", "trim");//密码
            $captcha  = $this->request->param("captcha", "", "trim");//验证码

            $data = [
                'username' => $username,
                'password' => $password,
                'captcha'  => $captcha,
            ];

            $vAdminUser = new VAdminUser();//实例化验证规则
            if (!$vAdminUser->check($data)) {
                return show(-100, $vAdminUser->getError());
            }

            //获取用户数据
            $AdminUserModel = new AdminUser();
            $adminUserInfo  = $AdminUserModel->getInfoByUsername($username);
            //判断是否用户存在
            if (empty($adminUserInfo)) {
                return show(-100, '用户不存在');
            }
            $adminUserInfo = $adminUserInfo->toArray();
            //判断密码是否正确
            if ($adminUserInfo['password'] != md5($password . '_bjy')) {
                return show(-100, '密码错误');
            }

            //更新数据表信息
            $updateData   = array(
                'last_login_time' => time(),//更新最后一次的登录时间
                'last_login_ip'   => $this->request->ip(),//更新最后一次的登录ip地址
                'update_time'     => time(),//更新时间
            );
            $updateResult = $AdminUserModel->updateById($adminUserInfo['id'], $updateData);
            if (empty($updateResult)) {
                return show(-100, '登录失败');
            }
        } catch (\Exception $e) {
            //todo 记录日志 $e->getMessage();
            return show(400, '内部异常,登录失败');
        }

        //登录成功之后，数据记录到session内
        session(config("admin.session_admin"), $adminUserInfo);
        cache(config('redis.admin_login_pre') . $username, $username, config('redis.admin_login_expire'));
        return show(200, '登录成功');
    }

}
