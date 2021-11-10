<?php
namespace App\Http\Controllers\Subway\eng181\pc_port;

use App\Libs\UrlLib;
use App\Libs\ArrLib;
use App\Libs\GeneralHelper;
use App\Models\PlanModel;
use App\Models\PlanType;
use App\Models\WeekPlan181;
use App\Libs\PlanConst;
use App\Models\DataRecords181;
use App\Models\StationTrackLine181;
use App\Models\Operation;
use App\Models\CollisionDetection181;
use App\Models\State;
use App\Models\StateConversion;
use App\Models\UserConfig;
use App\Models\Role;
use App\Models\Signature;
use App\Models\TrainInfo181;
use App\Models\NewestNum;
use App\Models\UserXDepartment;
use App\Models\CardPrincipalOfApplicants;
use Illuminate\Http\Request;
use App\Libs\MqttLib;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Excel;
use App\Http\Controllers\Controller;

class PlanDataController extends Controller
{
    //计划数据删除接口
    public function delete_plan_data(Request $request)
    {
        $table = 'App\Models\\' . $request->plan_content;
        $table::destroy($request->id);

        return $this->getJson(0, '计划数据删除成功');
    }

    //计划数据详情接口
    public function get_plan_data_one(Request $request)
    {
        $table = 'App\Models\\' . $request->plan_content;

        $obj = $table::find($request->id);
        if (empty($obj)) {
            return $this->getJson(-100, '参数错误');
        }
        $plan_model_info        = PlanModel::where('id', $obj->plan_model_id)->select('id', 'plan_type_id', 'project_id', 'plan_content', 'plan_content_logo')->first();
        $obj->plan_content      = $plan_model_info['plan_content'];
        $obj->plan_content_logo = $plan_model_info['plan_content_logo'];

        return $this->getJson(0, '数据详情获取成功', $obj);
    }

    //计划数据列表接口
    public function get_plan_data_list(Request $request)
    {
        $table = 'App\Models\\' . $request->plan_content;
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'];
        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');
        //当前项目的操作按钮列表
        $operation_data = Operation::where('project_id', $request->project_id)->select('id', 'title')->get()->toArray();
        //当前的计划类型id
        $plan_type_id = $request->plan_type_id;
        //查询主表数据
        $coll = $table::where('plan_data181.project_id', $request->project_id);
        //周下的日计划筛选
        if ($request->filled('week_plan_id')) {
            $coll->where('plan_data181.week_plan_id', $request->week_plan_id);
        }
        //---顶部筛选start---//
        //部门筛选
        if ($request->filled('department_id')) {
            $coll->where('plan_data181.department_id', $request->department_id);
        }
        //类别筛选
        if ($request->filled('plan_model_id')) {
            $coll->where('plan_data181.plan_model_id', $request->plan_model_id);
        }
        //接触网停电筛选
        if ($request->filled('has_breakpoint')) {
            $coll->where('plan_data181.has_breakpoint', $request->has_breakpoint);
        }
        //状态筛选
        if ($request->filled('approve_status')) {
            $coll->where('plan_data181.approve_status', $request->approve_status);
        }
        //审批流程筛选
        if ($request->filled('now_state_id')) {
            $coll->where('plan_data181.now_state_id', $request->now_state_id);
        }
        //线别筛选/时间筛选
        if ($request->filled('track_line_id') || $request->filled('search_time')) {
            $coll->leftJoin('station_track_line181 as a', 'plan_data181.newest_num', 'a.newest_num');
            if ($request->filled('track_line_id')) {
                $coll->where('a.track_line_id', $request->track_line_id);
            }
            if ($request->filled('search_time')) {
                $start_time = Carbon::createFromTimestamp(strtotime($request->search_time) + 64800)->addDays(-1)->toDateTimeString();
                $end_time   = Carbon::createFromTimestamp(strtotime($start_time))->addDays(+1)->toDateTimeString();
                $coll->where('a.start_time', '<=', $end_time)->where('a.end_time', '>=', $start_time);
            }
        }
        $coll->groupBy('plan_data181.id');
        //---顶部筛选end---//

        //----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        //拥有申报权限的人，只看自己提报的数据
        if ($shenbao_status == 1) {
            $coll->where('plan_data181.declare_user_id', $now_user_id);
        }
        //----提报权限人的查询判断end

        //----单位筛选判断模块(获取当前人员的查询部门范围)start
        $department_ids = UserXDepartment::where('user_id', $now_user_id)->pluck('department_id')->toArray();
        //如果当前用户配置了部门信息
        if ($department_ids) {
            if ($shenbao_status == 2) {
                //并且不是提报的权限，则查看他配置的相关部门的数据
                $coll->whereIn('plan_data181.department_id', $department_ids);
            }
        }
        //----单位筛选判断模块(获取当前人员的查询部门范围)end

        //-----状态判断是否展示数据模块start
        // $now_user_status = StateConversion::where('role_id', $now_role_id)->where('plan_type_id', $plan_type_id)->where('operation_id', $operation_data[0]['id'])->orderBy('id', 'ASC')->value('cid');

        // if ($now_user_status) {
        //     if ($shenbao_status == 2) { //并且不是提报的权限，则查看数据
        //         $coll->where('plan_data181.now_state_id', '>=', $now_user_status); //展示比 当前角色审批状态大或者等于的 数据
        //     }
        // }
        if ($shenbao_status == PlanConst::SHENBAO_STATUS_NOT_HAVE) {
            //并且不是提报的权限，则查看数据
            $now_user_status = StateConversion::where('role_id', $now_role_id)->where('plan_type_id', $plan_type_id)->where('operation_id', $operation_data[0]['id'])->value('cid');
            if ($now_user_status) {
                $state_sort = State::where('id', $now_user_status)->value('state_sort');
                $coll->where('now_state_sort', '>=', $state_sort); //展示比 当前角色审批状态大或者等于的 数据
            }
        }
        //-----状态判断是否展示数据模块end

        $coll = $coll->orderBy('id', 'Desc')->select('plan_data181.id as id', 'plan_data181.newest_num', 'plan_data181.now_state_id', 'plan_data181.now_state_title', 'plan_data181.declare_user_id', 'plan_data181.department_title', 'plan_data181.principal_user_name', 'plan_data181.principal_user_phone', 'plan_data181.safety_measures_and_notes', 'plan_data181.plan_model_id', 'plan_data181.serial_num', 'plan_data181.seq_num', 'plan_data181.work_content', 'plan_data181.has_breakpoint', 'plan_data181.plan_content', 'plan_data181.approve_status', 'plan_data181.event_id', 'plan_data181.construction_machinery')->paginate($request->page_size ?? 10);

        list($data, $total) = ArrLib::listDataTotal($coll);

        foreach ($data as &$value) {
            //判断计划是否为A3、A4类的计划
            if(strstr($value['serial_num'], 'A3') || strstr($value['serial_num'], 'A4')){
                //调取封装接口，获取拼接好的施工内容值(模型、数据id、1临时2周计划、字段名)
                $value['work_content'] = GeneralHelper::if_type_have('App\Models\StationTrackLine181',$value['id'],1,'work_content');
            }
            //根据唯一标识获取施工附表内的作业信息
            $StationTrackLine181 = StationTrackLine181::where('newest_num', $value['newest_num'])->orderBy('id', 'ASC')->first();
            if ($StationTrackLine181) {
                $value['station_track_line_time']         = $StationTrackLine181['start_time'] . ' 至 ' . $StationTrackLine181['end_time'];
                $value['station_track_line_title']        = $StationTrackLine181['track_line_title'];
                $value['station_track_line_a_station']    = $StationTrackLine181['a_station_title'];
                $value['station_track_line_b_station']    = $StationTrackLine181['b_station_title'];
                $value['station_track_line_start_pos']    = GeneralHelper::pos_conversion($StationTrackLine181['track_line_pre'], $StationTrackLine181['start_pos']); //开始里程
                $value['station_track_line_end_pos']      = GeneralHelper::pos_conversion($StationTrackLine181['track_line_pre'], $StationTrackLine181['end_pos']); //结束里程
                $value['station_track_line_work_content'] = $StationTrackLine181['work_content']; //施工内容（列车编组）
            }

            //获取模板的详细数据
            $plan_model_info            = PlanModel::where('id', $value['plan_model_id'])->select('id', 'plan_type_id', 'project_id', 'plan_content', 'plan_content_logo')->first();
            $value['plan_content']      = $plan_model_info['plan_content'];
            $value['plan_content_logo'] = $plan_model_info['plan_content_logo'];

            //------同意按钮的字段及展示start
            $value['operation_agree_id'] = $operation_data[0]['id'];
            if ($now_user_id == $value['declare_user_id']) {

                $value['operation_agree_title'] = '提交';

            } else {
                $value['operation_agree_title'] = $operation_data[0]['title'];
            }
            $value['operation_agree_status'] = 1; //默认1展示，2隐藏
            //判断当前用户的列表页面是否需要展示同意按钮
            $operation_agree_info = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[0]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_agree_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------同意按钮的字段及展示end

            //------拒绝按钮的字段及展示start
            $value['operation_refused_id']     = $operation_data[1]['id'];
            $value['operation_refused_title']  = $operation_data[1]['title'];
            $value['operation_refused_status'] = 1; //默认1展示，2隐藏
            //判断当前用户的列表页面是否需要展示拒绝按钮
            $operation_refused_info = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[1]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_refused_info)) {
                $value['operation_refused_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------拒绝按钮的字段及展示end

            //------作废按钮的字段及展示start
            $value['operation_invalid_id']     = $operation_data[2]['id'];
            $value['operation_invalid_title']  = $operation_data[2]['title'];
            $value['operation_invalid_status'] = 1; //默认1展示，2隐藏
            $operation_agree_info              = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[2]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_invalid_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------作废按钮的字段及展示end

            //申报人修改(删除)按钮的展示(当前登录人为申报人，并且提交按钮在)
            $value['update_status'] = 2; //默认为不展示
            $value['delete_status'] = 2; //默认为不展示
            if ($now_user_id == $value['declare_user_id'] && $value['operation_agree_status'] == 1) {
                $value['update_status'] = 1;
                $value['delete_status'] = 1;
                //判断当前用户的列表页面是否需要改变作废名称
                $value['operation_invalid_title'] = '终止';
            }

            //判断当前用户的列表页面是否需要展示清点按钮
            $value['operation_request_in_id']     = $operation_data[3]['id'];
            $value['operation_request_in_title']  = $operation_data[3]['title'];
            $value['operation_request_in_status'] = 1;
            $operation_agree_info                 = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[3]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_request_in_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------清点按钮的字段及展示end

            //判断当前用户的列表页面是否需要展示清点确认按钮
            $value['operation_request_inok_id']     = $operation_data[4]['id'];
            $value['operation_request_inok_title']  = $operation_data[4]['title'];
            $value['operation_request_inok_status'] = 1;
            $operation_agree_info                   = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[4]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_request_inok_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------清点确认按钮的字段及展示end

            //判断当前用户的列表页面是否需要展示施工确认按钮
            $value['operation_request_ok_id']     = $operation_data[7]['id'];
            $value['operation_request_ok_title']  = $operation_data[7]['title'];
            $value['operation_request_ok_status'] = 1;
            $operation_agree_info                 = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[7]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_request_ok_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------施工确认按钮的字段及展示end

            //判断当前用户的列表页面是否需要展示销点按钮
            $value['operation_request_out_id']     = $operation_data[5]['id'];
            $value['operation_request_out_title']  = $operation_data[5]['title'];
            $value['operation_request_out_status'] = 1;
            $operation_agree_info                  = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[5]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_request_out_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------销点按钮的字段及展示end

            //判断当前用户的列表页面是否需要展示注销按钮
            $value['operation_request_outok_id']     = $operation_data[6]['id'];
            $value['operation_request_outok_title']  = $operation_data[6]['title'];
            $value['operation_request_outok_status'] = 1;
            $operation_agree_info                    = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[6]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_request_outok_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------注销按钮的字段及展示end

            //冲突检测按钮的展示
            $value['chong_tu_status'] = 2;
            $chong_tu_num             = GeneralHelper::get_event_num($value['event_id'], 1);
            if ($chong_tu_num == 1) {
                if ($value['approve_status'] == 1 || $value['approve_status'] == 2 || $value['approve_status'] == 3) {
                    $value['chong_tu_status'] = 1;
                }
            }

        }

        return $this->getJson(0, '计划数据列表获取成功', $data, $total);
    }

