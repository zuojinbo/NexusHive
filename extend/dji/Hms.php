<?php

namespace dji;

use think\facade\Db;
use think\facade\Log;

class Hms
{
    // HMS JSON数据缓存,避免重复读取文件
    private static $hmsData = null;
    
    /**
     * 加载hms.json文件数据
     * @return array|null
     */
    private function loadHmsJson()
    {
        // 如果已缓存,直接返回
        if (self::$hmsData !== null) {
            return self::$hmsData;
        }
        
        $jsonPath = app()->getRootPath() . 'public/hms.json';
        
        // 文件不存在时尝试从DJI官方下载
        if (!file_exists($jsonPath)) {
            Log::warning("HMS配置文件不存在,尝试从官方下载: {$jsonPath}");
            try {
                $url = 'https://terra-1-g.djicdn.com/fee90c2e03e04e8da67ea6f56365fc76/SDK%20%E6%96%87%E6%A1%A3/CloudAPI/hms.json';
                $jsonContent = @file_get_contents($url);
                if ($jsonContent) {
                    // 保存到本地
                    @file_put_contents($jsonPath, $jsonContent);
                    Log::info("HMS配置文件下载成功");
                } else {
                    Log::error("HMS配置文件下载失败");
                    return null;
                }
            } catch (\Exception $e) {
                Log::error("HMS配置文件下载异常: " . $e->getMessage());
                return null;
            }
        }
        
        $jsonContent = @file_get_contents($jsonPath);
        if (!$jsonContent) {
            Log::error("无法读取HMS配置文件: {$jsonPath}");
            return null;
        }
        
        self::$hmsData = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("HMS配置文件JSON格式错误: " . json_last_error_msg());
            self::$hmsData = null;
            return null;
        }
        
        if (!is_array(self::$hmsData)) {
            Log::error("HMS配置文件内容不是有效的数组");
            self::$hmsData = null;
            return null;
        }
        
