<?php
namespace App\Http\Controllers\Subway\eng181\pc_port;

use App\Libs\UrlLib;
use App\Libs\ArrLib;
use App\Libs\PlanConst;
use App\Libs\GeneralHelper;
use App\Models\PlanModel;
use App\Models\PlanType;
use App\Models\Operation;
use App\Models\State;
use App\Models\PlanData181;
use App\Models\TrainInfo181;
use App\Models\StationTrackLine181;
use App\Models\DataRecords181;
use App\Models\StateConversion;
use App\Models\Role;
use App\Models\CardPrincipalOfApplicants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Excel;
use App\Http\Controllers\Controller;

class ApproveController extends Controller
{
	public function get_approve_date_lists(Request $request){

		$result = [];
		//获取当前登录用户id
        $now_user_id = session()->get('user_data')['data']['id'] ?? 1734;
        //获取当前登录用户角色id
        $now_role_id = session()->get('user_data')['data']['role_id'] ?? 4;



		//列表数据获取（当前状态筛选/项目id筛选）
		$coll = PlanData181::where('now_state_id', $request->cid)->where('project_id', $request->project_id);

		//----提报权限人的查询判断start
        $shenbao_status = Role::where('id', $now_role_id)->value('shenbao_status'); //当前登录人是否拥有提报计划的权限
        //拥有申报权限的人，只看自己提报的数据
        if ($shenbao_status == 1) {
            $coll->where('declare_user_id', $now_user_id);
        }else{
        	//----单位筛选判断模块(获取当前人员的查询部门范围)start
	        $department_ids = UserXDepartment::where('user_id', $now_user_id)->pluck('department_id')->toArray();
	        //如果当前用户配置了部门信息
	        if ($department_ids) {
	            //并且不是提报的权限，则查看他配置的相关部门的数据
	            $coll->whereIn('department_id', $department_ids);
	        }
	        //----单位筛选判断模块(获取当前人员的查询部门范围)end
        }
        //----提报权限人的查询判断end
        
        $data = $coll->orderBy('id', 'Desc')
        	->select('id','serial_num','newest_num','now_state_id','project_id','declare_user_id','department_id')
        	->get()
        	->toArray();

        //获取 当前tab选项卡 关联的操作按钮
        $operation_ids = StateConversion::where('cid', $request->cid)
        ->where('plan_type_id',$request->plan_type_id)
        ->where('role_id',$now_role_id)
        ->pluck('operation_id')
        ->toArray();
        //获取本项目下的全部操作按钮
		$operation_info = Operation::where('project_id', $request->project_id)->select('id','title')->get()->toArray();
		//遍历操作按钮，进行匹配
        foreach ($operation_info as &$value) {
        	$value['is_check'] = 2;
        	if(in_array($value['id'], $operation_ids)){
        		$value['is_check'] = 1;
        	}
        }
        $result['approve_data_lists'] = $data;
        $result['approve_operation_lists'] = $operation_info;

        return $this->getJson(0, '审批列表获取成功', $result, count($data));
        

	}
	

	//审批接口
	//审批同意、拒绝接口
    public function agree_refuse(Request $request)
    {

    	try {
           	//涉及到多表数据入库，开启事务
        	DB::beginTransaction();
        	
        	$table = 'App\Models\\' . $request->plan_content;//表名
        	$ids   = $request->ids;//操作id
        	$operation_id   = $request->operation_id;//操作id
        	$operation_title = $request->operation_title;//操作名称
        	$operation_approve_status = $request->operation_approve_status;//操作状态（状态字段1:新计划，2:审批中；3:拒绝;4:作废；5：完成）
        	$now_state_id = $request->now_state_id;//当前审批的流程状态
        	$operation_user_id = $request->operation_user_id;//操作用户id
        	$operation_user_name = $request->operation_user_name;//操作用户名称
        	$project_id = $request->project_id;//项目id
        	$week_plan_id = $request->week_plan_id ?? '';//周计划id
        	// dd($ids);
        	foreach ($ids as &$value) {
                //参数分别代表 表名、单个数据、操作id、操作名称、操作附带的按钮状态、当前流程id、操作人员id、名称项目id、周计划id
        		$result = $this->agree_refuse_auxiliary($table,$value,$operation_id,$operation_title,$operation_approve_status,$now_state_id,$operation_user_id,$operation_user_name,$project_id,$week_plan_id);
        		if($result[0] == -100){
        			DB::rollBack();
            		return $this->getJson($result[0], $result[1]);
        			break;
        		}
        	}
        	//成功提交
            DB::commit();
            return $this->getJson($result[0], $result[1]);

        }catch (\Exception $e) {
            //错误进行事务回滚
            DB::rollBack();
            return $this->getJson(-100, '参数错误');
        }
    }