    //计划数据列表接口
    public function get_plan_data_day_list(Request $request)
    {

        $table = 'App\Models\\' . $request->plan_content;
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'];
        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');
        //当前项目的操作按钮列表
        $operation_data = Operation::where('project_id', $request->project_id)->select('id', 'title')->get()->toArray();
        //当前的计划类型id
        $plan_type_id = $request->plan_type_id;
        //查询主表数据
        $coll = $table::where('project_id', $request->project_id);
        //周下的日计划筛选
        if ($request->filled('week_plan_id')) {
            $coll->where('week_plan_id', $request->week_plan_id);
        }
        //---顶部筛选start---//
        //部门筛选
        if ($request->filled('department_id')) {
            $coll->where('department_id', $request->department_id);
        }
        //类别筛选
        if ($request->filled('plan_model_id')) {
            $coll->where('plan_model_id', $request->plan_model_id);
        }
        //接触网停电筛选
        if ($request->filled('has_breakpoint')) {
            $coll->where('has_breakpoint', $request->has_breakpoint);
        }
        //状态筛选
        if ($request->filled('approve_status')) {
            $coll->where('approve_status', $request->approve_status);
        }
        //线别筛选
        if ($request->filled('track_line_id')) {
            // $coll->where('has_breakpoint', $request->has_breakpoint);
        }
        //---顶部筛选end---//

        //----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        //拥有申报权限的人，只看自己提报的数据
        if ($shenbao_status == 1) {
            $coll->where('declare_user_id', $now_user_id);
        }
        //----提报权限人的查询判断end

        //----单位筛选判断模块(获取当前人员的查询部门范围)start
        $department_ids = UserXDepartment::where('user_id', $now_user_id)->pluck('department_id')->toArray();
        //如果当前用户配置了部门信息
        if ($department_ids) {
            if ($shenbao_status == 2) {
                //并且不是提报的权限，则查看他配置的相关部门的数据
                $coll->whereIn('department_id', $department_ids);
            }
        }
        //----单位筛选判断模块(获取当前人员的查询部门范围)end

        //-----状态判断是否展示数据模块start
        // $now_user_status = StateConversion::where('role_id', $now_role_id)->where('plan_type_id', $plan_type_id)->where('operation_id', $operation_data[0]['id'])->value('cid');
        // if ($now_user_status) {
        //     if ($shenbao_status == 2) { //并且不是提报的权限，则查看数据
        //         $coll->where('now_state_id', '>=', $now_user_status); //展示比 当前角色审批状态大或者等于的 数据
        //     }
        // }
        if ($shenbao_status == PlanConst::SHENBAO_STATUS_NOT_HAVE) {
            //并且不是提报的权限，则查看数据
            $now_user_status = StateConversion::where('role_id', $now_role_id)->where('plan_type_id', $plan_type_id)->where('operation_id', $operation_data[0]['id'])->value('cid');
            if ($now_user_status) {
                $state_sort = State::where('id', $now_user_status)->value('state_sort');
                $coll->where('now_state_sort', '>=', $state_sort); //展示比 当前角色审批状态大或者等于的 数据
            }
        }
        //-----状态判断是否展示数据模块end

        $coll = $coll->orderBy('id', 'Desc')->select('id', 'newest_num', 'now_state_id', 'now_state_title', 'declare_user_id', 'department_title', 'principal_user_name', 'principal_user_phone', 'safety_measures_and_notes', 'plan_model_id', 'serial_num', 'seq_num', 'work_content', 'has_breakpoint', 'plan_content', 'approve_status', 'construction_machinery')->paginate($request->page_size ?? 10);

        list($data, $total) = ArrLib::listDataTotal($coll);

        foreach ($data as &$value) {
            //判断计划是否为A3、A4类的计划
            if(strstr($value['serial_num'], 'A3') || strstr($value['serial_num'], 'A4')){
                //调取封装接口，获取拼接好的施工内容值(模型、数据id、1临时2周计划、字段名)
                $value['work_content'] = GeneralHelper::if_type_have('App\Models\StationTrackLine181',$value['id'],2,'work_content');
            }
            //根据唯一标识获取施工附表内的作业信息
            $StationTrackLine181 = StationTrackLine181::where('newest_num', $value['newest_num'])->orderBy('id', 'ASC')->first();
            if ($StationTrackLine181) {
                $value['station_track_line_start_time'] = $StationTrackLine181['start_time'];
                $value['station_track_line_end_time']   = $StationTrackLine181['end_time'];
                $value['station_track_line_title']      = $StationTrackLine181['track_line_title'];
                $value['station_track_line_a_station']  = $StationTrackLine181['a_station_title'];
                $value['station_track_line_b_station']  = $StationTrackLine181['b_station_title'];
                // $value['station_track_line_start_pos']    = GeneralHelper::pos_conversion($StationTrackLine181['track_line_pre'], $StationTrackLine181['start_pos']); //开始里程
                // $value['station_track_line_end_pos']      = GeneralHelper::pos_conversion($StationTrackLine181['track_line_pre'], $StationTrackLine181['end_pos']); //结束里程
                $value['station_track_line_work_content'] = $StationTrackLine181['work_content']; //施工内容（列车编组）
            }

            //获取模板的详细数据
            $plan_model_info            = PlanModel::where('id', $value['plan_model_id'])->select('id', 'plan_type_id', 'project_id', 'plan_content', 'plan_content_logo')->first();
            $value['plan_content']      = $plan_model_info['plan_content'];
            $value['plan_content_logo'] = $plan_model_info['plan_content_logo'];

            //------同意按钮的字段及展示start
            $value['operation_agree_id'] = $operation_data[0]['id'];
            if ($now_user_id == $value['declare_user_id']) {
                $value['operation_agree_title'] = '提交';
            } else {
                $value['operation_agree_title'] = $operation_data[0]['title'];
            }
            $value['operation_agree_status'] = 1; //默认1展示，2隐藏
            //判断当前用户的列表页面是否需要展示同意按钮
            $operation_agree_info = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[0]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_agree_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------同意按钮的字段及展示end

            //------拒绝按钮的字段及展示start
            $value['operation_refused_id']     = $operation_data[1]['id'];
            $value['operation_refused_title']  = $operation_data[1]['title'];
            $value['operation_refused_status'] = 1; //默认1展示，2隐藏
            //判断当前用户的列表页面是否需要展示拒绝按钮
            $operation_refused_info = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[1]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_refused_info)) {
                $value['operation_refused_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------拒绝按钮的字段及展示end

            //------作废按钮的字段及展示start
            $value['operation_invalid_id']     = $operation_data[2]['id'];
            $value['operation_invalid_title']  = $operation_data[2]['title'];
            $value['operation_invalid_status'] = 1; //默认1展示，2隐藏
            $operation_agree_info              = StateConversion::where('cid', $value['now_state_id'])->where('operation_id', $operation_data[2]['id'])->where('role_id', $now_role_id)->first();
            if (empty($operation_agree_info)) {
                $value['operation_invalid_status'] = 2; //没有符合的信息，隐藏按钮
            }
            //------作废按钮的字段及展示end

            //申报人修改(删除)按钮的展示(当前登录人为申报人，并且提交按钮在)
            $value['update_status'] = 2; //默认为不展示
            $value['delete_status'] = 2; //默认为不展示
            if ($now_user_id == $value['declare_user_id'] && $value['operation_agree_status'] == 1) {
                $value['update_status'] = 1;
                $value['delete_status'] = 1;
                //判断当前用户的列表页面是否需要改变作废名称
                $value['operation_invalid_title'] = '终止';
            }
        }

        return $this->getJson(0, '计划数据列表获取成功', $data, $total);
    }

    public function get_newest_num()
    {
        $newest_num = GeneralHelper::check_newest_num();
        return $newest_num;
    }

    //计划数据添加 入库
    public function add_plan_data(Request $request)
    {
        try {
            $newest_num = GeneralHelper::check_newest_num();
            //涉及到多表数据入库，开启事务
            DB::beginTransaction();
            //数据入库 逻辑代码
            //需要存入的表
            $table = 'App\Models\\' . $request->plan_content;
            //先查询主表内的数据数量
            $data_count = $table::withTrashed()->where('project_id', $request->project_id)->count('id') + 1;
            //主表内 数据字段的入库
            $obj                      = new $table();
            $obj->bim_declare_user_id = getExternalUserId() ?? '';
            $obj->newest_num          = $newest_num ?? 0; //唯一标识
            $obj->plan_content        = $request->plan_content ?? ''; //表名
            $obj->declare_user_id     = $request->declare_user_id ?? 0; //提报人id
            $obj->declare_user_name   = $request->declare_user_name ?? ''; //提报人名称
            $obj->declare_user_phone  = $request->declare_user_phone ?? ''; //提报人电话
            $obj->plan_model_id       = $request->plan_model_id ?? 0; //模板类型
            $obj->eng_type_id         = $request->eng_type_id ?? 0; //项目所属的工程类型，1大铁 2地铁 默认0
            $obj->project_id          = $request->project_id ?? 0; //项目id
            //判断一下作业编号和作业令号是否存在，没有让重新选择时间进行生成   hqf
            if (!$request->serial_num) {
                return $this->getJson(-100, '作业编号不存在，请选择时间重新生成！');
            }
            if (!$request->seq_num) {
                return $this->getJson(-100, '作业令号不存在，请选择时间重新生成！');
            }
            if ($request->filled('week_plan_id')) {
                $obj->serial_num = $request->serial_num . $data_count ?? ''; //作业编号
                $obj->seq_num    = $request->seq_num . $data_count ?? ''; //作业令号
            } else {
                $obj->serial_num = $request->serial_num . $data_count . 'L' ?? ''; //作业编号
                $obj->seq_num    = $request->seq_num . $data_count . 'L' ?? ''; //作业令号
                $obj->event_id   = 121000; //当前操作状态转换的的事件id，默认值为11000
            }

            $obj->department_id             = $request->department_id ?? 0; //提报部门id
            $obj->department_title          = $request->department_title ?? ''; //提报部门名称
            $obj->card_holder_user_id       = $request->card_holder_user_id ?? 0; //持证人id
            $obj->card_holder_user_name     = $request->card_holder_user_name ?? ''; //持证人姓名
            $obj->card_holder_user_phone    = $request->card_holder_user_phone ?? ''; //持证人电话
            $obj->principal_user_id         = $request->principal_user_id ?? 0; //负责人id
            $obj->principal_user_name       = $request->principal_user_name ?? ''; //负责人姓名
            $obj->principal_user_phone      = $request->principal_user_phone ?? ''; //负责人电话
            $obj->work_content              = $request->work_content ?? ''; //工作内容
            $obj->guarder_and_guard_icon    = $request->guarder_and_guard_icon ?? ''; //防护员标志及安排
            $obj->turnout_option            = $request->turnout_option ?? ''; //道岔设置
            $obj->train_speed               = $request->train_speed ?? ''; //行车速度
            $obj->outage_plan               = $request->outage_plan ?? ''; //电力安排
            $obj->else                      = $request->else ?? ''; //其它
            $obj->work_num                  = $request->work_num ?? 0; //作业人数
            $obj->has_help                  = $request->has_help ?? 0; //是否需要帮助 0:否；1：是
            $obj->has_fire                  = $request->has_fire ?? 0; //是否需要动火 0:否；1：是
            $obj->has_train                 = $request->has_train ?? 0; //是否需要动车 0:否；1：是
            $obj->has_breakpoint            = $request->has_breakpoint ?? 0; //接触网是否停电 0:否；1：是
            $obj->safety_measures_and_notes = $request->safety_measures_and_notes ?? ''; //安全措施及注意事项
            $obj->construction_machinery    = $request->construction_machinery ?? ''; //施工机械内容
            $obj->approve_status            = 1; //状态字段1:新计划，2:审批中；3:拒绝;4:作废；5：完成
            $obj->driver_user_id            = $request->driver_user_id ?? ''; //司机id
            $obj->driver_user_name          = $request->driver_user_name ?? ''; //司机名称
            $obj->driver_user_phone         = $request->driver_user_phone ?? ''; //司机电话
            $obj->conductor_user_id         = $request->conductor_user_id ?? ''; //车长id
            $obj->conductor_user_name       = $request->conductor_user_name ?? ''; //车长name
            $obj->conductor_user_phone      = $request->conductor_user_phone ?? ''; //车长电话
            if ($request->management_user_ids) {
                $management_user_ids      = $request->management_user_ids;
                $obj->management_user_ids = json_encode($management_user_ids); //防护人ids数组
            }
            if ($request->protective_user_ids) {
                $protective_user_ids      = $request->protective_user_ids;
                $obj->protective_user_ids = json_encode($protective_user_ids); //防护人ids数组
            }
            // if ($request->construction_person_ids) {
            //     $construction_person_ids      = $request->construction_person_ids;
            //     $obj->construction_person_ids = json_encode($construction_person_ids); //施工人ids数组
            // }
            // //修改持证人和负责人的数据
            // if ($request->card_holder_user) {
            //     $obj->card_holder_user = mJsonEncode($request->card_holder_user); //持证人
            // }
            // if ($request->principal_user) {
            //     $obj->principal_user = mJsonEncode($request->principal_user); //负责人
            // }
            //修改防护员和施工人员的数据    hqf
            // if ($request->protective_user_ids) {
            //     $obj->protective_user_ids = mJsonEncode($request->protective_user_ids); //防护人
            // }
            if ($request->construction_person_ids) {
                $obj->construction_person_ids = mJsonEncode($request->construction_person_ids); //施工人员
            }
            // if ($request->protective_user) {
            //     $obj->protective_user = mJsonEncode($request->protective_user); //防护人
            // }
            if ($request->construction_person) {
                $obj->construction_person = mJsonEncode($request->construction_person); //施工人员
            }
            //工点  hqf
            if ($request->workarea) {
                $obj->workarea = mJsonEncode($request->workarea) ?? ""; //工点
            }
            //获取所属的计划类型id
            $plan_model_info = PlanModel::where('id', $request->plan_model_id)->select('id', 'plan_type_id', 'project_id', 'conversion_plan_model_id')->first();
            if (empty($plan_model_info)) {
                return $this->getJson(-100, '参数错误');
            }
            // //获取数据的当前状态值
            // $state = State::where('plan_type_id', $plan_model_info['plan_type_id'])->select('id', 'title')->orderBy('id', 'ASC')->first();
            // if (empty($state)) {
            //     return $this->getJson(-100, '参数错误');
            // }
            // $obj->now_state_id    = $state['id']; //当前状态
            // $obj->now_state_title = $state['title']; //当前状态名称
            //获取数据的当前状态值
            $state = State::where('plan_type_id', $plan_model_info['plan_type_id'])->where('state_sort', 1)->select('id', 'title', 'state_sort')->first();
            if (empty($state)) {
                return $this->getJson(-100, '参数错误');
            }
            $obj->now_state_id    = $state['id']; //当前状态
            $obj->now_state_title = $state['title']; //当前状态名称
            $obj->now_state_sort  = $state['state_sort']; //当前状排序

            //这里开始 临时计划与周下的日计划进行字段适配
            if ($request->filled('week_plan_id')) {
                $obj->week_plan_id             = $request->week_plan_id ?? 0; //周计划id
                $obj->conversion_plan_model_id = $plan_model_info['conversion_plan_model_id'] ?? 0; //需要转换的模板类型id
            }
            $obj->save();

            //车辆信息附表数据入库
            $train_info = $request->train_info ?? ''; //作业车辆信息
            if (!empty($train_info)) {
                foreach ($train_info as &$val) {
                    $TrainInfo181                   = new TrainInfo181();
                    $TrainInfo181->plan_data_id     = $obj->id; //数据id
                    $TrainInfo181->newest_num       = $obj->newest_num; //标识号
                    $TrainInfo181->train_type_name  = $val['train_type_name']; //车辆类型
                    $TrainInfo181->train_num        = $val['train_num']; //编号
                    $TrainInfo181->weight           = $val['weight']; //载重
                    $TrainInfo181->week_plan_status = 1; //数据类型  1:临时计划；2:日计划
                    if ($request->filled('week_plan_id')) {
                        $TrainInfo181->week_plan_status = 2; //数据类型  1:临时计划；2:日计划
                    }
                    $TrainInfo181->save();
                }
            }

            //施工作业数据入库
            $station_track_line181 = $request->station_track_line181 ?? ''; //作业车辆信息
            // dd($station_track_line181);
            if (!empty($station_track_line181)) {
                //判断作业信息数据条数
                if (count($station_track_line181) > 4) {
                    return $this->getJson(-100, '车站信息添加不能大于4条');
                }
                //循环数据入库
                foreach ($station_track_line181 as &$val) {
                    $StationTrackLine181                   = new StationTrackLine181();
                    $StationTrackLine181->plan_data_id     = $obj->id; //主数据id
                    $StationTrackLine181->newest_num       = $obj->newest_num; //唯一标识
                    $StationTrackLine181->project_id       = $request->project_id; //项目id或者工程id
                    $StationTrackLine181->a_station_id     = $val['a_station_id']; //起始车站id
                    $StationTrackLine181->a_station_title  = $val['a_station_title']; //起始车站名称
                    $StationTrackLine181->a_station_pos    = $val['a_station_pos']; //起始车站中心位置
                    $StationTrackLine181->b_station_id     = $val['b_station_id']; //结束车站id
                    $StationTrackLine181->b_station_title  = $val['b_station_title']; //结束车站名称
                    $StationTrackLine181->b_station_pos    = $val['b_station_pos']; //结束车站中心位置
                    $StationTrackLine181->start_pos        = $val['start_pos']; //开始位置
                    $StationTrackLine181->end_pos          = $val['end_pos']; //结束位置
                    $StationTrackLine181->start_time       = $val['start_time']; //开始时间
                    $StationTrackLine181->end_time         = $val['end_time']; //结束时间
                    $StationTrackLine181->work_content     = $val['work_content'] ?? ''; //作业内容（车辆编组）
                    $StationTrackLine181->track_line_id    = $val['track_line_id']; //线别id
                    $StationTrackLine181->track_line_title = $val['track_line_title']; //线别名称
                    $StationTrackLine181->track_line_pre   = $val['track_line_pre']; //线别前缀
                    $StationTrackLine181->track_line_main  = $val['track_line_main']; //线别是否为主线 0辅线 1主线
                    $StationTrackLine181->track_line_from  = $val['track_line_from']; //线别开始位置
                    $StationTrackLine181->track_line_end   = $val['track_line_end']; //线别结束位置
                    $StationTrackLine181->week_plan_status = 1; //数据类型  1:临时计划；2:日计划
                    if (strtotime($val['start_time']) > strtotime($val['end_time'])) {
                        return $this->getJson(-100, $val['start_station_name'] . '-' . $val['end_station_name'] . '--' . '开始时间大于结束时间');
                    }
                    if ($request->filled('week_plan_id')) {
                        $StationTrackLine181->week_plan_status = 2; //数据类型  1:临时计划；2:日计划
                    }
                    $StationTrackLine181->save();
                }
            }

            //成功提交
            DB::commit();
            return $this->getJson(0, '添加成功');
        } catch (\Exception $e) {
            //错误进行事务回滚
            DB::rollBack();
            if (substr_count($e->getMessage(), 'project_id_serial_num_unique')) {
                return $this->getJson(-101, '作业编号重复');
            }
            if (substr_count($e->getMessage(), 'project_id_serial_num_unique')) {
                return $this->getJson(-101, '作业编号重复');
            }
            if (substr_count($e->getMessage(), 'project_id_seq_num_unique')) {
                return $this->getJson(-101, '作业令号重复');
            }
            if (substr_count($e->getMessage(), 'project_id_seq_num_unique')) {
                return $this->getJson(-101, '作业令号重复');
            }
            return $this->getJson(-100, $e->getMessage());
            // return $this->getJson(-100, '参数错误');
        }
    }

