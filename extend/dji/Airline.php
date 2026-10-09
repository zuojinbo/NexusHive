<?php

namespace dji;

use app\admin\model\Airline as ModelAirline;
use app\admin\model\Equipment;
use app\admin\model\Flightrecord;
use app\admin\model\Flighttask;
use ba\Alists;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

class Airline
{
    private $endpoint;

    public function __construct()
    {
        // 使用统一的 Storage 类获取 CDN URL
        $this->endpoint = \ba\Storage::getCdnUrl();
    }
    /**
     * 航线任务下发接口 - 根据DJI官方API优化
     * @param array $param 任务参数
     * @return array 返回结果
     */
    public function pushTask($param)
    {
        // print_r($param);
        try {
            // 1. 参数验证
            $validation = $this->validatePushTaskParams($param);
            if (!$validation['status']) {
                return $validation;
            }

            // 2. 获取航线和设备信息
            // $wayline = ModelAirline::find($param['airline_id']);
            // if (!$wayline) {
            //     return ['status' => false, 'code' => 1001, 'msg' => '下发失败,未查询到对应航线！'];
            // }
            $equipment = Equipment::find($param['equipment_id']);
            if (!$equipment) {
                return ['status' => false, 'code' => 1002, 'msg' => '下发失败,未查询到对应设备！'];
            }

            // 3. 构建MQTT消息
            $mqttData = $this->buildFlightTaskPrepareMessage($param, $equipment);
            // 4. 发布消息
            $result = publish($mqttData);
            
            if ($result) {
                // 5. 记录任务状态
                // $this->recordTaskStatus($param, 'sent');
                return ['status' => true, 'code' => 0, 'msg' => '任务下发成功', 'data' => ['flight_id' => $param['bid']]];
            } else {
                return ['status' => false, 'code' => 1003, 'msg' => '任务下发失败,MQTT发布失败'];
            }

        } catch (\Exception $e) {
            return ['status' => false, 'code' => 1004, 'msg' => '任务下发异常: ' . $e->getMessage()];
        }
    }

    /**
     * 验证pushTask参数
     * @param array $param
     * @return array
     */
    private function validatePushTaskParams($param)
    {
        // 基础参数检查
        $requiredFields = ['bid', 'tid', 'equipment_id'];
        foreach ($requiredFields as $field) {
            if (!isset($param[$field]) || empty($param[$field])) {
                return ['status' => false, 'code' => 1000, 'msg' => "缺少必需参数: {$field}"];
            }
        }

        // 判断飞行模式
        $isManualFlight = isset($param['flight_mode']) && $param['flight_mode'] == 'manual';
        
        // 航线飞行需要航线ID
        if (!$isManualFlight && (empty($param['airline_id']) || empty($param['file_url']))) {
            return ['status' => false, 'code' => 1008, 'msg' => '航线飞行任务需要航线ID和航线文件'];
        }
        
        // === 新增：航线飞行器兼容性验证 ===
        if (!$isManualFlight && !empty($param['airline_id'])) {
            $validation = $this->validateAircraftCompatibility($param['airline_id'], $param['equipment_id']);
            if (!$validation['status']) {
                return $validation;
            }
        }

        // 任务类型验证（支持0=立即,1=定时,2=循环,3=手动飞行）
        if (!isset($param['task_type']) || !in_array($param['task_type'], ['0', '1', '2', '3', 0, 1, 2, 3])) {
            return ['status' => false, 'code' => 1005, 'msg' => '任务类型无效,支持: 0=立即,1=定时,2=循环,3=手动飞行'];
        }

        // 定时任务需要执行时间
        if (in_array($param['task_type'], ['1', 1]) && empty($param['execute_time'])) {
            return ['status' => false, 'code' => 1006, 'msg' => '定时任务需要指定执行时间'];
        }

        return ['status' => true];
    }

