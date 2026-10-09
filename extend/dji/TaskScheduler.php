<?php

namespace dji;

use think\facade\Db;
use think\facade\Log;
use app\admin\model\Flighttask;

/**
 * 航线任务调度器
 * 负责循环任务的自动下发
 */
class TaskScheduler
{
    /**
     * 扫描并执行到期的循环任务
     */
    public function scanAndExecute()
    {
        try {
            // 查询启用的循环任务
            $repeatTasks = Flighttask::where('task_type', '2')
                ->where('is_repeat_enabled', '1')
                ->select();
            
            Log::info("【循环任务扫描】共找到 " . count($repeatTasks) . " 个启用的循环任务");
            
            foreach ($repeatTasks as $task) {
                $this->checkAndExecuteRepeatTask($task);
            }
            
        } catch (\Exception $e) {
            Log::error("【循环任务扫描】异常: " . $e->getMessage());
        }
    }
    
    /**
     * 检查并执行循环任务
     */
    private function checkAndExecuteRepeatTask($task)
    {
        try {
            // 1. 检查是否到达结束日期
            if ($task['repeat_end_date'] && strtotime($task['repeat_end_date']) < time()) {
                $this->disableRepeatTask($task['id'], 'ended');
                return;
            }
            
            // 2. 检查是否达到最大执行次数
            if ($task['repeat_max_count'] && $task['repeat_count'] >= $task['repeat_max_count']) {
                $this->disableRepeatTask($task['id'], 'max_count');
                return;
            }
            
            // 3. 获取今天应该执行的时间点
            $todayExecuteTimes = $this->getTodayExecuteTimes($task);
            
            if (empty($todayExecuteTimes)) {
                return;
            }
            
            // 4. 检查是否有需要执行的时间点
            foreach ($todayExecuteTimes as $executeTime) {
                if ($this->shouldExecuteNow($executeTime, $task['id'])) {
                    $this->createAndExecuteChildTask($task, $executeTime);
                }
            }
            
        } catch (\Exception $e) {
            Log::error("【循环任务】检查执行异常 TaskID={$task['id']}: " . $e->getMessage());
        }
    }
    
    /**
     * 获取今天应该执行的时间点
     */
    private function getTodayExecuteTimes($task)
    {
        $config = json_decode($task['repeat_config'], true);
        if (!$config) {
            Log::warning("【循环任务】配置解析失败 TaskID={$task['id']}");
            return [];
        }
        
        $today = date('Y-m-d');
        $executeTimes = [];
        
        // 检查是否在开始日期之后
        if ($task['repeat_start_date'] && strtotime($task['repeat_start_date']) > strtotime($today)) {
            return [];
        }
        
        switch ($task['repeat_type']) {
            case 'daily':
                // 每天执行
                if (isset($config['times']) && is_array($config['times'])) {
                    foreach ($config['times'] as $time) {
                        $executeTimes[] = strtotime("{$today} {$time['hour']}:{$time['minute']}:00");
                    }
                }
                break;
                
            case 'weekly':
                // 每周执行
                $currentWeekday = (int)date('N'); // 1=周一, 7=周日
                if (isset($config['weekdays']) && in_array($currentWeekday, $config['weekdays'])) {
                    if (isset($config['times']) && is_array($config['times'])) {
                        foreach ($config['times'] as $time) {
                            $executeTimes[] = strtotime("{$today} {$time['hour']}:{$time['minute']}:00");
                        }
                    }
                }
                break;
                
            case 'monthly':
                // 每月执行
                $currentDay = (int)date('d');
                if (isset($config['days']) && in_array($currentDay, $config['days'])) {
                    if (isset($config['times']) && is_array($config['times'])) {
                        foreach ($config['times'] as $time) {
                            $executeTimes[] = strtotime("{$today} {$time['hour']}:{$time['minute']}:00");
                        }
                    }
                }
                break;
                
            case 'custom':
                // 自定义日期
                if (isset($config['dates']) && in_array($today, $config['dates'])) {
                    if (isset($config['times']) && is_array($config['times'])) {
                        foreach ($config['times'] as $time) {
                            $executeTimes[] = strtotime("{$today} {$time['hour']}:{$time['minute']}:00");
                        }
                    }
                }
                break;
        }
        
        return $executeTimes;
    }
    