    //计划数据修改 入库
    public function update_plan_data(Request $request)
    {
        try {
            //涉及到多表数据入库，开启事务
            DB::beginTransaction();
            //数据入库 逻辑代码
            //需要存入的表
            $table = 'App\Models\\' . $request->plan_content;
            //主表内 数据字段的入库
            $obj = $table::find($request->id);
            if ($request->has('card_holder_user_id')) {
                $obj->card_holder_user_id = $request->card_holder_user_id ?? 0; //持证人id
            }
            if ($request->has('card_holder_user_name')) {
                $obj->card_holder_user_name = $request->card_holder_user_name ?? ''; //持证人姓名
            }
            if ($request->has('card_holder_user_phone')) {
                $obj->card_holder_user_phone = $request->card_holder_user_phone ?? ''; //持证人电话
            }
            if ($request->has('principal_user_id')) {
                $obj->principal_user_id = $request->principal_user_id ?? 0; //负责人id
            }
            if ($request->has('principal_user_name')) {
                $obj->principal_user_name = $request->principal_user_name ?? ''; //负责人姓名
            }
            if ($request->has('principal_user_phone')) {
                $obj->principal_user_phone = $request->principal_user_phone ?? ''; //负责人电话
            }
            if ($request->has('work_content')) {
                $obj->work_content = $request->work_content ?? ''; //工作内容
            }
            if ($request->has('guarder_and_guard_icon')) {
                $obj->guarder_and_guard_icon = $request->guarder_and_guard_icon ?? ''; //防护员标志及安排
            }
            if ($request->has('turnout_option')) {
                $obj->turnout_option = $request->turnout_option ?? ''; //道岔设置
            }
            if ($request->has('train_speed')) {
                $obj->train_speed = $request->train_speed ?? ''; //行车速度
            }
            if ($request->has('outage_plan')) {
                $obj->outage_plan = $request->outage_plan ?? ''; //电力安排
            }
            if ($request->has('else')) {
                $obj->else = $request->else ?? ''; //其它
            }
            if ($request->has('work_num')) {
                $obj->work_num = $request->work_num ?? 0; //作业人数
            }
            if ($request->has('has_help')) {
                $obj->has_help = $request->has_help ?? 0; //是否需要帮助 0:否；1：是
            }
            if ($request->has('has_fire')) {
                $obj->has_fire = $request->has_fire ?? 0; //是否需要动火 0:否；1：是
            }
            if ($request->has('has_train')) {
                $obj->has_train = $request->has_train ?? 0; //是否需要动车 0:否；1：是
            }
            if ($request->has('has_breakpoint')) {
                $obj->has_breakpoint = $request->has_breakpoint ?? 0; //接触网是否停电 0:否；1：是
            }
            if ($request->has('safety_measures_and_notes')) {
                $obj->safety_measures_and_notes = $request->safety_measures_and_notes ?? ''; //安全措施及注意事项
            }
            if ($request->has('construction_machinery')) {
                $obj->construction_machinery = $request->construction_machinery ?? ''; //施工机械内容
            }
            if ($request->has('driver_user_id')) {
                $obj->driver_user_id = $request->driver_user_id ?? ''; //司机id
            }
            if ($request->has('driver_user_name')) {
                $obj->driver_user_name = $request->driver_user_name ?? ''; //司机名称
            }
            if ($request->has('driver_user_phone')) {
                $obj->driver_user_phone = $request->driver_user_phone ?? ''; //司机电话
            }
            if ($request->has('conductor_user_id')) {
                $obj->conductor_user_id = $request->conductor_user_id ?? ''; //车长id
            }
            if ($request->has('conductor_user_name')) {
                $obj->conductor_user_name = $request->conductor_user_name ?? ''; //车长name
            }
            if ($request->has('conductor_user_phone')) {
                $obj->conductor_user_phone = $request->conductor_user_phone ?? ''; //车长电话
            }
            if ($request->management_user_ids) {
                $management_user_ids      = $request->management_user_ids;
                $obj->management_user_ids = json_encode($management_user_ids); //防护人ids数组
            }
            if ($request->protective_user_ids) {
                $protective_user_ids      = $request->protective_user_ids;
                $obj->protective_user_ids = json_encode($protective_user_ids); //防护人ids数组
            }
            // if ($request->construction_person_ids) {
            //     $construction_person_ids      = $request->construction_person_ids;
            //     $obj->construction_person_ids = json_encode($construction_person_ids); //施工人ids数组
            // }
            // //修改持证人和负责人的数据  hqf
            // if ($request->has('card_holder_user')) {
            //     $obj->card_holder_user = mJsonEncode($request->card_holder_user); //持证人
            // }
            // else {
            //     $obj->card_holder_user = "";
            // }
            // if ($request->has('principal_user')) {
            //     $obj->principal_user = mJsonEncode($request->principal_user) ?? ""; //负责人
            // }
            //修改防护员和施工人员的数据    hqf
            // if ($request->has('protective_user_ids')) {
            //     $obj->protective_user_ids = mJsonEncode($request->protective_user_ids); //防护人
            // }
            // else {
            //     $obj->protective_user_ids = "";
            // }
            if ($request->has('construction_person_ids')) {
                $obj->construction_person_ids = mJsonEncode($request->construction_person_ids) ?? ""; //施工人员
            } else {
                $obj->construction_person_ids = "";
            }
            // if ($request->has('protective_user')) {
            //     $obj->protective_user = mJsonEncode($request->protective_user) ?? ""; //防护人
            // }
            // else {
            //     $obj->protective_user = "";
            // }
            if ($request->has('construction_person')) {
                $obj->construction_person = mJsonEncode($request->construction_person) ?? ""; //施工人员
            } else {
                $obj->construction_person = "";
            }
            //工点  hqf
            if ($request->has('workarea')) {
                $obj->workarea = mJsonEncode($request->workarea) ?? ""; //工点
            }
            $obj->save();

            $week_plan_status = 1;
            if ($request->filled('week_plan_id')) {
                $week_plan_status = 2; //数据类型  1:临时计划；2:日计划
            }

            //车辆信息附表数据入库
            if ($request->train_info) {
                // dd($request->train_info);
                $train_result = TrainInfo181::query()
                    ->where('plan_data_id', $obj->id)
                    ->where('week_plan_status', $week_plan_status)
                    ->delete();
                $train_info = $request->train_info ?? ''; //作业车辆信息
                if (!empty($train_info)) {
                    foreach ($train_info as &$val) {
                        $TrainInfo181                   = new TrainInfo181();
                        $TrainInfo181->plan_data_id     = $obj->id; //数据id
                        $TrainInfo181->newest_num       = $obj->newest_num; //标识号
                        $TrainInfo181->train_type_name  = $val['train_type_name']; //车辆类型
                        $TrainInfo181->train_num        = $val['train_num']; //编号
                        $TrainInfo181->weight           = $val['weight']; //载重
                        $TrainInfo181->week_plan_status = $week_plan_status; //数据类型  1:临时计划；2:日计划
                        $TrainInfo181->save();
                    }
                }
            }

            //施工作业数据入库
            $station_track_line181 = $request->station_track_line181 ?? ''; //作业车辆信息
            // dd($station_track_line181);
            if (!empty($station_track_line181)) {
                //判断作业信息数据条数
                if (count($station_track_line181) > 4) {
                    return $this->getJson(-100, '车站信息添加不能大于4条');
                }
                //循环数据入库
                foreach ($station_track_line181 as &$val) {
                    $StationTrackLine181                   = StationTrackLine181::find($val['id']);
                    $StationTrackLine181->plan_data_id     = $obj->id; //主数据id
                    $StationTrackLine181->newest_num       = $obj->newest_num; //唯一标识
                    $StationTrackLine181->project_id       = $obj->project_id; //项目id或者工程id
                    $StationTrackLine181->a_station_id     = $val['a_station_id']; //起始车站id
                    $StationTrackLine181->a_station_title  = $val['a_station_title']; //起始车站名称
                    $StationTrackLine181->a_station_pos    = $val['a_station_pos']; //起始车站中心位置
                    $StationTrackLine181->b_station_id     = $val['b_station_id']; //结束车站id
                    $StationTrackLine181->b_station_title  = $val['b_station_title']; //结束车站名称
                    $StationTrackLine181->b_station_pos    = $val['b_station_pos']; //结束车站中心位置
                    $StationTrackLine181->start_pos        = $val['start_pos']; //开始位置
                    $StationTrackLine181->end_pos          = $val['end_pos']; //结束位置
                    $StationTrackLine181->start_time       = $val['start_time']; //开始时间
                    $StationTrackLine181->end_time         = $val['end_time']; //结束时间
                    $StationTrackLine181->work_content     = $val['work_content'] ?? ''; //作业内容（车辆编组）
                    $StationTrackLine181->track_line_id    = $val['track_line_id']; //线别id
                    $StationTrackLine181->track_line_title = $val['track_line_title']; //线别名称
                    $StationTrackLine181->track_line_pre   = $val['track_line_pre']; //线别前缀
                    $StationTrackLine181->track_line_main  = $val['track_line_main']; //线别是否为主线 0辅线 1主线
                    $StationTrackLine181->track_line_from  = $val['track_line_from']; //线别开始位置
                    $StationTrackLine181->track_line_end   = $val['track_line_end']; //线别结束位置
                    $StationTrackLine181->week_plan_status = $week_plan_status; //数据类型  1:临时计划；2:日计划
                    if (strtotime($val['start_time']) > strtotime($val['end_time'])) {
                        return $this->getJson(-100, $val['start_station_name'] . '-' . $val['end_station_name'] . '--' . '开始时间大于结束时间');
                    }
                    //转出的日计划修改时所需要的的验证
                    if ($request->plan_content == 'PlanData181' && $obj->week_plan_id != 0) {
                        if ($val['start_pos_old'] < $val['end_pos_old']) {
                            if ($val['start_pos'] < $val['start_pos_old'] || $val['start_pos'] > $val['end_pos_old']) {
                                return $this->getJson(-100, '起始里程超出范围限制');
                            }
                            if ($val['end_pos'] < $val['start_pos_old'] || $val['end_pos'] > $val['end_pos_old']) {
                                return $this->getJson(-100, '结束里程超出范围限制');
                            }
                        }
                        if ($val['start_pos_old'] > $val['end_pos_old']) {
                            if ($val['start_pos'] < $val['end_pos_old'] || $val['start_pos'] > $val['start_pos_old']) {
                                return $this->getJson(-100, '起始里程超出范围限制');
                            }
                            if ($val['end_pos'] < $val['end_pos_old'] || $val['end_pos'] > $val['start_pos_old']) {
                                return $this->getJson(-100, '结束里程超出范围限制');
                            }
                        }
                        if (strtotime($val['start_time']) < strtotime($val['start_time_old']) || strtotime($val['start_time']) > strtotime($val['end_time_old'])) {
                            return $this->getJson(-100, '开始时间超出范围');
                        }
                        if (strtotime($val['end_time']) < strtotime($val['start_time_old']) || strtotime($val['end_time']) > strtotime($val['end_time_old'])) {
                            return $this->getJson(-100, '结束时间超出范围');
                        }
                    }
                    $StationTrackLine181->save();
                }
            }
            //成功提交
            DB::commit();
            return $this->getJson(0, '修改成功');
        } catch (\Exception $e) {
            //错误进行事务回滚
            DB::rollBack();
            if (substr_count($e->getMessage(), 'project_id_serial_num_unique')) {
                return $this->getJson(-101, '作业编号重复');
            }
            if (substr_count($e->getMessage(), 'project_id_serial_num_unique')) {
                return $this->getJson(-101, '作业编号重复');
            }
            if (substr_count($e->getMessage(), 'project_id_seq_num_unique')) {
                return $this->getJson(-101, '作业令号重复');
            }
            if (substr_count($e->getMessage(), 'project_id_seq_num_unique')) {
                return $this->getJson(-101, '作业令号重复');
            }
            return $this->getJson(-100, $e->getMessage());
            // return $this->getJson(-100, '参数错误');
        }
    }

