<?php


namespace Addons\Short\Service;

use Core\Orm\Mapper;

class TaskSubService extends Mapper
{
    public function __construct()
    {
        $this->table_name = "short_url_task_sub";
        $this->columns    = "id,uniacid,company_id,task_id,file_path,conversion_file_path,status,code_count,create_time,update_time";
    }

    /**
     * 查询子任务信息
     * field字段 -- 'id,name,icon'
     * @param $id
     * @param string $fields
     * @param int $type 类型 1查询某一条数据  2直接查询某个值
     * @return array
     * author bjy
     */
    public function getInfoById($id, $fields = '', $type = 1)
    {
        $mapper = $this->mapper->where('id', $id);
        //类型值为1，查询完整数据
        if ($type == 1) {
            $data = $mapper->getOne($fields);
        } else {//查询单个值
            $data = $mapper->getValue($fields);
        }
        return $data;
    }

    /**
     * 更新子任务信息
     * @param string $id 活动id
     * @param array $param
     * @return string
     * author bjy
     */
    public function update($id, $param)
    {
        //开始更新操作
        $result = $this->mapper->where('id', $id)->update($param);
        return $result;
    }

    /**
     * 子任务列表
     * @param $param
     * @return array
     * author bjy
     */
    public function queryPage($param)
    {
        $fields = '';
        $mapper = $this->mapper;

        //模糊查询
        if ($param['keywords']) {
            $keywords = "{$param['keywords']}%";
            $mapper->where('(name like ?)', [$keywords]);
        }

        //入库转化状态  0 未开始 1 执行中  2 执行完成
        if (is_array($param['status'])) {//数组值查询
            if (empty($param['status'])) {
                $mapper->where('status', 'false');
            } else {
                $mapper->where('status', $param['status'], 'in');
            }
        } elseif (is_int($param['status'])) {//单值查询
            $mapper->where('status', $param['status']);
        }

        //任务id
        if (is_int($param['task_id'])) {
            $mapper->where('task_id', $param['task_id']);
        }

        //企业id
        if (is_int($param['company_id'])) {
            $mapper->where('company_id', $param['company_id']);
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
            $orderByField = 'update_time';
            $orderBySort  = 'desc';
        }

        //进行判断验证（分页走这一步）
        if ($param['page']) {
            //计算新的数据总数、分页总数
            $newMapper = $mapper;
            $countData = $newMapper->getValue('count(id)');
            $pageCount = ceil($countData / $param['size']);
            //显示数
            $mapper->pageLimit = $param['size'];

            //查询结果
            $data = $mapper
                ->orderBy($orderByField, $orderBySort)
                ->arraybuilder()
                ->paginate($param['page'], $fields);

            $result = array(
                'page'       => $param['page'],//当前页
                'size'       => $param['size'],//每页数量
                'page_count' => $pageCount,//总页数
                'count'      => $countData,//总数量
                'list'       => $data//查询的数据结果集
            );
        } else {
            $result = $mapper->orderBy($orderByField, $orderBySort)->get(null, $fields);
        }

        return $result;
    }

    /**
     * 获取id最小的任务的一条数据（任务未转化入库）
     * @return array
     * author bjy
     */
    public function getOneInfo()
    {
        //开始更新操作
        $result = $this->mapper->where('status',0)->orderBy('id', 'asc')->getOne();
        return $result;
    }


}