    /**
     * 构建flighttask_prepare消息（支持航线飞行和手动飞行）
     * @param array $param
     * @param object $equipment
     * @return array
     */
    private function buildFlightTaskPrepareMessage($param, $equipment)
    {
        $topic = 'thing/product/' . $equipment['sn'] . '/services';
        
        // 判断是否为手动飞行
        $isManualFlight = isset($param['flight_mode']) && $param['flight_mode'] == 'manual';
        
        // 基础消息结构
        $data = [
            'bid' => $param['bid'],
            'tid' => $param['tid'],
            'timestamp' => round(microtime(true) * 1000),
            'method' => 'flighttask_prepare',
            'topic' => $topic,
            'data' => []
        ];

        // 任务数据
        $taskData = [
            'flight_id' => $param['bid'],
            'task_type' => (int)$param['task_type']
        ];
        
        // 航线飞行才需要文件信息
        if (!$isManualFlight && !empty($param['file_url'])) {
            $taskData['file'] = [
                'url' => $this->endpoint . $param['file_url'],
                'fingerprint' => $this->calculateFileMD5($this->endpoint . $param['file_url'])
            ];
        }

        // 执行时间设置（DJI要求13位毫秒级时间戳）
        if (isset($param['execute_time'])) {
            if (in_array($param['task_type'], ['0', 0])) {
                // 立即任务：使用当前时间（毫秒）
                $taskData['execute_time'] = round(microtime(true) * 1000);
            } elseif (in_array($param['task_type'], ['1', 1])) {
                // 定时任务：智能识别并转换时间戳格式
                $executeTime = $param['execute_time'];
                
                if (is_numeric($executeTime)) {
                    $len = strlen((string)$executeTime);
                    if ($len == 10) {
                        // 10位秒级时间戳，转换为13位毫秒级
                        $taskData['execute_time'] = $executeTime * 1000;
                        Log::info("【航线下发】时间戳转换: {$executeTime}(秒) → {$taskData['execute_time']}(毫秒)");
                    } elseif ($len == 13) {
                        // 13位毫秒级时间戳，直接使用
                        $taskData['execute_time'] = $executeTime;
                        Log::info("【航线下发】使用毫秒级时间戳: {$executeTime}");
                    } else {
                        // 异常长度，记录警告并尝试修正
                        Log::warning("【航线下发】⚠️ 异常时间戳长度: {$len}位, 值={$executeTime}");
                        $taskData['execute_time'] = $len < 13 ? $executeTime * 1000 : $executeTime;
                    }
                } else {
                    // 字符串时间，解析为毫秒级时间戳
                    $taskData['execute_time'] = strtotime($executeTime) * 1000;
                    Log::info("【航线下发】字符串时间解析: {$executeTime} → {$taskData['execute_time']}");
                }
                
                // 验证时间戳的合理性（不能是过去超过1小时，或未来超过30天）
                $now = round(microtime(true) * 1000);
                if ($taskData['execute_time'] < ($now - 3600000)) {
                    Log::warning("【航线下发】⚠️ 执行时间已过期: " . 
                               date('Y-m-d H:i:s', $taskData['execute_time'] / 1000) . 
                               " (当前: " . date('Y-m-d H:i:s', $now / 1000) . ")");
                } elseif ($taskData['execute_time'] > ($now + 2592000000)) {
                    Log::warning("【航线下发】⚠️ 执行时间过远: " . 
                               date('Y-m-d H:i:s', $taskData['execute_time'] / 1000));
                }
            }
        }

        // 返航设置
        $taskData['rth_altitude'] = isset($param['rth_altitude']) ? (int)$param['rth_altitude'] : 100;
        $taskData['rth_mode'] = isset($param['rth_mode']) ? (int)$param['rth_mode'] : 1;
        
        // 失控动作设置
        $taskData['out_of_control_action'] = isset($param['out_of_control_action']) ? (int)$param['out_of_control_action'] : 0;
        $taskData['exit_wayline_when_rc_lost'] = isset($param['exit_wayline_when_rc_lost']) ? (int)$param['exit_wayline_when_rc_lost'] : 0;
        
        // 航线精度类型（只有航线飞行才需要）
        if (!$isManualFlight) {
            $taskData['wayline_precision_type'] = isset($param['wayline_precision_type']) ? (int)$param['wayline_precision_type'] : 1;
        }

        // 模拟任务设置
        if (isset($param['simulate_mission']) && $param['simulate_mission']) {
            $taskData['simulate_mission'] = [
                'is_enable' => 1,
                'latitude' => isset($param['simulate_latitude']) ? (float)$param['simulate_latitude'] : 30.46822907,
                'longitude' => isset($param['simulate_longitude']) ? (float)$param['simulate_longitude'] : 105.562635819
            ];
        }

        // 执行条件
        if (isset($param['executable_conditions'])) {
            $taskData['executable_conditions'] = [
                'storage_capacity' => isset($param['executable_conditions']['storage_capacity']) ? (int)$param['executable_conditions']['storage_capacity'] : 1000
            ];
        }

        // 断点续飞（只有航线飞行才支持）
        if (!$isManualFlight && isset($param['break_point'])) {
            $taskData['break_point'] = [
                'index' => (int)$param['break_point']['index'],
                'state' => (int)$param['break_point']['state'],
                'progress' => (float)$param['break_point']['progress'],
                'wayline_id' => (int)$param['break_point']['wayline_id']
            ];
        }

        // 飞行安全预检查
        if (isset($param['flight_safety_advance_check'])) {
            $taskData['flight_safety_advance_check'] = (bool)$param['flight_safety_advance_check'];
        }

        $data['data'] = $taskData;
        return $data;
    }