    //基础数据生成接口
    public function template_info(Request $request)
    {
        //当前用户
        $user_id = session()->get('user_data')['data']['id'] ?? 1472;
        if ($user_id == 0 || empty($user_id)) {
            return $this->getJson(-100, '用户id为空');
        }
        //当前用户的默认单位id 和 名称
        $bureau_department_id              = session()->get('user_data')['data']['bureau_department_id'] ?? 1;
        $bureau_department_title           = session()->get('user_data')['data']['bureau_department_title'] ?? '测试单位';
        $data                              = [];
        $data['department_id']             = $bureau_department_id; //用户的默认单位id
        $data['department_title']          = $bureau_department_title; //用户的默认单位名称
        $data['declare_user_id']           = $user_id; //申请人名称
        $data['declare_user_name']         = session()->get('user_data')['data']['nickname'] ?? '卜锦元'; //申请人名称
        $data['declare_user_phone']        = session()->get('user_data')['data']['phone'] ?? '18329730510'; //申请人电话phone
        $data['card_holder_user_lists']    = GeneralHelper::applicant_card_principal_user_info($user_id, 1); //申请人关联的持证人
        $data['principal_user_lists']      = GeneralHelper::applicant_card_principal_user_info($user_id, 2); //申请人关联的负责人
        $data['protective_user_lists']     = GeneralHelper::applicant_card_principal_user_info($user_id, 4); //申请人关联的防护人
        $data['construction_person_lists'] = GeneralHelper::applicant_card_principal_user_info($user_id, 6); //申请人关联的施工人
        $data['driver_user_lists']         = GeneralHelper::applicant_card_principal_user_info($user_id, 11); //申请人关联的司机
        $data['conductor_user_lists']      = GeneralHelper::applicant_card_principal_user_info($user_id, 12); //申请人关联的车长
        $data['management_user_lists']     = GeneralHelper::applicant_card_principal_user_info($user_id, 13); //申请人关联的管理人员
        $data['track_line_lists']          = GeneralHelper::track_line_lists($request->project_id, 2); //项目下的线别列表
        $data['station_lists']             = GeneralHelper::station_lists($request->project_id, 2); //项目下的车站列表

        return $this->getJson(0, '信息获取成功', $data);
    }

    //详情接口
    public function template_details(Request $request)
    {
        //获取表名
        $table = 'App\Models\\' . $request->plan_content;
        $data  = $table::find($request->id);
        if (empty($data)) {
            return $this->getJson(-100, '参数错误');
        }
        $data                           = $data->toArray();
        $data['plan_model_desc']        = PlanModel::where('id', $data['plan_model_id'])->value('desc');
        $data['card_holder_user_lists'] = GeneralHelper::applicant_card_principal_user_info($data['declare_user_id'], 1); //申请人关联的负责人
        $data['principal_user_lists']   = GeneralHelper::applicant_card_principal_user_info($data['declare_user_id'], 2); //申请人关联的持证人
        $data['driver_user_lists']      = GeneralHelper::applicant_card_principal_user_info($data['declare_user_id'], 11); //申请人关联的司机
        $data['conductor_user_lists']   = GeneralHelper::applicant_card_principal_user_info($data['declare_user_id'], 12); //申请人关联的车长
        //现场防护人数据转化
        if ($data['protective_user_ids']) {
            $protective_user_ids = json_decode($data['protective_user_ids']) ?? [];
            //获取防护人员的名称
            $protective_user_names = CardPrincipalOfApplicants::query()->whereIn('id', $protective_user_ids)->select('user_name', 'phone')->get()->toArray();
            if ($protective_user_names) {
                $name_phone = [];
                foreach ($protective_user_names as &$val) {
                    $name_phone_pin_jie = '';
                    $name_phone_pin_jie = $val['user_name'] . '/' . $val['phone'];
                    $name_phone[]       = $name_phone_pin_jie;
                }
                $protective_user_names = implode(",", $name_phone);
            } else {
                $protective_user_names = '';
            }
            $data['protective_user_names'] = $protective_user_names;
            unset($data['protective_user_ids']);
            unset($protective_user_names);
            $data['protective_user_ids'] = $protective_user_ids;
        }
        $data['protective_user_ids_all'] = GeneralHelper::applicant_card_principal_user_info($data['declare_user_id'], 4);
        //管理人员数据转化
        if ($data['management_user_ids']) {
            $management_user_ids = json_decode($data['management_user_ids']) ?? [];
            //获取管理人员的名称
            $management_user_names = CardPrincipalOfApplicants::query()->whereIn('id', $management_user_ids)->select('user_name', 'phone')->get()->toArray();
            if ($management_user_names) {
                $name_phone = [];
                foreach ($management_user_names as &$val) {
                    $name_phone_pin_jie = '';
                    $name_phone_pin_jie = $val['user_name'];
                    $name_phone[]       = $name_phone_pin_jie;
                }
                $management_user_names = implode(",", $name_phone);
            } else {
                $management_user_names = '';
            }
            $data['management_user_names'] = $management_user_names;
            unset($data['management_user_ids']);
            unset($management_user_names);
            $data['management_user_ids'] = $management_user_ids;
        } else {
            $data['management_user_names'] = '';
            $data['management_user_ids']   = [];
        }
        $data['management_user_lists'] = GeneralHelper::applicant_card_principal_user_info($data['declare_user_id'], 13);
        // //持证人和负责人数据处理   hqf
        // if ($data['card_holder_user']) {
        //     $card_holder_user             = json_decode($data['card_holder_user']) ?? [];//持证人
        //     $data['card_holder_user']     = $card_holder_user;
        // }
        // else {
        //     $data['card_holder_user']     = [];
        // }
        // if ($data['principal_user']) {
        //     $principal_user             = json_decode($data['principal_user']) ?? [];//负责人
        //     $data['principal_user']     = $principal_user;
        // }
        // else {
        //     $data['principal_user']     = [];
        // }
        // //防护人员数据处理   hqf
        // if ($data['protective_user']) {
        //     $protective_user = json_decode($data['protective_user']) ?? [];
        //     // return $protective_user;
        //     if ($protective_user) {
        //         $name_phone = [];
        //         foreach ($protective_user as &$val) {

        //             $name_phone_pin_jie = '';
        //             $name_phone_pin_jie = $val->name . '/' . $val->phone;
        //             $name_phone[]       = $name_phone_pin_jie;
        //         }
        //         $protective_user_names = implode(",", $name_phone);
        //     } else {
        //         $protective_user_names = '';
        //     }
        //     // return $protective_user_names;
        //     $data['protective_user_names'] = $protective_user_names;
        //     unset($data['protective_user']);
        //     unset($protective_user_names);
        //     $data['protective_user_ids']     = $protective_user;
        // }
        // else {
        //     $data['protective_user_names']   = '';
        //     $data['protective_user_ids']     = [];
        // }

        // //施工人人数据转化
        // if ($data['construction_person_ids']) {
        //     $construction_person_ids = json_decode($data['construction_person_ids']);
        //     //获取施工人员的名称
        //     $construction_person_names = CardPrincipalOfApplicants::query()->whereIn('id', $construction_person_ids)->pluck('user_name');
        //     if ($construction_person_names) {
        //         $construction_person_names = $construction_person_names->toArray();
        //         $construction_person_names = implode(",", $construction_person_names);
        //     } else {
        //         $construction_person_names = '';
        //     }
        //     $data['construction_person_names'] = $construction_person_names;
        //     unset($data['construction_person_ids']);
        //     $data['construction_person_ids']     = $construction_person_ids;
        //     $data['construction_person_ids_all'] = GeneralHelper::applicant_card_principal_user_info($data['declare_user_id'], 6);
        // }
        //施工人员数据处理  hqf
        if ($data['construction_person']) {
            $construction_person = json_decode($data['construction_person']) ?? [];
            // return $construction_person;
            if ($construction_person) {
                $name_phone = [];
                foreach ($construction_person as &$val) {
                    $name_phone_pin_jie = '';
                    $name_phone_pin_jie = $val->name;
                    $name_phone[]       = $name_phone_pin_jie;
                }
                $construction_person_names = implode(",", $name_phone);
            } else {
                $construction_person_names = '';
            }
            // return $construction_person_names;
            $data['construction_person_names'] = $construction_person_names;
            unset($data['construction_person']);
            unset($construction_person_names);
            $data['construction_person_ids'] = $construction_person;
        } else {
            $data['construction_person_names'] = '';
            $data['construction_person_ids']   = [];
        }
        //工点 hqf
        if ($data['workarea']) {
            $workarea = json_decode($data['workarea']) ?? [];
            // return $workarea;
            if ($workarea) {
                $workarea_name = [];
                foreach ($workarea as &$val) {
                    $workarea_name_pin_jie = '';
                    $workarea_name_pin_jie = $val->name;
                    $workarea_name[]       = $workarea_name_pin_jie;
                }
                $workarea_names = implode(",", $workarea_name);
            } else {
                $workarea_names = '';
            }
            $data['workarea_names'] = $workarea_names;
            $data['workarea']       = $workarea;
        } else {
            $data['workarea_names'] = "";
            $data['workarea']       = [];
        }
        //机车信息
        $TrainInfo181 = TrainInfo181::where('newest_num', $data['newest_num'])->select('id', 'plan_data_id', 'newest_num', 'train_type_name', 'train_num', 'weight');
        if ($request->filled('week_plan_status')) {
            $TrainInfo181->where('week_plan_status', $request->week_plan_status);
        }
        $TrainInfo181             = $TrainInfo181->get()->toArray();
        $data['train_info_lists'] = $TrainInfo181;
        //施工信息
        $StationTrackLine181 = StationTrackLine181::where('newest_num', $data['newest_num']);
        if ($request->filled('week_plan_status')) {
            $StationTrackLine181->where('week_plan_status', $request->week_plan_status);
        }
        $StationTrackLine181              = $StationTrackLine181->get()->toArray();
        $data['station_track_line_lists'] = $StationTrackLine181;

        if ($data['plan_content'] == 'PlanData181') {
            //打印按钮的展示start
            $data['dayin_status'] = 2;
            $event_id_dayin       = GeneralHelper::get_event_num($data['event_id'], 2);
            if ($event_id_dayin == 1) {
                $data['dayin_status'] = 1;
            }
            //打印按钮的展示end

            //内审签章的展示start
            $data['signature_neishen_status'] = 2;
            $event_id_neishen                 = GeneralHelper::get_event_num($data['event_id'], 3);
            // $event_id_neishen   = $event_id[count($event_id) - 3];
            if ($event_id_neishen == 1) {
                $data['signature_neishen_status'] = 1;
            }
            //内审签章的展示end
            //监理签章的展示start
            $data['signature_jianli_status'] = 2;
            $event_id_jianli                 = GeneralHelper::get_event_num($data['event_id'], 6);
            // $event_id_jianli   = $event_id[count($event_id) - 6];
            if ($event_id_jianli == 2) {
                $data['signature_jianli_status'] = 1;
            }
            //监理签章的展示end

            //请点确认签章的展示start
            $data['signature_diaodu_status'] = 2;
            $event_id_diaodu                 = GeneralHelper::get_event_num($data['event_id'], 4);
            // $event_id_diaodu   = $event_id[count($event_id) - 4];
            if ($event_id_diaodu == 2) {
                $data['signature_diaodu_status'] = 1;
            }
            //请点确认签章的展示end

            //销点签章按钮的展示 （要求是 调度注销之后展示内部领导的签章，注销之后计划已经完成，所以用完成的状态字段判断）
            $data['signature_xiaodian_status'] = 2;
            $event_id_xiaodian                 = $data['approve_status'];
            if ($event_id_xiaodian == 5) {
                $data['signature_xiaodian_status'] = 1;
            }
            //销点签章的展示end
            $data['signature_info'] = $this->signature_info($request->id, $data['project_id']);
            //签章--状态
        }

        return $this->getJson(0, '详情获取成功', $data);
    }

