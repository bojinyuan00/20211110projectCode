<?php

/**
 * 值验证及转化
 * @param $param -出参
 * @param $data -入参
 * @param $newField -出参字段名
 * @param $oldField -入参字段名
 * @param $default -出参默认值
 * @return array
 */
function isSetValue(&$param, $data, $newField, $oldField, $default = '')
{
    //判断值是否被设置
    if (isset($data[$oldField])) {
        $param[$newField] = $data[$oldField];
    } else {
        //未被设置时，判断有没有默认值
        if ($default) {
            $param[$newField] = $default;
        }
    }

    return $param;

}

/**
 * 值验证及转化(转化为json格式数据入库)
 * @param $param -出参
 * @param $data -入参
 * @param $newField -出参字段名
 * @param $oldField -入参字段名
 * @param $default -出参默认值
 * @return array
 */
function isSetJsonValue(&$param, $data, $newField, $oldField, $default = '')
{
    //判断值是否被设置
    if (isset($data[$oldField])) {
        $param[$newField] = json_encode($data[$oldField]);
    } else {
        //未被设置时，判断有没有默认值
        if ($default) {
            $param[$newField] = $default;
        }
    }

    return $param;
}

/**
 * 唯一编号生成
 * @param string $field 前缀参数
 * @return string
 */
function uniqueOrderNum($field)
{
    $orderNum = $field . date('Ymd') . substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 9) . substr(microtime(), 2, 5) . substr(time(), -6) . sprintf('%02d', rand(0, 99));
    return $orderNum;
}

/**
 * 唯一编号生成
 * @param string $field 前缀参数
 * @return string
 */
function uniqueCodeNumNew($field)
{
    $codeImage = array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'i', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z');
    $codeNum   = $field . $codeImage[rand(0, 26)] . $codeImage[rand(0, 26)] . $codeImage[rand(0, 26)] . strtoupper(dechex(date('m'))) . date('d') . substr(time(), -6) . substr(microtime(), 2, 5) . sprintf('%02d', rand(0, 99));
    return $codeNum;
}

/**
 * 二维数组根据某个字段的值进行分组
 * @param array $arr 前缀参数
 * @param $key '字段名
 * @return string
 */
function array_group_by($arr, $key)
{
    $grouped = [];
    foreach ($arr as $value) {
        $grouped[$value[$key]][] = $value;
    }
    // Recursively build a nested grouping if more parameters are supplied
    // Each grouped array value is grouped according to the next sequential key
    if (func_num_args() > 2) {
        $args = func_get_args();
        foreach ($grouped as $key => $value) {
            $parms         = array_merge([$value], array_slice($args, 2, func_num_args()));
            $grouped[$key] = call_user_func_array('array_group_by', $parms);
        }
    }
    return $grouped;
}

/**
 * 二维数组根据某个字段的值进行累加求和
 * @param array $arr 前缀参数
 * @param $key '字段名
 * @return string
 */
function multi_array_sum($arr, $key)
{
    if ($arr) {
        $sum_no = 0;
        foreach ($arr as $v) {
            $sum_no += $v[$key];
        }
        return $sum_no;
    } else {
        return 0;
    }

}

/**
 * 二维数组根据某个字段的值排序
 * @param array $arr 前缀参数
 * @param string $key '字段名
 * @return string
 */
function rank_array(&$arr, $key)
{
    $delivery_count = array_column($arr, $key);
    array_multisort($delivery_count, SORT_DESC, $arr);
}

/**
 * 获取本月第一天及最后一天
 * @param string $date 前缀参数
 * @return array
 */
function getTheMonth($date)
{
    $firstday = date('Y-m-01 00:00:00', strtotime($date));
    $lastday  = date('Y-m-d 00:00:00', strtotime("$firstday +1 month -1 day"));
    return [$firstday, $lastday];
}

/**
 * 获取本周开始与结束日期
 * @param string $date 前缀参数
 * @return array
 */
function getTheWeek($date)
{
    $timestampStart = mktime(0, 0, 0, date('m'), date('d') - date('w') + 1, date('Y'));
    $timestampEnd   = mktime(23, 59, 59, date('m'), date('d') - date('w') + 7, date('Y'));
    return [date('Y-m-d H:i:s', $timestampStart), date('Y-m-d H:i:s', $timestampEnd)];
}

/**
 * 唯一编号生成
 * @param string $field 前缀参数
 * @return string
 */
