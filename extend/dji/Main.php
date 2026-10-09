<?php

namespace dji;

use think\facade\Log;
use think\facade\Db;
use dji\Log as DjiLog;
use dji\TrackRecorder;
use dji\BreakpointHandler;
use dji\ReturnHomeHandler;
use dji\handler\OtaProgressHandler;

class Main
{
    protected $airline = null;
    protected $hms = null;
    protected $osd = null;
    protected $organization = null;


    public function __construct()
    {
        $this->airline = new Airline();
        $this->hms = new Hms();
        $this->osd = new Osd();
        $this->organization = new Organization();
    }

    public function responseEvents($param)
    {
        // if (isset($param['method']) && $param['method'] == 'flighttask_ready') {
        //     $this->airline->flighttaskReady($param);
        // }
        
        // 处理文件上传回调 - 必须在need_reply之前处理
        if (isset($param['method']) && $param['method'] == 'file_upload_callback') {
            Log::info("【MQTT消息路由】检测到 file_upload_callback 消息, gateway=" . ($param['gateway'] ?? 'null') . ", timestamp=" . ($param['timestamp'] ?? 'null'));
            $result = $this->airline->file_upload_callback($param);
            Log::info("【MQTT消息路由】file_upload_callback 处理结果: " . json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        
        // 处理媒体下载追踪消息 - 更新文件大小
        if (isset($param['method']) && $param['method'] == 'track') {
            Log::info("【MQTT消息路由】检测到 track 消息, gateway=" . ($param['gateway'] ?? 'null'));
            $this->airline->media_download_track($param);
        }
        
        if (isset($param['method']) && $param['method'] == 'hms') {
            $this->hms->save($param);
        }
        
        // 处理 OTA 固件升级进度
        if (isset($param['method']) && $param['method'] == 'ota_progress') {
            Log::info("【MQTT消息路由】检测到 ota_progress 消息, sn=" . ($param['sn'] ?? 'unknown'));
            $otaHandler = new OtaProgressHandler();
            $otaHandler->handle($param['sn'] ?? '', $param);
        }
        
        if (isset($param['method']) && $param['method'] == 'flighttask_progress') {
            // ========== 轨迹审计: 处理航线进度 ==========
            $this->handleFlightTaskProgress($param);
            
            // 保留原有逻辑
            $this->airline->flighttask_progress($param);
        }
        
        // ========== 轨迹审计: 处理返航信息 ==========
        if (isset($param['method']) && $param['method'] == 'return_home_info') {
            ReturnHomeHandler::recordReturnHome($param);
        }
        
        // 统一回复处理 - 放在最后
        if (isset($param['need_reply']) && $param['need_reply'] == 1) {
            $other = [];
            $other['result'] = 0;
            $this->eventReply($param, $other);
        }
    }

    public function responseOsd($param)
    {
        // print_r($param);
        if (isset($param['data']['best_link_gateway'])) {
            $this->osd->osdSave($param);
        }
        
        // ========== 轨迹审计: 记录OSD轨迹点 ==========
        $this->handleOsdForTrack($param);
        
        // ========== 子设备自动绑定逻辑 ==========
        // 当OSD消息包含子设备信息时，自动处理子设备绑定
        if (isset($param['data']['sub_device']['device_sn'])) {
            $this->organization->handleSubDeviceBind($param);
        }
    }

    public function responseStatus($param)
    {
        $this->topoLog('收到status消息: method=' . ($param['method'] ?? 'unknown') . ', sn=' . ($param['sn'] ?? 'unknown') . ', tid=' . ($param['tid'] ?? 'unknown') . ', bid=' . ($param['bid'] ?? 'unknown'));
        
        if (isset($param['method']) && $param['method'] == 'update_topo') {
            $startTime = microtime(true);
            
            $other = [];
            $other['result'] = 0;
            
            // 立即回复，确保在2秒内响应
            $result = $this->osd->statusReady($param, $other);
            
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            $this->topoLog('update_topo回复完成: sn=' . ($param['sn'] ?? 'unknown') . ', 耗时=' . $elapsed . 'ms, 结果=' . ($result ? 'success' : 'failed'));
            
            // 如果回复失败，尝试重试一次
            if (!$result) {
                $this->topoLog('首次回复失败，尝试重试: sn=' . ($param['sn'] ?? 'unknown'));
                usleep(100000); // 等待100ms后重试
                $result = $this->osd->statusReady($param, $other);
                $this->topoLog('重试结果: ' . ($result ? 'success' : 'failed'));
            }
        }
    }
    
    /**
     * 拓扑更新专用日志 - 直接写文件确保Worker进程能记录
     */
    protected function topoLog($message)
    {
        $logFile = runtime_path() . 'topo_update.log';
        $time = date('Y-m-d H:i:s');
        $content = "[{$time}] {$message}\n";
        file_put_contents($logFile, $content, FILE_APPEND | LOCK_EX);
    }


    public function responseRequest($param)
    {
        $this->topoLog('收到requests消息: method=' . ($param['method'] ?? 'unknown') . ', sn=' . ($param['sn'] ?? 'unknown') . ', tid=' . ($param['tid'] ?? 'unknown'));
        
        if (isset($param['method']) && $param['method'] == 'flighttask_resource_get') {
            $this->airline->resourceReady($param);
        }
        if (isset($param['method']) && $param['method'] == 'storage_config_get') {
            if ($param['data'] && $param['data']['module'] < 1) {
                $this->airline->storageConfigReady($param);
            }
        }
        
        // ===== 配置更新请求处理 =====
        if (isset($param['method']) && $param['method'] == 'config') {
            $this->topoLog('处理config请求: sn=' . ($param['sn'] ?? 'unknown'));
            
            // 记录读取到的DJI配置
            $appId = env('DJI.APP_ID', '');
            $appLicense = env('DJI.APP_LICENSE', '');
            $this->topoLog('DJI配置: app_id=' . ($appId ?: '空') . ', app_license长度=' . strlen($appLicense));
            
            $result = $this->airline->configReady($param);
            $this->topoLog('config请求处理结果: ' . ($result ? 'success' : 'failed'));
        }
        
        // ===== 组织绑定相关处理 =====
        if (isset($param['method']) && $param['method'] == 'airport_bind_status') {
            $this->topoLog('处理airport_bind_status请求: sn=' . ($param['sn'] ?? 'unknown'));
            $result = $this->organization->handleBindStatusRequest($param);
            $this->topoLog('airport_bind_status请求处理结果: ' . ($result ? 'success' : 'failed'));
        }
        if (isset($param['method']) && $param['method'] == 'airport_organization_get') {
            $this->topoLog('处理airport_organization_get请求: sn=' . ($param['sn'] ?? 'unknown'));
            $result = $this->organization->handleOrganizationGetRequest($param);
            $this->topoLog('airport_organization_get请求处理结果: ' . ($result ? 'success' : 'failed'));
        }
        if (isset($param['method']) && $param['method'] == 'airport_organization_bind') {
            $this->topoLog('处理airport_organization_bind请求: sn=' . ($param['sn'] ?? 'unknown'));
            $result = $this->organization->handleOrganizationBindRequest($param);
            $this->topoLog('airport_organization_bind请求处理结果: ' . ($result ? 'success' : 'failed'));
        }
    }

    public function responseServicesReply($param)
    {
        if (isset($param['method']) && $param['method'] == 'fileupload_list') {
            $djilog = new DjiLog();
            $djilog->saveLog($param);
        }
        
        // 处理 flighttask_prepare 成功回复 - 触发 flighttask_execute
        if (isset($param['method']) && $param['method'] == 'flighttask_prepare') {
            $result = $param['data']['result'] ?? -1;
            
            if ($result === 0) {
                // 准备成功，触发执行
                Log::info("【航线下发】flighttask_prepare 成功，准备检查是否需要触发执行: bid=" . ($param['bid'] ?? 'unknown'));
                
                // 查找对应的任务并执行
                $task = \app\admin\model\Flighttask::where('bid', $param['bid'])->find();
                if ($task) {
                    // 更新状态为 sent（已下发，等待执行）
                    \app\admin\model\Flighttask::where('bid', $param['bid'])->update(['status' => 'sent']);
                    $shouldTriggerExecute = (int)$task['task_type'] === 0;
                    Log::info("【航线下发】任务已完成 prepare，任务详情: bid=" . $param['bid']
                        . ", task_type=" . $task['task_type']
                        . ", execute_time=" . ($task['execute_time'] ?? 'null')
                        . ", trigger_execute=" . ($shouldTriggerExecute ? 'yes' : 'no'));
                    
                    // 如果是立即执行任务（task_type=0），直接触发执行
                    if ($shouldTriggerExecute) {
                        Log::info("【航线下发】立即执行任务，触发 flighttask_execute: bid=" . $param['bid']);
                        $this->airline->flighttaskReady([$task->toArray()]);
                    } else {
                        Log::info("【航线下发】定时任务，等待定时器触发: bid=" . $param['bid'] . ", execute_time=" . $task['execute_time']);
                    }
                } else {
                    Log::error("【航线下发】任务不存在: bid=" . ($param['bid'] ?? 'unknown'));
                }
            } else {
                // 准备失败，记录错误
                Log::error("【航线下发】flighttask_prepare 失败: result={$result}, bid=" . ($param['bid'] ?? 'unknown'));
                $this->airline->flighttaskError($param);
            }
        }
        
        // 处理 flighttask_execute 错误回复
        if (isset($param['method']) && $param['method'] == 'flighttask_execute') {
            $result = $param['data']['result'] ?? -1;
            if ($result !== 0) {
                Log::error("【航线执行】flighttask_execute 失败: result={$result}, bid=" . ($param['bid'] ?? 'unknown'));
                $this->airline->flighttaskError($param);
            } else {
                Log::info("【航线执行】flighttask_execute 成功: bid=" . ($param['bid'] ?? 'unknown'));
            }
        }
    }

    protected function eventReply($param, $other)
    {
        $data = [];
        $data['bid'] = $param['bid'];
        $data['tid'] = $param['tid'];
        $data['timestamp'] = round(microtime(true) * 1000);
        $data['method'] = $param['method'];
        $data['topic'] = 'thing/product/' . $param['sn'] . '/events_reply';
        $data['data'] = $other;
        $result = publish($data);
        if ($result) {
            return true;
        } else {
            return false;
        }
    }
    
    // ============================================
    // 航线轨迹审计功能
    // ============================================
    
    /**
     * 处理航线任务进度(控制轨迹记录生命周期)
     * 
     * @param array $param 消息参数
     */
    protected function handleFlightTaskProgress($param)
    {
        $ext = $param['data']['output']['ext'] ?? [];
        $trackId = $ext['track_id'] ?? '';
        $flightId = $ext['flight_id'] ?? '';
        $waylineMissionState = $ext['wayline_mission_state'] ?? null;
        
        if (empty($trackId)) {
            return;
        }
        
        // 获取设备SN
        $gateway = $param['gateway'] ?? '';
        $dockSn = $gateway;
        
        // 根据航线状态控制轨迹记录
        switch ($waylineMissionState) {
            case 5: // 进入航线 → 开始记录
                TrackRecorder::startTrack($trackId, $flightId, '', $dockSn);
                break;
                
            case 6: // 执行中 → 确保活跃
            case 8: // 恢复 → 继续记录
                TrackRecorder::resumeTrack($trackId);
                break;
                
            case 7: // 中断 → 暂停记录
                TrackRecorder::pauseTrack($trackId);
                
                // 记录断点信息
                if (isset($ext['break_point'])) {
                    BreakpointHandler::recordBreakpoint($trackId, $flightId, $ext['break_point']);
                }
                break;
                
            case 9: // 停止 → 结束记录
                TrackRecorder::stopTrack($trackId);
                
                // 更新返航状态为完成
                ReturnHomeHandler::updateReturnStatus($flightId, 'completed');
                break;
        }
    }
    
    /**
     * 处理OSD消息,记录轨迹点
     * 
     * @param array $param 消息参数
     */
    protected function handleOsdForTrack($param)
    {
        $data = $param['data'] ?? [];
        $gateway = $param['gateway'] ?? '';
        
        if (empty($gateway)) {
            return;
        }
        
        // 方式1: 从 nz_flightrecord 查找当前活跃的 track_id
        try {
            $activeRecord = Db::name('flightrecord')
                ->where('sn', $gateway)
                ->whereIn('wayline_mission_state', [5, 6, 8]) // 进入航线、执行中、恢复
                ->order('update_time', 'desc')
                ->find();
            
            if (!$activeRecord || empty($activeRecord['track_id'])) {
                return; // 当前没有活跃航线
            }
            
            $trackId = $activeRecord['track_id'];
            $flightId = $activeRecord['flight_id'] ?? '';
            
            // 记录轨迹点
            TrackRecorder::recordOsdPoint($trackId, $data, $flightId);
            
        } catch (\Exception $e) {
            Log::error("【轨迹审计】处理OSD失败: " . $e->getMessage());
        }
    }
}