    //施工许可证页面 车站及线别下拉 列表  bjy
    public function get_station_track_line(Request $request)
    {
        $data                     = [];
        $data['station_lists']    = GeneralHelper::station_lists($request->project_id, 2); //项目下的车站列表
        $data['track_line_lists'] = GeneralHelper::track_line_lists($request->project_id, 2); //项目下的线别列表
        return $this->getJson(0, '信息获取成功', $data);
    }

    //施工许可证页面 车站及线别下拉 列表  bjy
    public function get_track_line(Request $request)
    {
        $track_line_lists = GeneralHelper::track_line_lists($request->project_id, 2); //项目下的线别列表
        return $this->getJson(0, '信息获取成功', $track_line_lists);
    }

    //添加人员成功，更新人员下拉框
    public function get_card_principal_lists(Request $request)
    {
        //当前用户
        $user_id = session()->get('user_data')['data']['id'] ?? 1472;
        if ($user_id == 0 || empty($user_id)) {
            return $this->getJson(-100, '用户id为空');
        }
        $data                              = [];
        $data['card_holder_user_lists']    = GeneralHelper::applicant_card_principal_user_info($user_id, 1); //申请人关联的持证人
        $data['principal_user_lists']      = GeneralHelper::applicant_card_principal_user_info($user_id, 2); //申请人关联的负责人
        $data['protective_user_lists']     = GeneralHelper::applicant_card_principal_user_info($user_id, 4); //申请人关联的防护人
        $data['construction_person_lists'] = GeneralHelper::applicant_card_principal_user_info($user_id, 6); //申请人关联的施工人
        $data['driver_user_lists']         = GeneralHelper::applicant_card_principal_user_info($user_id, 11); //申请人关联的司机
        $data['conductor_user_lists']      = GeneralHelper::applicant_card_principal_user_info($user_id, 12); //申请人关联的车长
        $data['management_user_lists']     = GeneralHelper::applicant_card_principal_user_info($user_id, 13); //申请人关联的管理人员
        return $this->getJson(0, '信息获取成功', $data);
    }

    //项目下的 部门列表
    public function get_departments_lists(Request $request)
    {
        $data = GeneralHelper::departments_lists($request->project_id, 2); //项目下的部门列表
        return $this->getJson(0, '信息获取成功', $data);
    }

    //根据工程获取其下的部门信息
    public function get_all_departments(Request $request)
    {
        $engineering_all_departments = $this->get_engineering_all_departments($request->project_id ?? session()->get('user_data')['data']['engineering_id']); //工程下的全部部门信息
        return $this->getJson(0, '信息获取成功', $engineering_all_departments);
    }

    //工程下的全部部门列表信息
    protected function get_engineering_all_departments($project_id)
    {
        //调取接口  返回车站id和名称
        $client = new \GuzzleHttp\Client(['base_uri' => UrlLib::WEB_URL()]);
        $body   = ['engineering_id' => $project_id];
        $res    = $client->request('POST', 'api/declare/get_project_all_departments',
            ['json'   => $body,
                'headers' => [
                    'Content-type' => 'application/json'],
            ]);
        $departments     = $res->getBody()->getContents();
        $departmentsinfo = json_decode($departments)->data; //线别信息
        if (empty($departmentsinfo)) {
            return '';
        }
        return $departmentsinfo;
    }

    //审批同意、拒绝接口
    public function approval_agree_refuse(Request $request)
    {
        //表名
        $table          = 'App\Models\\' . $request->plan_content;
        $operation_id   = $request->operation_id;
        $operation_info = Operation::where('id', $operation_id)->select('approve_status', 'title')->first();
        $obj            = $table::find($request->id);
        if ($obj['now_state_id'] != $request->now_state_id) {
            return $this->getJson(-100, '重复操作,请刷新页面');
        }
        // dd($obj);
        //mqtt 推送
        if ($operation_info->title == '请点') {
            $res['serial_num']               = $obj->serial_num; //作业编号
            $res['seq_num']                  = $obj->seq_num; //作业令号
            $planmodel                       = PlanModel::where('id', $obj->plan_model_id)->select('title', 'desc')->first();
            $res['construction_title']       = $planmodel->title; //类型名称
            $res['construction_print_title'] = $planmodel->desc; //类型标题
            $res['department_title']         = $obj->department_title; //施工单位
            $res['work_num']                 = $obj->work_num; //施工人数
            $res['applicant_name']           = $obj->declare_user_name; //施工申请人
            $res['applicant_phone']          = $obj->declare_user_phone; //施工申请人电话
            $res['card_holder_user_name']    = $obj->card_holder_user_name; //持证人
            $res['card_holder_user_phone']   = $obj->card_holder_user_phone; //持证人电话
            $res['principal_user_name']      = $obj->principal_user_name; //负责人
            $res['principal_user_phone']     = $obj->principal_user_phone; //负责人电话

            $res['protective_user_info'] = '';
            if (!empty($obj['protective_user_ids'])) {
//防护人
                $protective_user_ids         = json_decode($obj->protective_user_ids);
                $res['protective_user_info'] = ArrLib::getCardOrPrincipal($protective_user_ids);
            }

            $res['construction_user_info'] = '';
            // if (!empty($obj['construction_person_ids'])) {// bjy 2020-07-25 home修改
            if (!empty($obj['construction_person'])) {
//施工人员
                $res['construction_user_info'] = json_decode($obj->construction_person); // bjy 2020-07-25 home修改
                // $construction_person_ids       = json_decode($obj->construction_person_ids);
                // $res['construction_user_info'] = ArrLib::getCardOrPrincipal($construction_person_ids);
            }
            $res['template_expends'] = StationTrackLine181::query()
                ->where('plan_data_id', $obj->id)
                ->where('week_plan_status', 1)
                ->selectRaw("a_station_title as start_station_name,start_pos,b_station_title as end_station_name,end_pos,start_time,end_time,track_line_title,track_line_pre")
                ->get()
                ->toArray();
            MqttLib::mqtt_publish('guang_zhou18_dplan_user', $res);
        }
        //mqtt 推送 end
        if (empty($obj)) {
            return $this->getJson(-100, '参数错误');
        }
        $now_state_id = $obj['now_state_id']; //主表数据的当前状态id
        //查询 当前步骤的 状态转换数据
        $state_conversion = StateConversion::where('operation_id', $operation_id)->where('cid', $obj['now_state_id'])->first();
        // dd($state_conversion);
        if (empty($state_conversion)) {
            return $this->getJson(-100, '参数错误');
        }

        $next_state_id = $state_conversion['eid']; //下一步状态id
        $role_id       = $state_conversion['role_id']; //审批角色id
        $role_title    = Role::where('id', $role_id)->value('title');

        //日志记录
        $coll                       = new DataRecords181();
        $coll->plan_data_id         = $obj['id']; //当前计划数据id
        $coll->now_state_id         = $next_state_id; //当前状态id更新
        $coll->pre_state_id         = $obj['now_state_id']; //上一步状态id
        $coll->operation_id         = $operation_id; //当前操作id
        $coll->operation_user_id    = $request->operation_user_id ?? 0; //当前操作用户id
        $coll->operation_user_name  = $request->operation_user_name ?? ''; //当前操作用户名称
        $coll->operation_role_id    = $role_id ?? 0; //当前操作用户角色id
        $coll->operation_role_title = $role_title ?? ''; //当前操作用户角色名称
        $coll->operation_note       = $request->operation_note ?? ''; //当前操作备注
        $coll->newest_num           = $obj['newest_num']; //数据唯一标识
        $coll->project_id           = $request->project_id; //当前项目id
        $coll->week_plan_status     = 1; //数据类型  1:临时计划；2:日计划
        if ($request->week_plan_id) {
            $coll->week_plan_status = 2; //数据类型  1:临时计划；2:日计划
        }
        $coll->save(); //记录数据入库

        //判断下一步是否还有审批
        $have_state_conversion = StateConversion::where('operation_id', $operation_id)->where('cid', $next_state_id)->first();
        //周计划下判断一下是否彻底审批完成
        if ($request->week_plan_id) {
            if (empty($have_state_conversion)) {
                //需要往临时计划表内插入一条一样的数据
                $data = $this->assignment_plan_data($obj);
            }
        }

        $state_info           = State::find($next_state_id);
        $obj->now_state_id    = $state_info->id; //更新主表的当前状态id
        $obj->now_state_title = $state_info->title; //更新主表的当前状态名称
        $obj->now_state_sort  = $state_info->state_sort; //更新主表的当前状态排序
        if (!$request->week_plan_id) {
            $obj->event_id = $state_conversion['event_id']; //当前操作步骤的事件id
        }
        if (empty($have_state_conversion)) { //表示计划审批完成
            $obj->approve_status = 5; //更新主表的数据当前状态id（状态字段1:新计划，2:审批中；3:拒绝;4:作废；5：完成）
        } else {
            $obj->approve_status = $operation_info['approve_status']; //更新主表的数据当前状态id（状态字段1:新计划，2:审批中；3:拒绝;4:作废；5：完成）
        }

        $obj->save();

        return $this->getJson(0, $role_title . $operation_info['title']);
    }

    //历史记录
    public function get_data_records(Request $request)
    {
        $DataRecords181 = DataRecords181::where('newest_num', $request->newest_num)->orderBy('id', 'desc');
        if ($request->week_plan_status == 1) {
            $DataRecords181->where('week_plan_status', 1);
        } else {
            $DataRecords181->where('week_plan_status', 2);
        }
        $DataRecords181 = $DataRecords181->select('id', 'pre_state_id', 'operation_user_name', 'operation_id', 'operation_note', 'created_at')->orderBy('id', 'ASC')->get()->toArray();
        foreach ($DataRecords181 as &$value) {
            $value['operation_title'] = Operation::where('id', $value['operation_id'])->value('title');
            $value['pre_state_title'] = State::where('id', $value['pre_state_id'])->value('title');
        }
        return $this->getJson(0, '历史记录获取成功', $DataRecords181, count($DataRecords181));
    }

    //作业编号和作业令号的生成
    public function get_work_point_info(Request $request)
    {
        $data               = [];
        $plan_model_id      = $request->plan_model_id;
        $plan_model_title   = PlanModel::where('id', $plan_model_id)->value('title');
        $start_time         = $request->start_time ? strtotime($request->start_time) : time();
        $start_time         = date('Y-m-d', $start_time);
        $serial_num         = $plan_model_title . '_' . $start_time . '_';
        $seq_num            = '(' . date('Y') . ')字 第(' . $start_time . ')-';
        $data['serial_num'] = $serial_num;
        $data['seq_num']    = $seq_num;
        return $this->getJson(0, '编号生成成功', $data);
    }

