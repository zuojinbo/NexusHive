<?php

namespace app\admin\controller;

use Throwable;
use app\common\controller\Backend;
use dji\Airline;
use app\admin\model\Airline as ModelAirline;

/**
 * 计划任务
 */
class Flighttask extends Backend
{
    /**
     * Flighttask模型对象
     * @var object
     * @phpstan-var \app\admin\model\Flighttask
     */
    protected object $model;

    protected array|string $preExcludeFields = ['id', 'create_time', 'update_time'];

    protected array $withJoinTable = ['airline', 'equipment', 'admin'];

    protected string|array $quickSearchField = ['id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\Flighttask();
    }

    /**
     * 查看
     * @throws Throwable
     */
    public function index(): void
    {
        // 如果是 select 则转发到 select 方法，若未重写该方法，其实还是继续执行 index
        if ($this->request->param('select')) {
            $this->select();
        }

        /**
         * 1. withJoin 不可使用 alias 方法设置表别名，别名将自动使用关联模型名称（小写下划线命名规则）
         * 2. 以下的别名设置了主表别名，同时便于拼接查询参数等
         * 3. paginate 数据集可使用链式操作 each(function($item, $key) {}) 遍历处理
         */
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->visible(['airline' => ['name'], 'equipment' => ['nickname'], 'admin' => ['username', 'nickname']])
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $this->success('', [
            'list'   => $res->items(),
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 若需重写查看、编辑、删除等方法，请复制 @see \app\admin\library\traits\Backend 中对应的方法至此进行重写
     */

    /**
     * 添加
     */
    public function add(): void
    {
        $admin = $this->auth->getAdmin();
        $airline = new Airline();
        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            $data = $this->excludeFields($data);
            if ($this->dataLimit && $this->dataLimitFieldAutoFill) {
                $data[$this->dataLimitField] = $this->auth->id;
            }
            
            // 判断飞行模式
            $isManualFlight = isset($data['flight_mode']) && $data['flight_mode'] == 'manual';
            $isRepeatTask = isset($data['task_type']) && $data['task_type'] == '2';
            
            // ⚠️ 调试日志：记录手动飞行任务的关键参数
            if ($isManualFlight) {
                \think\facade\Log::info('【手动飞行任务创建】接收到的参数', [
                    'equipment_id' => $data['equipment_id'] ?? 'null',
                    'airline_id' => $data['airline_id'] ?? 'null',
                    'task_name' => $data['name'] ?? 'null',
                    'flight_mode' => $data['flight_mode'] ?? 'null',
                    'task_type' => $data['task_type'] ?? 'null',
                    'all_data_keys' => array_keys($data)
                ]);
            }
            
            // 航线飞行需要航线信息
            if (!$isManualFlight && !$isRepeatTask) {
                $wayline = ModelAirline::find($data['airline_id'])->toArray();
                if (!$wayline) {
                    $this->error('下发失败,未查询到对应航线！');
                }
                $data['file_url'] = $wayline['kmz'];
                $data['total_point'] = $wayline['point_num'];
            } elseif (!$isManualFlight && $isRepeatTask && !empty($data['airline_id'])) {
                // 循环任务也需要航线信息
                $wayline = ModelAirline::find($data['airline_id'])->toArray();
                if (!$wayline) {
                    $this->error('循环航线任务需要有效的航线！');
                }
                $data['file_url'] = $wayline['kmz'];
                $data['total_point'] = $wayline['point_num'];
            }
            
            $result = false;
            $this->model->startTrans();
            // try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) $validate->scene('add');
                        $validate->check($data);
                    }
                }
                
                $data['bid'] = uuid();
                $data['tid'] = uuid();
                $data['admin_id'] = $admin->id;
                // 清理空字符串的execute_time（避免数据库报错）
                if (isset($data['execute_time']) && $data['execute_time'] === '') {
                    unset($data['execute_time']);
                }
                
                // === 分支1: 循环任务 ===
                if ($isRepeatTask) {
                    // 处理循环配置
                    if (isset($data['repeat_config']) && is_array($data['repeat_config'])) {
                        $data['repeat_config'] = json_encode($data['repeat_config']);
                    }
                    
                    $data['status'] = 'paused'; // 循环任务状态为等待调度
                    $result = $this->model->save($data);
                    if($result){
                        $this->model->commit();
                        $this->success('循环任务创建成功', [
                            'id' => $this->model->id,
                            'bid' => $data['bid'],
                            'tid' => $data['tid']
                        ]);
                    }
                }
                // === 分支2: 手动飞行任务 ===
                elseif ($isManualFlight) {
                    // 验证必要参数
                    if (empty($data['equipment_id'])) {
                        $this->model->rollback();
                        $this->error('手动飞行任务必须指定设备ID（机场ID）');
                    }
                    
                    // 设置任务状态
                    $data['status'] = 'sent'; // 标记为已下发(虽然是前端下发)
                    
                    // 立即执行任务：设置当前时间
                    if($data['task_type'] == '0'){
                        $data['execute_time'] = time();
                    }
                    
                    // 定时任务：验证execute_time是否有效
                    if($data['task_type'] == '1' && empty($data['execute_time'])){
                        $this->model->rollback();
                        $this->error('定时任务必须指定执行时间');
                    }
                    
                    // 只保存到数据库,不进行MQTT下发(由前端通过MQTT直连控制)
                    $result = $this->model->save($data);
                    if($result){
                        // ⚠️ 调试日志：确认保存的数据
                        \think\facade\Log::info('【手动飞行任务创建成功】保存的数据', [
                            'task_id' => $this->model->id,
                            'equipment_id' => $this->model->equipment_id,
                            'airline_id' => $this->model->airline_id,
                            'bid' => $this->model->bid,
                            'name' => $this->model->name
                        ]);
                        
                        $this->model->commit();
                        // 返回bid和tid供前端MQTT使用
                        $this->success('手动飞行任务创建成功', [
                            'id' => $this->model->id,
                            'bid' => $data['bid'],
                            'tid' => $data['tid'],
                            'task_type' => $data['task_type'],
                            'execute_time' => $data['execute_time'] ?? null
                        ]);
                    } else {
                        $this->model->rollback();
                        $this->error('任务创建失败');
                    }
                }
                // === 分支3: 普通航线任务(立即/定时) ===
                else {
                    $data['status'] = 'sent';
                    
                    // 立即执行任务：设置当前时间
                    if($data['task_type'] == '0'){
                        $data['execute_time'] = time();
                    }
                    
                    // 定时任务：验证execute_time是否有效
                    if($data['task_type'] == '1' && empty($data['execute_time'])){
                        $this->model->rollback();
                        $this->error('定时任务必须指定执行时间');
                    }
                    
                    // 先保存到数据库，确保 MQTT 回包处理时能查到该任务 (bid)
                    $result = $this->model->save($data);
                    if(!$result){
                        $this->model->rollback();
                        $this->error(__('No rows were added'));
                    }

                    // 后端MQTT下发
                    $res = $airline->pushTask($data);
                    
                    if($res['code'] > 0){
                        $this->model->rollback();
                        $this->error($res['msg']);
                    }
                    
                    $this->model->commit();
                    $this->success(__('Added successfully'));
                }
                
            // } catch (Throwable $e) {
            //     $this->model->rollback();
            //     $this->error($e->getMessage());
            // }
        }

        $this->error(__('Parameter error'));
    }

