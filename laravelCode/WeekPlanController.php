<?php

namespace App\Http\Controllers\Subway\eng181\pc_port;

use App\Models\WeekPlan181;
use App\Models\PlanDataDay181;
use App\Models\UserConfig;
use App\Models\Role;
use App\Models\UserXDepartment;
use App\Libs\ArrLib;
use App\Libs\GeneralHelper;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class WeekPlanController extends Controller
{
    //周计划添加 bjy
    public function week_plan_add(Request $request)
    {
        try {

            $obj = new WeekPlan181();

            $week_counts             = GeneralHelper::get_week_counts($request->project_id,'App\Models\WeekPlan181');
            $obj->week_plan_num      = $request->week_plan_num.$week_counts ?? ''; //周计划编号
            $obj->week_start_time    = $request->week_start_time ?? date('Y-m-d', time()); //周计划开始日期
            $obj->week_end_time      = $request->week_end_time ?? date('Y-m-d', time()); //周计划结束日期
            $obj->declare_user_id    = $request->declare_user_id ?? 0; //提报人id
            $obj->declare_user_name  = $request->declare_user_name ?? ''; //提报人名称
            $obj->declare_user_phone = $request->declare_user_phone ?? ''; //提报人电话
            $obj->department_id      = $request->department_id ?? 0; //部门id
            $obj->department_title   = $request->department_title ?? ''; //部门名称
            $obj->if_submit          = 2; //'是否确认提报 1：确认；2：未确认'
            $obj->project_id         = $request->project_id; //项目id

            $obj->save();
            return $this->getJson(0, '周计划添加成功');
        } catch (\Exception $e) {
            return $this->getJson(-100, $e->getMessage());
        }
    }

    //周计划修改  修改时其类别不能修改 bjy
    public function week_plan_update(Request $request)
    {
        try {
            $obj = WeekPlan181::find($request->id);

            if ($request->has('week_plan_num')) {
                $obj->week_plan_num = $request->week_plan_num ?? ''; //周计划编号
            }
            if ($request->has('week_start_time')) {
                $obj->week_start_time = $request->week_start_time ?? date('Y-m-d', time());
            }
            if ($request->has('week_end_time')) {
                $obj->week_end_time = $request->week_end_time ?? date('Y-m-d', time());
            }

            $obj->save();
            return $this->getJson(0, '周计划修改成功');
        } catch (\Exception $e) {
            return $this->getJson(-100, $e->getMessage());
        }
    }

    //周计划详情
    public function week_plan_details(Request $request)
    {
        $data = WeekPlan181::find($request->id);
        if (empty($data)) {
            return $this->getJson(-100, '数据不存在');
        }
        return $this->getJson(0, '详情获取成功', $data, 1);
    }

    //周计划删除 bjy
    public function week_plan_delete(Request $request)
    {
        $PlanDataDay181 = PlanDataDay181::where('week_plan_id', $request->id)->first();
        if ($PlanDataDay181) {
            return $this->getJson(-100, '该周计划绑定数据暂无法删除');
        }
        WeekPlan181::destroy($request->id);
        return $this->getJson(0, '删除成功');
    }

    //周计划列表  带分页 bjy
    public function week_plan_list(Request $request)
    {
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'] ?? 1472;
        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');

        $coll = WeekPlan181::where('project_id', $request->project_id);

        //----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        // dd($shenbao_status);
        //拥有申报权限的人，只看自己提报的数据
        if ($shenbao_status == 1) {
            $coll->where('declare_user_id', $now_user_id);
        } else {
//当登录用户不是提报人的时候，则只展示已经提交的周计划模块
            $coll->where('if_submit', 1);
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

        //时间筛选条件
        if ($request->filled('start_time')) {
            if ($request->filled('end_time')) {
                $coll->where('week_start_time', '<=', $request->end_time) //开始小于结束
                    ->where('week_end_time', '>=', $request->start_time); //结束大于开始 产生交集
            }
        }

        //部门筛选条件
        if ($request->filled('department_id')) {
            $coll->where('department_id', $request->department_id);
        }

        $coll = $coll->orderByDesc('id')
            ->paginate($request->page_size ?? 20)
            ->toArray();

        list($data, $total) = ArrLib::listDataTotal($coll);

        foreach ($data as &$value) {
            $value['week_approve_status_title'] = "暂无数据"; //暂无数据
            //先判断周计划下是否有数据
            $week_day_plan = PlanDataDay181::where('week_plan_id', $value['id'])->get()->toArray();
            if ($week_day_plan) {
//存在数据时，判断
                //判断该周计划下是否有未审批完成的日计划
                $have_day_data = PlanDataDay181::where('week_plan_id', $value['id'])->whereIn('approve_status', [1, 2, 3])->first();
                if ($have_day_data) {
                    $value['week_approve_status_title'] = "待审批"; //审批中
                } else {
                    $value['week_approve_status_title'] = "审批完成"; //审批完成
                }
            }
        }
        return $this->getJson(0, '数据获取成功', $data, $total);

    }

    //周计划计划编号自动生成  bjy
    public function get_week_plan_num(Request $request)
    {
        $week_plan_num['week_plan_num'] = date('Ymd') . '_周计划_';
        return $this->getJson(0, '数据获取成功', $week_plan_num);
    }

    //周计划下日版计划页面的渲染方法  bjy
    public function pc_port_weeklyTable(Request $request, $id)
    {
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'] ?? 1472;
        $one_approval_status = session()->get('user_data')['data']['one_approval_status'] ?? 2;//获取是否开启了一键审批
        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');
        //----提报权限人的查询判断start
        $shenbao_status    = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        $week_plan_data    = WeekPlan181::query()->where('id', $id)->first();
        $new_week_end_time = date('Y-m-d', strtotime($week_plan_data['week_end_time']) + 86400);
        if ($week_plan_data['if_submit'] == 1) {
            $week_submit_time = $week_plan_data['updated_at'];
            $shenbao_status   = 2; //计划已经提交，添加按钮就隐藏
        } else {
            $week_submit_time = '';
        }

        //查看周下的日计划是否全部审批完毕
        $daochu                = 1; //默认1为导出
        $have_approve_data_day = PlanDataDay181::where('week_plan_id', $id)->whereIn('approve_status', [1, 2, 3])->first();
        if ($have_approve_data_day) {
            $daochu = 2;
        }

        return view('Subway.eng181.pc_port.weeklyPlan.weeklyTable', [
            'show_add'           => $shenbao_status,
            'week_plan_id'       => $id,
            'department_title'   => $week_plan_data['department_title'],
            'declare_user_name'  => $week_plan_data['declare_user_name'],
            'declare_user_phone' => $week_plan_data['declare_user_phone'],
            'week_submit_time'   => $week_submit_time,
            'if_submit'          => $week_plan_data['if_submit'], //为1表示已经提交  为2表示暂未提交
            'week_plan_num'      => $week_plan_data['week_plan_num'], //周计划编号名称
            'daochu_button'      => $daochu, //导出按钮的展示  1：展示，2隐藏
            'one_approval_status' => $one_approval_status,
        ]);
    }

    //周计划列表页面的渲染方法  bjy
    public function pc_port_weeklyPlan(Request $request)
    {
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'] ?? 1472;
        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');
        //----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限

        return view('Subway.eng181.pc_port.weeklyPlan.weeklyPlan', [
            'show_add' => $shenbao_status,
        ]);
    }

    //调度主任对周计划的确认和提交 bjy
    public function tibao_submit(Request $request)
    {
        //获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'] ?? 1472;
        //当前登录人的权限id
        $now_role_id = UserConfig::where('user_id', $now_user_id)->value('role_id');
        //----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        if ($shenbao_status != 1) {
            return $this->getJson(0, '该用户没有提交权限');
        }
        //进行提交数据状态的修改
        $obj            = WeekPlan181::find($request->week_plan_id);
        $obj->if_submit = 1; //状态为1，表示已经提交
        $obj->save();
        return $this->getJson(0, '周计划提报成功');
    }

    //相应模块下的审批流程列表（去除 ==》 角色延伸）
    public function get_type_state_info(Request $request){
        if(!$request->plan_type_id){
            return $this->getJson(-100, '参数错误');
        }
        //调取通用方法，所有项目都适用
        $data = GeneralHelper::get_type_state_info($request->plan_type_id);
        //结果反参
        return $this->getJson(0, '流程列表获取成功', $data);
    }

}