    /**
     * 转化数据
     */
    protected static function assignment_plan_data($week_plan_day_data)
    {
        try {
            //涉及到多表数据入库，开启事务
            DB::beginTransaction();
            //数据入库 逻辑代码
            //获取所属的计划类型id
            $plan_model_info = PlanModel::where('id', $week_plan_day_data['conversion_plan_model_id'])->select('id', 'plan_type_id', 'project_id', 'plan_content')->first();
            if (empty($plan_model_info)) {
                return [-100, '参数错误'];
            }
            //需要存入的表
            $table = 'App\Models\\' . $plan_model_info['plan_content'];
            //主表内 数据字段的入库
            $obj                            = new $table();
            $obj->bim_declare_user_id       = $week_plan_day_data['bim_declare_user_id'] ?? ''; //bim人员id
            $obj->newest_num                = $week_plan_day_data['newest_num'] ?? 0; //唯一标识
            $obj->plan_content              = $plan_model_info['plan_content'] ?? ''; //表名
            $obj->declare_user_id           = $week_plan_day_data['declare_user_id'] ?? 0; //提报人id
            $obj->declare_user_name         = $week_plan_day_data['declare_user_name'] ?? ''; //提报人名称
            $obj->declare_user_phone        = $week_plan_day_data['declare_user_phone'] ?? ''; //提报人电话
            $obj->plan_model_id             = $week_plan_day_data['conversion_plan_model_id'] ?? 0; //模板类型
            $obj->eng_type_id               = $week_plan_day_data['eng_type_id'] ?? 0; //项目所属的工程类型，1大铁 2地铁 默认0
            $obj->project_id                = $week_plan_day_data['project_id'] ?? 0; //项目id
            $obj->serial_num                = $week_plan_day_data['serial_num'] ?? ''; //作业编号
            $obj->seq_num                   = $week_plan_day_data['seq_num'] ?? ''; //作业令号
            $obj->department_id             = $week_plan_day_data['department_id'] ?? 0; //提报部门id
            $obj->department_title          = $week_plan_day_data['department_title'] ?? ''; //提报部门名称
            $obj->card_holder_user_id       = $week_plan_day_data['card_holder_user_id'] ?? 0; //持证人id
            $obj->card_holder_user_name     = $week_plan_day_data['card_holder_user_name'] ?? ''; //持证人姓名
            $obj->card_holder_user_phone    = $week_plan_day_data['card_holder_user_phone'] ?? ''; //持证人电话
            $obj->principal_user_id         = $week_plan_day_data['principal_user_id'] ?? 0; //负责人id
            $obj->principal_user_name       = $week_plan_day_data['principal_user_name'] ?? ''; //负责人姓名
            $obj->principal_user_phone      = $week_plan_day_data['principal_user_phone'] ?? ''; //负责人电话
            $obj->work_content              = $week_plan_day_data['work_content'] ?? ''; //工作内容
            $obj->guarder_and_guard_icon    = $week_plan_day_data['guarder_and_guard_icon'] ?? ''; //防护员标志及安排
            $obj->turnout_option            = $week_plan_day_data['turnout_option'] ?? ''; //道岔设置
            $obj->train_speed               = $week_plan_day_data['train_speed'] ?? ''; //行车速度
            $obj->outage_plan               = $week_plan_day_data['outage_plan'] ?? ''; //电力安排
            $obj->else                      = $week_plan_day_data['else'] ?? ''; //其它
            $obj->work_num                  = $week_plan_day_data['work_num'] ?? 0; //作业人数
            $obj->has_help                  = $week_plan_day_data['has_help'] ?? 0; //是否需要帮助 0:否；1：是
            $obj->has_fire                  = $week_plan_day_data['has_fire'] ?? 0; //是否需要动火 0:否；1：是
            $obj->has_train                 = $week_plan_day_data['has_train'] ?? 0; //是否需要动车 0:否；1：是
            $obj->has_breakpoint            = $week_plan_day_data['has_breakpoint'] ?? 0; //接触网是否停电 0:否；1：是
            $obj->safety_measures_and_notes = $week_plan_day_data['safety_measures_and_notes'] ?? ''; //安全措施及注意事项
            $obj->construction_machinery    = $week_plan_day_data['construction_machinery'] ?? ''; //安全措施及注意事项
            $obj->protective_user_ids       = $week_plan_day_data['protective_user_ids']; //防护人ids数组
            $obj->construction_person_ids   = $week_plan_day_data['construction_person_ids']; //施工人ids数组
            //存储防护员和施工人员   hqf
            $obj->protective_user      = $week_plan_day_data['protective_user']; //防护人
            $obj->construction_person  = $week_plan_day_data['construction_person']; //施工人
            $obj->workarea             = $week_plan_day_data['workarea']; //工点
            $obj->event_id             = 121000; //初始值，默认为11000，当前操作步骤的事件id
            $obj->approve_status       = 1; //状态字段1:新计划，2:审批中；3:拒绝;4:作废；5：完成
            $obj->week_plan_id         = $week_plan_day_data['week_plan_id']; //周计划id
            $obj->driver_user_id       = $week_plan_day_data['driver_user_id'] ?? ''; //司机id
            $obj->driver_user_name     = $week_plan_day_data['driver_user_name'] ?? ''; //司机名称
            $obj->driver_user_phone    = $week_plan_day_data['driver_user_phone'] ?? ''; //司机电话
            $obj->conductor_user_id    = $week_plan_day_data['conductor_user_id'] ?? ''; //车长id
            $obj->conductor_user_name  = $week_plan_day_data['conductor_user_name'] ?? ''; //车长name
            $obj->conductor_user_phone = $week_plan_day_data['conductor_user_phone'] ?? ''; //车长电话
            $obj->management_user_ids  = $week_plan_day_data['management_user_ids'] ?? ''; //防护人ids数组

            // //获取数据的当前状态值
            // $state = State::where('plan_type_id', $plan_model_info['plan_type_id'])->select('id', 'title')->orderBy('id', 'ASC')->first();
            // if (empty($state)) {
            //     return [-100, '参数错误'];
            // }
            // $obj->now_state_id    = $state['id']; //当前状态
            // $obj->now_state_title = $state['title']; //当前状态名称
            //获取数据的当前状态值
            $state = State::where('plan_type_id', $plan_model_info['plan_type_id'])
                ->select('id', 'title', 'state_sort')
                ->where('state_sort', 1)
                ->first();
            if (empty($state)) {
                return [-100, '参数错误'];
            }
            $obj->now_state_id    = $state['id']; //当前状态
            $obj->now_state_title = $state['title']; //当前状态名称
            $obj->now_state_sort  = 1; //当前状态排序
            $obj->save();

            $train_info181 = TrainInfo181::where('newest_num', $week_plan_day_data['newest_num'])->where('week_plan_status', 2)->get()->toArray();
            //车辆信息附表数据入库
            $train_info181 = $train_info181; //作业车辆信息
            if (!empty($train_info181)) {
                foreach ($train_info181 as &$val) {
                    $TrainInfo181                   = new TrainInfo181();
                    $TrainInfo181->plan_data_id     = $obj->id; //数据id
                    $TrainInfo181->newest_num       = $obj->newest_num; //标识号
                    $TrainInfo181->train_type_name  = $val['train_type_name']; //车辆类型
                    $TrainInfo181->train_num        = $val['train_num']; //编号
                    $TrainInfo181->weight           = $val['weight']; //载重
                    $TrainInfo181->week_plan_status = 1; //数据类型  1:临时计划；2:日计划
                    $TrainInfo181->save();
                }
            }

            //施工作业数据入库
            $station_track_line181 = StationTrackLine181::where('newest_num', $week_plan_day_data['newest_num'])->where('week_plan_status', 2)->get()->toArray();
            $station_track_line181 = $station_track_line181 ?? ''; //作业车辆信息
            if (!empty($station_track_line181)) {
                //判断作业信息数据条数
                if (count($station_track_line181) > 4) {
                    return [-100, '车站信息添加不能大于4条'];
                }
                //循环数据入库
                foreach ($station_track_line181 as &$val) {
                    $StationTrackLine181                   = new StationTrackLine181();
                    $StationTrackLine181->plan_data_id     = $obj->id; //主数据id
                    $StationTrackLine181->newest_num       = $obj->newest_num; //唯一标识
                    $StationTrackLine181->project_id       = $obj->project_id; //项目id或者工程id
                    $StationTrackLine181->a_station_id     = $val['a_station_id']; //起始车站id
                    $StationTrackLine181->a_station_title  = $val['a_station_title']; //起始车站名称
                    $StationTrackLine181->a_station_pos    = $val['a_station_pos']; //起始车站中心位置
                    $StationTrackLine181->b_station_id     = $val['b_station_id']; //结束车站id
                    $StationTrackLine181->b_station_title  = $val['b_station_title']; //结束车站名称
                    $StationTrackLine181->b_station_pos    = $val['b_station_pos']; //结束车站中心位置
                    $StationTrackLine181->start_pos        = $val['start_pos']; //开始位置
                    $StationTrackLine181->end_pos          = $val['end_pos']; //结束位置
                    $StationTrackLine181->start_time       = $val['start_time']; //开始时间
                    $StationTrackLine181->end_time         = $val['end_time']; //结束时间
                    $StationTrackLine181->work_content     = $val['work_content'] ?? ''; //作业内容（车辆编组）
                    $StationTrackLine181->track_line_id    = $val['track_line_id']; //线别id
                    $StationTrackLine181->track_line_title = $val['track_line_title']; //线别名称
                    $StationTrackLine181->track_line_pre   = $val['track_line_pre']; //线别前缀
                    $StationTrackLine181->track_line_main  = $val['track_line_main']; //线别是否为主线 0辅线 1主线
                    $StationTrackLine181->track_line_from  = $val['track_line_from']; //线别开始位置
                    $StationTrackLine181->track_line_end   = $val['track_line_end']; //线别结束位置
                    $StationTrackLine181->week_plan_status = 1; //数据类型  1:临时计划；2:日计划
                    if (strtotime($val['start_time']) > strtotime($val['end_time'])) {
                        return [-100, $val['start_station_name'] . '-' . $val['end_station_name'] . '--' . '开始时间大于结束时间'];
                    }
                    $StationTrackLine181->save();
                }
            }

            //成功提交
            DB::commit();
            return [0, '转换成功'];
        } catch (\Exception $e) {
            //错误进行事务回滚
            DB::rollBack();
            if (substr_count($e->getMessage(), 'project_id_serial_num_unique')) {
                return [-100, '作业编号重复'];
            }
            if (substr_count($e->getMessage(), 'project_id_serial_num_unique')) {
                return [-100, '作业编号重复'];
            }
            if (substr_count($e->getMessage(), 'project_id_seq_num_unique')) {
                return [-100, '作业令号重复'];
            }
            if (substr_count($e->getMessage(), 'project_id_seq_num_unique')) {
                return [-100, '作业令号重复'];
            }
            return [-100, '参数错误'];
        }
    }

    //签章获取
    public static function signature_info($id, $project_id)
    {
        $data = [];

        //获取行调的审批时间
        $DataRecords181 = DataRecords181::query()
            ->select('data_records181.id', 'data_records181.operation_user_id', 'data_records181.created_at', 'data_records181.operation_role_id')
            ->leftJoin('operation as a', 'data_records181.operation_id', 'a.id')
            ->where('a.signature_status', 1) //签章展示状态 （只查找同意操作）
            ->where('data_records181.plan_data_id', $id)
            ->where('data_records181.week_plan_status', 1) //临时计划+转出的日计划
            ->orderBy('data_records181.id', 'asc')
            ->groupBy('data_records181.operation_user_id') //根据人员+审批时间分组
            ->get()
            ->toArray();
        // return $DataRecords181;
        //申请签章
        if ($DataRecords181) {
            foreach ($DataRecords181 as &$value) {
                $result                 = [];
                $qianzi                 = Signature::where('user_id', $value['operation_user_id'])->where('project_id', $project_id)->where('type', 1)->value('name');
                $yinzhang               = Signature::where('user_id', $value['operation_user_id'])->where('project_id', $project_id)->where('type', 2)->value('name');
                $result['qianzi']       = $qianzi;
                $result['yinzhang']     = $yinzhang;
                $result['approve_time'] = $value['created_at'];
                $data[]                 = $result;
            }
        }
        //
        $data_result = [];
        if (isset($data[1])) {
            $data_result['neishen_signature'] = $data[1];
        } else {
            $data_result['neishen_signature']['qianzi']       = '';
            $data_result['neishen_signature']['yinzhang']     = '';
            $data_result['neishen_signature']['approve_time'] = '';
        }
        //监理审批部门代码（注释）(分别为数据节点)
        if($id > 309 && $id < 333 && $id != 326){//数据大于最新数据，表示采用新流程（监理、调度）
            if (isset($data[2])) {
                $data_result['jianli_signature'] = $data[2];
            } else {
                $data_result['jianli_signature']['qianzi']       = '';
                $data_result['jianli_signature']['yinzhang']     = '';
                $data_result['jianli_signature']['approve_time'] = '';
            }
            if (isset($data[3])) {
                $data_result['diaodu_signature'] = $data[3];
            } else {
                $data_result['diaodu_signature']['qianzi']       = '';
                $data_result['diaodu_signature']['yinzhang']     = '';
                $data_result['diaodu_signature']['approve_time'] = '';
            }
        }else{//旧数据处理（只有调度,监理默认为空，避免前端报错）
            if (isset($data[2])) {
                $data_result['diaodu_signature'] = $data[2];
            } else {
                $data_result['diaodu_signature']['qianzi']       = '';
                $data_result['diaodu_signature']['yinzhang']     = '';
                $data_result['diaodu_signature']['approve_time'] = '';
            }
            $data_result['jianli_signature']['qianzi']       = '';
            $data_result['jianli_signature']['yinzhang']     = '';
            $data_result['jianli_signature']['approve_time'] = '';
        }
        
        //销点的签字印章
        $xiaodian_operation_id = Operation::where('project_id', $project_id)->where('title', '销点')->value('id');
        // dd($xiaodian_operation_id);
        $xiaodian_time = DataRecords181::query()
            ->where('data_records181.plan_data_id', $id)
            ->where('data_records181.week_plan_status', 1) //临时计划+转出的日计划
            ->where('operation_id', $xiaodian_operation_id)
            ->orderBy('data_records181.id', 'desc')
            ->value('created_at');
        if (isset($data[1])) {
            $data_result['xiaodian_signature'] = $data[1];
            if ($xiaodian_time) {
                $data_result['xiaodian_signature']['approve_time'] = date('Y-m-d H:i:s', strtotime($xiaodian_time));
            }
        } else {
            $data_result['xiaodian_signature']['qianzi']       = '';
            $data_result['xiaodian_signature']['yinzhang']     = '';
            $data_result['xiaodian_signature']['approve_time'] = '';
        }
        //销点的签字印章

        return $data_result;
    }

    //冲突检测
    public function check_conflict_detection(Request $request)
    {
        //获取当前数据的基本信息
        $table    = 'App\Models\\' . $request->plan_content;
        $MainData = $table::find($request->id);
        if ($MainData['approve_status'] == 4) {
            return $this->getJson(-100, '计划作废,无法检测');
        }
        //获取主数据的模板名称，跟所属的计划类型
        $MainPlanModelInfo = PlanModel::where('id', $MainData['plan_model_id'])->select('plan_type_id', 'title')->first();
        // dd($MainData);
        //开始匹配对应的冲突模板
        if ($MainPlanModelInfo['title'] == 'A1') {
            //获取项目下可能存在冲突条件的类型数组
            $PlanModeIds = PlanModel::query()
            // ->whereNotIn('title', ['A4']) //A4不和任何类型冲突
                ->whereIn('title', ['A1', 'A2', 'A3', 'A4']) //A1==》（A1A2A3A4）
                ->where('plan_type_id', $MainPlanModelInfo['plan_type_id']) //主数据的计划类型
                ->where('project_id', $MainData['project_id'])
                ->pluck('id')
                ->toArray();
        } else if ($MainPlanModelInfo['title'] == 'A2') {
            //获取项目下可能存在冲突条件的类型数组
            $PlanModeIds = PlanModel::query()
            // ->whereNotIn('title', ['A4']) //A4不和任何类型冲突
                ->whereIn('title', ['A1', 'A2', 'A3']) //A2==>(A1，A2,A3)
                ->where('plan_type_id', $MainPlanModelInfo['plan_type_id']) //主数据的计划类型
                ->where('project_id', $MainData['project_id'])
                ->pluck('id')
                ->toArray();
        } else if ($MainPlanModelInfo['title'] == 'A3') {
            //获取项目下可能存在冲突条件的类型数组
            $PlanModeIds = PlanModel::query()
            // ->whereNotIn('title', ['A4']) //A4不和任何类型冲突
                ->whereIn('title', ['A1', 'A2']) //A3==>(A1，A2)
                ->where('plan_type_id', $MainPlanModelInfo['plan_type_id']) //主数据的计划类型
                ->where('project_id', $MainData['project_id'])
                ->pluck('id')
                ->toArray();
        } else if ($MainPlanModelInfo['title'] == 'A4') {
            //获取项目下可能存在冲突条件的类型数组
            $PlanModeIds = PlanModel::query()
                ->whereIn('title', ['A1']) //A4==>(A1)
                ->where('plan_type_id', $MainPlanModelInfo['plan_type_id']) //主数据的计划类型
                ->where('project_id', $MainData['project_id'])
                ->pluck('id')
                ->toArray();
        } else {
            return $this->getJson(-100, '参数错误，无法检测');
        }
        // dd($PlanModeIds);
        $status = 1; //代表有效的冲突数据

        $StationTrackLineMain = StationTrackLine181::query()
            ->where('newest_num', $MainData['newest_num']) //唯一标识筛选
            ->where('week_plan_status', 1) //该数据的临时计划+转出后的日计划
            ->get()
            ->toArray();
        $MainData['station_track_line'] = $StationTrackLineMain;
        // dd($MainData['station_track_line']);
        if (!$MainData['station_track_line']) {
            return $this->getJson(-100, '该计划下暂无与之冲突的计划');
        }
        //对表数据进行扫描
        $AuxiliaryDataIds = $table::where('project_id', $MainData['project_id']) //项目id
            ->where('id', '<>', $MainData['id']) //排除自身
            ->whereIn('plan_model_id', $PlanModeIds) //符合冲突的模板类型id数组
            ->where('approve_status', '<>', 4)
            ->pluck('id')
            ->toArray();
        //获取与主线相关联的数组数据
        $linshi_data = $this->get_template_expends($MainData['station_track_line'], $AuxiliaryDataIds);
        //循环插入冲突数据表
        $into_data_result = $this->insert_into_conflict_detections($linshi_data);
        // dd($into_data_result);
        return $this->getJson(0, '检测成功，数据更新');
    }

