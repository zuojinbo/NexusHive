<?php

namespace dji;

use think\facade\Db;
use think\facade\Cache;
use think\facade\Log;

/**
 * 航线轨迹记录器
 * 
 * 功能:
 * 1. 管理轨迹生命周期(开始/暂停/恢复/停止)
 * 2. 记录OSD轨迹点(批量写入优化)
 * 3. 计算轨迹统计数据
 * 4. 活跃轨迹状态管理
 * 
 * @package dji
 */
class TrackRecorder
{
    // Redis缓存键前缀
    const CACHE_PREFIX = 'flight_track:';
    const ACTIVE_TRACKS_KEY = 'flight_track:active_list'; // 活跃轨迹列表
    const POINTS_BUFFER_SIZE = 10; // 缓冲区大小(10条刷盘)
    const BUFFER_FLUSH_INTERVAL = 5; // 刷盘间隔(秒)
    
    /**
     * 开始记录轨迹
     * 触发时机: flighttask_progress (wayline_mission_state=5)
     * 
     * @param string $trackId 轨迹ID
     * @param string $flightId 任务ID
     * @param string $droneSn 飞行器SN
     * @param string $dockSn 机场SN
     * @return bool
     */
    public static function startTrack($trackId, $flightId, $droneSn = '', $dockSn = '')
    {
        if (empty($trackId) || empty($flightId)) {
            Log::warning("【轨迹记录】参数缺失: trackId={$trackId}, flightId={$flightId}");
            return false;
        }
        
        try {
            // 检查是否已存在
            $exists = Db::name('flight_track')->where('track_id', $trackId)->find();
            
            if ($exists) {
                // 已存在,更新为recording状态(处理断点续飞)
                Log::info("【轨迹记录】轨迹已存在,更新为recording状态: {$trackId}");
                Db::name('flight_track')
                    ->where('track_id', $trackId)
                    ->update([
                        'track_status' => 'recording',
                        'update_time' => self::getMilliTimestamp()
                    ]);
            } else {
                // 查询任务信息
                $taskInfo = Db::name('flighttask')->where('bid', $flightId)->find();
                
                // 创建新轨迹记录
                Db::name('flight_track')->insert([
                    'flight_id' => $flightId,
                    'track_id' => $trackId,
                    'task_id' => $taskInfo['id'] ?? null,
                    'equipment_id' => $taskInfo['equipment_id'] ?? null,
                    'drone_sn' => $droneSn,
                    'dock_sn' => $dockSn,
                    'track_type' => 'wayline',
                    'track_status' => 'recording',
                    'wayline_id' => $taskInfo['airline_id'] ?? null,
                    'start_time' => self::getMilliTimestamp(),
                    'create_time' => self::getMilliTimestamp(),
                    'update_time' => self::getMilliTimestamp()
                ]);
                
                Log::info("【轨迹记录】创建轨迹记录成功: trackId={$trackId}, flightId={$flightId}");
            }
            
            // 加入活跃轨迹列表(使用普通缓存键)
            Cache::set(self::CACHE_PREFIX . $trackId . ':active', true);
            
            // 初始化轨迹点缓冲区(使用数组存储)
            Cache::delete(self::CACHE_PREFIX . $trackId . ':points');
            Cache::set(self::CACHE_PREFIX . $trackId . ':last_flush', time());
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("【轨迹记录】开始记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查轨迹是否处于活跃状态(允许记录OSD)
     * 
     * @param string $trackId 轨迹ID
     * @return bool
     */
    public static function isActive($trackId)
    {
        if (empty($trackId)) {
            return false;
        }
        
        // 使用普通缓存键判断(兼容所有缓存驱动)
        return Cache::get(self::CACHE_PREFIX . $trackId . ':active') === true;
    }
    
    /**
     * 暂停轨迹记录
     * 触发时机: flighttask_progress (wayline_mission_state=7)
     * 
     * @param string $trackId 轨迹ID
     * @return bool
     */
    public static function pauseTrack($trackId)
    {
        if (empty($trackId)) {
            return false;
        }
        
        try {
            // 先刷新缓冲区
            self::flushPointsBuffer($trackId);
            
            // 更新状态
            Db::name('flight_track')
                ->where('track_id', $trackId)
                ->update([
                    'track_status' => 'paused',
                    'update_time' => self::getMilliTimestamp()
                ]);
            
            // 暂不从活跃列表移除(因为可能恢复)
            
            Log::info("【轨迹记录】暂停轨迹: {$trackId}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("【轨迹记录】暂停失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 恢复轨迹记录
     * 触发时机: flighttask_progress (wayline_mission_state=8)
     * 
     * @param string $trackId 轨迹ID
     * @return bool
     */
    public static function resumeTrack($trackId)
    {
        if (empty($trackId)) {
            return false;
        }
        
        try {
            Db::name('flight_track')
                ->where('track_id', $trackId)
                ->update([
                    'track_status' => 'recording',
                    'update_time' => self::getMilliTimestamp()
                ]);
            
            // 重新加入活跃列表
            Cache::set(self::CACHE_PREFIX . $trackId . ':active', true);
            
            Log::info("【轨迹记录】恢复轨迹: {$trackId}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("【轨迹记录】恢复失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 停止轨迹记录
     * 触发时机: flighttask_progress (wayline_mission_state=9)
     * 
     * @param string $trackId 轨迹ID
     * @return bool
     */
    public static function stopTrack($trackId)
    {
        if (empty($trackId)) {
            return false;
        }
        
        try {
            // 1. 刷新缓冲区中的轨迹点到数据库
            self::flushPointsBuffer($trackId);
            
            // 2. 计算统计数据
            $stats = self::calculateStatistics($trackId);
            
            // 3. 更新轨迹主表
            Db::name('flight_track')
                ->where('track_id', $trackId)
                ->update([
                    'track_status' => 'completed',
                    'end_time' => self::getMilliTimestamp(),
                    'total_points' => $stats['total_points'],
                    'total_distance' => $stats['total_distance'],
                    'total_duration' => $stats['total_duration'],
                    'max_altitude' => $stats['max_altitude'],
                    'min_altitude' => $stats['min_altitude'],
                    'avg_speed' => $stats['avg_speed'],
                    'max_speed' => $stats['max_speed'],
                    'end_battery_percent' => $stats['end_battery_percent'],
                    'battery_consumption' => $stats['battery_consumption'],
                    'avg_wind_speed' => $stats['avg_wind_speed'],
                    'max_wind_speed' => $stats['max_wind_speed'],
                    'update_time' => self::getMilliTimestamp()
                ]);
            
            // 4. 从活跃列表移除
            Cache::delete(self::CACHE_PREFIX . $trackId . ':active');
            
            // 5. 清理缓存
            Cache::delete(self::CACHE_PREFIX . $trackId . ':points');
            Cache::delete(self::CACHE_PREFIX . $trackId . ':last_flush');
            
            Log::info("【轨迹记录】停止轨迹成功: {$trackId}, 统计: " . json_encode($stats, JSON_UNESCAPED_UNICODE));
            return true;
            
        } catch (\Exception $e) {
            Log::error("【轨迹记录】停止失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 记录OSD轨迹点
     * 触发时机: OSD消息 (每2秒)
     * 
     * @param string $trackId 轨迹ID
     * @param array $osdData OSD数据
     * @param string $flightId 任务ID
     * @return bool
     */
    public static function recordOsdPoint($trackId, $osdData, $flightId = '')
    {
        if (!self::isActive($trackId)) {
            // 轨迹不在活跃状态,不记录
            return false;
        }
        
        try {
            // 提取关键数据
            $point = [
                'track_id' => $trackId,
                'flight_id' => $flightId,
                'latitude' => $osdData['latitude'] ?? null,
                'longitude' => $osdData['longitude'] ?? null,
                'height' => $osdData['height'] ?? null,
                'relative_height' => $osdData['relative_height'] ?? null,
                'heading' => $osdData['attitude_head'] ?? null,
                'pitch' => $osdData['attitude_pitch'] ?? null,
                'roll' => $osdData['attitude_roll'] ?? null,
                'horizontal_speed' => $osdData['horizontal_speed'] ?? null,
                'vertical_speed' => $osdData['vertical_speed'] ?? null,
                'battery_percent' => $osdData['capacity_percent'] ?? null,
                'gps_signal_level' => $osdData['position_state']['quality'] ?? null,
                'satellite_count' => $osdData['position_state']['gps_number'] ?? null,
                'wind_speed' => $osdData['wind_speed'] ?? null,
                'wind_direction' => $osdData['wind_direction'] ?? null,
                'temperature' => $osdData['temperature'] ?? null,
                'sdr_signal_quality' => $osdData['wireless_link']['sdr_quality'] ?? null,
                '4g_signal_quality' => $osdData['wireless_link']['4g_quality'] ?? null,
                'timestamp' => $osdData['timestamp'] ?? self::getMilliTimestamp(),
                'create_time' => self::getMilliTimestamp()
            ];
            
            // 验证必要字段
            if (empty($point['latitude']) || empty($point['longitude'])) {
                Log::warning("【轨迹记录】坐标数据缺失,跳过记录: trackId={$trackId}");
                return false;
            }
            
            // 加入缓冲区(使用数组存储)
            $bufferKey = self::CACHE_PREFIX . $trackId . ':points';
            $buffer = Cache::get($bufferKey, []);
            $buffer[] = $point;
            Cache::set($bufferKey, $buffer);
            
            // 检查是否需要刷盘
            $bufferSize = count($buffer);
            $lastFlush = Cache::get(self::CACHE_PREFIX . $trackId . ':last_flush', 0);
            $timeSinceFlush = time() - $lastFlush;
            
            // 条件: 每10条或超过5秒刷盘
            if ($bufferSize >= self::POINTS_BUFFER_SIZE || $timeSinceFlush >= self::BUFFER_FLUSH_INTERVAL) {
                self::flushPointsBuffer($trackId);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("【轨迹记录】记录OSD点失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 刷新缓冲区到数据库(批量插入)
     * 
     * @param string $trackId 轨迹ID
     * @return int 刷入的点数
     */
    private static function flushPointsBuffer($trackId)
    {
        $bufferKey = self::CACHE_PREFIX . $trackId . ':points';
        $points = Cache::get($bufferKey, []);
        
        if (empty($points)) {
            return 0;
        }
        
        try {
            // 清空缓冲区
            Cache::set($bufferKey, []);
            
            // 批量插入
            Db::name('flight_track_point')->insertAll($points);
            
            // 更新最后刷盘时间
            Cache::set(self::CACHE_PREFIX . $trackId . ':last_flush', time());
            
            Log::info("【轨迹记录】刷盘成功: trackId={$trackId}, 点数=" . count($points));
            return count($points);
            
        } catch (\Exception $e) {
            Log::error("【轨迹记录】刷盘失败: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 计算轨迹统计数据
     * 
     * @param string $trackId 轨迹ID
     * @return array
     */
    private static function calculateStatistics($trackId)
    {
        try {
            // 查询所有轨迹点(按时间排序)
            $points = Db::name('flight_track_point')
                ->where('track_id', $trackId)
                ->order('timestamp', 'asc')
                ->select()
                ->toArray();
            
            if (empty($points)) {
                return [
                    'total_points' => 0,
                    'total_distance' => 0,
                    'total_duration' => 0,
                    'max_altitude' => null,
                    'min_altitude' => null,
                    'avg_speed' => null,
                    'max_speed' => null,
                    'end_battery_percent' => null,
                    'battery_consumption' => null,
                    'avg_wind_speed' => null,
                    'max_wind_speed' => null
                ];
            }
            
            // 初始化统计变量
            $totalDistance = 0;
            $maxAltitude = $points[0]['height'] ?? 0;
            $minAltitude = $points[0]['height'] ?? 0;
            $maxSpeed = 0;
            $totalSpeed = 0;
            $speedCount = 0;
            $maxWindSpeed = 0;
            $totalWindSpeed = 0;
            $windCount = 0;
            
            // 遍历计算
            for ($i = 0; $i < count($points); $i++) {
                $point = $points[$i];
                
                // 距离统计(从第二个点开始)
                if ($i > 0) {
                    $prevPoint = $points[$i - 1];
                    $distance = self::calculateDistance(
                        $prevPoint['latitude'],
                        $prevPoint['longitude'],
                        $point['latitude'],
                        $point['longitude']
                    );
                    $totalDistance += $distance;
                }
                
                // 高度统计
                if (!empty($point['height'])) {
                    if ($point['height'] > $maxAltitude) {
                        $maxAltitude = $point['height'];
                    }
                    if ($point['height'] < $minAltitude) {
                        $minAltitude = $point['height'];
                    }
                }
                
                // 速度统计
                if (!empty($point['horizontal_speed'])) {
                    $totalSpeed += $point['horizontal_speed'];
                    $speedCount++;
                    if ($point['horizontal_speed'] > $maxSpeed) {
                        $maxSpeed = $point['horizontal_speed'];
                    }
                }
                
                // 风速统计
                if (!empty($point['wind_speed'])) {
                    $totalWindSpeed += $point['wind_speed'];
                    $windCount++;
                    if ($point['wind_speed'] > $maxWindSpeed) {
                        $maxWindSpeed = $point['wind_speed'];
                    }
                }
            }
            
            // 计算时长(毫秒转秒)
            $track = Db::name('flight_track')->where('track_id', $trackId)->find();
            $startTime = $track['start_time'] ?? 0;
            $endTime = self::getMilliTimestamp();
            $duration = ($endTime - $startTime) / 1000;
            
            // 电量统计
            $startBattery = $track['start_battery_percent'] ?? null;
            $endBattery = $points[count($points) - 1]['battery_percent'] ?? null;
            $batteryConsumption = null;
            if ($startBattery !== null && $endBattery !== null) {
                $batteryConsumption = $startBattery - $endBattery;
            }
            
            return [
                'total_points' => count($points),
                'total_distance' => round($totalDistance, 2),
                'total_duration' => (int)$duration,
                'max_altitude' => $maxAltitude,
                'min_altitude' => $minAltitude,
                'avg_speed' => $speedCount > 0 ? round($totalSpeed / $speedCount, 2) : null,
                'max_speed' => $maxSpeed > 0 ? $maxSpeed : null,
                'end_battery_percent' => $endBattery,
                'battery_consumption' => $batteryConsumption,
                'avg_wind_speed' => $windCount > 0 ? round($totalWindSpeed / $windCount, 2) : null,
                'max_wind_speed' => $maxWindSpeed > 0 ? $maxWindSpeed : null
            ];
            
        } catch (\Exception $e) {
            Log::error("【轨迹记录】统计计算失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 计算两点距离(Haversine公式)
     * 
     * @param float $lat1 纬度1
     * @param float $lon1 经度1
     * @param float $lat2 纬度2
     * @param float $lon2 经度2
     * @return float 距离(米)
     */
    private static function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // 地球半径(米)
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
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
    
    /**
     * 更新轨迹的起飞电量
     * 
     * @param string $trackId 轨迹ID
     * @param int $batteryPercent 电量百分比
     * @return bool
     */
    public static function updateStartBattery($trackId, $batteryPercent)
    {
        try {
            Db::name('flight_track')
                ->where('track_id', $trackId)
                ->update([
                    'start_battery_percent' => $batteryPercent,
                    'update_time' => self::getMilliTimestamp()
                ]);
            return true;
        } catch (\Exception $e) {
            Log::error("【轨迹记录】更新起飞电量失败: " . $e->getMessage());
            return false;
        }
    }
}