    /**
     * 取消任务（增强版 - 支持所有类型任务）
     */
    public function cancel(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 参数验证
            if (empty($data['ids'])) {
                $this->error('请选择要取消的任务');
            }
            
            // 支持批量取消，ids可以是字符串或数组
            $ids = is_array($data['ids']) ? $data['ids'] : explode(',', $data['ids']);
            
            if (empty($ids)) {
                $this->error('任务ID不能为空');
            }
            
            // try {
                // 查询要取消的任务
                $tasks = $this->model->whereIn('id', $ids)->select();
                
                if ($tasks->isEmpty()) {
                    $this->error('未找到要取消的任务');
                }
                
                $successCount = 0;
                $failedTasks = [];
                $canceledChildCount = 0; // 记录取消的子任务数量
                
                foreach ($tasks as $task) {
                    // 检查任务状态是否可以取消
                    // 只有以下状态不可取消：ok(已完成)、failed(已失败)、canceled(已取消)、rejected(已拒绝)、timeout(超时)
                    $cannotCancelStatuses = ['ok', 'failed', 'canceled', 'rejected', 'timeout'];
                    if (in_array($task['status'], $cannotCancelStatuses)) {
                        $failedTasks[] = [
                            'name' => $task['name'],
                            'reason' => '任务状态为 ' . $task['status'] . '，无法取消'
                        ];
                        continue;
                    }
                    
                    // === 处理循环任务（父任务）===
                    if ($task['task_type'] == '2') {
                        // 1. 取消父任务本身
                        $result = $this->model->where('id', $task['id'])->update([
                            'status' => 'canceled',
                            'is_repeat_enabled' => '0',
                            'error_msg' => '用户手动取消',
                            'update_time' => time()
                        ]);
                        
                        if ($result) {
                            $successCount++;
                            
                            // 2. 查询并取消所有子任务
                            $childTasks = $this->model
                                ->where('parent_task_id', $task['id'])
                                ->whereNotIn('status', $cannotCancelStatuses)
                                ->select();
                            
                            foreach ($childTasks as $child) {
                                // 根据子任务状态决定取消方式
                                $cancelResult = $this->cancelSingleTask($child);
                                if ($cancelResult['success']) {
                                    $canceledChildCount++;
                                }
                            }
                        } else {
                            $failedTasks[] = [
                                'name' => $task['name'],
                                'reason' => '数据库更新失败'
                            ];
                        }
                        continue;
                    }
                    
                    // === 处理普通任务（立即/定时任务）===
                    $cancelResult = $this->cancelSingleTask($task);
                    if ($cancelResult['success']) {
                        $successCount++;
                    } else {
                        $failedTasks[] = [
                            'name' => $task['name'],
                            'reason' => $cancelResult['reason']
                        ];
                    }
                }
                
                // 返回结果
                $totalCanceled = $successCount + $canceledChildCount;
                if (count($failedTasks) == 0) {
                    $msg = "成功取消 {$successCount} 个任务";
                    if ($canceledChildCount > 0) {
                        $msg .= "（包含 {$canceledChildCount} 个子任务）";
                    }
                    $this->success($msg);
                } elseif ($successCount > 0) {
                    $msg = "成功取消 {$successCount} 个任务";
                    if ($canceledChildCount > 0) {
                        $msg .= "（包含 {$canceledChildCount} 个子任务）";
                    }
                    $msg .= "，失败 " . count($failedTasks) . " 个：";
                    foreach ($failedTasks as $failed) {
                        $msg .= "\n{$failed['name']}: {$failed['reason']}";
                    }
                    $this->success($msg);
                } else {
                    $msg = "取消任务失败：";
                    foreach ($failedTasks as $failed) {
                        $msg .= "\n{$failed['name']}: {$failed['reason']}";
                    }
                    $this->error($msg);
                }
                
            // } catch (Throwable $e) {
            //     $this->error('取消任务异常: ' . $e->getMessage());
            // }
        }
        
