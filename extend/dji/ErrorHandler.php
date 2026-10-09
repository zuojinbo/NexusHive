<?php

namespace dji;

use think\facade\Log;

/**
 * DJI 航线任务错误处理辅助类
 * 
 * 功能:
 * 1. 错误码转换为人性化描述
 * 2. 错误严重程度判断
 * 3. 错误日志记录
 * 4. 自动重试判断
 */
class ErrorHandler
{
    private static $errorCodes = null;
    
    /**
     * 加载错误码配置
     * @return array
     */
    private static function loadErrorCodes()
    {
        if (self::$errorCodes === null) {
            $configFile = root_path() . 'config/dji_error_codes.php';
            if (file_exists($configFile)) {
                self::$errorCodes = require $configFile;
            } else {
                self::$errorCodes = [
                    'prepare' => [],
                    'execution' => [],
                    'wayline_state' => [],
                    'task_status' => []
                ];
                Log::error("【错误处理】配置文件不存在: {$configFile}");
            }
        }
        return self::$errorCodes;
    }
    
    /**
     * 获取下发错误描述
     * @param int $code 错误代码
     * @return string
     */
    public static function getPrepareErrorMessage($code)
    {
        $codes = self::loadErrorCodes();
        return $codes['prepare'][$code] ?? "未知下发错误(代码: {$code})";
    }
    
    /**
     * 获取执行错误描述
     * @param int $breakReason 中断原因代码
     * @return string
     */
    public static function getExecutionErrorMessage($breakReason)
    {
        $codes = self::loadErrorCodes();
        return $codes['execution'][$breakReason] ?? "未知执行错误(代码: {$breakReason})";
    }
    
    /**
     * 获取航线状态描述
     * @param int $state 状态代码
     * @return string
     */
    public static function getWaylineStateMessage($state)
    {
        $codes = self::loadErrorCodes();
        return $codes['wayline_state'][$state] ?? "未知状态(代码: {$state})";
    }
    
    /**
     * 获取任务状态描述
     * @param string $status 状态代码
     * @return string
     */
    public static function getTaskStatusMessage($status)
    {
        $codes = self::loadErrorCodes();
        return $codes['task_status'][$status] ?? $status;
    }
    
    /**
     * 判断是否为严重错误（需要人工处理）
     * @param int $breakReason 中断原因代码
     * @return bool
     */
    public static function isCriticalError($breakReason)
    {
        // 设备问题、限飞区、障碍物等严重错误
        $criticalErrors = [
            515, // 航线穿过限飞区
            517, // 飞行器触发避障
            519, // 接近禁飞区边界
            521, // 超过机场限飞区限高
            529, // 有障碍物或禁飞区域
            513, // 飞行器超过限高高度
            514, // 飞行器超过限远距离
            516, // 飞行器触发限低
            1563, // 航线生成失败
            1564, // 航线运行失败
            1565, // 航线避障紧急刹停
        ];
        return in_array($breakReason, $criticalErrors);
    }
    
    /**
     * 判断是否可自动重试
     * @param int $breakReason 中断原因代码
     * @return bool
     */
    public static function canAutoRetry($breakReason)
    {
        // GPS信号弱、RTK信号差、电量不足等可重试错误
        $retryableErrors = [
            518,  // RTK信号差
            769,  // GPS信号弱
            772,  // 当前电量过低（充电后可重试）
            784,  // 大风返航（天气转好后可重试）
            7,    // 解析WPMZ文件超时
        ];
        return in_array($breakReason, $retryableErrors);
    }
    
    /**
     * 判断是否为用户主动操作
     * @param int $breakReason 中断原因代码
     * @return bool
     */
    public static function isUserAction($breakReason)
    {
        $userActions = [
            1281, // 用户主动退出
            1282, // 用户主动中断
            1283, // 用户触发返航
        ];
        return in_array($breakReason, $userActions);
    }
    
    /**
     * 判断是否为设备状态问题
     * @param int $breakReason 中断原因代码
     * @return bool
     */
    public static function isDeviceIssue($breakReason)
    {
        $deviceIssues = [
            769,  // GPS信号弱
            770,  // 遥控器档位不在N档
            771,  // 返航点未刷新
            772,  // 当前电量过低
            773,  // 低电量返航
            775,  // 遥控器与飞行器失联
            778,  // 飞行器在地面起桨
            518,  // RTK信号差
        ];
        return in_array($breakReason, $deviceIssues);
    }
    