function uniquee($field)
{
    $orderNum = $field . date('Ymd') . substr(implode(NULL, array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 9) . substr(microtime(), 2, 5) . substr(time(), -6) . sprintf('%02d', rand(0, 99));
    return $orderNum;
}

/**
 * 唯一生成编号
 * @param string $field 前缀参数
 * @param int $unique
 * @return string
 */
function genRequestSn($field = 'DD', $unique = 0)
{
    $orderNo = $field . date('YmdHis') . substr(microtime(), 2, 5) . mt_rand(10000, 99999);
    if (!empty($unique)) $orderNo = $orderNo . $unique;
    return $orderNo;
}

/**
 * 值提起及去重及重组
 * @param $param -
 * @param $fields -入参
 * @return array
 */
function extractFieldArray($param, $fields)
{
    $result = array_merge(array_unique(array_column($param, $fields)));
    return $result;
}

/**
 * 统计二维数组数据
 * @param $data -
 * @param $baseField -查询字段
 * @param $staticField -统计字段
 * @param string $type 类型 count-总数 sum-求和
 * @return array
 * @author bjy
 */
function arrayStatistical($data, $baseField, $staticField, $type = 'count')
{
    //重组划分
    $dataNew = array_group_by($data, $baseField);
    //新数组
    $result = [];
    //遍历
    foreach ($dataNew as $key => $val) {
        //提取字段
        $countArray = array_column($val, $staticField);
        //判断类型
        if ($type == 'count') {
            $oneInfo[$key] = count($countArray);
        } else {
            $oneInfo[$key] = array_sum($countArray);
        }
        $result = $oneInfo;
    }
    return $result;
}

/**
 * 文件行数统计
 * @param $filePath - 文件路径
 * @return int
 * @author bjy
 */
function getFileLines($filePath)
{
    $line = 0; //初始化行数
    //打开文件
    $fp = fopen($filePath, 'r') or die("open file failure!");
    if ($fp) {
        //获取文件的一行内容，注意：需要php5才支持该函数；
        // while (stream_get_line($fp, 8192, "\n")) {
        //     $line++;
        // }
        // fclose($fp);//关闭文件
        while (fgets($fp)) {
            $line++;
        }
        fclose($fp); //关闭文件
    }
//输出行数；
    return $line;
}

/**
 * 下载远程文件到本地服务器
 * @return array
 * @author bjy
 */
function downFile($url, $save_dir = '', $filename = '', $type = 0)
{
    if (trim($url) == '') {
        return false;
    }
    if (trim($save_dir) == '') {
        $save_dir = './';
    }
    if (0 !== strrpos($save_dir, '/')) {
        $save_dir .= '/';
    }
    //创建保存目录
    if (!file_exists($save_dir) && !mkdir($save_dir, 0777, true)) {
        return false;
    }
    //获取远程文件所采用的方法
    if ($type) {
        $ch      = curl_init();
        $timeout = 5;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $content = curl_exec($ch);
        curl_close($ch);
    } else {
        ob_start();
        readfile($url);
        $content = ob_get_contents();
        ob_end_clean();
    }
    $size = strlen($content);
    //文件大小
    $fp2 = @fopen($save_dir . $filename, 'a');
    fwrite($fp2, $content);
    fclose($fp2);
    unset($content, $url);
    return array(
        'file_name' => $filename,
        'save_path' => $save_dir . $filename
    );
}

/**
 * 函数说明：获取URL某个参数的值
 * @access  public
 * @param   $url  路径
 * @param   $key  要获取的参数
 * @return  string     返回参数值
 */
function getQuerystr($url, $key)
{
    $res = '';
    $a   = strpos($url, '?');
    if ($a !== false) {
        $str = substr($url, $a + 1);
        $arr = explode('&', $str);
        foreach ($arr as $k => $v) {
            $tmp = explode('=', $v);
            if (!empty($tmp[0]) && !empty($tmp[1])) {
                $barr[$tmp[0]] = $tmp[1];
            }
        }
    }
    if (!empty($barr[$key])) {
        $res = $barr[$key];
    }
    return $res;
}

/**
 * php截取指定两个字符之间字符串
 * @param int $status
 * @param string $end
 * @param $input
 * @param $start
 * */
function get_between($input, $start, $end = '', $status = 1)
{
    if (!empty($end)) {
        $substr = substr($input, strlen($start) + strpos($input, $start), (strlen($input) - strpos($input, $end)) * (-1));
    } else {
        if ($status == 1) {
            $substr = substr($input, strripos($input, $start) + 1);
        } else {
            $substr = substr($input, 0, strrpos($input, $start));
        }
    }

    return $substr;
}

/**
 * php创建文件夹
 * @param string $dir
 * @param int $mode
 * */
function makeDirs($dir, $mode = 0777)
{
    if (is_dir($dir) || @mkdir($dir, $mode)) {
        return true;
    }
    if (!mkdirs(dirname($dir), $mode)) {
        return false;
    }
    return @mkdir($dir, $mode);
}