        $this->error('请求方式错误');
    }
    
    /**
     * 取消单个任务（根据状态选择合适的取消方法）
     * @param object $task 任务对象
     * @return array ['success' => bool, 'reason' => string]
     */
    private function cancelSingleTask($task): array
    {
        try {
            // 获取设备信息
            $equipment = \app\admin\model\Equipment::find($task['equipment_id']);
            if (!$equipment) {
                return ['success' => false, 'reason' => '未找到关联设备'];
            }
            
            $mqttData = [
                'bid' => uuid(),
                'tid' => uuid(),
                'timestamp' => round(microtime(true) * 1000),
                'topic' => 'thing/product/' . $equipment['sn'] . '/services',
                'data' => []
            ];
            
            // 根据任务状态选择取消方法
            if ($task['status'] == 'in_progress') {
                // === 飞行中任务：使用 in_flight_wayline_cancel ===
                $mqttData['method'] = 'in_flight_wayline_cancel';
                // data为空对象，按官方文档要求
            } else {
                // === 未执行/已下发任务：使用 flighttask_undo ===
                $mqttData['method'] = 'flighttask_undo';
                $mqttData['data']['flight_ids'] = [$task['bid']];
            }
            
            // 发布MQTT消息
            $result = publish($mqttData);
            
            if ($result) {
                // 更新数据库状态
                $this->model->where('id', $task['id'])->update([
                    'status' => 'canceled',
                    'error_msg' => '用户手动取消',
                    'update_time' => time()
                ]);
                return ['success' => true];
            } else {
                return ['success' => false, 'reason' => 'MQTT消息发送失败'];
            }
            
        } catch (Throwable $e) {
            return ['success' => false, 'reason' => 'MQTT发送异常: ' . $e->getMessage()];
        }
    }
    
    /**
     * 上报手动飞行任务媒体数量（供前端调用）
     * 用于统计拍照和录像产生的媒体文件数量
     */
    public function reportMedia(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 参数验证
            if (empty($data['id']) && empty($data['bid'])) {
                $this->error('任务ID或业务ID不能为空');
            }
            
            if (!isset($data['media_count']) || $data['media_count'] < 0) {
                $this->error('媒体数量参数无效');
            }
            
            // try {
                // 查询任务（支持通过id或bid查询）
                $query = $this->model;
                if (!empty($data['id'])) {
                    $task = $query->find($data['id']);
                } else {
                    $task = $query->where('bid', $data['bid'])->find();
                }
                // print_r($task);
                if (!$task) {
                    $this->error('任务不存在');
                }
                
                // 验证是否为手动飞行任务
                if ($task['flight_mode'] != 'manual') {
                    $this->error('该接口仅支持手动飞行任务');
                }
                
                // 准备更新数据
                $updateData = [
                    'media_total' => $data['media_count'],
                    'update_time' => time()
                ];
                
                // 关键逻辑：如果上报的数量大于当前media_now，同步更新media_now
                // 这样可以保证media_now始终不会大于media_total
                if ($data['media_count'] > $task['media_now']) {
                    $updateData['media_now'] = $data['media_count'];
                }
                
                // 如果提供了媒体类型统计（可选）
                if (isset($data['photo_count'])) {
                    $updateData['photo_count'] = $data['photo_count'];
                }
                if (isset($data['video_count'])) {
                    $updateData['video_count'] = $data['video_count'];
                }
                
                $result = $this->model->where('id', $task['id'])->update($updateData);
                
                if ($result !== false) {
                    $responseData = [
                        'id' => $task['id'],
                        'media_total' => $data['media_count'],
                        'update_time' => time()
                    ];
                    
                    // 如果同步更新了media_now，在响应中返回
                    if (isset($updateData['media_now'])) {
                        $responseData['media_now'] = $updateData['media_now'];
                        $responseData['synced'] = true; // 标记已同步
                    }
                    
                    $this->success('媒体数量上报成功', $responseData);
                } else {
                    $this->error('媒体数量上报失败');
                }
                
            // } catch (Throwable $e) {
            //     $this->error('上报异常: ' . $e->getMessage());
            // }
        }
        
        $this->error('请求方式错误');
    }
    
    /**
     * 完成手动飞行任务（供前端调用）
     * 将任务状态更新为成功完成(ok)
     */
    public function completeManual(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 参数验证
            if (empty($data['id']) && empty($data['bid'])) {
                $this->error('任务ID或业务ID不能为空');
            }
            
            try {
                // 查询任务（支持通过id或bid查询）
                $query = $this->model;
                if (!empty($data['id'])) {
                    $task = $query->find($data['id']);
                } else {
                    $task = $query->where('bid', $data['bid'])->find();
                }
                
                if (!$task) {
                    $this->error('任务不存在');
                }
                
                // 验证是否为手动飞行任务
                if ($task['flight_mode'] != 'manual') {
                    $this->error('该接口仅支持手动飞行任务');
                }
                
                // 验证任务状态（只有执行中或已下发的任务才能完成）
                if (!in_array($task['status'], ['sent', 'in_progress'])) {
                    $this->error('任务状态不允许完成操作，当前状态: ' . $task['status']);
                }
                
                // 准备更新数据
                $updateData = [
                    'status' => 'ok',
                    'end_time' => isset($data['end_time']) ? $data['end_time'] : time(),
                    'update_time' => time()
                ];
                
                // 如果提供了额外的完成信息（可选）
                // if (isset($data['media_total'])) {
                //     $updateData['media_total'] = $data['media_total'];
                // }
                // if (isset($data['media_now'])) {
                //     $updateData['media_now'] = $data['media_now'];
                // }
                if (isset($data['now_point'])) {
                    $updateData['now_point'] = $data['now_point'];
                }
                
                // 执行更新
                $result = $this->model->where('id', $task['id'])->update($updateData);
                
                if ($result !== false) {
                    // 计算任务时长
                    $duration = null;
                    if ($task['execute_time'] && $updateData['end_time']) {
                        $startTime = is_numeric($task['execute_time']) ? intval($task['execute_time']) : strtotime($task['execute_time']);
                        $endTime = intval($updateData['end_time']);
                        if ($startTime > 9999999999) $startTime = intval($startTime / 1000);
                        if ($endTime > 9999999999) $endTime = intval($endTime / 1000);
                        $duration = $endTime - $startTime;
                    }
                    
                   
                } else {
                    $this->error('任务完成失败');
                }
                
            } catch (Throwable $e) {
                $this->error('完成任务异常: ' . $e->getMessage());
            }
        }
         $this->success('任务完成成功', [
            'id' => $task['id'],
            'bid' => $task['bid'],
            'status' => 'ok',
            'end_time' => $updateData['end_time'],
            'duration' => $duration,
            'media_total' => $updateData['media_total'] ?? $task['media_total']
        ]);
        // $this->error('请求方式错误');
    }

    /**
     * 导出
     * @throws Throwable
     */
    public function export(): void
    {
        // 处理跨域请求
        $this->handleCors();
        
        $param = $this->request->param();
        
        // 构建查询条件
        $where = [];
        if (!empty($param['quick_search'])) {
            $where[] = ['name', 'like', '%' . $param['quick_search'] . '%'];
        }
        if (!empty($param['status'])) {
            $where[] = ['status', '=', $param['status']];
        }
        if (!empty($param['task_type'])) {
            $where[] = ['task_type', '=', $param['task_type']];
        }
        if (!empty($param['equipment_id'])) {
            $where[] = ['equipment_id', '=', $param['equipment_id']];
        }
        if (!empty($param['airline_id'])) {
            $where[] = ['airline_id', '=', $param['airline_id']];
        }
        
        // 时间范围查询
        if (!empty($param['create_time'])) {
            $timeRange = explode(' - ', $param['create_time']);
            if (count($timeRange) == 2) {
                $where[] = ['create_time', 'between', [strtotime($timeRange[0]), strtotime($timeRange[1] . ' 23:59:59')]];
            }
        }

        // 查询数据并关联外键表
        $list = $this->model
            ->field('nz_flighttask.*, nz_airline.name as airline_name, nz_equipment.nickname as equipment_name, nz_admin.username as admin_name')
            ->leftJoin('nz_airline', 'nz_flighttask.airline_id = nz_airline.id')
            ->leftJoin('nz_equipment', 'nz_flighttask.equipment_id = nz_equipment.id')
            ->leftJoin('nz_admin', 'nz_flighttask.admin_id = nz_admin.id')
            ->where($where)
            ->order('nz_flighttask.id', 'desc')
            ->select()
            ->toArray();

        // 处理数据格式化
        $exportData = [];
        foreach ($list as $item) {
            // 预处理时间相关字段
            $executeTime = '';
            $endTime = '';
            $taskDuration = '';
            
            // 处理开始执行时间
            if (!empty($item['execute_time'])) {
                $executeTime = strtotime($item['execute_time'],time());
            }
            
            // 处理任务结束时间
            if (!empty($item['end_time'])) {
                $endTime = strtotime($item['end_time'],time());
            }
            
            // 处理任务时长
            if (!empty($item['execute_time']) && !empty($item['end_time'])) {
                $taskDuration = $this->calculateTaskDuration($executeTime, $endTime);
            }
            
            $exportData[] = [
                'ID' => $item['id'],
                '任务名称' => $item['name'] ?? '',
                '业务ID' => $item['bid'],
                '事务ID' => $item['tid'],
                '执行航线' => $item['airline_name'] ?? '',
                '执行设备' => $item['equipment_name'] ?? '',
                '开始执行时间' => $item['execute_time'] ?? '',
                '任务结束时间' => $item['end_time'] ?? '',
                '任务时长' => $taskDuration,
                '任务类型' => $this->getTaskTypeText($item['task_type']),
                '航线文件URL' => $item['file_url'],
                '航线文件签名' => $item['file_fingerprint'],
                '返航高度' => $item['rth_altitude'] ?? '',
                '返航高度模式' => $this->getRthModeText($item['rth_mode']),
                '遥控器失控动作' => $this->getOutOfControlActionText($item['out_of_control_action']),
                '航线失控动作' => $this->getExitWaylineText($item['exit_wayline_when_rc_lost']),
                '航线精度类型' => $this->getWaylinePrecisionText($item['wayline_precision_type']),
                '创建时间' => $item['create_time'] ? date('Y-m-d H:i:s', $item['create_time']) : '',
                '修改时间' => $item['update_time'] ? date('Y-m-d H:i:s', $item['update_time']) : '',
                '执行状态' => $this->getStatusText($item['status']),
                '创建人' => $item['admin_name'] ?? '',
                '错误码' => $item['error_code'],
                '错误原因' => $item['error_msg'],
                '总航点' => $item['total_point'] ?? 0,
                '执行航点' => $item['now_point'] ?? 0,
                '媒体数量' => $item['media_total'] ?? 0,
                '上传数量' => $item['media_now'] ?? 0,
            ];
        }

        // 导出Excel
        $fileName = '飞行任务数据_' . date('YmdHis') . '.xlsx';
        $result = $this->exportToExcel($exportData, $fileName);
        
        if ($result['success']) {
            $this->success('导出成功', $result['data']);
        } else {
            $this->error($result['message']);
        }
    }

    /**
     * 获取任务类型文本
     */
    private function getTaskTypeText($type)
    {
        $types = [
            '0' => '立即任务',
            '1' => '定时任务',
            '2' => '循环任务',
            '3' => '手动飞行'
        ];
        return $types[$type] ?? $type;
    }

    /**
     * 获取返航高度模式文本
     */
    private function getRthModeText($mode)
    {
        $modes = [
            '0' => '智能高度',
            '1' => '设定高度'
        ];
        return $modes[$mode] ?? $mode;
    }

    /**
     * 获取遥控器失控动作文本
     */
    private function getOutOfControlActionText($action)
    {
        $actions = [
            '0' => '返航',
            '1' => '悬停',
            '2' => '降落'
        ];
        return $actions[$action] ?? $action;
    }

    /**
     * 获取航线失控动作文本
     */
    private function getExitWaylineText($action)
    {
        $actions = [
            '0' => '继续执行航线任务',
            '1' => '退出航线任务',
            '2' => '执行遥控器失控动作'
        ];
        return $actions[$action] ?? $action;
    }

    /**
     * 获取航线精度类型文本
     */
    private function getWaylinePrecisionText($type)
    {
        $types = [
            '0' => 'GPS 任务',
            '1' => '高精度 RTK 任务'
        ];
        return $types[$type] ?? $type;
    }

    /**
     * 获取执行状态文本
     */
    private function getStatusText($status)
    {
        $statuses = [
            'canceled' => '取消或终止',
            'failed' => '失败',
            'in_progress' => '执行中',
            'ok' => '执行成功',
            'partially_done' => '部分完成',
            'paused' => '暂停',
            'rejected' => '拒绝',
            'sent' => '已下发',
            'timeout' => '超时'
        ];
        return $statuses[$status] ?? $status;
    }

    /**
     * 处理跨域请求
     */
    private function handleCors()
    {
        // 设置允许跨域的响应头
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        
        // 处理预检请求
        if ($this->request->method() === 'OPTIONS') {
            exit();
        }
    }

    /**
     * 格式化时间戳
     */
    private function formatTimestamp($timestamp)
    {
        if (empty($timestamp)) {
            return '';
        }
        
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 计算任务时长
     */
    private function calculateTaskDuration($startTime, $endTime)
    {
        // 检查开始时间和结束时间是否都存在
        if (empty($startTime) || empty($endTime)) {
            return '';
        }
        
        // 处理字符串类型的时间戳
        if (is_string($startTime)) {
            $startTime = trim($startTime);
            if (!is_numeric($startTime)) {
                return '';
            }
            $startTime = floatval($startTime); 
        }
        if (is_string($endTime)) {
            $endTime = trim($endTime);
            if (!is_numeric($endTime)) {
                return '';
            }
            $endTime = floatval($endTime);
        }
        
        // 如果是毫秒时间戳，转换为秒时间戳
        if ($startTime > 9999999999) {
            $startTime = intval($startTime / 1000);
        }
        if ($endTime > 9999999999) {
            $endTime = intval($endTime / 1000);
        }
        
        // 验证时间戳是否有效
        if ($startTime <= 0 || $endTime <= 0) {
            return '';
        }
        
        // 计算时长差值（秒）
        $duration = $endTime - $startTime;
        
        // 如果时长为负数或0，返回空
        if ($duration <= 0) {
            return '';
        }
        
        // 转换为分秒格式
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;
        
        if ($minutes > 0) {
            return $minutes . '分' . $seconds . '秒';
        } else {
            return $seconds . '秒';
        }
    }

    /**
     * 导出数据到Excel
     */
    private function exportToExcel($data, $fileName)
    {
        // 检查是否安装了PhpSpreadsheet
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return ['success' => false, 'message' => '请先安装PhpSpreadsheet扩展包'];
        }

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // 设置表头
            if (!empty($data)) {
                $headers = array_keys($data[0]);
                $col = 'A';
                foreach ($headers as $header) {
                    $sheet->setCellValue($col . '1', $header);
                    $col++;
                }

                // 设置数据
                $row = 2;
                foreach ($data as $item) {
                    $col = 'A';
                    foreach ($item as $value) {
                        $sheet->setCellValue($col . $row, $value);
                        $col++;
                    }
                    $row++;
                }
            }

            // 确保导出目录存在
            $exportDir = root_path() . 'public/exports/';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }

            // 生成文件路径
            $filePath = $exportDir . $fileName;
            
            // 保存文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filePath);

            // 生成下载链接
            $downloadUrl = request()->domain() . '/exports/' . $fileName;

            return [
                'success' => true,
                'data' => [
                    'download_url' => $downloadUrl,
                    'file_name' => $fileName,
                    'file_size' => filesize($filePath)
                ]
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => '导出失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 获取任务错误详情
     * @throws Throwable
     */
    public function errorDetail(): void
    {
        $id = $this->request->param('id');
        
        if (!$id) {
            $this->error('缺少任务ID参数');
        }
        
        $task = $this->model->find($id);
        
        if (!$task) {
            $this->error('任务不存在');
        }
        
        // 引入错误处理类
        $errorHandler = new \dji\ErrorHandler();
        
        // 构建错误详情
        $errorDetail = [
            'basic' => [
                'task_id' => $task['id'],
                'flight_id' => $task['bid'],
                'task_name' => $task['name'],
                'status' => $task['status'],
                'status_text' => $errorHandler::getTaskStatusMessage($task['status']),
                'failed_time' => $task['failed_time'] ? date('Y-m-d H:i:s', $task['failed_time']) : null
            ],
            'error' => null,
            'location' => null,
            'state' => null,
            'suggestion' => null
        ];
        
        // 如果有错误信息
        if ($task['error_code'] || $task['break_reason']) {
            // 下发错误
            if ($task['error_code'] && $task['status'] == 'rejected') {
                $errorDetail['error'] = [
                    'type' => 'prepare',
                    'code' => $task['error_code'],
                    'message' => $task['error_msg'] ?: $errorHandler::getPrepareErrorMessage($task['error_code']),
                    'is_critical' => true,
                    'can_retry' => false
                ];
                $errorDetail['suggestion'] = '请检查航线文件和设备状态后重新下发任务';
            }
            
            // 执行错误
            if ($task['break_reason']) {
                $breakReason = (int)$task['break_reason'];
                $errorDetail['error'] = [
                    'type' => 'execution',
                    'code' => $breakReason,
                    'message' => $task['error_msg'] ?: $errorHandler::getExecutionErrorMessage($breakReason),
                    'category' => $errorHandler::getErrorCategory($breakReason),
                    'is_critical' => $errorHandler::isCriticalError($breakReason),
                    'can_retry' => $errorHandler::canAutoRetry($breakReason),
                    'is_user_action' => $errorHandler::isUserAction($breakReason),
                    'is_device_issue' => $errorHandler::isDeviceIssue($breakReason)
                ];
                
                // 断点位置信息
                if ($task['break_latitude'] || $task['break_longitude']) {
                    $errorDetail['location'] = [
                        'latitude' => $task['break_latitude'],
                        'longitude' => $task['break_longitude'],
                        'waypoint_index' => $task['now_point']
                    ];
                }
                
                // 建议处理方案
                $errorDetail['suggestion'] = $errorHandler::getSuggestedAction($breakReason);
            }
        }
        
        // 航线任务状态
        if ($task['wayline_mission_state'] !== null) {
            $errorDetail['state'] = [
                'wayline_mission_state' => $task['wayline_mission_state'],
                'wayline_mission_state_text' => $errorHandler::getWaylineStateMessage($task['wayline_mission_state']),
                'failed_step' => $task['failed_step'],
                'current_waypoint' => $task['now_point'],
                'total_waypoint' => $task['total_point']
            ];
        }
        
        $this->success('获取成功', $errorDetail);
    }
    
    /**
     * 获取错误统计
     * @throws Throwable
     */
    public function errorStats(): void
    {
        // 统计各类错误的数量
        $stats = [
            'total_failed' => $this->model->where('status', 'in', ['failed', 'rejected', 'paused'])->count(),
            'prepare_error' => $this->model->where('status', 'rejected')->count(),
            'execution_error' => $this->model->where('break_reason', '>', 0)->count(),
            'critical_error' => 0,
            'retryable_error' => 0,
            'user_action' => 0,
            'device_issue' => 0
        ];
        
        // 获取所有有break_reason的任务
        $tasks = $this->model
            ->where('break_reason', '>', 0)
            ->field('break_reason')
            ->select();
        
        $errorHandler = new \dji\ErrorHandler();
        
        foreach ($tasks as $task) {
            $breakReason = (int)$task['break_reason'];
            if ($errorHandler::isCriticalError($breakReason)) {
                $stats['critical_error']++;
            }
            if ($errorHandler::canAutoRetry($breakReason)) {
                $stats['retryable_error']++;
            }
            if ($errorHandler::isUserAction($breakReason)) {
                $stats['user_action']++;
            }
            if ($errorHandler::isDeviceIssue($breakReason)) {
                $stats['device_issue']++;
            }
        }
        
        // 错误TOP10
        $topErrors = $this->model
            ->where('break_reason', '>', 0)
            ->field('break_reason, error_msg, COUNT(*) as count')
            ->group('break_reason, error_msg')
            ->order('count', 'desc')
            ->limit(10)
            ->select()
            ->toArray();
        
        $stats['top_errors'] = array_map(function($item) use ($errorHandler) {
            return [
                'code' => $item['break_reason'],
                'message' => $item['error_msg'] ?: $errorHandler::getExecutionErrorMessage($item['break_reason']),
                'count' => $item['count'],
                'category' => $errorHandler::getErrorCategory($item['break_reason'])
            ];
        }, $topErrors);
        
        $this->success('获取成功', $stats);
    }
}