        Log::info("HMS配置文件加载成功,共 " . count(self::$hmsData) . " 条告警码");
        return self::$hmsData;
    }
    
    /**
     * 生成HMS文案Key
     * @param string $code 告警码
     * @param string $deviceType 设备类型 (格式: domain-type-subtype)
     * @param int $inTheSky 是否在飞行 (0:地面 1:空中)
     * @return array [主Key, 备用Key数组]
     */
    private function generateHmsKey($code, $deviceType, $inTheSky)
    {
        // 提取domain部分
        $domainParts = explode('-', $deviceType);
        $domain = isset($domainParts[0]) ? $domainParts[0] : '';
        
        $primaryKey = '';
        $fallbackKeys = [];
        
        switch ($domain) {
            case '0':
                // 飞行器类
                if ($inTheSky == 1) {
                    $primaryKey = 'fpv_tip_' . $code . '_in_the_sky';
                    $fallbackKeys[] = 'fpv_tip_' . $code;
                } else {
                    $primaryKey = 'fpv_tip_' . $code;
                }
                break;
                
            case '1':
                // 负载类 (云台/相机)
                $primaryKey = 'payload_tip_' . $code;
                // 某些负载告警可能归类为fpv
                $fallbackKeys[] = 'fpv_tip_' . $code;
                break;
                
            case '2':
                // 遥控器类
                $primaryKey = 'rc_tip_' . $code;
                break;
                
            case '3':
                // 机场类
                $primaryKey = 'dock_tip_' . $code;
                break;
                
            default:
                // 未知类型,尝试所有可能的前缀
                $primaryKey = 'fpv_tip_' . $code;
                $fallbackKeys[] = 'dock_tip_' . $code;
                $fallbackKeys[] = 'payload_tip_' . $code;
                $fallbackKeys[] = 'rc_tip_' . $code;
                break;
        }
        
        return [$primaryKey, $fallbackKeys];
    }
    
    /**
     * 从hms.json中获取告警文案
     * @param string $code 告警码
     * @param string $deviceType 设备类型
     * @param int $inTheSky 是否在飞行
     * @param array $hmsData HMS配置数据
     * @return string 告警文案
     */
    private function getHmsMessage($code, $deviceType, $inTheSky, $hmsData)
    {
        list($primaryKey, $fallbackKeys) = $this->generateHmsKey($code, $deviceType, $inTheSky);
        
        // 尝试主Key
        if (isset($hmsData[$primaryKey]['zh'])) {
            Log::info("HMS告警匹配成功: {$primaryKey}");
            return $hmsData[$primaryKey]['zh'];
        }
        
        // 尝试备用Keys
        foreach ($fallbackKeys as $fallbackKey) {
            if (isset($hmsData[$fallbackKey]['zh'])) {
                Log::info("HMS告警使用备用Key匹配: {$fallbackKey}");
                return $hmsData[$fallbackKey]['zh'];
            }
        }
        
        // 未找到,记录日志
        Log::warning("HMS告警码未找到文案: code={$code}, device_type={$deviceType}, in_the_sky={$inTheSky}, 尝试的Keys: {$primaryKey}, " . implode(', ', $fallbackKeys));
        return '未知告警 [' . $code . ']';
    }
    
    /**
     * 填充告警文案中的变量
     * @param string $message 原始文案
     * @param string $code 告警码
     * @param array $args 参数
     * @return string 填充后的文案
     */
    private function fillMessageVariables($message, $code, $args)
    {
        // %alarmid - 告警码
        if (strpos($message, '%alarmid') !== false) {
            $message = str_replace('%alarmid', $code, $message);
        }
        
        // %index - 传感器索引+1
        if (strpos($message, '%index') !== false && isset($args['sensor_index'])) {
            $message = str_replace('%index', $args['sensor_index'] + 1, $message);
        }
        
        // %component_index - 组件索引+1 (限定1-3,对应1号云台、2号云台、3号云台)
        if (strpos($message, '%component_index') !== false && isset($args['component_index'])) {
            $componentIndex = max(1, min(3, $args['component_index'] + 1));
            $message = str_replace('%component_index', $componentIndex, $message);
        }
        
        // %battery_index - 电池索引 (0:左 其他:右)
        if (strpos($message, '%battery_index') !== false && isset($args['sensor_index'])) {
            $batteryIndex = $args['sensor_index'] == 0 ? '左' : '右';
            $message = str_replace('%battery_index', $batteryIndex, $message);
        }
        
        // %dock_cover_index - 机场舱盖索引 (0:左 其他:右)
        if (strpos($message, '%dock_cover_index') !== false && isset($args['sensor_index'])) {
            $dockCoverIndex = $args['sensor_index'] == 0 ? '左' : '右';
            $message = str_replace('%dock_cover_index', $dockCoverIndex, $message);
        }
        
        // %charging_rod_index - 充电杆索引 (0:前 1:后 2:左 3:右)
        if (strpos($message, '%charging_rod_index') !== false && isset($args['sensor_index'])) {
            $positions = ['前', '后', '左', '右'];
            $chargingRodIndex = isset($positions[$args['sensor_index']]) 
                ? $positions[$args['sensor_index']] 
                : '未知';
            $message = str_replace('%charging_rod_index', $chargingRodIndex, $message);
        }
        
        return $message;
    }
    
    /**
     * 保存HMS告警数据
     * @param array $param MQTT消息参数
     * @return array
     */
    public function save($param)
    {
        // 参数校验
        if (empty($param) || !isset($param['data']['list']) || !is_array($param['data']['list'])) {
            Log::warning("HMS消息参数无效");
            return ['code' => -1, 'msg' => '参数无效'];
        }
        
        // 加载HMS配置
        $hmsData = $this->loadHmsJson();
        if (!$hmsData) {
            Log::error("HMS配置文件加载失败,无法处理告警消息");
            // 即使配置加载失败,也要保存原始数据
        }
        
        $currentTime = time();
        $insertData = [];
        $sn = isset($param['sn']) ? $param['sn'] : '';
        
        Log::info("开始处理HMS告警消息: sn={$sn}, 告警数量=" . count($param['data']['list']));
        
        // 处理每个告警
        foreach ($param['data']['list'] as $index => $alarm) {
            // 必要字段校验
            if (!isset($alarm['code']) || !isset($alarm['device_type'])) {
                Log::warning("HMS告警项缺少必要字段: index={$index}");
                continue;
            }
            
            $code = $alarm['code'];
            $deviceType = $alarm['device_type'];
            $inTheSky = isset($alarm['in_the_sky']) ? intval($alarm['in_the_sky']) : 0;
            $args = isset($alarm['args']) ? $alarm['args'] : [];
            
            // 获取告警文案
            $message = $hmsData 
                ? $this->getHmsMessage($code, $deviceType, $inTheSky, $hmsData)
                : '未知告警 [' . $code . ']';
            
            // 填充文案变量
            if ($message !== '未知告警 [' . $code . ']' && strpos($message, '%') !== false) {
                $message = $this->fillMessageVariables($message, $code, $args);
            }
            
            // 准备插入数据
            $insertData[] = [
                'sn' => $sn,
                'level' => isset($alarm['level']) ? intval($alarm['level']) : 0,
                'module' => isset($alarm['module']) ? intval($alarm['module']) : 3,
                'in_the_sky' => $inTheSky,
                'code' => $code,
                'device_type' => $deviceType,
                'imminent' => isset($alarm['imminent']) ? intval($alarm['imminent']) : 0,
                'component_index' => isset($args['component_index']) ? intval($args['component_index']) : null,
                'sensor_index' => isset($args['sensor_index']) ? intval($args['sensor_index']) : null,
                'message' => $message,
                'create_time' => $currentTime,
                'update_time' => $currentTime
            ];
        }
        
        // 批量插入数据
        if (!empty($insertData)) {
            try {
                Db::name('hmscenter')->insertAll($insertData);
                Log::info("HMS告警保存成功: sn={$sn}, 数量=" . count($insertData));
            } catch (\Exception $e) {
                Log::error("HMS告警保存失败: " . $e->getMessage());
                return ['code' => -2, 'msg' => '数据库保存失败: ' . $e->getMessage()];
            }
        }
        
        return ['code' => 0, 'msg' => '保存成功', 'count' => count($insertData)];
    }
}
