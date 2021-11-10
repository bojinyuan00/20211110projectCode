<?php

namespace app\admin\controller;

use think\facade\View;
use app\common\model\Category as CategoryModel;
use think\Request;
use think\facade;

class Category extends AdminBase
{
    /**
     * 分类首页
     * @return string
     * @author bjy
     */
    public function index()
    {
        $pid = input('param.pid', 0, 'intval');//父级id

        $data = [
            "fields" => '',//需要查询的字段，不写表示全部
            "pid"    => $pid,//上级id
            "size"   => 8,//每页的展示条数
            "order"  => [
                'listorder' => "asc",
                'id'        => "asc",
            ],
        ];

        $CategoryModel = new CategoryModel();//实例化类

        //查询结果
        $CategoryResult = $CategoryModel->queryPage($data);
        //转换为数组
        $dataResult = $CategoryResult->toArray()['data'];
        //提取id值
        $pids = array_column($dataResult, 'id');
        //查询每个id下的数据集
        $sonInfo = $CategoryModel->getInfoByPids(['pid' => $pids])->toArray();

        //遍历以pid作为下标值
        $idCounts = [];
        foreach ($sonInfo as $one) {
            $idCounts[$one['pid']] = $one['count'];
        }
        //模板赋值
        return View::fetch("", [
            "categorys" => $CategoryResult,
        ]);
    }

    /**
     * 分类添加页
     * @return string
     * @author bjy
     */
    public function add()
    {
        //查询目前的分类结果集
        $CategoryModel = new CategoryModel();//实例化类

        try {
            $param['fields'] = 'id,name,pid';//查询的字段值
            $data            = $CategoryModel->queryPage($param);

        } catch (\Exception $e) {
            $data = [];
        }
        return View::fetch("", [
            "categorys" => $data,
        ]);
    }


    /**
     * 分类列表 --带分页
     * @author bjy
     */
    public function page()
    {
        $pid = input('param.pid', 0, 'intval');//父级id

        $data = [
            "pid"   => $pid,
            "size"  => 5,//每页的展示条数
            "order" => [
                'id' => "desc",
            ],
        ];

        $CategoryModel = new CategoryModel();//实例化类

        //查询结果
        $CategoryResult = $CategoryModel->queryPage($data);

        return View::fetch("index", [
            "categorys" => $CategoryResult,
        ]);
    }

    /**
     * 分类数据修改 -- 排序
     * @author bjy
     */
    public function listorder()
    {
        //接受值
        $id        = input('param.id', 0, 'intval');//数据id
        $listorder = input('param.listorder', 0, 'intval');//排序大小

        //查询数据是否存在
        $CategoryModel = new CategoryModel();
        //根据用户id，获取用户信息
        $categoryInfo = $CategoryModel->getInfoById($id);
        if (!$categoryInfo) {
            return show(-100, '数据不存在');
        }

        //更新操作
        $data['update_time'] = time();//更新时间
        $data['listorder']   = $listorder;//排序
        $updateResult        = $CategoryModel->updateById($id, $data);
        if (empty($updateResult)) {
            return show(-100, '更新数据失败');
        }

        return show(200, 'success');
    }

    /**
     * 分类数据修改 -- 状态
     * @author bjy
     */
    public function status()
    {
        //接受值
        $id     = input('param.id', 0, 'intval');//数据id
        $status = input('param.status', 0, 'intval');//排序大小

        //查询数据是否存在
        $CategoryModel = new CategoryModel();
        //根据用户id，获取用户信息
        $categoryInfo = $CategoryModel->getInfoById($id);
        if (!$categoryInfo) {
            return show(-100, '数据不存在');
        }

        //更新操作
        $data['update_time'] = time();//更新时间
        $data['status']      = $status;//状态值
        $updateResult        = $CategoryModel->updateById($id, $data);
        if (empty($updateResult)) {
            return show(-100, '更新数据失败');
        }

        return show(200, 'success');
    }


    /**
     * 分类添加
     * @author bjy
     */
    public function save()
    {

        $pid          = input('param.pid', 0, 'intval');//父级id
        $name         = input('param.name', '', 'trim');//分类名称
        $icon         = input('param.icon', '', 'trim');//图片
        $path         = input('param.path', '', 'trim');//路径
        $operate_user = input('param.operate_user', session(config("admin.session_admin"))['id'], 'intval');//操作人
        $status       = input('param.status', 1, 'intval');//状态
        $listorder    = input('param.listorder', 0, 'intval');//排序

        $data = [
            'pid'          => $pid,
            'name'         => $name,
            'icon'         => '',
            'path'         => '',
            'create_time'  => time(),
            'update_time'  => time(),
            'operate_user' => $operate_user,
            'status'       => $status,
            'listorder'    => $listorder,

        ];

        $CategoryModel = new CategoryModel();//实例化类

        try {
            //数据添加
            $result = $CategoryModel->add($data);
            //结果值判断
            if ($result) {
                return show(200, 'OK', $result);
            }
            return show(-100, 'error');
        } catch (\Exception $e) {
            if (substr_count($e->getMessage(), 'Duplicate entry')) {
                if (substr_count($e->getMessage(), "for key 'name'")) {
                    return show(-100, '分类名称重复');
                }
                return show(-100, 'error');
            }
        }
//        array_multisort(array_column($data,$key),SORT_DESC,$data);
    }


}