    //获取主线与之相关联的、存在冲突的附表数据
    protected function get_template_expends($main_template_expends, $auxiliary_data_ids)
    {
        // return $main_template_expends;
        $data = [];
        //对主要数据进行遍历
        foreach ($main_template_expends as &$val) {
            $result = [];
            //主线小里程到大里程 辅线小里程到大里程
            if ($val['start_pos'] < $val['end_pos']) {
                //先查询全部的单线作业里程数据
                // DB::connection()->enableQueryLog();
                $small_big_data = StationTrackLine181::whereIn('plan_data_id', $auxiliary_data_ids) //可能存在冲突的主表数据id
                    ->select('id', 'newest_num', 'week_plan_status')
                    ->where('track_line_id', $val['track_line_id']) //线别一样
                    ->where('start_time', '<', $val['end_time']) //开始小于结束--相交
                    ->where('end_time', '>', $val['start_time']) //结束大于开始--相交
                    ->where('start_pos', '<=', $val['end_pos']) //里程位置有相交 1开始小于2结束
                    ->where('end_pos', '>=', $val['start_pos']) //里程位置有相交 1结束大于2开始
                    ->where('week_plan_status', 1); //只查询临时计划内的数据
                //主线小里程到大里程 辅线大里程到小里程
                $auxiliary_data = StationTrackLine181::whereIn('plan_data_id', $auxiliary_data_ids) //可能存在冲突的主表数据id
                    ->select('id', 'newest_num', 'week_plan_status')
                    ->where('track_line_id', $val['track_line_id']) //线别一样
                    ->where('start_time', '<', $val['end_time']) //开始小于结束--相交
                    ->where('end_time', '>', $val['start_time']) //结束大于开始--相交
                    ->where('start_pos', '>=', $val['start_pos']) //里程位置有相交 1开始小于2结束
                    ->where('end_pos', '<=', $val['end_pos']) //里程位置有相交 1结束大于2开始
                    ->where('week_plan_status', 1) //只查询临时计划内的数据
                    ->union($small_big_data)
                    ->get()
                    ->toArray();
                // dd(DB::getQueryLog());
                // return 111;
            } else {
                //先查询全部的单线作业里程数据
                //主线大里程到小里程 辅线小里程到大里程
                // DB::connection()->enableQueryLog();
                $big_small_data = StationTrackLine181::whereIn('plan_data_id', $auxiliary_data_ids) //可能存在冲突的主表数据id
                    ->select('id', 'newest_num', 'week_plan_status')
                    ->where('track_line_id', $val['track_line_id']) //线别一样
                    ->where('start_time', '<', $val['end_time']) //开始小于结束--相交
                    ->where('end_time', '>', $val['start_time']) //结束大于开始--相交
                    ->where('start_pos', '<=', $val['start_pos']) //里程位置有相交 1开始小于2开始
                    ->where('end_pos', '>=', $val['end_pos']) //里程位置有相交 1结束大于2结束
                    ->where('week_plan_status', 1); //只查询临时计划内的数据
                //主线大里程到小里程 辅线大里程到小里程
                $auxiliary_data = StationTrackLine181::whereIn('plan_data_id', $auxiliary_data_ids) //可能存在冲突的主表数据id
                    ->select('id', 'newest_num', 'week_plan_status')
                    ->where('track_line_id', $val['track_line_id']) //线别一样
                    ->where('start_time', '<', $val['end_time']) //开始小于结束--相交
                    ->where('end_time', '>', $val['start_time']) //结束大于开始--相交
                    ->where('start_pos', '>=', $val['end_pos']) //里程位置有相交 1开始大于2结束
                    ->where('end_pos', '<=', $val['start_pos']) //里程位置有相交 1结束小于2开始
                    ->where('week_plan_status', 1) //只查询临时计划内的数据
                    ->union($big_small_data)
                    ->get()
                    ->toArray();
            }
            $result['main_station_line_id'] = $val['id']; //主施工信息id
            $result['main_newest_num']      = $val['newest_num']; //主施工信息标识
            $result['week_plan_status']     = $val['week_plan_status']; //主施工信息临时计划
            $result['project_id']           = $val['project_id']; //主施工信息临时计划
            $result['auxiliary_data_info']  = $auxiliary_data;
            $data[]                         = $result;
        }
        return $data;
    }

    //数据循环进入冲突表
    protected function insert_into_conflict_detections($into_data_data) //需要存储的数组、几表内的关联数据

    {
        try {
            //主数据下的数组、辅助数据
            foreach ($into_data_data as &$value) {
                //先删除原有数据
                CollisionDetection181::query()
                    ->where('main_station_line_id', $value['main_station_line_id'])
                    ->where('week_plan_status', $value['week_plan_status'])
                    ->delete();
                if ($value['auxiliary_data_info']) {
                    foreach ($value['auxiliary_data_info'] as &$val) {
                        $obj                            = new CollisionDetection181();
                        $obj->main_station_line_id      = $value['main_station_line_id']; //主施工信息id
                        $obj->main_newest_num           = $value['main_newest_num']; //主标识
                        $obj->auxiliary_station_line_id = $val['id']; //副施工信息id
                        $obj->auxiliary_newest_num      = $val['newest_num']; //副标识
                        $obj->week_plan_status          = $value['week_plan_status'];
                        $obj->status                    = 1; //临时计划、转出的日计划
                        $obj->project_id                = $value['project_id'];
                        $obj->save();
                    }
                }
            }
            return '0';
        } catch (\Exception $e) {
            return '-100';
        }
    }

    //冲突检测 bjy  根据某一计划，查询出与其相冲突的计划列表
    public function get_conflict_detection_lists(Request $request)
    {
        //主要冲突方数据
        $table    = 'App\Models\\' . $request->plan_content;
        $MainData = $table::select('id', 'newest_num', 'serial_num', 'seq_num', 'department_title', 'approve_status')->find($request->id);
        if ($MainData['approve_status'] == 4) {
            return $this->getJson(-100, '计划作废,无法检测');
        }
        //先查询出关联的列表数据
        $coll = CollisionDetection181::query()
            ->where('main_newest_num', $MainData['newest_num']) //数据唯一标识
            ->where('week_plan_status', 1) //临时计划
            ->where('status', 1) //只查询有效数据
            ->get()->toArray();
        //冲突数据统计
        $count = count($coll);
        //为空则返回该计划暂无冲突
        if (empty($coll)) {
            return $this->getJson(-100, '该计划暂无冲突');
        }
        //判断该计划有几条作业信息 1条，则在外部统一查询，多条则放入循环内部查询
        $main_count = StationTrackLine181::where('newest_num', $MainData['newest_num'])->where('week_plan_status', 1)->count('id');
        if ($main_count == 0) {
            return $this->getJson(-100, '该计划暂无冲突');
        }
        //查询出左侧栏目的作业数据
        if ($main_count == 1) {
            $main_expends_obj = StationTrackLine181::where('id', $coll[0]['main_station_line_id'])
                ->select('start_time', 'end_time', 'track_line_pre', 'start_pos', 'end_pos', 'track_line_title')
                ->where('week_plan_status', 1)
                ->first();
        }
        //存在值时，通过遍历获取其相关的作业编号信息
        foreach ($coll as &$value) {
            //判断该计划有几条作业信息 1条，则在外部统一查询，多条则放入循环内部查询
            if ($main_count > 1) {
                //查询出左侧栏目的作业数据
                $main_expends_obj = StationTrackLine181::where('id', $value['main_station_line_id'])
                    ->where('week_plan_status', 1)
                    ->select('start_time', 'end_time', 'track_line_pre', 'start_pos', 'end_pos', 'track_line_title')
                    ->first();
            }
            //主要冲突数据
            $value['main_serial_num']       = $MainData['serial_num'] ?? '编号不存在';
            $value['main_seq_num']          = $MainData['seq_num'] ?? '令号不存在';
            $value['main_department_title'] = $MainData['department_title'] ?? '单位不存在';
            $value['main_start_time']       = $main_expends_obj['start_time'];
            $value['main_end_time']         = $main_expends_obj['end_time'];
            $value['main_pre']              = $main_expends_obj['track_line_pre'];
            $value['main_track_line_title'] = $main_expends_obj['track_line_title'];
            $value['main_start_pos']        = $main_expends_obj['start_pos'];
            $value['main_end_pos']          = $main_expends_obj['end_pos'];
            //次要冲突方数据
            $auxiliary_expends_obj = $table::select('id', 'newest_num', 'serial_num', 'seq_num', 'department_title')->where('newest_num', $value['auxiliary_newest_num'])->first();
            // dd($auxiliary_expends_obj);
            $value['auxiliary_serial_num']       = $auxiliary_expends_obj['serial_num'] ?? '编号不存在';
            $value['auxiliary_seq_num']          = $auxiliary_expends_obj['seq_num'] ?? '令号不存在';
            $value['auxiliary_department_title'] = $auxiliary_expends_obj['department_title'] ?? '单位不存在';
            $auxiliary_expends_obj               = StationTrackLine181::where('id', $value['auxiliary_station_line_id'])
                ->where('week_plan_status', 1)
                ->select('start_time', 'end_time', 'track_line_pre', 'start_pos', 'end_pos', 'track_line_title')
                ->first();
            $value['auxiliary_start_time']       = $auxiliary_expends_obj['start_time'];
            $value['auxiliary_end_time']         = $auxiliary_expends_obj['end_time'];
            $value['auxiliary_pre']              = $auxiliary_expends_obj['track_line_pre'];
            $value['auxiliary_start_pos']        = $auxiliary_expends_obj['start_pos'];
            $value['auxiliary_end_pos']          = $auxiliary_expends_obj['end_pos'];
            $value['auxiliary_track_line_title'] = $auxiliary_expends_obj['track_line_title'];
            unset($value['id']);
            unset($value['main_newest_num']);
            unset($value['main_station_line_id']);
            unset($value['auxiliary_newest_num']);
            unset($value['auxiliary_station_line_id']);
            unset($value['week_plan_status']);
            unset($value['status']);
            unset($value['project_id']);
        }
        return $this->getJson(0, '数据获取成功', $coll, $count);
    }

    //周计划审批完成的导出接口
    public function plan_data_day_export(Request $request)
    {
        //调取调出数据接口
        $week_export_data = $this->week_export_data($request);
        if($week_export_data[0] == -100){
            return $this->getJson(-100, '暂无数据');
        }

        $tableTitle = "周计划表格汇总导出"; //表名
        //表头
        $tableTou = [
            ['周计划编号', '作业类型', '施工日期', '施工地点', '施工线别', '施工内容', '施工机械', '风险及安全防护措施', '施工提报人', '施工负责人', '施工单位'],
        ];
        //合并数组
        $tableTou = array_merge($tableTou, $week_export_data[1]);
        Excel::create($tableTitle, function ($excel) use ($tableTou) {
            $excel->sheet('plan_data_day', function ($sheet) use ($tableTou) {
                $sheet->rows($tableTou);
            });
        })->export('xls');
        return $this->getJson(0, '导出成功');
    }

    //获取组织下的施工班组信息
    public function get_team_list(Request $request)
    {
        $data = GeneralHelper::get_business_team($request->department_id); //组织下的施工班组
        return $this->getJson(0, '班组信息获取成功', $data, count($data));
    }

    //获取施工班组下的施工人员信息
    public function get_team_people_list(Request $request)
    {
        $data = GeneralHelper::get_team_people($request->id, $request->type); //施工班组下的施工人员，2代表班组
        return $this->getJson(0, '施工人员获取成功', $data, count($data));
    }

    //获取四分部（土建）架构
    public function get_organization_list(Request $request)
    {
        $data = GeneralHelper::get_leader_organization(); //四分部（土建）架构
        return $this->getJson(0, '四分部架构获取成功', $data, count($data));
    }

    //获取四分部（土建）架构下的人员-----负责人  持证人  防护员
    public function get_leader_list(Request $request)
    {
        $data = GeneralHelper::get_leader_people($request->_id); //四分部（土建）架构下的人员
        return $this->getJson(0, '人员获取成功', $data, count($data));
    }

