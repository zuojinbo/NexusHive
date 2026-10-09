<?php

namespace dji;

use think\facade\Db;
use think\facade\Log;

/**
 * 航线断点处理器
 * 
 * 功能:
 * 1. 记录断点事件
 * 2. 关联错误代码和描述
 * 3. 支持续飞追踪
 * 4. 更新轨迹主表的断点标记
 * 
 * @package dji
 */
class BreakpointHandler
{
    /**
     * 记录断点信息
     * 触发时机: flighttask_progress 包含 break_point 字段
     * 
     * @param string $trackId 轨迹ID
     * @param string $flightId 任务ID
     * @param array $breakPointData 断点数据
     * @return bool
     */
    public static function recordBreakpoint($trackId, $flightId, $breakPointData)
    {
        if (empty($trackId) || empty($flightId) || empty($breakPointData)) {
            Log::warning("【断点记录】参数缺失");
            return false;
        }
        
        try {
            // 获取断点原因描述
            $breakReason = $breakPointData['break_reason'] ?? 0;
            $reasonDesc = self::getBreakReasonDescription($breakReason);
            $reasonCategory = self::getBreakReasonCategory($breakReason);
            
            // 插入断点记录
            Db::name('flight_breakpoint')->insert([
                'flight_id' => $flightId,
                'track_id' => $trackId,
                'break_index' => $breakPointData['index'] ?? null,
                'break_state' => $breakPointData['state'] ?? null,
                'break_progress' => $breakPointData['progress'] ?? null,
                'break_reason' => $breakReason,
                'break_reason_desc' => $reasonDesc,
                'break_reason_category' => $reasonCategory,
                'latitude' => $breakPointData['latitude'] ?? null,
                'longitude' => $breakPointData['longitude'] ?? null,
                'height' => $breakPointData['height'] ?? null,
                'attitude_head' => $breakPointData['attitude_head'] ?? null,
                'wayline_id' => $breakPointData['wayline_id'] ?? null,
                'break_time' => self::getMilliTimestamp(),
                'create_time' => self::getMilliTimestamp()
            ]);
            
            // 更新轨迹主表的断点标记
            $track = Db::name('flight_track')->where('track_id', $trackId)->find();
            if ($track) {
                Db::name('flight_track')
                    ->where('track_id', $trackId)
                    ->update([
                        'has_breakpoint' => 1,
                        'breakpoint_count' => ($track['breakpoint_count'] ?? 0) + 1,
                        'update_time' => self::getMilliTimestamp()
                    ]);
            }
            
            Log::info("【断点记录】记录成功: trackId={$trackId}, reason={$breakReason}, desc={$reasonDesc}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("【断点记录】记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 标记断点已续飞
     * 
     * @param string $trackId 原轨迹ID
     * @param string $newTrackId 续飞后的新轨迹ID
     * @return bool
     */
    public static function markResumed($trackId, $newTrackId)
    {
        try {
            // 查找该轨迹最后一个未续飞的断点
            $breakpoint = Db::name('flight_breakpoint')
                ->where('track_id', $trackId)
                ->where('is_resumed', 0)
                ->order('break_time', 'desc')
                ->find();
            
            if ($breakpoint) {
                Db::name('flight_breakpoint')
                    ->where('id', $breakpoint['id'])
                    ->update([
                        'is_resumed' => 1,
                        'resume_time' => self::getMilliTimestamp(),
                        'resume_track_id' => $newTrackId
                    ]);
                
                Log::info("【断点记录】标记续飞: oldTrackId={$trackId}, newTrackId={$newTrackId}");
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error("【断点记录】标记续飞失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取断点原因描述(从配置文件或数据库)
     * 
     * @param int $reasonCode 原因代码
     * @return string
     */
    private static function getBreakReasonDescription($reasonCode)
    {
        // 优先从数据库字典表查询
        $dict = Db::name('breakpoint_reason_dict')
            ->where('reason_code', $reasonCode)
            ->find();
        
        if ($dict) {
            return $dict['reason_desc'];
        }
        
        // 备用: 从配置文件查询
        $errorCodes = config('dji_error_codes');
        if ($errorCodes && isset($errorCodes[$reasonCode])) {
            return $errorCodes[$reasonCode];
        }
        
        // 常见断点原因映射(内置)
        $reasons = [
            0 => '无异常',
            773 => '低电量返航导致航线中断',
            784 => '大风返航导致航线中断',
            518 => 'RTK信号差',
            517 => '飞行器触发避障',
            769 => 'GPS信号弱',
            1281 => '用户主动退出',
            1282 => '用户主动中断',
            1283 => '用户触发返航',
            513 => '飞行器超过限高高度',
            514 => '飞行器超过限远距离',
            515 => '航线穿过限飞区',
            775 => '遥控器与飞行器失联'
        ];
        
        return $reasons[$reasonCode] ?? "未知中断原因(代码:{$reasonCode})";
    }
    
    /**
     * 获取断点原因分类
     * 
     * @param int $reasonCode 原因代码
     * @return string
     */
    private static function getBreakReasonCategory($reasonCode)
    {
        // 用户操作类(1281-1283)
        if ($reasonCode >= 1281 && $reasonCode <= 1283) {
            return 'user';
        }
        
        // 环境因素类(769, 773, 784, 518, 517)
        $environmentCodes = [769, 773, 784, 518, 517, 519, 529];
        if (in_array($reasonCode, $environmentCodes)) {
            return 'environment';
        }
        
        // 系统错误类(513-523)
        if ($reasonCode >= 513 && $reasonCode <= 523) {
            return 'system';
        }
        
        // 配置错误类(1539-1614)
        if ($reasonCode >= 1539 && $reasonCode <= 1614) {
            return 'error';
        }
        
        // 无异常
        if ($reasonCode == 0) {
            return 'system';
        }
        
        return 'unknown';
    }
    
    /**
     * 获取轨迹的所有断点
     * 
     * @param string $trackId 轨迹ID
     * @return array
     */
    public static function getBreakpoints($trackId)
    {
        try {
            return Db::name('flight_breakpoint')
                ->where('track_id', $trackId)
                ->order('break_time', 'asc')
                ->select()
                ->toArray();
        } catch (\Exception $e) {
            Log::error("【断点记录】查询失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 统计断点原因分布
     * 
     * @param int $startTime 开始时间(毫秒)
     * @param int $endTime 结束时间(毫秒)
     * @param int $limit 返回数量
     * @return array
     */
    public static function getBreakReasonStatistics($startTime = null, $endTime = null, $limit = 10)
    {
        try {
            $query = Db::name('flight_breakpoint')
                ->field('break_reason, break_reason_desc, break_reason_category, count(*) as count')
                ->group('break_reason');
            
            if ($startTime) {
                $query->where('break_time', '>=', $startTime);
            }
            if ($endTime) {
                $query->where('break_time', '<=', $endTime);
            }
            
            return $query->order('count', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();
                
        } catch (\Exception $e) {
            Log::error("【断点统计】查询失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取毫秒时间戳
     * 
     * @return int
     */
    private static function getMilliTimestamp()
    {
        return intval(microtime(true) * 1000);
    }
}
