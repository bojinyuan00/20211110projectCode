<?php

namespace Addons\Short;

use Addons\Short\Service\{CodeService, TaskService, CompanyService, TaskSubService};
use Core\Annotation\Model\{ContentUser, Controller, Resource, ResponseJson, Transaction};
use Core\Log\JLogger;
use Core\Utils\HttpJsonRequest;
use Core\Utils\ExcelHelper;
use \Exception;


/**
 * Class TaskController
 * @Controller()
 */
class TaskController
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
     * 依赖注入--子任务service
     * @var TaskSubService
     * @Resource("addons.short.taskSubService")
     */
    private $taskSubService;

    /**
     * @var \AttachmentService
     * @Resource("attachmentService")
     */
    private $attachmentService;

    /**
     * @var \SystemConfigService
     * @Resource("systemConfigService")
     */
    private $systemConfigService;

    /**
     * @var JLogger
     * @Resource("log")
     */
    private $logger;

    private function getConfig()
    {
        $baseAttachmentDir              = "";
        $baseAttachmentDir              = $baseAttachmentDir . "/";
        $CONFIG                         = json_decode(preg_replace("/\/\*[\s\S]+?\*\//", "", file_get_contents(SERVER_ROOT . "/lib/ueditor/php/config.json")), true);
        $CONFIG['imagePathFormat']      = $baseAttachmentDir . $CONFIG['imagePathFormat'];
        $CONFIG['snapscreenPathFormat'] = $baseAttachmentDir . $CONFIG['snapscreenPathFormat'];
        $CONFIG['catcherPathFormat']    = $baseAttachmentDir . $CONFIG['catcherPathFormat'];
        $CONFIG['videoPathFormat']      = $baseAttachmentDir . $CONFIG['videoPathFormat'];
        $CONFIG['filePathFormat']       = $baseAttachmentDir . $CONFIG['filePathFormat'];
        return $CONFIG;
    }

    /**
     * @Controller
     * @ResponseJson
     */
    public function uploadTask()
    {
        \ini_set('upload_max_filesize', '1000M');
        \ini_set('post_max_size', '1000M');
        \ini_set('memory_limit', '800M');
        set_time_limit(0);
        ignore_user_abort(true);//忽略客户端断开
        $result = $this->uploadFile();
        $this->logger->info("上传时间为：" . date('Y-m-d H:i:s'));
        return $result;
    }

    private function uploadFile()
    {
        include_once UEDITOR_ROOT . "php/Uploader.class.php";
        $CONFIG = $this->getConfig();
        /* 上传配置 */
        $base64    = "upload";
        $action    = htmlspecialchars($_GET['action']);
        $config    = array(
            "pathFormat" => $CONFIG['filePathFormat'],
            "maxSize"    => $CONFIG['fileMaxSize'],
            "allowFiles" => $CONFIG['fileAllowFiles']
        );
        $fieldName = $CONFIG['fileFieldName'];
        /* 生成上传实例对象并完成上传 */
        $up = new \Uploader($fieldName, $config, $base64);
        $up->upload();
        /**
         * 得到上传文件所对应的各个参数,数组结构
         * array(
         *     "state" => "",          //上传状态，上传成功时必须返回"SUCCESS"
         *     "url" => "",            //返回的地址
         *     "title" => "",          //新文件名
         *     "original" => "",       //原始文件名
         *     "type" => ""            //文件类型
         *     "size" => "",           //文件大小
         * )
         */


        /* 返回数据 */
        $result = $up->getFileInfo();
        $this->logger->info("上传结果：", $result);
        $addData['file']     = $result['url'];
        $addData['min_file'] = $result['min_url'];
        $addData['original'] = $result['original'];
        $addData['type'] = $result['type'];
        $addData['size'] = $result['size'];
        //存入数据库
        $attachment = $this->attachmentService->saveToDB($addData);
        $this->logger->info("入库结果：", $attachment);
        if ($attachment) {
            $result['id'] = $attachment['id'];
        }
        return $result;

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
    public function startTask($data)
    {
        $param = array();

        //接受每个参数，必要时进行数据字段的验证
        $param['uniacid']     = uniacid() ?? 0;//项目id
        $param['update_time'] = date('Y-m-d H:i:s');//修改时间

        isSetValue($param, $data, 'company_id', 'company_id', 0);//企业名称
        isSetValue($param, $data, 'name', 'name', '');//企业名称
        isSetValue($param, $data, 'status', 'status', 0);//入库转化状态  0未开始 1执行中  2执行完成
        isSetValue($param, $data, 'progress', 'progress', 0);//完成度  -- 60%
        isSetValue($param, $data, 'encode_file_path', 'encode_file_path', '');//转化后的文件下载地址
        isSetValue($param, $data, 'mode', 'mode', 'primeval');//存储规则标识  encrypt（加密） primeval（原始） random（随机转化）
        isSetValue($param, $data, 'code_sign', 'code_sign', '');//需要读取url内的参数字段名
        isSetValue($param, $data, 'prefix', 'prefix', '');//截取前缀
        isSetValue($param, $data, 'suffix', 'suffix', '');//截取后缀
        isSetValue($param, $data, 'split', 'split', 0);//文件拆分状态     0未拆分 1拆分中  2已拆分
        isSetValue($param, $data, 'notice', 'notice', 0);//通知状态  0未通知 1已通知
        isSetValue($param, $data, 'callback', 'callback', '');//回调地址
        isSetValue($param, $data, 'task_id', 'task_id', 0);//外部任务id

        if ($data['attachment_id']) {
            $attachment         = $this->attachmentService->getAttachment($data['attachment_id']);
            $param['file_path'] = $attachment['file'] ?? '';
        } else {
            throw new Exception('创建任务时-码文件id必传', 400);
        }

        //查询该数据是否存在
        $companyInfo = $this->companyService->getInfoById($data['company_id']);
        if (!$companyInfo) {
            throw new Exception('企业信息不存在', 400);
        }
        $param['domain']     = $companyInfo['domain'] ?? '';//访问域名
        $param['code_count'] = getFileLines(ATTACHMENT_ROOT . $param['file_path']) ?? 0;//码数量值计算

        //判断ID
        if ($data['id']) {
            $id     = $data['id'];//任务id
            $result = $this->taskService->update($id, $param);
        } else {
            $param['create_time'] = date('Y-m-d H:i:s');//创建时间
            $result               = $this->taskService->mapper->insert($param);
            $id                   = $result;
        }
        $this->logger->info("任务创建时间为：" . date('Y-m-d H:i:s'));
        return $this->taskService->getInfoById($id);
    }

    /**
     * 获取任务列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param null $status 入库转化状态  0 未开始 1 执行中  2 执行完成
     * @param string $keywords 任务搜索
     * @param null $companyId 公司id
     * @param null $split 0 未拆分 1 拆分中  2 已拆分
     * @param int $page 页码
     * @param int $size 每页数量
     * @param string $fields
     * @return array
     * @throws Exception
     */
    public function query($keywords = '', $companyId = NULL, $status = NULL, $split = NULL, $page = 1, $size = 10, $fields = '')
    {
        $param = array(
            'keywords'   => $keywords,
            'company_id' => $companyId,
            'status'     => $status,
            'split'      => $split,
            'page'       => $page,
            'size'       => $size,
            'fields'     => $fields,
        );

        $result = $this->taskService->queryPage($param);
        return $result;
    }

    /**
     * 获取任务列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param null $status 入库转化状态  0 未开始 1 执行中  2 执行完成
     * @param string $keywords 任务搜索
     * @param null $companyId 公司id
     * @param null $split 0 未拆分 1 拆分中  2 已拆分
     * @param int $page 页码
     * @param int $size 每页数量
     * @param string $fields
     * @return array
     * @throws Exception
     */
    public function page($keywords = '', $companyId = NULL, $status = NULL, $split = NULL, $page = 1, $size = 10, $fields = '')
    {
        $param = array(
            'keywords'   => $keywords,
            'company_id' => $companyId,
            'status'     => $status,
            'split'      => $split,
            'page'       => $page,
            'size'       => $size,
            'fields'     => $fields,
        );

        $result = $this->taskService->queryPage($param);
        return $result;
    }

    /**
     * 获取企业列表接口 -- 分页（后台）
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param null $status 入库转化状态  0 未开始 1 执行中  2 执行完成
     * @param string $keywords 任务搜索
     * @param null $companyId 公司id
     * @param null $split 0 未拆分 1 拆分中  2 已拆分
     * @param string $fields
     * @return array
     * @throws Exception
     */
    public function list($keywords = '', $companyId = NULL, $status = NULL, $split = NULL, $fields = '')
    {
        $fields = 'id,name,status';
        $param  = array(
            'keywords'   => $keywords,
            'company_id' => $companyId,
            'status'     => $status,
            'split'      => $split,
            'fields'     => $fields,
        );

        $result = $this->taskService->queryPage($param);
        return $result;
    }

    /**
     * 获取任务详情接口
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
        $result = $this->taskService->getInfoById($id, $fields);
        if (!$result) {
            throw new Exception('数据不存在', 400);
        }
        return $result;
    }

    /**
     * 大文件拆分接口
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @Transaction()
     * @param $id
     * @return string
     * @throws Exception
     */
    public function fileSplit($id)
    {
        //查询该数据是否存在
        $taskInfo = $this->taskService->getInfoById($id);
        if (!$taskInfo) {
            throw new Exception('数据不存在', 400);
        }
        if ($taskInfo['split']) {
            throw new Exception('任务已拆分，请勿重复操作', 400);
        }
        $filePath = ATTACHMENT_ROOT . $taskInfo['file_path'];

        //文件处理
        $file = fopen($filePath, 'r+') or die('fail');
        $fileNum = ceil($taskInfo['code_count'] / 25000); //拆分成新文件的数量---临时设定（配置）
        $fileLen = ceil(filesize($filePath) / $fileNum);  //每个新文件的长度
        rewind($file);  //指针设置到文件开头0的位置
        $lastLen = 0;

        //子任务数据处理
        $taskSubInsert = [];
        //需要存储到子任务表的数据
        $taskSubOne['uniacid']     = uniacid() ?? 0;//项目id
        $taskSubOne['company_id']  = $taskInfo['company_id'];//企业id
        $taskSubOne['task_id']     = $id;//任务id
        $taskSubOne['status']      = 0;//入库转化状态  0未开始 1转化中  2转化完成
        $taskSubOne['create_time'] = date('Y-m-d H:i:s');//创建时间
        $taskSubOne['update_time'] = date('Y-m-d H:i:s');//修改时间

        //判断文件夹是否存在，不存在则创建
        makeDirs(ATTACHMENT_ROOT . 'splitCodeFiles/company' . $taskInfo['company_id'] . '/task' . $id);

        for ($i = 0; $i < $fileNum; $i++) {
            $content  = fread($file, $fileLen + $lastLen);//等长字符串，加上上一个字符串不足一行的部分
            $lastn    = strrchr($content, "\n");//不足一行的字符串
            $lastLen  = strlen($lastn);  //不足一行的字符串长度
            $complete = substr($content, 0, strlen($content) - $lastLen);   //减去当前字符串不足一行的部分,得到每一行都完整的字符串
            //写入新文件
            $newFile = fopen(ATTACHMENT_ROOT . 'splitCodeFiles/company' . $taskInfo['company_id'] . '/task' . $id . '/task' . $id . 'Code' . $i . '.txt', 'w+');
            fwrite($newFile, $complete);
            fseek($file, ftell($file) - $lastLen + 1);  //将文件指针返回到不足一行的开头，使下一个文件能得到完整行
            $taskSubOne['file_path']  = 'splitCodeFiles/company' . $taskInfo['company_id'] . '/task' . $id . '/task' . $id . 'Code' . $i . '.txt';//存储路径
            $taskSubOne['code_count'] = getFileLines(ATTACHMENT_ROOT . $taskSubOne['file_path']);//码数量值
            $taskSubInsert[]          = $taskSubOne;
        }
        fclose($file);//关闭文件

        //子任务数据批量入库
        $result = $this->taskSubService->mapper->insertMulti($taskSubInsert);
        //修改 任务表的文件拆分状态值
        $updateParam['split']       = 2;//文件拆分状态     0未拆分 1拆分中  2已拆分
        $updateParam['update_time'] = date('Y-m-d H:i:s');//修改时间
        $result                     = $this->taskService->update($id, $updateParam);
        $this->logger->info("任务文件拆分时间为：" . date('Y-m-d H:i:s'));
        return $result;
    }

    /**
     * 子任务数据入库
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param $id
     * @return bool
     * @throws Exception
     */
    public function saveCode($id)
    {
        //

        //获取子任务详情信息
        $taskSubInfo = $this->taskSubService->getInfoById($id);
        if (!$taskSubInfo) {
            throw new Exception('数据不存在', 400);
        }

        //获取任务详情信息
        $taskInfo = $this->taskService->getInfoById($taskSubInfo['task_id']);
        if ($taskInfo['status'] == 0) {
            //修改子任务入库状态值未进行中
            $updateTaskParam['status']      = 1;//入库转化状态  0未开始 1转化中  2转化完成
            $updateTaskParam['update_time'] = date('Y-m-d H:i:s');//修改时间
            $this->taskService->update($taskInfo['id'], $updateTaskParam);
        }

        //文件地址
        $filePath = ATTACHMENT_ROOT . $taskSubInfo['file_path'];
        //采用生成器加载文件 -- 读取文件内容,保证内存中永远只有一条数据
        $fileContent = $this->taskService->readFile($filePath);

        $codeInfo                = [];//单码
        $codesInfo               = [];//码集合
        $codeInfo['uniacid']     = $taskInfo['uniacid'];//项目id
        $codeInfo['company_id']  = $taskInfo['company_id'];//企业id
        $codeInfo['task_id']     = $taskInfo['id'];//任务id
        $codeInfo['create_time'] = date('Y-m-d H:i:s');//创建时间
        $codeInfo['update_time'] = date('Y-m-d H:i:s');//修改时间

        makeDirs(ATTACHMENT_ROOT . 'subCodeFiles/company' . $taskInfo['company_id'] . '/task' . $id);
        $file = 'subCodeFiles/company' . $taskInfo['company_id'] . '/task' . $id;

        $subFileName = ATTACHMENT_ROOT . $file . '/taskCode' . $taskInfo['id'] . '-' . $taskSubInfo['id'] . '.txt';
        $handle      = fopen($subFileName, "a+");
        $start       = time();
        $lastInsert  = time();
        $totalInsert = 0;
        $insertCount = 200;

        foreach ($fileContent as $value) {
            //判断传值类型
            switch ($taskInfo['mode']) {
                case 'encrypt' ://加密转码
                    //逻辑待完善
                    break;
                case 'primeval'://原始码
                    $codeInfo['code']   = getQuerystr($value, $taskInfo['code_sign']);//源码
                    $codeInfo['encode'] = getQuerystr($value, $taskInfo['code_sign']);//转换后的码
                    break;
                case 'random'://随机转化
                    //逻辑待完善
                    break;
            }
            $codeInfo['target_url'] = get_between($value, 'm/', '');//目标跳转地址
            $returnCode             = 'https://s.b-sh.cloud' . '?u=' . $taskInfo['company_id'] . '&s=' . $codeInfo['encode'];//单个返回的转换后数据
            $codesInfo[]            = $codeInfo;//需要入库的数据组合
            if (sizeof($codesInfo) == $insertCount) {
                //
                $res         = $this->codeService->saveCode($codesInfo, $taskInfo['company_id']);
                $codesInfo   = [];
                $cost        = time() - $start;
                $currentCost = time() - $lastInsert;
                $lastInsert  = time();
                $totalInsert += $insertCount;
                $this->logger->info('本次插入数据' . $insertCount . '条，用时' . $currentCost . 's,总计用时' . $cost . 's,总计数据：' . $totalInsert);
//                echo '本次插入数据' . $insertCount . '条，用时' . $currentCost . 's,总计用时' . $cost . 's,总计数据：' . $totalInsert . "\n";
            }
            //数据写入转换后的子文件内
            $str = fwrite($handle, $returnCode . "\n");
        }
        fclose($handle);

        if (!empty($codesInfo)) {
            //数据的批量入库
            $res         = $this->codeService->saveCode($codesInfo, $taskInfo['company_id']);
            $cost        = time() - $start;
            $currentCost = time() - $lastInsert;
            $totalInsert += sizeof($codesInfo);
            $this->logger->info('本次插入数据' . $insertCount . '条，用时' . $currentCost . 's,总计用时' . $cost . 's,总计数据：' . $totalInsert);
//            echo '本次插入数据' . $insertCount . '条，用时' . $currentCost . 's,总计用时' . $cost . 's,总计数据：' . $totalInsert;
        }
        //入库完成，删除子文件
//        unlink($filePath);

        //完成后 更新--子任务的状态
        $updateSubParam['status']               = 2;//入库转化状态  0未开始 1转化中  2转化完成
        $updateSubParam['update_time']          = date('Y-m-d H:i:s');//修改时间
        $updateSubParam['conversion_file_path'] = $file . '/taskCode' . $taskInfo['id'] . '-' . $taskSubInfo['id'] . '.txt';//转换后的新文件地址
        $this->taskSubService->update($taskSubInfo['id'], $updateSubParam);

        //子任务更新后，查询子任务关联的子任务下是否还有 未入库的文件  ==》更新主任务的状态值及完成度
        $selectParam['task_id'] = $taskInfo['id'];//任务id
        $selectParam['status']  = [0, 1];//入库转化状态  0未开始 1转化中
        $selectParam['fields']  = 'id';//入库转化状态  0未开始 1转化中
        $remainSubData          = $this->taskSubService->queryPage($selectParam);//查询是否存在未转化、转化中的子任务

        //获取最新任务详情信息
        $taskInfo                       = $this->taskService->getInfoById($taskSubInfo['task_id']);
        $updateTaskParam['update_time'] = date('Y-m-d H:i:s');//修改时间
        //不存在，则更新任务状态值
        if (empty($remainSubData)) {
            $updateTaskParam['status']      = 2;//入库转化状态  0未开始 1转化中  2转化完成
            $updateTaskParam['export_code'] = $taskInfo['code_count'];//任务已经入库的码数量
            $updateTaskParam['progress']    = 100;//任务完成进度
            //进行 子任务文件数据的合并
            $returnFile                          = $this->mergeFiles($taskInfo['id'], $taskInfo['company_id']);
            $updateTaskParam['encode_file_path'] = 'https://s.b-sh.cloud/attachment//' . $returnFile;//下载地址
            //通知 --  远程任务转码全部完毕
            $noticeParam['pass']     = 'success';
            $noticeParam['taskId']   = $taskInfo['task_id'];//外部任务id
            $noticeParam['status']   = 2;//成功状态
            $noticeParam['file_url'] = $updateTaskParam['encode_file_path'];//成功状态
//            $noticeResult              = $this->taskService->noticeTaskInfo($taskInfo['callback'], $noticeParam);
            $callBackResult = HttpJsonRequest::post($taskInfo['callback'], $noticeParam);
            if ($callBackResult['status'] = 200) {
                $updateTaskParam['notice'] = 1;//通知状态 更改为已通知
            }
            $this->logger->info('通知生码任务时间为：' . date('Y-m-d H:i:s'));

        } else {
            $updateTaskParam['export_code'] = $taskInfo['export_code'] + $taskSubInfo['code_count'];//任务已经入库的码数量
            $updateTaskParam['progress']    = round(($updateTaskParam['export_code'] / $taskInfo['code_count']) * 100, 2);
        }

        $taskResult = $this->taskService->update($taskInfo['id'], $updateTaskParam);

        //更新公司的总的统计数据值
        $companyInfo                   = $this->companyService->getInfoById($taskInfo['company_id']);
        $companyParam['storage_count'] = $companyInfo['storage_count'] + $taskSubInfo['code_count'];
        $companyParam['update_time']   = date('Y-m-d H:i:s');//修改时间
        $companyResult                 = $this->companyService->update($taskInfo['company_id'], $companyParam);
        return $taskResult;
    }

    /**
     * 定时任务 -- 获取一条任务数据
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @return string
     * @throws Exception
     */
    public function cronTask()
    {
        //每次获取id最小的一条任务
        $result = $this->taskService->getOneInfo();
        if ($result) {
            $result = $this->fileSplit($result['id']);
        }
        return '';
    }

    /**
     * 定时任务 -- 获取一条子任务入库
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @return string
     * @throws Exception
     */
    public function cronTaskSub()
    {
        set_time_limit(0);
        ignore_user_abort(true);//忽略客户端断开
        ini_set('max_execution_time', 0);
        ini_set('display_errors', 'on');

        //每次最多同时 -- 10个进行跑动
        $param['status'] = 1;
        $param['fields'] = 'count(id) as count';
        $taskSubInfo     = $this->taskSubService->queryPage($param);
        if ($taskSubInfo[0]['count'] >= 4) {
            return null;
        }

        $result = $this->taskSubService->getOneInfo();
        if ($result) {
            $this->taskSubService->mapper->where('id', $result['id'])->update(['status' => 1]);
            return $this->saveCode($result['id']);
        }
        return null;
    }

    /**
     * 合并txt文件内容
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @param $companyId
     * @param $id
     * @return string
     * @throws Exception
     */
    public function mergeFiles($id, $companyId)
    {
        //最终的文件返回地址
        makeDirs(ATTACHMENT_ROOT . 'returnCodeFiles/company' . $companyId);
        $file           = 'returnCodeFiles/company' . $companyId . '/rawCode' . time() . '-' . $id . '.txt';
        $returnFilePath = ATTACHMENT_ROOT . $file;
        //查询任务下的全部子任务数据
        $param['status']         = 2;//任务执行完成
        $param['fields']         = 'id,conversion_file_path';
        $param['task_id']        = $id;
        $param['order_by_field'] = 'id,asc';
        $taskSubInfo             = $this->taskSubService->queryPage($param);
        $filesNames              = array_column($taskSubInfo, 'conversion_file_path');
        //遍历--组装
        foreach ($filesNames as $name) {
            $str = file_get_contents(ATTACHMENT_ROOT . $name);
            file_put_contents($returnFilePath, $str, FILE_APPEND);
        }
        $this->logger->info('合并文件时间为：' . date('Y-m-d H:i:s'));
        return $file;
    }

    /**
     * 调取远程测试
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @return string
     * @throws Exception
     */
    public function callBack()
    {
        $noticeParam['pass']     = 'success';
        $noticeParam['task_id']  = 1;//外部任务id
        $noticeParam['status']   = 3;//成功状态
        $noticeParam['file_url'] = '/acb.txt';//成功状态
        //  return  $noticeParam;
        $noticeResult = HttpJsonRequest::post("http://uat.be-shell.com/index.php/addons/wolong/codeTask/callback", $noticeParam);
        return $noticeResult['status'];
    }

    /**
     * 导出旧有数据到txt文件
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @return string
     * @throws Exception
     */
    public function stringSubStr()
    {
        $code     = "http://uat.be-shell.com/addons/trace/h5/#/pages/index/index?uniacid=&code=HCD2021-2000001";
        $codeInfo = get_between($code, 'm/', '');//目标跳转地址
        return $codeInfo;
//        $handles = fopen($filePath,"r");
//        if($handles){
//            while (($line = fgets($handles)) != false){
//                $fileContent[] = str_replace(PHP_EOL,'',$line);//去除换行符
//            }
//            fclose($handles);
//        }
//        return $fileContent;
    }

    /**
     * 导出旧有数据到txt文件
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @return string
     * @throws Exception
     */
    public function downloadFile($url = "http://sit.be-shell.com/attachment//returnCodeFiles/taskCode1.txt", $file = "", $timeout = 60)
    {
        $filename = $url; //文件路径
        header("Content-Type: application/force-download");
        header("Accept-Ranges: bytes");
        header("Content-Disposition: attachment; filename=" . basename($filename));
        readfile($filename);
    }

    /**
     * 导出旧有数据到txt文件
     * @Controller()
     * @ResponseJson()
     * @ContentUser()
     * @return string
     * @throws Exception
     */
    public function ifExitDir()
    {
        return makeDirs(ATTACHMENT_ROOT . 'abc');

    }


}