    //获取工点
    public function get_workarea_list(Request $request)
    {
        $data = GeneralHelper::get_workarea(); //获取工点
        return $this->getJson(0, '工点获取成功', $data, count($data));
    }

    //周计划审批完成的导出接口
    public function plan_data_excel_export(Request $request)
    {
        //调取调出数据接口
        $export_data = $this->export_data($request);
        if($export_data[0] == -100){
            return $this->getJson(-100, '暂无数据');
        }

        $tableTitle = "日计划表格汇总导出"; //表名
        //表头
        $tableTou = [
            ['编号', '线别', '施工项目', '施工日期', '施工地点', '施工时间', '出入站点', '施工内容、主要机械、作业人员', '限速及行车方式变化', '施工单位及负责人', '备注'],
        ];
        //合并数组
        $tableTou = array_merge($tableTou, $export_data[1]);
        Excel::create($tableTitle, function ($excel) use ($tableTou) {
            $excel->sheet('plan_data', function ($sheet) use ($tableTou) {
                $sheet->rows($tableTou);
            });
        })->export('xls');
        return $this->getJson(0, '导出成功');
    }

    //周计划审批完成的导出接口
    public function plan_data_pdf_export(Request $request)
    {
        //调取调出数据接口
        $export_data = $this->export_data($request);
        if($export_data[0] == -100){
            return $this->getJson(-100, '暂无数据');
        }
        return $this->getJson(0, 'pdf参数返回成功', $export_data[1], count($export_data[1]));
    }

    //导出数据整理
    public function export_data($request)
    {
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'];

        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');

        //----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        //----提报权限人的查询判断end
        
        //当前项目的操作按钮列表start
        $operation_data = Operation::where('project_id', session()->get('user_data')['data']['engineering_id'] ?? 181)->select('id', 'title')->get()->toArray();
        //当前项目的操作按钮列表end

        //----单位筛选判断模块(获取当前人员的查询部门范围)start
        $department_ids = UserXDepartment::where('user_id', $now_user_id)->pluck('department_id')->toArray();
        //----单位筛选判断模块(获取当前人员的查询部门范围)end

        //-----状态判断是否展示数据模块start
        $now_user_status = StateConversion::where('role_id', $now_role_id)->where('plan_type_id', $request->plan_type_id)->where('operation_id', $operation_data[0]['id'])->value('cid');
        $state_sort = State::where('id', $now_user_status)->value('state_sort');
       //-----状态判断是否展示数据模块end

        $coll = StationTrackLine181::query()->where('week_plan_status', 1)->orderBy('id', 'desc');
        //线别筛选
        if ($request->filled('track_line_id')) {
            $coll->where('track_line_id', $request->track_line_id);
        }

        //时间筛选
        if ($request->filled('search_time')) {
            $start_time = Carbon::createFromTimestamp(strtotime($request->search_time) + 64800)->addDays(-1)->toDateTimeString();
            $end_time   = Carbon::createFromTimestamp(strtotime($start_time))->addDays(+1)->toDateTimeString();
            $coll->where('start_time', '<=', $end_time)->where('end_time', '>=', $start_time);
        }

        $coll = $coll
            ->with(['plan_data181' => function ($query) use ($request,$now_user_id,$shenbao_status,$department_ids,$now_user_status,$state_sort) {
                //筛选------start//
                //状态筛选
                $query->where('approve_status', '!=', 4);
                if ($request->filled('approve_status')) {
                    $query->where('approve_status', $request['approve_status']);
                }
                //类别筛选
                if ($request->filled('plan_model_id')) {
                    $query->where('plan_model_id', $request['plan_model_id']);
                }
                //单位筛选
                if ($request->filled('department_id')) {
                    $query->where('department_id', $request['department_id']);
                }
                //接触网是否停送电筛选
                if ($request->filled('has_breakpoint')) {
                    $query->where('has_breakpoint', $request['has_breakpoint']);
                }
                //筛选------end//
                if ($shenbao_status == 1) {
                    $query->where('declare_user_id', $now_user_id);
                }
                //如果当前用户配置了部门信息
                if ($department_ids) {
                    if ($shenbao_status == 2) {
                        //并且不是提报的权限，则查看他配置的相关部门的数据
                        $query->whereIn('department_id', $department_ids);
                    }
                }
                //判断当前用户是否可导出该数据
                if ($now_user_status) {
                    if ($shenbao_status == 2) { //并且不是提报的权限，则查看数据
                        $query->where('now_state_sort', '>=', $state_sort); //展示比 当前角色审批状态排序大或者等于的 数据
                    }
                }

                $query->select('*');
            }])
            ->get()
            ->toArray();

        //重新组成符合条件的数组
        $export_data = [];
        //默认序号
        $sort_num = 0;
        //循环获取数据
        foreach ($coll as &$value) {

            if ($value['plan_data181'] != null) {
                $on_off = GeneralHelper::get_event_num($value['plan_data181']['event_id'], 5);
                if($on_off == 1){
                    $result   = [];
                    $sort_num = $sort_num + 1;
                    //编号
                    $result['sort_num'] = $sort_num;

                    //线别
                    $result['track_line_title'] = $value['track_line_title'];

                    //施工项目
                    $result['project_title'] = session()->get('user_data')['data']['engineering_title'];

                    //施工日期
                    $result['work_date'] = date('Y.m.d', strtotime($value['start_time'])) . ' - ' . date('Y.m.d', strtotime($value['end_time']));

                    $qujian  = $value['a_station_title'] . ' - ' . $value['b_station_title'];
                    $licheng = ' 区间 ' . GeneralHelper::pos_conversion($value['track_line_pre'], $value['start_pos']) . ' - ' . GeneralHelper::pos_conversion($value['track_line_pre'], $value['end_pos']); //开始里程
                    //施工地点
                    $result['work_place'] = $qujian . $licheng;

                    //施工时间
                    $result['work_time'] = date('H:i', strtotime($value['start_time'])) . '-' . date('H:i', strtotime($value['end_time']));

                    //工点数据拼接组合
                    $workarea = json_decode($value['plan_data181']['workarea']);
                    if ($workarea) {
                        $workarea_name = [];
                        foreach ($workarea as &$val) {
                            $name_phone_pin_jie = '';
                            $name_phone_pin_jie = $val->name;
                            $workarea_name[]    = $name_phone_pin_jie;
                        }
                        $workarea_names = implode(",", $workarea_name);
                    } else {
                        $workarea_names = '';
                    }
                    //出入站点
                    $result['workarea_names'] = $workarea_names;

                    //施工内容
                    //判断计划是否为A3、A4类的计划  hqf
                    if(strstr($value['plan_data181']['serial_num'], 'A3') || strstr($value['plan_data181']['serial_num'], 'A4')){
                        //调取封装接口，获取拼接好的施工内容值(模型、数据id、1临时2周计划、字段名)
                        $work_content = $value['work_content'];
                    }
                    else {
                        $work_content = $value['plan_data181']['work_content'];
                    }
                    // $work_content = $value['work_content'];
                    //主要机械
                    $construction_machinery = $value['plan_data181']['construction_machinery'];
                    //作业人员
                    $work_num = $value['plan_data181']['work_num'];
                    //影响范围
                    $safety_measures_and_notes = $value['plan_data181']['safety_measures_and_notes'];

                    //施工内容、主要机械、作业人员及影响范围
                    $result['work_content'] = '施工内容:' . $work_content . '; 主要机械:' . $construction_machinery . '; 作业人数:' . $work_num . '人;';

                    //限速及行车方式变化
                    $result['train_speed'] = $value['plan_data181']['train_speed'];

                    //负责人及单位
                    $principal_info = $value['plan_data181']['department_title'] . '负责人:' . $value['plan_data181']['principal_user_name'] . '; ';
                    //提报人及姓名
                    $tibaoren_info  = '调度联络人:' . $value['plan_data181']['declare_user_name'];
                    //施工单位及负责人
                    $result['user_info'] = $principal_info . $tibaoren_info;

                    //备注
                    $result['else'] = $value['plan_data181']['else'];

                    $export_data[] = $result;
                }
            }
        }

        return [0,$export_data];
    }

    //周计划下的日计划导出接口---pdf---hqf
    public function plan_data_day_pdf_export(Request $request)
    {
        //调取调出数据接口
        $week_export_data = $this->week_export_data($request);
        if($week_export_data[0] == -100){
            return $this->getJson(-100, '暂无数据');
        }
        return $this->getJson(0, 'pdf参数返回成功', $week_export_data[1], count($week_export_data[1]));
    }

    //周计划下的日计划导出数据整理----周计划下的---hqf
    public function week_export_data($request)
    {
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'];

        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');

        //----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        //----提报权限人的查询判断end
        
        //当前项目的操作按钮列表start
        $operation_data = Operation::where('project_id', session()->get('user_data')['data']['engineering_id'] ?? 181)->select('id', 'title')->get()->toArray();
        //当前项目的操作按钮列表end

        //----单位筛选判断模块(获取当前人员的查询部门范围)start
        $department_ids = UserXDepartment::where('user_id', $now_user_id)->pluck('department_id')->toArray();
        //----单位筛选判断模块(获取当前人员的查询部门范围)end

        //-----状态判断是否展示数据模块start
        $now_user_status = StateConversion::where('role_id', $now_role_id)->where('plan_type_id', $request->plan_type_id)->where('operation_id', $operation_data[0]['id'])->value('cid');
        $state_sort = State::where('id', $now_user_status)->value('state_sort');
       //-----状态判断是否展示数据模块end

        $coll = StationTrackLine181::query()->where('week_plan_status', 2)->orderBy('id', 'desc');
        //线别筛选
        if ($request->filled('track_line_id')) {
            $coll->where('track_line_id', $request->track_line_id);
        }

        //时间筛选
        if ($request->filled('search_time')) {
            $start_time = Carbon::createFromTimestamp(strtotime($request->search_time) + 64800)->addDays(-1)->toDateTimeString();
            $end_time   = Carbon::createFromTimestamp(strtotime($start_time))->addDays(+1)->toDateTimeString();
            $coll->where('start_time', '<=', $end_time)->where('end_time', '>=', $start_time);
        }

        $coll = $coll
            ->with(['plan_data_day181' => function ($query) use ($request,$now_user_id,$shenbao_status,$department_ids,$now_user_status,$state_sort) {
                //筛选------start//
                //状态筛选
                $query->where('approve_status', '!=', 4);
                //周计划下的日计划
                if ($request->filled('week_plan_id')) {
                    $query->where('week_plan_id', $request['week_plan_id']);
                }
                if ($request->filled('approve_status')) {
                    $query->where('approve_status', $request['approve_status']);
                }
                //类别筛选
                if ($request->filled('plan_model_id')) {
                    $query->where('plan_model_id', $request['plan_model_id']);
                }
                //单位筛选
                if ($request->filled('department_id')) {
                    $query->where('department_id', $request['department_id']);
                }
                //接触网是否停送电筛选
                if ($request->filled('has_breakpoint')) {
                    $query->where('has_breakpoint', $request['has_breakpoint']);
                }
                //筛选------end//
                if ($shenbao_status == 1) {
                    $query->where('declare_user_id', $now_user_id);
                }
                //如果当前用户配置了部门信息
                if ($department_ids) {
                    if ($shenbao_status == 2) {
                        //并且不是提报的权限，则查看他配置的相关部门的数据
                        $query->whereIn('department_id', $department_ids);
                    }
                }
                //判断当前用户是否可导出该数据
                if ($now_user_status) {
                    if ($shenbao_status == 2) { //并且不是提报的权限，则查看数据
                        $query->where('now_state_sort', '>=', $state_sort); //展示比 当前角色审批状态排序大或者等于的 数据
                    }
                }

                $query->select('*');
            }])
            ->get()
            ->toArray();

        //重新组成符合条件的数组
        $export_data = [];

        //循环获取数据
        foreach ($coll as &$value) {
            if ($value['plan_data_day181'] != null) {
                // $on_off = GeneralHelper::get_event_num($value['plan_data_day181']['event_id'], 5);
                // if($on_off == 1){
                    $result   = [];
                    //打印内容的存储
                    $result['week_plan_num']                   = WeekPlan181::where('id', $value['plan_data_day181']['week_plan_id'])->value('week_plan_num'); //周计划编号
                    $result['plan_model_title']                = PlanModel::where('id', $value['plan_data_day181']['plan_model_id'])->value('title'); //计划类型
                    $result['work_time']                       = $value['start_time'] . ' 至 ' . $value['end_time']; //施工日期
                    $result['work_local']                      = $value['a_station_title'] . '至' . $value['b_station_title']; //施工地点
                    $result['station_track_line_title']        = $value['track_line_title']; //施工线别
                    //施工内容
                    //判断计划是否为A3、A4类的计划  hqf
                    if(strstr($value['plan_data_day181']['serial_num'], 'A3') || strstr($value['plan_data_day181']['serial_num'], 'A4')){
                        //调取封装接口，获取拼接好的施工内容值(模型、数据id、1临时2周计划、字段名)
                        $work_content = $value['work_content'] ?? '暂无信息';
                    }
                    else {
                        $work_content = $value['plan_data_day181']['work_content'] ?? '暂无信息';
                    }
                    $result['station_track_line_work_content'] = $work_content; //施工内容

                    $result['construction_machinery']          = $value['plan_data_day181']['construction_machinery'] ?? '暂无信息'; //施工机械
                    $result['safety_measures_and_notes']       = $value['plan_data_day181']['safety_measures_and_notes'] ?? '暂无信息'; //安全防护措施
                    $result['declare_user_name']               = $value['plan_data_day181']['declare_user_name']; //施工提报人
                    $result['principal_user_name']             = $value['plan_data_day181']['principal_user_name']; //施工负责人
                    $result['department_title']                = $value['plan_data_day181']['department_title']; //施工单位
                    // $result                                    = array_values($result); //重置数组下标
                    $export_data[]                             = $result;

                // }
            }
        }

        return [0,$export_data];
    }


}
