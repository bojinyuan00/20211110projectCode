<?php

namespace Addons\Short;

use Core\Annotation\Model\Controller;
use Core\Annotation\Model\ResponseJson;
use Core\Annotation\Model\Resource;

/**
 * @Controller
 */
class UploadController
{
    /**
     * @var \SystemConfigService
     * @Resource("systemConfigService")
     */
    private $systemConfigService;

    /**
     * @var \AttachmentService
     * @Resource("attachmentService")
     */
    private $attachmentService;

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
     * @RealJson
     */
    public function index()
    {
        $result = $this->uploadFile();
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

        $result['file']     = $result['url'];
        $result['url']      = tomedia($result['url']);
        $result['min_file'] = ($result['min_url']);
        //存入数据库
        $attachment = $this->attachmentService->saveToDB($result);
        if ($attachment) {
            $result['id'] = $attachment['id'];
        }
        return $result;

    }
}