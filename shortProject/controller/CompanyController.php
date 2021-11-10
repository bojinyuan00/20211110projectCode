<?php

namespace Addons\Short;

use Addons\Short\Service\{CodeService, TaskService, CompanyService};
use Core\Annotation\Model\{ContentUser, Controller, Resource, ResponseJson, Transaction};
use \Exception;


/**
 * Class CompanyController
 * @Controller()
 */
class CompanyController
{

    /**
     * 依赖注入-- 短链接码存放service
     * @var CodeService
     * @Resource("addons.short.codeService")
     */
    private $codeService;

    /**
     * 依赖注入-- 短链接转码任务service
     * @var TaskService
     * @Resource("addons.short.taskService")
     */
    private $taskService;


    /**
     * 依赖注入--企业service
     * @var CompanyService
     * @Resource("addons.short.companyService")
     */
    private $companyService;

    /**
     * 根据 企业的数据量 -- 获取企业分表分库的最优解（用来自动实现最优分表分库方案）
     * @Controller()
     * @ContentUser()
     * @ResponseJson()
     * @param $id
     * @return string
     * @throws Exception
     * @author bjy
     */
    public function dataBaseProgramme($id)
    {
        $fields = '';
        //查询该数据是否存在
        $result = $this->companyService->getInfoById($id, $fields);
        if (!$result) {
            throw new Exception('数据不存在', 400);
        }

        //计算出 将要划分的数据表个数（企业预估数据量/单表2000万）
        $dataTableNum = ceil($result['data_volume'] / 20000000);
        //最佳合理方案
        $result = '数据量：' . $result['data_volume'] . ' ==>分表：' . $dataTableNum . '张, 单表实际数据量为:20000000';

        return $result;
    }

    /**
     * 根据 企业的数据量 -- 创建表及数据库
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @Transaction()
     * @param $id
     * @return bool
     * @throws Exception
     * @author bjy
     */
    public function createCodeTables($id)
    {
//        try {
        $fields = '';
        //查询该数据是否存在
        $result = $this->companyService->getInfoById($id, $fields);
        if (!$result) {
            throw new Exception('数据不存在', 400);
        }
        if ($result['table_status'] == 1) {
            throw new Exception('数据表已创建', 400);
        }


        $dataVolume = $result['data_volume'];//企业数据量

        //计算出 将要划分的数据表个数（企业预估数据量/单表2000万）
        $dataTableNum = ceil($dataVolume / 20000000);

        //参数拼装
        $param = array(
            'company_id'      => $id,//项目id
            'database_number' => 1,//当前数据库的序号，本次默认为1库
            'table_numbers'   => $dataTableNum,//表数
        );
//        return $param;
        //调取创建表
        $result                      = $this->codeService->creatTables($param);
        $updateParam['table_status'] = 1;//表创建状态 0未创建  1已创建
        $updateParam['table_count']  = $dataTableNum;//表创建数量
        $updateParam['update_time']  = date('Y-m-d H:i:s');//修改时间
        $result                      = $this->companyService->update($id, $updateParam);
        return $result;
//        } catch (\Exception $e) {
//            //异常控制
//            throw new Exception('初始化失败', 400);
//        }
    }


    /**
     * 添加企业信息接口
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param $data
     * @return array
     * @throws Exception
     */
    public function save($data)
    {

        //开启 异常抛出模式
        try {
            $param = array();

            //接受每个参数，必要时进行数据字段的验证
            $param['uniacid']     = uniacid() ?? 0;//项目id
            $param['update_time'] = date('Y-m-d H:i:s');//修改时间

            isSetValue($param, $data, 'name', 'name', '');//企业名称
            isSetValue($param, $data, 'description', 'description', '');//企业简介
            isSetValue($param, $data, 'icon', 'icon', '');//图标
            isSetValue($param, $data, 'phone', 'phone', '');//联系电话
            isSetValue($param, $data, 'token', 'token', '');//token
            isSetValue($param, $data, 'data_volume', 'data_volume', 0);//预计存储的数据量
            isSetValue($param, $data, 'domain', 'domain', 0);//预计存储的数据量

            //判断ID
            if ($data['id']) {
                $param['id'] = $data['id'];
            } else {
                $param['create_time'] = date('Y-m-d H:i:s');//创建时间
            }

            //数据入库
            $result = $this->companyService->insertOrUpdate($param);

            return $result;

        } catch (\Exception $e) {
            //异常控制
            throw new Exception('参数错误', 400);
        }
    }

    /**
     * 获取企业列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param string $keywords 模糊查询 企业 （名称、联系电话、证书编号）
     * @param int $table_status 表创建状态 0未创建  1已创建
     * @param int $status 删除状态 -1已经删除 1正常
     * @param int $page 页码
     * @param int $size 每页数量
     * @param string $fields
     * @return array
     * @throws Exception
     */
    public function query($keywords = '', $table_status = null, $status = 1, $page = 1, $size = 10, $fields = '')
    {
        $param = array(
            'keywords'     => $keywords,
            'table_status' => $table_status,
            'status'       => $status,
            'page'         => $page,
            'size'         => $size,
            'fields'       => $fields,
        );

        $result = $this->companyService->queryPage($param);
        return $result;
    }

    /**
     * 获取企业列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param string $keywords 模糊查询 企业 （名称、联系电话、证书编号）
     * @param int $table_status 表创建状态 0未创建  1已创建
     * @param int $status 删除状态 -1已经删除 1正常
     * @param int $page 页码
     * @param int $size 每页数量
     * @param string $fields
     * @return array
     * @throws Exception
     */
    public function page($keywords = '', $table_status = null, $status = 1, $page = 1, $size = 10, $fields = '')
    {
        $param = array(
            'keywords'     => $keywords,
            'table_status' => $table_status,
            'status'       => $status,
            'page'         => $page,
            'size'         => $size,
            'fields'       => $fields,
        );

        $result = $this->companyService->queryPage($param);
        return $result;
    }

    /**
     * 获取企业列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param string $keywords 模糊查询 企业 （名称、联系电话、证书编号）
     * @param int $table_status 表创建状态 0未创建  1已创建
     * @param string $fields
     * @return array
     * @throws Exception
     */
    public function list($keywords = '', $table_status = null, $fields = '')
    {
        $fields = 'id,name,icon';
        $param  = array(
            'keywords'     => $keywords,
            'table_status' => $table_status,
            'fields'       => $fields,
        );

        $result = $this->companyService->queryPage($param);
        return $result;
    }

    /**
     * 获取企业详情接口
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param $id
     * @param $fields ==> 'id,name,icon..'
     * @return array
     * @throws Exception
     */
    public function detail($id, $fields = '')
    {
        //查询该数据是否存在
        $result = $this->companyService->getInfoById($id, $fields);
        if (!$result) {
            throw new Exception('数据不存在', 400);
        }
        return $result;
    }

    /**
     * 删除企业信息接口
     * @Controller()
     * @ResponseJson()
     * @param $id
     * @return string
     * @throws Exception
     */
    public function delete($id)
    {
        //查询该数据是否存在
        $have_info = $this->companyService->getInfoById($id, 'id');
        if (empty($have_info)) {
            throw new Exception('数据不存在', 400);
        }

        $updateParam['status']      = -1;//删除状态 -1已经删除 1正常
        $updateParam['update_time'] = date('Y-m-d H:i:s');//修改时间
        $result                     = $this->companyService->update($id, $updateParam);

        if ($result) {
            return '';
        }
        throw new Exception('删除失败', 400);
    }


}