    protected function agree_refuse_auxiliary($table,$id,$operation_id,$operation_title,$operation_approve_status,$now_state_id,$operation_user_id,$operation_user_name,$project_id,$week_plan_id=null)
    {
    	//查询主数据
        $obj            = $table::find($id);

        //进行重复误操作判断
        if ($obj['now_state_id'] != $now_state_id) {
            return [-100, "编号 {$obj['serial_num']} 已审批,请勿重复操作"];
        }

        //进行主数据是否存在的判断
        if (empty($obj)) {
            return [-100, "编号 {$obj['serial_num']} 数据不存在"];
        }

        $now_state_id = $obj['now_state_id']; //主表数据的当前状态id
        //查询 当前步骤的 状态转换数据
        $state_conversion = StateConversion::where('operation_id', $operation_id)->where('cid', $obj['now_state_id'])->first();
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
        $coll->operation_user_id    = $operation_user_id; //当前操作用户id
        $coll->operation_user_name  = $operation_user_name; //当前操作用户名称
        $coll->operation_role_id    = $role_id ?? 0; //当前操作用户角色id
        $coll->operation_role_title = $role_title ?? ''; //当前操作用户角色名称
        $coll->operation_note       = '';//"{$role_title}审批{$operation_title}"; //当前操作备注
        $coll->newest_num           = $obj['newest_num']; //数据唯一标识
        $coll->project_id           = $project_id; //当前项目id
        $coll->week_plan_status     = 1; //数据类型  1:临时计划；2:日计划
        if ($week_plan_id) {
            $coll->week_plan_status = 2; //数据类型  1:临时计划；2:日计划
        }
        $coll->save(); //记录数据入库

        //判断下一步是否还有审批
        $have_state_conversion = StateConversion::where('operation_id', $operation_id)->where('cid', $next_state_id)->first();

        //周计划下判断一下是否彻底审批完成
        if ($week_plan_id) {
            if (empty($have_state_conversion)) {
                //需要往临时计划表内插入一条一样的数据
                $data = $this->assignment_plan_data($obj);
                if($data[0] == -100){
            		return [$data[0], $data[1]];
        		}
            }
        }

        $state_info           = State::find($next_state_id);
        $obj->now_state_id    = $state_info->id; //更新主表的当前状态id
        $obj->now_state_title = $state_info->title; //更新主表的当前状态名称
        $obj->now_state_sort  = $state_info->state_sort; //更新主表的当前状态排序
        if (!$week_plan_id) {
            $obj->event_id = $state_conversion['event_id']; //当前操作步骤的事件id
        }
        if (empty($have_state_conversion)) { //表示计划审批完成
            $obj->approve_status = 5; //更新主表的数据当前状态id（状态字段1:新计划，2:审批中；3:拒绝;4:作废；5：完成）
        } else {
            $obj->approve_status = $operation_approve_status; //更新主表的数据当前状态id（状态字段1:新计划，2:审批中；3:拒绝;4:作废；5：完成）
        }

        $obj->save();

        return [0, $role_title . $operation_title];
    }


    /**
     * 转化数据
     */
    protected static function assignment_plan_data($week_plan_day_data)
    {
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
            return [0, '转换成功'];

    }

    //计划过时间段（自动作废功能）
    public static function timeout_invalid($project_id,$plan_type_id,$num){
    	try {
           	//涉及到多表数据入库，开启事务
        	DB::beginTransaction();
	    	//查询出全部的审批流程
		   	$StateConversionInfo = StateConversion::where('project_id',$project_id)->where('plan_type_id',$plan_type_id)->get()->toArray();
		   	
		   	//数据遍历，进行处理过滤
	        foreach ($StateConversionInfo as $key => $value) {
	            $StateConversionInfo[$key]['cid_title'] = State::where('id', $value['cid'])->value('title');
	            $event_num = GeneralHelper::get_event_num($value['event_id'], $num);
	            $StateConversionInfo[$key]['event_num'] = $event_num;
	            if($event_num != 1){
	                unset($StateConversionInfo[$key]);
	            }
	        }
	        //数组下标进行重新排列
	        $StateConversionInfo = array_merge($StateConversionInfo);

	        //循环，查询数据库，找出相应的数据
		   	foreach ($StateConversionInfo as &$value) {
		   		$data = PlanData181::where('now_state_id',$value['cid'])
		   		->select('id','serial_num','now_state_id','now_state_title')
		   		->get()
		   		->toArray();
		   		//数据 与 流程进行绑定
		   		$value['data'] = $data;
		   		//循环遍历数据，查询每条计划的最终终止时间
		   		foreach ($value['data'] as &$val) {
		   			$end_time = StationTrackLine181::where('week_plan_status',1)->where('plan_data_id',$val['id'])->orderBy('end_time','desc')->value('end_time');
		   			$val['end_time'] = strtotime($end_time);
		   			$now_time = time();//1603524094
		   			if($now_time > $val['end_time']){
		   				//开始对数据进行修改
			   			$UpdatePlanData = PlanData181::find($val['id']);	
			   			$UpdatePlanData->now_state_id = $value['eid'];
			   			$UpdatePlanData->now_state_title = '过期自动作废';
			   			$UpdatePlanData->now_state_sort = State::where('id',$value['eid'])->value('state_sort');
			   			$UpdatePlanData->approve_status = Operation::where('id',$value['operation_id'])->value('approve_status');
			   			$UpdatePlanData->save();
		   			}
		   		}
		   	}
		   	//成功提交
            DB::commit();
            return 0;
	   	}catch (\Exception $e) {
            //错误进行事务回滚
            DB::rollBack();
            return -100;
        }
    }
    

}