    /**
     * 获取错误分类
     * @param int $breakReason 中断原因代码
     * @return string critical|retryable|user_action|device_issue|config_error|unknown
     */
    public static function getErrorCategory($breakReason)
    {
        if (self::isUserAction($breakReason)) {
            return 'user_action';
        }
        if (self::isCriticalError($breakReason)) {
            return 'critical';
        }
        if (self::canAutoRetry($breakReason)) {
            return 'retryable';
        }
        if (self::isDeviceIssue($breakReason)) {
            return 'device_issue';
        }
        
        // 航线配置错误（1539-1561）
        if ($breakReason >= 1539 && $breakReason <= 1561) {
            return 'config_error';
        }
        
        return 'unknown';
    }
    
    /**
     * 记录错误日志
     * @param string $flightId 任务ID
     * @param int $errorCode 错误代码
     * @param string $errorMsg 错误描述
     * @param string $type prepare|execution
     * @param array $extra 额外信息
     */
    public static function logError($flightId, $errorCode, $errorMsg, $type = 'execution', $extra = [])
    {
        $category = $type === 'execution' ? self::getErrorCategory($errorCode) : 'prepare_error';
        
        $logData = [
            'flight_id' => $flightId,
            'error_code' => $errorCode,
            'error_msg' => $errorMsg,
            'error_type' => $type,
            'category' => $category,
            'extra' => $extra,
            'time' => date('Y-m-d H:i:s')
        ];
        
        // 根据严重程度选择日志级别
        $isCritical = ($type === 'execution') ? self::isCriticalError($errorCode) : ($errorCode > 0);
        
        if ($isCritical) {
            Log::error("【航线任务错误】" . json_encode($logData, JSON_UNESCAPED_UNICODE));
        } else {
            Log::warning("【航线任务警告】" . json_encode($logData, JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * 获取建议处理方案
     * @param int $breakReason 中断原因代码
     * @return string
     */
    public static function getSuggestedAction($breakReason)
    {
        $suggestions = [
            // GPS/RTK问题
            518 => '等待RTK信号恢复后重试，确保RTK基站正常工作',
            769 => '移动到开阔区域，等待GPS信号恢复',
            
            // 电量问题
            772 => '请为设备充电至90%以上再执行任务',
            773 => '设备已自动返航，请充电后重新执行任务',
            
            // 限飞区问题
            515 => '航线规划穿过限飞区，请重新规划航线',
            519 => '航线太接近禁飞区边界，建议调整航线距离禁飞区至少200米',
            521 => '超过机场限飞区限高，请降低航线高度',
            
            // 避障问题
            517 => '检测到障碍物，请确认航线规划是否合理，或关闭避障功能',
            529 => '航线路径存在障碍物，请重新规划航线',
            1565 => '避障紧急刹停，请检查航线周围是否有障碍物',
            
            // 设备状态
            770 => '请将遥控器档位切换到N档',
            771 => '返航点未刷新，请等待GPS定位后再执行任务',
            775 => '遥控器与飞行器失联，请检查通信环境',
            778 => '飞行器在地面起桨，请检查设备状态',
            
            // 配置问题
            1547 => '航线全局速度超过合理范围，请在DJI Pilot中调整速度参数',
            1548 => '航点数量异常，请检查航线规划',
            1549 => '经纬度数据异常，请重新生成航线文件',
            
            // 用户操作
            1281 => '用户主动退出任务',
            1282 => '用户主动中断任务',
            1283 => '用户触发返航',
        ];
        
        return $suggestions[$breakReason] ?? '请根据错误描述检查航线配置和设备状态';
    }
    
    /**
     * 构建完整的错误详情
     * @param int $code 错误代码
     * @param string $type prepare|execution
     * @return array
     */
    public static function buildErrorDetail($code, $type = 'execution')
    {
        if ($type === 'prepare') {
            return [
                'code' => $code,
                'message' => self::getPrepareErrorMessage($code),
                'type' => 'prepare',
                'category' => 'prepare_error',
                'is_critical' => $code > 0,
                'can_retry' => false,
                'suggestion' => '请检查航线文件和设备状态'
            ];
        }
        
        return [
            'code' => $code,
            'message' => self::getExecutionErrorMessage($code),
            'type' => 'execution',
            'category' => self::getErrorCategory($code),
            'is_critical' => self::isCriticalError($code),
            'can_retry' => self::canAutoRetry($code),
            'is_user_action' => self::isUserAction($code),
            'is_device_issue' => self::isDeviceIssue($code),
            'suggestion' => self::getSuggestedAction($code)
        ];
    }
}