    /**
     * 计算文件MD5值
     * @param string $url
     * @return string
     */
    private function calculateFileMD5($url)
    {
        try {
            // 如果是本地文件路径
            if (file_exists($url)) {
                return md5_file($url);
            }
            
            // 如果是远程URL，下载后计算MD5
            $content = file_get_contents($url);
            if ($content !== false) {
                return md5($content);
            }
            
            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * 记录任务状态
     * @param array $param
     * @param string $status
     */
    private function recordTaskStatus($param, $status)
    {
        try {
            // 更新或创建任务记录
            Flighttask::updateOrCreate(
                ['bid' => $param['bid']],
                [
                    'equipment_id' => $param['equipment_id'],
                    'airline_id' => $param['airline_id'],
                    'status' => $status,
                    'task_type' => $param['task_type'],
                    'create_time' => time(),
                    'update_time' => time()
                ]
            );
        } catch (\Exception $e) {
            // 记录日志但不影响主流程
            error_log('记录任务状态失败: ' . $e->getMessage());
        }
    }

    public function resourceReady($param)
    {
        if (isset($param['data']['flight_id']) && $param['data']['flight_id']) {
            $task = Flighttask::where('bid', $param['data']['flight_id'])->find();
            $data = [];
            $data['bid'] = $param['bid'];
            $data['tid'] = $param['tid'];
            $data['timestamp'] = round(microtime(true) * 1000);
            $data['method'] = 'flighttask_resource_get';
            $data['topic'] = 'thing/product/' . $param['sn'] . '/requests_reply';
            $data['data'] = [];
            $data['data']['result'] = 0;
            $data['data']['output'] = [];
            $data['data']['output']['file'] = [];
            $data['data']['output']['file']['fingerprint'] = md5_file($this->endpoint . $task['file_url']);
            $data['data']['output']['file']['url'] = $this->endpoint . $task['file_url'];
            $result = publish($data);
            if ($result) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function flighttaskReady($param)
    {
        Log::info('【航线执行】开始处理 ' . count($param) . ' 个任务');
        
        foreach ($param as $key => $value) {
            $row = $value;
            if ($row) {
                $erow = Equipment::find($row['equipment_id']);
                if ($erow) {
                    //组装任务执行参数
                    $data = [];
                    $data['bid'] = $value['bid'];
                    $data['tid'] = uuid();
                    $data['topic'] = 'thing/product/' . $erow['sn'] . '/services';
                    $data['timestamp'] = round(microtime(true) * 1000);
                    $data['method'] = 'flighttask_execute';
                    $data['data'] = [];
                    $data['data']['flight_id'] = $value['bid'];
                    
                    Log::info("【航线执行】发送 flighttask_execute: bid={$value['bid']}, sn={$erow['sn']}");
                    
                    $result = publish($data);
                    
                    if ($result) {
                        Flighttask::where('bid', $value['bid'])->update([
                            'status' => 'in_progress',
                            'update_time' => time(),
                        ]);
                        Log::info("【航线执行】任务 {$value['bid']} 状态已更新为 in_progress");
                    } else {
                        Flighttask::where('bid', $value['bid'])->update([
                            'status' => 'sent',
                            'update_time' => time(),
                        ]);
                        Log::error("【航线执行】任务 {$value['bid']} 发送失败");
                    }
                } else {
                    Log::error("【航线执行】设备不存在: equipment_id={$row['equipment_id']}");
                }
            }
        }
        
        return true;
    }

    public function flighttaskReady_Bak($param)
    {
        if (isset($param['data']['flight_ids']) && count($param['data']['flight_ids']) > 0) {
            foreach ($param['data']['flight_ids'] as $key => $value) {
                $row = Flighttask::where('bid', $value)->find();
                if ($row) {
                    $erow = Equipment::find($row['equipment_id']);
                    if ($erow) {
                        //组装任务执行参数
                        $data = [];
                        $data['bid'] = $value;
                        $data['tid'] = uuid();
                        $data['topic'] = 'thing/product/' . $erow['sn'] . '/services';
                        $data['timestamp'] = round(microtime(true) * 1000);
                        $data['method'] = 'flighttask_execute';
                        $data['data'] = [];
                        $data['data']['flight_id'] = $value;
                        $result = publish($data);
                        Flighttask::where('bid', $value)->update(['status' => 1]);
                        if ($result) {
                            return true;
                        } else {
                            return false;
                        }
                    } else {
                        return false;
                    }
                } else {
                    return false;
                }
            }
        } else {
            return false;
        }
    }

    /**
     * 处理航线错误回复（下发和执行阶段）
     * @param array $param MQTT消息参数
     * @return bool
     */
    public function flighttaskError($param)
    {
        if (!isset($param['data']['result'])) {
            return false;
        }
        
        $result = (int)$param['data']['result'];
        
        // result = 0 表示成功，不需要处理
        if ($result === 0) {
            return true;
        }
        
        try {
            // 根据SN查找设备
            $equipment = Equipment::where('sn', $param['sn'])->find();
            if (!$equipment) {
                Log::error("【航线错误】设备不存在: SN={$param['sn']}");
                return false;
            }
            
            // 查找任务（优先通过bid，其次通过equipment_id和状态）
            $task = null;
            if (isset($param['bid'])) {
                $task = Flighttask::where('bid', $param['bid'])->find();
            }
            
            if (!$task) {
                // 根据错误阶段选择不同的状态筛选
                $statusFilter = ['sent', 'in_progress'];
                $task = Flighttask::where('equipment_id', $equipment['id'])
                    ->whereIn('status', $statusFilter)
                    ->order('id', 'desc')
                    ->find();
            }
            
            if (!$task) {
                Log::error("【航线错误】任务不存在: BID={$param['bid']}, EquipmentID={$equipment['id']}");
                return false;
            }
            
            // 判断错误阶段
            $errorStage = isset($param['method']) && $param['method'] === 'flighttask_execute' ? 'execute' : 'prepare';
            
            // 获取错误描述
            $errorMsg = ErrorHandler::getPrepareErrorMessage($result);
            
            // 根据错误阶段设置不同的任务状态
            $status = 'rejected';  // prepare阶段默认为rejected（被拒绝）
            if ($errorStage === 'execute') {
                $status = 'failed';  // execute阶段为failed（执行失败）
            }
            
            // 更新任务状态
            $updateData = [
                'status' => $status,
                'error_code' => $result,
                'error_msg' => $errorMsg,
                'failed_time' => time()
            ];
            
            Flighttask::where('id', $task['id'])->update($updateData);
            
            // 记录错误日志
            ErrorHandler::logError(
                $task['bid'],
                $result,
                $errorMsg,
                $errorStage,  // 记录错误阶段
                [
                    'task_id' => $task['id'],
                    'sn' => $param['sn'],
                    'tid' => $param['tid'] ?? '',
                    'method' => $param['method'] ?? '',
                    'stage_desc' => $errorStage === 'prepare' ? '航线下发阶段' : '航线执行阶段'
                ]
            );
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("【航线错误处理异常】" . $e->getMessage());
            return false;
        }
    }

    /**
     * 处理航线进度上报 - 增强错误处理
     * @param array $param MQTT消息参数
     */
    public function flighttask_progress($param)
    {
        if (!isset($param['data']['output'])) {
            return false;
        }
        
        $output = $param['data']['output'];
        $ext = $output['ext'] ?? [];
        $progress = $output['progress'] ?? [];
        
        // 1. 记录飞行日志
        $this->saveFlightRecord($param);
        
        // 2. 更新任务进度和状态（优化：传入完整的progress和ext信息）
        if (isset($output['status'])) {
            $this->updateFlightTaskProgress($output, $param);
        }
        
        // 3. 处理中断错误（break_reason > 0）
        if (isset($ext['break_point']['break_reason']) && $ext['break_point']['break_reason'] > 0) {
            $this->handleBreakpointError($ext, $param);
        }
        
        // 4. 更新航线任务状态
        if (isset($ext['wayline_mission_state'])) {
            $this->updateWaylineMissionState($ext['flight_id'], $ext['wayline_mission_state']);
        }
        
        // 5. 更新媒体文件总数
        if (isset($ext['media_count']) && $ext['media_count'] > 0) {
            Flighttask::where('bid', $ext['flight_id'])
                ->update(['media_total' => $ext['media_count']]);
        }
        
        // ===== 新增：智能完成判断 =====
        // 根据DJI官方文档，当满足以下任一条件时，强制更新为完成状态：
        // 条件1：wayline_mission_state = 9 (航线停止) + percent >= 90
        // 条件2：current_step = 35 (通知任务结果) + status = 'ok'
        // 条件3：percent = 100 + status = 'ok'
        $this->smartCompleteDetection($ext['flight_id'], $output);
    }
    
    /**
     * 保存飞行记录
     * @param array $param MQTT消息参数
     */
    private function saveFlightRecord($param)
    {
        $output = $param['data']['output'];
        $ext = $output['ext'] ?? [];
        
        $data = [
            'sn' => $param['sn'],
            'flight_id' => $ext['flight_id'] ?? '',
            'track_id' => $ext['track_id'] ?? '',
            'current_waypoint_index' => $ext['current_waypoint_index'] ?? 0,
            'media_count' => $ext['media_count'] ?? 0,
            'wayline_mission_state' => $ext['wayline_mission_state'] ?? 0,
            'wayline_id' => $ext['wayline_id'] ?? 0,
            'progress_current_step' => $output['progress']['current_step'] ?? 0,
            'progress_percent' => $output['progress']['percent'] ?? 0,
            'status' => $output['status'] ?? 'in_progress',  // 新增：记录任务状态
            'create_time' => time(),
            'update_time' => time()
        ];
        
        try {
            Flightrecord::create($data);
        } catch (\Exception $e) {
            Log::error("【飞行记录】保存失败: " . $e->getMessage());
        }
    }
    
    /**
     * 处理断点错误
     * @param array $ext 扩展数据
     * @param array $param 完整MQTT参数
     */
    private function handleBreakpointError($ext, $param)
    {
        $breakPoint = $ext['break_point'];
        $breakReason = (int)$breakPoint['break_reason'];
        $flightId = $ext['flight_id'];
        
        // 获取错误描述
        $errorMsg = ErrorHandler::getExecutionErrorMessage($breakReason);
        
        // 判断错误严重程度
        $isCritical = ErrorHandler::isCriticalError($breakReason);
        $canRetry = ErrorHandler::canAutoRetry($breakReason);
        $isUserAction = ErrorHandler::isUserAction($breakReason);
        
        // 确定任务状态
        $status = 'paused'; // 默认暂停
        if ($isCritical) {
            $status = 'failed'; // 严重错误标记为失败
        } elseif ($isUserAction) {
            $status = 'canceled'; // 用户主动操作标记为取消
        }
        
        // 更新任务
        $updateData = [
            'status' => $status,
            'break_reason' => $breakReason,
            'error_msg' => $errorMsg,
            'break_latitude' => $breakPoint['latitude'] ?? null,
            'break_longitude' => $breakPoint['longitude'] ?? null,
            'failed_time' => time(),
            'failed_step' => $param['data']['output']['progress']['current_step'] ?? null
        ];
        
        Flighttask::where('bid', $flightId)->update($updateData);
        
        // 记录日志
        ErrorHandler::logError(
            $flightId,
            $breakReason,
            $errorMsg,
            'execution',
            [
                'is_critical' => $isCritical,
                'can_retry' => $canRetry,
                'is_user_action' => $isUserAction,
                'latitude' => $breakPoint['latitude'] ?? null,
                'longitude' => $breakPoint['longitude'] ?? null,
                'wayline_id' => $ext['wayline_id'] ?? null,
                'current_waypoint' => $ext['current_waypoint_index'] ?? null,
                'suggestion' => ErrorHandler::getSuggestedAction($breakReason)
            ]
        );
    }
    
    /**
     * 更新航线任务状态
     * @param string $flightId 任务ID
     * @param int $state 状态代码
     */
    private function updateWaylineMissionState($flightId, $state)
    {
        $stateMsg = ErrorHandler::getWaylineStateMessage($state);
        
        Flighttask::where('bid', $flightId)->update([
            'wayline_mission_state' => $state
        ]);

        if (in_array((int)$state, [4, 5, 6, 8], true)) {
            $promoted = Flighttask::where('bid', $flightId)
                ->where('status', 'sent')
                ->update([
                    'status' => 'in_progress',
                    'update_time' => time(),
                ]);

            if ($promoted) {
                Log::info("【航线状态变更】检测到任务已开始执行，任务状态强制晋级为 in_progress: FlightID={$flightId}, State={$state}");
            }
        }
        
        // 状态7=中断，9=停止时记录日志
        if (in_array($state, [7, 9])) {
            Log::info("【航线状态变更】FlightID={$flightId}, State={$state}, Msg={$stateMsg}");
        }
    }

    /**
     * 更新飞行任务进度
     * 
     * @param array $output 输出数据
     * @param array $param 参数数据
     * @return void
     */
    private function updateFlightTaskProgress($output, $param)
    {
        $flightId = $output['ext']['flight_id'];
        $currentStatus = $this->promoteTaskToInProgressIfStarted($flightId, $output);
        $currentWaypointIndex = $output['ext']['current_waypoint_index'] ?? 0;
        
        // 获取缓存中的状态
        $cachedStatus = Cache::get($flightId);
        $cachedWaypointIndex = Cache::get($flightId . 'current_waypoint_index');
        
        // === 调试日志：记录状态变化 ===
        Log::info("【任务进度更新】FlightID={$flightId}, Status: {$cachedStatus} → {$currentStatus}, Waypoint: {$cachedWaypointIndex} → {$currentWaypointIndex}, MissionState: " . ($output['ext']['wayline_mission_state'] ?? 'null'));
        
        // 处理失败状态
        if ($currentStatus === 'failed') {
            $this->handleFailedStatus($flightId, $param['data']['result'], $currentStatus);
        } else {
            // 处理正常状态更新
            $this->handleNormalStatusUpdate(
                $flightId, 
                $currentStatus, 
                $currentWaypointIndex, 
                $cachedStatus, 
                $cachedWaypointIndex
            );
        }
        
        // 更新缓存
        Cache::set($flightId, $currentStatus);
        Cache::set($flightId . 'current_waypoint_index', $currentWaypointIndex);
    }

    private function hasStartedSignal(array $output): bool
    {
        $ext = $output['ext'] ?? [];
        $progress = $output['progress'] ?? [];
        $missionState = $ext['wayline_mission_state'] ?? null;

        return in_array($missionState, [4, 5, 6, 8], true)
            || (($progress['current_step'] ?? 0) > 0)
            || (($progress['percent'] ?? 0) > 0)
            || (($ext['current_waypoint_index'] ?? 0) > 0);
    }

    private function promoteTaskToInProgressIfStarted(string $flightId, array $output): string
    {
        $task = Flighttask::where('bid', $flightId)->find();
        if (!$task) {
            Log::warning("【任务进度更新】未找到任务，无法推进状态: FlightID={$flightId}");
            return $output['status'] ?? 'sent';
        }

        $taskStatus = (string)$task['status'];
        if ($taskStatus === 'sent' && $this->hasStartedSignal($output)) {
            $updated = Flighttask::where('id', $task['id'])
                ->where('status', 'sent')
                ->update([
                    'status' => 'in_progress',
                    'update_time' => time(),
                ]);

            if ($updated) {
                $taskStatus = 'in_progress';
                Log::info("【任务进度更新】检测到已开始执行信号，任务状态强制晋级为 in_progress: FlightID={$flightId}");
            }
        }

        $progressStatus = $output['status'] ?? $taskStatus;
        if ($taskStatus === 'in_progress' && $progressStatus === 'sent') {
            return 'in_progress';
        }

        return $progressStatus;
    }

    /**
     * 处理失败状态
     * 
     * @param string $flightId 飞行任务ID
     * @param int $errorCode 错误代码
     * @param string $status 状态
     * @return void
     */
    private function handleFailedStatus($flightId, $errorCode, $status)
    {
        Flighttask::where('bid', $flightId)->update([
            'error_code' => $errorCode,
            'status' => $status
        ]);
    }

    /**
     * 处理正常状态更新
     * 
     * @param string $flightId 飞行任务ID
     * @param string $currentStatus 当前状态
     * @param int $currentWaypointIndex 当前航点索引
     * @param string $cachedStatus 缓存状态
     * @param int $cachedWaypointIndex 缓存航点索引
     * @return void
     */
    private function handleNormalStatusUpdate($flightId, $currentStatus, $currentWaypointIndex, $cachedStatus, $cachedWaypointIndex)
    {
        $updateData = [];
        $needUpdate = false;
        
        // ===== 优化方案：根据DJI官方文档，只有同时满足以下条件才认为任务真正完成 =====
        // 1. status = 'ok'
        // 2. progress.percent = 100 (如果有的话)
        // 3. wayline_mission_state = 9 (航线停止) 或 current_step >= 35 (通知任务结果)
        // 
        // 这样可以避免中间状态误判为完成
        
        // 状态和航点都发生变化
        if ($cachedStatus !== $currentStatus && $cachedWaypointIndex !== $currentWaypointIndex) {
            if ($currentStatus == 'ok') {
                $updateData['end_time'] = time();
            }
            $updateData['status'] = $currentStatus;
            $updateData['now_point'] = $currentWaypointIndex;
            $needUpdate = true;
        } else {
            // 仅状态发生变化
            if ($cachedStatus !== $currentStatus) {
                $updateData['status'] = $currentStatus;
                if ($currentStatus == 'ok') {
                    $updateData['end_time'] = time();
                }
                $needUpdate = true;
            }
            
            // 仅航点发生变化
            if ($cachedWaypointIndex !== $currentWaypointIndex) {
                $updateData['now_point'] = $currentWaypointIndex;
                $needUpdate = true;
            }
        }
        
        // 执行更新
        if ($needUpdate) {
            $result = Flighttask::where('bid', $flightId)->update($updateData);
            Log::info("【任务状态更新】FlightID={$flightId}, UpdateData=" . json_encode($updateData) . ", AffectedRows={$result}");
        } else {
            Log::info("【任务状态无变化】FlightID={$flightId}, CachedStatus={$cachedStatus}, CurrentStatus={$currentStatus}");
        }
    }
    
    /**
     * 智能完成检测 - 根据DJI官方文档的多条件判断
     * 
     * @param string $flightId 飞行任务ID
     * @param array $output MQTT输出数据
     * @return void
     */
    private function smartCompleteDetection($flightId, $output)
    {
        $status = $output['status'] ?? '';
        $progress = $output['progress'] ?? [];
        $ext = $output['ext'] ?? [];
        
        $currentStep = $progress['current_step'] ?? 0;
        $percent = $progress['percent'] ?? 0;
        $missionState = $ext['wayline_mission_state'] ?? null;
        
        // 查询当前任务状态
        $task = Flighttask::where('bid', $flightId)->find();
        if (!$task) {
            return;
        }
        
        // 如果已经是完成状态，跳过
        if (in_array($task['status'], ['ok', 'failed', 'canceled'])) {
            return;
        }
        
        $shouldComplete = false;
        $reason = '';
        
        // ===== 完成条件判断（按优先级） =====
        
        // 条件1：wayline_mission_state = 9 (航线停止) - 最可靠的完成标志
        if ($missionState == 9) {
            $shouldComplete = true;
            $reason = "航线任务状态=9(停止)";
        }
        // 条件2：current_step = 35 (通知任务结果) + status = 'ok' - DJI机场专用
        elseif ($currentStep == 35 && $status == 'ok') {
            $shouldComplete = true;
            $reason = "执行步骤=35(通知任务结果) + 状态=ok";
        }
        // 条件3：percent = 100 + status = 'ok' - 进度100%且状态ok
        elseif ($percent == 100 && $status == 'ok') {
            $shouldComplete = true;
            $reason = "进度=100% + 状态=ok";
        }
        // 条件4：percent >= 95 + status = 'ok' + current_step >= 33 (获取媒体文件数量)
        // 这是一个兜底条件，适用于任务实际已完成但状态上报不完整的情况
        elseif ($percent >= 95 && $status == 'ok' && $currentStep >= 33) {
            $shouldComplete = true;
            $reason = "进度≥95% + 状态=ok + 步骤≥33(兜底判断)";
        }
        
        // 执行强制完成
        if ($shouldComplete) {
            $updateData = [
                'status' => 'ok',
                'end_time' => time(),
                'update_time' => time()
            ];
            
            $result = Flighttask::where('bid', $flightId)->update($updateData);
            
            Log::info("【智能完成检测】FlightID={$flightId}, 触发条件: {$reason}, " .
                     "MissionState={$missionState}, Step={$currentStep}, Percent={$percent}, " .
                     "更新结果: " . ($result ? '成功' : '失败'));
        }
    }

    public function storageConfigReady($param)
    {
        // 使用统一的 Storage 类获取配置
        $storageConfig = \ba\Storage::getDjiStorageConfig($param['sn']);
        
        Log::info("【存储配置】Provider: {$storageConfig['provider']}, Bucket: {$storageConfig['bucket']}");
        
        $data = [];
        $data['topic'] = 'thing/product/' . $param['sn'] . '/requests_reply';
        $data['bid'] = $param['bid'];
        $data['tid'] = $param['tid'];
        $data['timestamp'] = round(microtime(true) * 1000);
        $data['method'] = 'storage_config_get';
        $data['data'] = [
            'output' => $storageConfig,
            'result' => 0
        ];
        publish($data);
    }
    
    /**
     * 处理设备配置请求 - 返回DJI配置信息
     * 
     * @param array $param MQTT请求参数
     * @return bool 是否成功发布配置
    */
    public function configReady($param)
    {
        try {
            // 从.env读取DJI配置参数（注意：env key 需要与.env文件中的大小写一致）
            $ntpServerHost = env('DJI.NTP_SERVER_HOST', 'ntp.aliyun.com');
            $ntpServerPort = env('DJI.NTP_SERVER_PORT', 123);
            $appId = env('DJI.APP_ID', '');
            $appKey = env('DJI.APP_KEY', '');
            $appLicense = env('DJI.APP_LICENSE', '');
            
            // 构建响应数据 - 按照官方文档格式，配置直接放在data里
            $data = [
                'bid' => $param['bid'],
                'tid' => $param['tid'],
                'timestamp' => round(microtime(true) * 1000),
                'method' => 'config',
                'topic' => 'thing/product/' . $param['sn'] . '/requests_reply',
                'gateway' => $param['sn'],
                'data' => [
                    'ntp_server_host' => $ntpServerHost,
                    'ntp_server_port' => (int)$ntpServerPort,
                    'app_id' => $appId,
                    'app_key' => $appKey,
                    'app_license' => $appLicense
                ]
            ];
            
            // 记录日志
            Log::info("【配置下发】设备 {$param['sn']} 请求配置, app_id=" . ($appId ?: '空'));
            
            // 发布MQTT消息
            $result = publish($data);
            
            if ($result) {
                Log::info("【配置下发成功】设备 {$param['sn']} 配置已下发");
                return true;
            } else {
                Log::error("【配置下发失败】设备 {$param['sn']} MQTT发布失败");
                return false;
            }
            
        } catch (\Exception $e) {
            Log::error("【配置下发异常】设备 {$param['sn']}: " . $e->getMessage());
            return false;
        }
    }

    // public function storageConfigReady($param)
    // {
    //     // $alists = new Alists('djiapi');
    //     // $sts = $alists->sts();
    //     $data = [];
    //     $data['topic'] = 'thing/product/' . $param['sn'] . '/requests_reply';
    //     $data['bid'] = $param['bid'];
    //     $data['tid'] = $param['tid'];
    //     $data['timestamp'] = round(microtime(true) * 1000);
    //     $data['method'] = 'storage_config_get';
    //     $data['data'] = [
    //         'output' => [],
    //         'result' => 0
    //     ];
    //     $data['data']['output']['bucket'] = 'djiapi';
    //     $data['data']['output']['credentials'] = [];
    //     $data['data']['output']['credentials']['access_key_id'] = 'hPPB3l7LNKmgNlPq5vKM';
    //     $data['data']['output']['credentials']['access_key_secret'] = 'vxGvXudIYAVKSdYWBNjxTfL34vFz3yH2U8CZ9KL5';
    //     $data['data']['output']['credentials']['expire'] = 3600;
    //     $data['data']['output']['endpoint'] = 'http://minio.chuangxing.ren';
    //     $data['data']['output']['object_key_prefix'] = $param['sn'];
    //     $data['data']['output']['provider'] = 'minio';
    //     $data['data']['output']['region'] = 'cn-chengdu';
    //     publish($data);
    // }

    /**
     * 文件上传回调处理函数 - 将上传文件信息入库
     * @param array $param 回调参数
     * @return array 处理结果
     */
    public function file_upload_callback($param)
    {
        // ===== 调试日志：记录原始MQTT消息 =====
        Log::info("【文件上传回调】收到MQTT消息: " . json_encode($param, JSON_UNESCAPED_UNICODE));
        
        // 参数验证
        if (!isset($param) || !is_array($param) || !isset($param['data']['file'])) {
            Log::write('file_upload_callback: 参数无效或文件信息缺失', 'warning');
            return ['status' => false, 'code' => -1, 'msg' => '参数无效或文件信息缺失'];
        }

        try {
            // 提取文件信息
            $fileInfo = $param['data']['file'];
            $extInfo = isset($fileInfo['ext']) ? $fileInfo['ext'] : [];
            $metadata = isset($fileInfo['metadata']) ? $fileInfo['metadata'] : [];
            $shootPosition = isset($metadata['shoot_position']) ? $metadata['shoot_position'] : [];
            $flightTask = isset($param['data']['flight_task']) ? $param['data']['flight_task'] : [];
            
            // 提取flight_id并记录日志
            $flightId = isset($extInfo['flight_id']) ? $extInfo['flight_id'] : '';
            Log::write("【文件上传回调】接收到文件上传回调, flight_id={$flightId}, file={$fileInfo['name']}, expected_count=" . ($flightTask['expected_file_count'] ?? 'null') . ", uploaded_count=" . ($flightTask['uploaded_file_count'] ?? 'null'), 'info');
            
            // 通过文件名后缀判断文件类型：0=图片,1=视频,2=其它
            $fileType = 2; // 默认类型为其它
            $fileName = isset($fileInfo['name']) ? $fileInfo['name'] : '';
            if (!empty($fileName)) {
                $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                // 图片类型后缀
                $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'];
                // 视频类型后缀
                $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm'];
                
                if (in_array($extension, $imageExtensions)) {
                    $fileType = 0; // 图片
                } elseif (in_array($extension, $videoExtensions)) {
                    $fileType = 1; // 视频
                }
            }
            
            // 组装入库数据
            $data = [
                'type' => $fileType, // 文件类型:0=图片,1=视频,2=其它
            
                'sn' => isset($param['gateway']) ? $param['gateway'] : '', // 使用gateway作为sn
                'name' => isset($fileInfo['name']) ? $fileInfo['name'] : '',
                'object_key' => isset($fileInfo['object_key']) ? $fileInfo['object_key'] : '',
                'path' => isset($fileInfo['path']) ? $fileInfo['path'] : '',
                'flight_id' => $flightId,
                'drone_model_key' => isset($extInfo['drone_model_key']) ? $extInfo['drone_model_key'] : '',
                'payload_model_key' => isset($extInfo['payload_model_key']) ? $extInfo['payload_model_key'] : '',
                'is_original' => isset($extInfo['is_original']) && $extInfo['is_original'] ? '1' : '0',
                // ===== 修复:处理空值和0值的情况 =====
                // 对于数值类型,0是有效值,但空字符串需要转为null
                'gimbal_yaw_degree' => isset($metadata['gimbal_yaw_degree']) && $metadata['gimbal_yaw_degree'] !== '' 
                    ? round($metadata['gimbal_yaw_degree'], 2) : null,
                'absolute_altitude' => isset($metadata['absolute_altitude']) && $metadata['absolute_altitude'] !== '' 
                    ? round($metadata['absolute_altitude'], 3) : null,
                'relative_altitude' => isset($metadata['relative_altitude']) && $metadata['relative_altitude'] !== '' 
                    ? round($metadata['relative_altitude'], 3) : null,
                // created_time为空字符串时转为null
                'c_time' => (isset($metadata['created_time']) && $metadata['created_time'] !== '') 
                    ? $metadata['created_time'] : null,
                // 经纬度为0时转为null (0,0坐标无意义)
                'lat' => (isset($shootPosition['lat']) && $shootPosition['lat'] != 0) 
                    ? $shootPosition['lat'] : null,
                'lng' => (isset($shootPosition['lng']) && $shootPosition['lng'] != 0) 
                    ? $shootPosition['lng'] : null,
                'create_time' => time()
            ];
            
            // ===== 调试日志：记录准备入库的数据 =====
            Log::info("【文件入库准备】flight_id={$flightId}, 入库数据: " . json_encode($data, JSON_UNESCAPED_UNICODE));

            // 插入数据库
            try {
                $result = Db::table('nz_media')->insert($data);
                
                if ($result) {
                    Log::write("【文件入库成功】flight_id={$flightId}, file={$fileName}, type={$fileType}, insert_result={$result}", 'info');
                    
                    // 更新对应飞行任务的已上传媒体文件数量
                    $this->updateFlightTaskMediaCount($flightId);
                    
                    return ['status' => true, 'code' => 0, 'msg' => '文件信息入库成功'];
                } else {
                    Log::write("【文件入库失败】flight_id={$flightId}, file={$fileName}, result=" . var_export($result, true), 'error');
                    return ['status' => false, 'code' => -2, 'msg' => '文件信息入库失败'];
                }
            } catch (\Exception $dbException) {
                Log::write("【文件入库异常】flight_id={$flightId}, file={$fileName}, error=" . $dbException->getMessage() . ", trace=" . $dbException->getTraceAsString(), 'error');
                return ['status' => false, 'code' => -2, 'msg' => '文件信息入库异常: ' . $dbException->getMessage()];
            }
        } catch (\Exception $e) {
            Log::write('file_upload_callback异常: ' . $e->getMessage() . ', trace: ' . $e->getTraceAsString(), 'error');
            return ['status' => false, 'code' => -3, 'msg' => '处理异常: ' . $e->getMessage()];
        }
    }

    /**
     * 更新飞行任务的已上传媒体文件数量
     * 自动处理media_total和media_now的同步更新
     * 
     * @param string $flightId 飞行任务ID (bid)
     * @return void                                                                                                                                                                                                               
     */
    private function updateFlightTaskMediaCount($flightId)
    {
        if (empty($flightId)) {
            Log::write('更新媒体数量失败: flight_id为空', 'warning');
            return;
        }

        try {
            // 统计该飞行任务已上传的媒体文件数量
            $mediaCount = Db::table('nz_media')
                ->where('flight_id', $flightId)
                ->count();

            Log::write("统计媒体文件数量: flight_id={$flightId}, count={$mediaCount}", 'info');

            // 获取当前任务信息
            $task = Flighttask::where('bid', $flightId)->find();
            
            if (!$task) {
                Log::write("任务不存在: bid={$flightId}", 'error');
                return;
            }

            // 准备更新数据
            $updateData = [
                'media_now' => $mediaCount,
                'update_time' => time()
            ];

            // 关键逻辑：如果media_now超过了media_total，同步更新media_total
            // 适用场景：
            // 1. 手动飞行任务（不确定会拍多少张照片）
            // 2. 航线任务中途增加拍照
            // 3. 其他未知文件类型（如.MRK标记文件）
            if ($mediaCount > $task->media_total) {
                $updateData['media_total'] = $mediaCount;
                Log::write("media_now({$mediaCount}) 超过 media_total({$task->media_total})，同步更新 media_total", 'info');
            }

            // 执行更新
            $updateResult = Flighttask::where('bid', $flightId)
                ->update($updateData);

            if ($updateResult !== false) {
                $logMsg = "更新任务媒体数量成功: bid={$flightId}, media_now={$mediaCount}";
                if (isset($updateData['media_total'])) {
                    $logMsg .= ", media_total={$mediaCount} (已同步更新)";
                }
                $logMsg .= ", affected_rows={$updateResult}";
                Log::write($logMsg, 'info');
            } else {
                Log::write("更新任务媒体数量失败: bid={$flightId}", 'warning');
            }

        } catch (\Exception $e) {
            // 记录详细的错误日志
            Log::write('更新飞行任务媒体文件数量异常: flight_id=' . $flightId . ', error=' . $e->getMessage() . ', trace=' . $e->getTraceAsString(), 'error');
        }
    }
    
    /**
     * 处理媒体下载追踪消息
     * 根据track消息更新媒体文件的大小信息
     * 
     * @param array $param MQTT消息参数
     * @return void
     */
    public function media_download_track($param)
    {
        Log::info("【媒体下载追踪】收到track消息: " . json_encode($param, JSON_UNESCAPED_UNICODE));
        
        // 参数验证
        if (!isset($param['data']['list']) || !is_array($param['data']['list'])) {
            Log::warning("【媒体下载追踪】消息格式错误,缺少list数组");
            return;
        }
        
        $updateCount = 0;
        $skipCount = 0;
        $notFoundCount = 0;
        
        foreach ($param['data']['list'] as $item) {
            // 只处理media_download_track类型
            if (!isset($item['type']) || $item['type'] !== 'media_download_track') {
                continue;
            }
            
            $properties = $item['properties'] ?? [];
            $flightId = $properties['flight_id'] ?? '';
            $fileName = $properties['name'] ?? '';
            $fileSize = $properties['size'] ?? 0;
            
            // 验证必要字段
            if (empty($flightId) || empty($fileName)) {
                Log::warning("【媒体下载追踪】跳过无效数据,缺少flight_id或name: " . json_encode($properties, JSON_UNESCAPED_UNICODE));
                continue;
            }
            
            try {
                // 查询是否存在该文件记录
                $media = Db::table('nz_media')
                    ->where('flight_id', $flightId)
                    ->where('name', $fileName)
                    ->find();
                
                if (!$media) {
                    Log::warning("【媒体下载追踪】文件未在数据库中: flight_id={$flightId}, name={$fileName}");
                    $notFoundCount++;
                    continue;
                }
                
                // 如果size已存在且非0,跳过更新(只更新一次)
                if (isset($media['size']) && $media['size'] > 0) {
                    Log::info("【媒体下载追踪】文件size已存在,跳过更新: {$fileName}, size={$media['size']}");
                    $skipCount++;
                    continue;
                }
                
                // 更新文件大小
                $result = Db::table('nz_media')
                    ->where('flight_id', $flightId)
                    ->where('name', $fileName)
                    ->update(['size' => $fileSize]);
                
                if ($result !== false) {
                    Log::info("【媒体下载追踪】更新文件大小成功: flight_id={$flightId}, name={$fileName}, size={$fileSize}");
                    $updateCount++;
                } else {
                    Log::warning("【媒体下载追踪】更新文件大小失败: flight_id={$flightId}, name={$fileName}");
                }
                
            } catch (\Exception $e) {
                Log::error("【媒体下载追踪】更新异常: flight_id={$flightId}, name={$fileName}, error=" . $e->getMessage());
            }
        }
        
        Log::info("【媒体下载追踪】处理完成: 更新{$updateCount}条, 跳过{$skipCount}条, 未找到{$notFoundCount}条");
    }
    
    /**
     * 验证航线与飞行器兼容性
     * 
     * @param int $airlineId 航线ID
     * @param int $equipmentId 设备ID（机场）
     * @return array ['status' => bool, 'code' => int, 'msg' => string]
     */
    private function validateAircraftCompatibility($airlineId, $equipmentId)
    {
        try {
            // 查询航线信息
            $airline = ModelAirline::find($airlineId);
            if (!$airline) {
                return ['status' => false, 'code' => 1009, 'msg' => '航线不存在'];
            }
            
            // 通用航线（drone_model_key为空），跳过验证
            if (empty($airline['drone_model_key'])) {
                Log::info("【兼容性验证】航线 {$airline['name']} 为通用航线，跳过验证");
                return ['status' => true];
            }
            
            // 查询机场设备
            $equipment = Equipment::find($equipmentId);
            if (!$equipment || $equipment['domain'] != 3) {
                return ['status' => false, 'code' => 1010, 'msg' => '设备不存在或非机场类型'];
            }
            
            // 获取机场绑定的飞行器
            $drone = $this->getDockAircraft($equipment['sn']);
            if (!$drone) {
                Log::warning("【兼容性验证】未找到机场 {$equipment['sn']} 的飞行器信息");
                return [
                    'status' => false, 
                    'code' => 1011, 
                    'msg' => '未找到机场绑定的飞行器，请确认设备在线并已绑定飞行器'
                ];
            }
            
            // 生成飞行器model_key
            $droneModelKey = sprintf('%d-%d-%d', 
                $drone['domain'], 
                $drone['type_code'], 
                $drone['sub_type']
            );
            
            // 验证是否匹配
            if ($droneModelKey !== $airline['drone_model_key']) {
                $productConfig = config('dji_products');
                $getProductInfo = $productConfig['get_product_info'] ?? null;
                
                $requiredAircraft = is_callable($getProductInfo) ? 
                    $getProductInfo($airline['drone_model_key']) : null;
                $currentAircraft = is_callable($getProductInfo) ? 
                    $getProductInfo($droneModelKey) : null;
                
                $errorMsg = sprintf(
                    '航线不适配当前飞行器！要求: %s (%s), 实际: %s (%s)',
                    $requiredAircraft['name'] ?? '未知型号',
                    $airline['drone_model_key'],
                    $currentAircraft['name'] ?? '未知型号',
                    $droneModelKey
                );
                
                Log::warning("【兼容性验证失败】{$errorMsg}");
                return ['status' => false, 'code' => 1012, 'msg' => $errorMsg];
            }
            
            Log::info("【兼容性验证成功】航线 {$airline['name']} 与飞行器 {$droneModelKey} 完全匹配");
            return ['status' => true];
            
        } catch (\Exception $e) {
            Log::error("【兼容性验证异常】" . $e->getMessage());
            return ['status' => false, 'code' => 1013, 'msg' => '兼容性验证异常: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取机场绑定的飞行器设备
     * 
     * @param string $dockSn 机场序列号
     * @return object|null 飞行器设备对象
     */
    private function getDockAircraft($dockSn)
    {
        $drone = '';
        try {
            // 方法1：通过最新OSD数据获取子设备
            // $osdData = Db::table('nz_osd')
            //     ->where('gateway_sn', $dockSn)
            //     ->where('sub_device_sn', '<>', '')
            //     ->order('create_time', 'desc')
            //     ->find();
            
            // if ($osdData && !empty($osdData['sub_device_sn'])) {
            //     $drone = Equipment::where('sn', $osdData['sub_device_sn'])->find();
            //     if ($drone) {
            //         Log::info("【获取飞行器】通过OSD数据找到飞行器: {$osdData['sub_device_sn']}");
            //         return $drone;
            //     }
            // }
            
            // 方法2：通过parent_id关系查询
            $dock = Equipment::where('sn', $dockSn)->find();
            // print_r($dock);
            if ($dock) {
                $drone = Equipment::where('parent_id', $dock['id'])
                    ->where('domain', 0)
                    ->find();
                // print_r($drone->toArray());
                if ($drone) {
                    Log::info("【获取飞行器】通过parent_id找到飞行器: {$drone['sn']}");
                    
                }
            }

        } catch (\Exception $e) {
            Log::error("【获取飞行器】异常: " . $e->getMessage());
            return null;
        }
        return $drone;
    }
}
