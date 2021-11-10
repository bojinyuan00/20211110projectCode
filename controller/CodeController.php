<?php

namespace Addons\Short;

use Addons\Short\Service\{CodeService, CodeOldService, TaskService, CompanyService};
use Core\Annotation\Model\{ContentUser, Controller, Redirect, Resource, ResponseJson, Transaction};
use Core\Utils\ExcelHelper;
use \Exception;


/**
 * Class CodeController
 * @Controller()
 */
class CodeController
{

    /**
     * 依赖注入-- 短链接码存放service
     * @var CodeService
     * @Resource("addons.short.codeService")
     */
    private $codeService;

    /**
     * 依赖注入-- 短链接码存放service旧有数据
     * @var CodeOldService
     * @Resource("addons.short.codeOldService")
     */
    private $codeOldService;

    /**
     * 依赖注入-- 短链接转码任务service
     * @var TaskService
     * @Resource("addons.short.taskService")
     */
    private $taskService;

    /**
     * 依赖注入--企业域名service
     * @var CompanyService
     * @Resource("addons.short.companyService")
     */
    private $companyService;

    /**
     * 根据 短链接 -- 获取转换的长链接完整路由地址并跳转
     * @Controller()
     * @Redirect()
     * @param $s
     * @param $u
     * @return string
     * @throws Exception
     * @author bjy
     */
    public function jump($s, $u = null)
    {
        //获取项目id 默认鱼浪
        $companyId = $u ?? 18;
        //截取完整的code码
        $shortCode = $s;

        //获取企业详细信息
        $companyInfo = $this->companyService->getInfoById($companyId);

        //TODO 临时 -- 兼容就有的码数据
        //获取码详情
        $codeInfo = $this->detail(NULL, $shortCode, $companyId);//先查询新码表内的信息
        if (!$codeInfo) {//不存在则查询原有的旧码数据
            //根据算法，得出code存储的表计数
            $tableNumber = $this->codeOldService->conversionTableMethod($shortCode, $companyInfo['table_count']);
            //拼接请求的参数
            $param = array(
                'uniacid'         => $companyId,//项目id
                'database_number' => 1,//当前数据库的序号，本次默认为1库
                'table_number'    => $tableNumber,//此表分配的表计数
                'code'            => $shortCode,//查询码
                'field'           => '',//需要查询的字段
            );
            //查询获取短链接码对应的长链接码
            $codeInfo = $this->codeOldService->getByCode($param, $companyId, 1, $tableNumber);
            //若码信息不存在，则跳转至百度
            if(!$codeInfo){
                return 'https://www.baidu.com/';
            }

        }

        //获取 域名+长链接的完整参数信息
        $longUrl = $companyInfo['domain'] . $codeInfo['target_url'];

        //TODO 临时
        if (strpos($longUrl, "uniacid=") === false && $companyId == 18) {
            $longUrl .= "&uniacid=18";
        }
        return $longUrl;
    }

    /**
     * 获取任务列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param null $taskId 任务id
     * @param string $keywords 任务搜索
     * @param null $companyId 公司id
     * @param null $code 源码
     * @param null $encode 加密码
     * @param int $page 页码
     * @param int $size 每页数量
     * @param string $fields
     * @return array
     * @throws Exception
     */
    public function query($keywords = '', $companyId = NULL, $taskId = NULL, $code = NULL, $encode = NULL, $page = 1, $size = 10, $fields = '')
    {
        $param = array(
            'keywords'   => $keywords,
            'company_id' => $companyId,
            'task_id'    => $taskId,
            'code'       => $code,
            'encode'     => $encode,
            'page'       => $page,
            'size'       => $size,
            'fields'     => $fields,
        );

        $result = $this->codeService->queryPage($param);
        return $result;
    }

    /**
     * 获取任务列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param null $code 源码
     * @param null $encode 加密码
     * @param NULL $companyId 公司id
     * @return array
     * @throws Exception
     */
    public function detail($code = NULL, $encode = NULL, $companyId = NULL)
    {
        $param  = array(
            'code'       => $code,
            'company_id' => $companyId,
            'encode'     => $encode
        );
        $result = $this->codeService->getByCode($param);
        return $result;
    }


}