    /**
     * 判断是否应该执行（在5分钟容错范围内）
     */
    private function shouldExecuteNow($executeTime, $parentTaskId)
    {
        $now = time();
        $diff = abs($now - $executeTime);
        
        // 5分钟容错范围
        if ($diff <= 300) {
            // 检查该时间点是否已经执行过（避免重复执行）
            $executed = Db::name('flighttask')
                ->where('parent_task_id', $parentTaskId)
                ->where('execute_time', $executeTime)
                ->where('create_time', '>', time() - 600) // 10分钟内
                ->count();
            
            return $executed == 0;
        }
        
        return false;
    }
    
    /**
     * 创建并执行子任务
     */
    private function createAndExecuteChildTask($parentTask, $executeTime)
    {
        Db::startTrans();
        try {
            // 生成UUID
            $bid = uuid();
            $tid = uuid();
            
            // ✅ 验证时间戳合法性
            $now = time();
            if ($executeTime < ($now - 600) || $executeTime > ($now + 86400)) {
                Log::warning("【循环任务】异常执行时间: " . date('Y-m-d H:i:s', $executeTime) . 
                           " (当前: " . date('Y-m-d H:i:s', $now) . "), 父任务={$parentTask['id']}");
            }
            
            // 创建子任务
            $childTask = [
                'parent_task_id' => $parentTask['id'],
                'name' => $parentTask['name'] . ' (自动-' . date('Y-m-d H:i', $executeTime) . ')',
                'bid' => $bid,
                'tid' => $tid,
                'airline_id' => $parentTask['airline_id'],
                'equipment_id' => $parentTask['equipment_id'],
                'execute_time' => $executeTime, // 秒级时间戳，Airline会自动转换为毫秒
                'task_type' => '1', // 子任务改为定时执行（按照计算的时间执行）
                'flight_mode' => $parentTask['flight_mode'],
                'file_url' => $parentTask['file_url'],
                'file_fingerprint' => $parentTask['file_fingerprint'],
                'rth_altitude' => $parentTask['rth_altitude'],
                'rth_mode' => $parentTask['rth_mode'],
                'out_of_control_action' => $parentTask['out_of_control_action'],
                'exit_wayline_when_rc_lost' => $parentTask['exit_wayline_when_rc_lost'],
                'wayline_precision_type' => $parentTask['wayline_precision_type'],
                'total_point' => $parentTask['total_point'],
                'admin_id' => $parentTask['admin_id'],
                'status' => 'sent',
                'create_time' => time(),
                'update_time' => time()
            ];
            
            $childTaskId = Db::name('flighttask')->insertGetId($childTask);
            
            // 下发任务
            $airline = new Airline();
            $childTask['id'] = $childTaskId; // 添加ID用于后续处理
            $result = $airline->pushTask($childTask);
            
            if ($result['status']) {
                // 更新父任务执行次数
                Db::name('flighttask')
                    ->where('id', $parentTask['id'])
                    ->inc('repeat_count')
                    ->update();
                
                Db::commit();
                Log::info("【循环任务】✅ 成功创建并下发子任务: ID={$childTaskId}, 父任务={$parentTask['id']}, " .
                         "执行时间=" . date('Y-m-d H:i:s', $executeTime) . " (时间戳: {$executeTime})");
            } else {
                Db::rollback();
                Log::error("【循环任务】❌ 下发子任务失败: " . $result['msg'] . ", 父任务={$parentTask['id']}, " .
                          "执行时间=" . date('Y-m-d H:i:s', $executeTime));
            }
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("【循环任务】创建子任务异常: " . $e->getMessage() . ", 父任务={$parentTask['id']}");
        }
    }
    
    /**
     * 禁用循环任务
     */
    private function disableRepeatTask($taskId, $reason)
    {
        try {
            Db::name('flighttask')
                ->where('id', $taskId)
                ->update([
                    'is_repeat_enabled' => '0',
                    'update_time' => time(),
                    'error_msg' => "循环任务已结束: {$reason}"
                ]);
            
            Log::info("【循环任务】任务已禁用: ID={$taskId}, 原因={$reason}");
            
        } catch (\Exception $e) {
            Log::error("【循环任务】禁用任务异常: " . $e->getMessage());
        }
    }
}
