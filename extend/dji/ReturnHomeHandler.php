<?php

namespace dji;

use think\facade\Db;
use think\facade\Log;

/**
 * 返航轨迹处理器
 * 
 * 功能:
 * 1. 记录返航规划路径
 * 2. 支持蛙跳任务多机场返航
 * 3. 追踪返航状态
 * 4. 对比规划vs实际飞行数据
 * 
 * @package dji
 */
class ReturnHomeHandler
{
    /**
     * 记录返航信息
     * 触发时机: return_home_info 事件
     * 
     * @param array $param 消息参数
     * @return bool
     */
    public static function recordReturnHome($param)
    {
        $data = $param['data'] ?? [];
        $flightId = $data['flight_id'] ?? '';
        
        if (empty($flightId)) {
            Log::warning("【返航记录】flight_id缺失");
            return false;
        }
        
        try {
            // 查找关联的 track_id
            $record = Db::name('flightrecord')
                ->where('flight_id', $flightId)
                ->order('create_time', 'desc')
                ->find();
            
            $trackId = $record['track_id'] ?? '';
            
            // 判断返航类型
            $returnType = self::detectReturnType($data);
            
            // 判断是否蛙跳任务
            $isMultiDock = isset($data['multi_dock_home_info']) && !empty($data['multi_dock_home_info']);
            
            // 提取规划路径点
            $plannedPoints = $data['planned_path_points'] ?? [];
            $plannedPointsCount = count($plannedPoints);
            
            // 计算规划距离
            $plannedDistance = self::calculatePlannedDistance($plannedPoints);
            
            // 提取触发位置(第一个规划点)
            $triggerLat = null;
            $triggerLon = null;
            $triggerHeight = null;
            if (!empty($plannedPoints)) {
                $triggerLat = $plannedPoints[0]['latitude'] ?? null;
                $triggerLon = $plannedPoints[0]['longitude'] ?? null;
                $triggerHeight = $plannedPoints[0]['height'] ?? null;
            }
            
            // 提取Home点信息(最后一个点)
            $homeLat = null;
            $homeLon = null;
            $homeHeight = null;
            if ($plannedPointsCount > 0) {
                $lastPoint = $plannedPoints[$plannedPointsCount - 1];
                $homeLat = $lastPoint['latitude'] ?? null;
                $homeLon = $lastPoint['longitude'] ?? null;
                $homeHeight = $lastPoint['height'] ?? null;
            }
            
            // 提取预估电量消耗
            $estimatedBattery = null;
            if ($isMultiDock && isset($data['multi_dock_home_info'][0])) {
                $estimatedBattery = $data['multi_dock_home_info'][0]['estimated_battery_consumption'] ?? null;
            }
            
            // 插入返航记录
            $returnId = Db::name('flight_return_track')->insertGetId([
                'flight_id' => $flightId,
                'track_id' => $trackId,
                'return_type' => $returnType,
                'last_point_type' => $data['last_point_type'] ?? null,
                'home_latitude' => $homeLat,
                'home_longitude' => $homeLon,
                'home_height' => $homeHeight,
                'home_dock_sn' => $data['home_dock_sn'] ?? null,
                'trigger_latitude' => $triggerLat,
                'trigger_longitude' => $triggerLon,
                'trigger_height' => $triggerHeight,
                'planned_points' => json_encode($plannedPoints, JSON_UNESCAPED_UNICODE),
                'planned_points_count' => $plannedPointsCount,
                'planned_distance' => $plannedDistance,
                'estimated_battery_consumption' => $estimatedBattery,
                'is_multi_dock' => $isMultiDock ? 1 : 0,
                'multi_dock_info' => $isMultiDock ? json_encode($data['multi_dock_home_info'], JSON_UNESCAPED_UNICODE) : null,
                'return_status' => 'planning',
                'trigger_time' => $param['timestamp'] ?? self::getMilliTimestamp(),
                'create_time' => self::getMilliTimestamp(),
                'update_time' => self::getMilliTimestamp()
            ]);
            
            // 更新轨迹主表的返航标记
            if (!empty($trackId)) {
                Db::name('flight_track')
                    ->where('track_id', $trackId)
                    ->update([
                        'has_return_home' => 1,
                        'update_time' => self::getMilliTimestamp()
                    ]);
            }
            
            Log::info("【返航记录】记录成功: flightId={$flightId}, trackId={$trackId}, type={$returnType}, 规划点数={$plannedPointsCount}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("【返航记录】记录失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新返航状态
     * 
     * @param string $flightId 任务ID
     * @param string $status planning/in_progress/completed/failed/canceled
     * @return bool
     */
    public static function updateReturnStatus($flightId, $status)
    {
        try {
            $updateData = [
                'return_status' => $status,
                'update_time' => self::getMilliTimestamp()
            ];
            
            // 如果是完成状态,记录完成时间
            if ($status == 'completed') {
                $updateData['complete_time'] = self::getMilliTimestamp();
            }
            
            Db::name('flight_return_track')
                ->where('flight_id', $flightId)
                ->order('trigger_time', 'desc')
                ->limit(1)
                ->update($updateData);
            
            Log::info("【返航记录】更新状态: flightId={$flightId}, status={$status}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("【返航记录】更新状态失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 计算返航实际数据(从轨迹点统计)
     * 
     * @param string $trackId 轨迹ID
     * @param int $returnStartTime 返航开始时间(毫秒)
     * @return bool
     */
    public static function calculateActualData($trackId, $returnStartTime)
    {
        try {
            // 查询返航开始后的轨迹点
            $points = Db::name('flight_track_point')
                ->where('track_id', $trackId)
                ->where('timestamp', '>=', $returnStartTime)
                ->order('timestamp', 'asc')
                ->select()
                ->toArray();
            
            if (empty($points)) {
                return false;
            }
            
            // 计算实际距离
            $actualDistance = 0;
            for ($i = 1; $i < count($points); $i++) {
                $distance = self::calculateDistance(
                    $points[$i - 1]['latitude'],
                    $points[$i - 1]['longitude'],
                    $points[$i]['latitude'],
                    $points[$i]['longitude']
                );
                $actualDistance += $distance;
            }
            
            // 计算电量消耗
            $startBattery = $points[0]['battery_percent'] ?? null;
            $endBattery = $points[count($points) - 1]['battery_percent'] ?? null;
            $actualBatteryConsumption = null;
            if ($startBattery !== null && $endBattery !== null) {
                $actualBatteryConsumption = $startBattery - $endBattery;
            }
            
            // 更新返航记录
            Db::name('flight_return_track')
                ->where('track_id', $trackId)
                ->order('trigger_time', 'desc')
                ->limit(1)
                ->update([
                    'actual_points_count' => count($points),
                    'actual_distance' => round($actualDistance, 2),
                    'actual_battery_consumption' => $actualBatteryConsumption,
                    'update_time' => self::getMilliTimestamp()
                ]);
            
            Log::info("【返航记录】更新实际数据: trackId={$trackId}, 距离={$actualDistance}m, 点数=" . count($points));
            return true;
            
        } catch (\Exception $e) {
            Log::error("【返航记录】计算实际数据失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检测返航类型
     * 
     * @param array $data 返航数据
     * @return string
     */
    private static function detectReturnType($data)
    {
        // 可以根据实际业务逻辑判断,这里提供基础实现
        // 建议结合断点原因代码判断
        
        // 如果有多机场信息,可能是计划返航
        if (isset($data['multi_dock_home_info']) && !empty($data['multi_dock_home_info'])) {
            return 'planned';
        }
        
        // 默认返回手动返航
        return 'manual';
    }
    
    /**
     * 计算规划路径的总距离
     * 
     * @param array $points 规划点数组
     * @return float
     */
    private static function calculatePlannedDistance($points)
    {
        if (empty($points) || count($points) < 2) {
            return 0;
        }
        
        $totalDistance = 0;
        for ($i = 1; $i < count($points); $i++) {
            $distance = self::calculateDistance(
                $points[$i - 1]['latitude'],
                $points[$i - 1]['longitude'],
                $points[$i]['latitude'],
                $points[$i]['longitude']
            );
            $totalDistance += $distance;
        }
        
        return round($totalDistance, 2);
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
     * 获取轨迹的返航记录
     * 
     * @param string $trackId 轨迹ID
     * @return array|null
     */
    public static function getReturnTrack($trackId)
    {
        try {
            return Db::name('flight_return_track')
                ->where('track_id', $trackId)
                ->order('trigger_time', 'desc')
                ->find();
        } catch (\Exception $e) {
            Log::error("【返航记录】查询失败: " . $e->getMessage());
            return null;
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
