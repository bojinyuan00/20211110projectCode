<?php

namespace Addons\Short\Service;

use \Exception;
use Core\Orm\Mapper;
use Core\Base\Data\_W;
use Core\Annotation\Model\Resource;
use Predis\Command\StringGet;


class CodeService extends Mapper
{
    public function __construct()
    {
//        //链接数据库，设置前缀
//        $this->db = new MysqliDb ('192.168.1.245', 'beesh', 'M7fKKF52BGxpR7yS', 'beesh');
//        $this->db->setPrefix('ims_');

        $this->columns = 'id,uniacid,company_id,task_id,code,encode,target_url,count,create_time,update_time';
    }


    /**
     * 数据入库的速度 (此处的入库函数，支持后期的批量插入数据，而非单条数据入库)
     * @param $param
     * @param $companyId
     * @return int
     * author bjy
     */
    public function saveCode($param, $companyId = 1)
    {
        //简易赋值
        $db = pdo();
        //拼装新的表名
        $table = 'short_url_code_' . $companyId;
        //数据入库(支持 - 批量与单条数据)
        $result = $db->insertMulti($table, $param);
        return $result;

    }

    /**
     * 查询 -- 码具体信息
     * @param $param [code/encode/company_id]
     * @return array
     * author bjy
     */
    public function getByCode($param)
    {
        //简易赋值
        $db = pdo();
        //拼装新的表名(通用前缀+公司id+数据库序号+表计数)
        $table = 'short_url_code_' . $param['company_id'];
        //数据入库
        if($param['code']){
            $db->where('code', $param['code']);
        }
        if($param['encode']){
            $db->where('encode', $param['encode']);
        }
        $result = $db->getOne($table, $param['field']);
        return $result;
    }

    /**
     * 根据 码来进行算法的计算
     * @param $code
     * @param $number
     * @return int
     * author bjy
     */
    public function conversionTableMethod($code, $number)
    {
        $num = sprintf("%u", crc32($code));
        //计数值
        return $num % $number == 0 ? $number : $num % $number;
    }

    /**
     * 根据 uniacid、数据库编号、计数创建表
     * @param $param [database_number/table_number]
     * @return array
     * author bjy
     */
    public function creatTables($param)
    {
//        $db = new \MysqliDb ('49.233.9.78', 'uat1_be_shell_co', 'SEmXJTEzxSPWx2KY', 'uat1_be_shell_co');
        $db = new \MysqliDb ('172.21.0.6', 'beesh_short', '75073fc666', 'beesh_short');

        //表名动态赋值
        $tablePrefix = _W::get('config')['db']['prefix'];
        $numArray    = [];//反参集合

        //循环创建数据表
        for ($i = 0; $i < $param['table_numbers']; $i++) {
            $table = $tablePrefix . 'short_url_code_' . $param['company_id'] . '_' . $i;
            //建表sql
            $sql = "CREATE TABLE  IF NOT EXISTS $table (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `uniacid` int(11) NOT NULL DEFAULT '0' COMMENT '项目id',
              `company_id` int(11) NOT NULL DEFAULT '0' COMMENT '企业id',
              `task_id` int(11) NOT NULL DEFAULT '0' COMMENT '任务id',
              `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '源码',
              `encode` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '加密码',
              `target_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '长链接url',
              `count` int(11) NOT NULL DEFAULT '0' COMMENT '扫描查询次数',
              `create_time` timestamp NULL DEFAULT NULL COMMENT '创建时间',
              `update_time` timestamp NULL DEFAULT NULL COMMENT '修改时间',
              PRIMARY KEY (`id`),
              UNIQUE KEY `code` (`code`(191)) USING BTREE
            ) ENGINE=INNODB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;";


            $data       = $db->rawQuery($sql);//运行建表sql
            $numArray[] = $table;//表名存储
            mysqli_free_result($data);//释放结果集
        }

        //关闭数据库连接
        $db->disconnectAll();
        return $numArray;
    }

    /**
     * 任务列表
     * @param $param
     * @return array
     * author bjy
     */
    public function queryPage($param)
    {
        //简易赋值
        $db = pdo();
        $fields = '';
//        $mapper = $this->mapper;
        //拼装新的表名
        $table = 'short_url_code_' . $param['company_id'];

        //模糊查询
        if ($param['keywords']) {
            $keywords = "{$param['keywords']}%";
            $db->where('(name like ?)', [$keywords]);
        }

        //入库转化状态  0 未开始 1 执行中  2 执行完成
        if (is_array($param['status'])) {//数组值查询
            if (empty($param['status'])) {
                $db->where('status', 'false');
            } else {
                $db->where('status', $param['status'], 'in');
            }
        } elseif (is_int($param['status'])) {//单值查询
            $db->where('status', $param['status']);
        }

        //0 未拆分 1 拆分中  2 已拆分
        if (is_int($param['split'])) {
            $db->where('split', $param['split']);
        }

        //查询字段筛选
        if ($param['fields']) {
            $fields = explode(',', $param['fields']);
        }

        //排序字段
        if ($param['order_by_field']) {
            $orderByInfo  = explode(',', $param['order_by_field']);
            $orderByField = $orderByInfo[0];//取出要排序的字段
            $orderBySort  = $orderByInfo[1];//去除要排序的顺序 asc/desc
        } else {
            $orderByField = 'id';
            $orderBySort  = 'desc';
        }

        //进行判断验证（分页走这一步）
        if ($param['page']) {
            //计算新的数据总数、分页总数
            $newMapper = $db;
            $countData = $newMapper->getValue($table,'count(id)');
            $pageCount = ceil($countData / $param['size']);
            //显示数
            $db->pageLimit = $param['size'];

            //查询结果
            $data = $db
                ->orderBy($orderByField, $orderBySort)
                ->arraybuilder()
                ->paginate($table,$param['page'], $fields);

            $result = array(
                'page'       => $param['page'],//当前页
                'size'       => $param['size'],//每页数量
                'page_count' => $pageCount,//总页数
                'count'      => $countData,//总数量
                'list'       => $data//查询的数据结果集
            );
        } else {
            $result = $db->orderBy($orderByField, $orderBySort)->get($table, $fields);
        }

        return $result;
    }


}
