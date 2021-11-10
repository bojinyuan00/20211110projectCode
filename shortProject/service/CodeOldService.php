<?php

namespace Addons\Short\Service;

use \Exception;
use Core\Orm\Mapper;
use Core\Base\Data\_W;
use Core\Annotation\Model\Resource;
use Predis\Command\StringGet;


class CodeOldService extends Mapper
{
    public function __construct()
    {
//        //链接数据库，设置前缀
//        $this->db = new MysqliDb ('192.168.1.245', 'beesh', 'M7fKKF52BGxpR7yS', 'beesh');
//        $this->db->setPrefix('ims_');

        $this->columns = 'id,short_url_task_id,short_code,long_code,create_time,update_time';
    }


    /**
     * 数据入库的速度 (此处的入库函数，支持后期的批量插入数据，而非单条数据入库)
     * @param $param [short_code/long_code/create_time/update_time]
     * @param $dataBaseNumber
     * @param $tableNumber
     * @return bool
     * author bjy
     */
    public function saveCode($param, $dataBaseNumber = 1, $tableNumber,$num)
    {
        //简易赋值
        $db = pdo();
        //拼装新的表名
        $table = 'short_url_code_' . uniacid() . '_' . $dataBaseNumber . '_' . $tableNumber;
        //数据入库(支持 - 批量与单条数据)
        $result = $db->insertMulti($table, $param);
        if (!$result) {
            throw new Exception('参数错误', 400);
        }
        return $num;
    }

    /**
     * 查询 -- 码具体信息
     * @param $param [code/field]
     * @param $uniacid
     * @param $database_number
     * @param $table_number
     * @return array
     * author bjy
     */
    public function getByCode($param, $uniacid, $database_number, $table_number)
    {
        //简易赋值
        $db = pdo();
        //拼装新的表名(通用前缀+公司id+数据库序号+表计数)
        $table = 'short_url_code_' . $uniacid . '_' . $database_number . '_' . $table_number;
        //数据入库
        $result = $db->where('code', $param['code'])->getOne($table, $param['field']);
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
     * 截取两个字符串中间的值
     * @param $kw1
     * @param $mark1
     * @param $mark2
     * @return int
     * author bjy
     */
    function getNeedBetween($kw1, $mark1, $mark2)
    {
        $kw = $kw1;
        $kw = '123' . $kw . '123';
        $st = stripos($kw, $mark1);
        $ed = stripos($kw, $mark2);
        if (($st == false || $ed == false) || $st >= $ed)
            return 0;
        $kw = substr($kw, ($st + 1), ($ed - $st - 1));
        return $kw;
    }

    /**
     * 根据 uniacid、数据库编号、计数创建表
     * @param $param [database_number/table_number]
     * @return array
     * author bjy
     */
    public function creatTable($param)
    {
        //简易赋值
        $db = pdo();

        //表名动态赋值
        $tablePrefix = _W::get('config')['db']['prefix'];
        $table       = $tablePrefix . 'short_url_code_' . uniacid() . '_' . $param['database_number'] . '_' . $param['table_number'];

        //建表sql
        $sql = "CREATE TABLE  IF NOT EXISTS $table (
              `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
              `uniacid` int(11) NOT NULL DEFAULT '0' COMMENT '项目id',
              `domain_id` int(11) NOT NULL DEFAULT '0' COMMENT '域名id',
              `task_id` int(11) NOT NULL DEFAULT '0' COMMENT '任务id',
              `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '短链接码',
              `target_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '长链接url',
              `count` int(11) NOT NULL DEFAULT '0' COMMENT '扫描查询次数',
              `create_time` timestamp NULL DEFAULT NULL COMMENT '创建时间',
              `update_time` timestamp NULL DEFAULT NULL COMMENT '修改时间',
              PRIMARY KEY (`id`),
              UNIQUE KEY `code` (`code`(191)) USING BTREE
            ) ENGINE=INNODB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;";
        //运行建表sql
        $data = $db->rawQuery($sql);

        return $data;
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
        $table = 'short_url_code_' . $param['company_id'].'_1_'.$param['number'];

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
                'page_count' => $db->totalPages,//总页数
                'count'      => $db->totalCount,//总数量
                'list'       => $data//查询的数据结果集
            );
        } else {
            $result = $db->orderBy($orderByField, $orderBySort)->get($table);
        }

        return $result;
    }


}
