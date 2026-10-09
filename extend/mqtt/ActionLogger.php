<?php

namespace mqtt;

use think\facade\Log;

/**
 * 动作日志记录器
 * 记录所有 MQTT 相关操作，支持实时查看和历史查询
 */
class ActionLogger
{
    /** @var array 日志存储（环形缓冲区） */
    private static $logs = [];
    
    /** @var int 最大日志数 */
    private static $maxLogs = 10000;
    
    /** @var int 日志计数器 */
    private static $counter = 0;
    
    /** @var bool 是否启用 */
    private static $enabled = true;
    
    /** @var bool 是否记录消息内容 */
    private static $logPayload = true;
    
    /** @var int 最大消息内容大小 */
    private static $maxPayloadSize = 1024;
    
    /** @var array 实时日志回调 */
    private static $realtimeCallbacks = [];
    
    /**
     * 记录订阅动作
     */
    public static function logSubscribe(string $source, string $topic): void
    {
        self::log([
            'type' => 'subscribe',
            'source' => $source,
            'topic' => $topic,
            'direction' => null,
        ]);
    }
    
    /**
     * 记录取消订阅动作
     */
    public static function logUnsubscribe(string $source, string $topic): void
    {
        self::log([
            'type' => 'unsubscribe',
            'source' => $source,
            'topic' => $topic,
            'direction' => null,
        ]);
    }
    
    /**
     * 记录发布动作
     */
    public static function logPublish(string $source, string $topic, $payload, float $latency = 0): void
    {
        self::log([
            'type' => 'publish',
            'source' => $source,
            'topic' => $topic,
            'payload' => $payload,
            'direction' => 'down',
            'latency' => $latency,
        ]);
    }
    
    /**
     * 记录收到消息
     */
    public static function logMessage(string $topic, $payload, string $source = 'mqtt'): void
    {
        self::log([
            'type' => 'message',
            'source' => $source,
            'topic' => $topic,
            'payload' => $payload,
            'direction' => 'up',
        ]);
    }

    /**
     * 记录连接事件
     */
    public static function logConnection(string $source, string $event, array $extra = []): void
    {
        self::log(array_merge([
            'type' => 'connection',
            'source' => $source,
            'topic' => null,
            'event' => $event,
            'direction' => null,
        ], $extra));
    }
    
    /**
     * 记录错误
     */
    public static function logError(string $source, string $message, array $context = []): void
    {
        self::log([
            'type' => 'error',
            'source' => $source,
            'topic' => null,
            'message' => $message,
            'context' => $context,
            'direction' => null,
        ]);
        
        // 同时写入系统日志
        Log::error("【MQTT】{$source}: {$message}", $context);
    }
    
    /**
     * 核心日志方法
     */
    private static function log(array $data): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $id = 'log_' . str_pad(++self::$counter, 10, '0', STR_PAD_LEFT);
        $timestamp = microtime(true);
        
        // 处理 payload
        $payloadSize = 0;
        if (isset($data['payload'])) {
            $payloadStr = is_string($data['payload']) ? $data['payload'] : json_encode($data['payload']);
            $payloadSize = strlen($payloadStr);
            
            if (self::$logPayload) {
                if ($payloadSize > self::$maxPayloadSize) {
                    $data['payload'] = substr($payloadStr, 0, self::$maxPayloadSize) . '...[truncated]';
                    $data['payload_truncated'] = true;
                }
            } else {
                unset($data['payload']);
            }
        }
        
        $log = array_merge([
            'id' => $id,
            'timestamp' => $timestamp,
            'datetime' => date('Y-m-d H:i:s', (int)$timestamp) . '.' . sprintf('%03d', ($timestamp - floor($timestamp)) * 1000),
            'payload_size' => $payloadSize,
        ], $data);
        
        // 添加到环形缓冲区
        self::$logs[] = $log;
        if (count(self::$logs) > self::$maxLogs) {
            array_shift(self::$logs);
        }
        
        // 触发实时回调
        foreach (self::$realtimeCallbacks as $callback) {
            try {
                $callback($log);
            } catch (\Exception $e) {
                // 忽略回调错误
            }
        }
    }
    
    /**
     * 获取日志列表
     */
    public static function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $logs = self::$logs;
        
        // 应用过滤器
        if (!empty($filters)) {
            $logs = array_filter($logs, function ($log) use ($filters) {
                if (isset($filters['type']) && $log['type'] !== $filters['type']) {
                    return false;
                }
                if (isset($filters['source']) && $log['source'] !== $filters['source']) {
                    return false;
                }
                if (isset($filters['topic']) && strpos($log['topic'] ?? '', $filters['topic']) === false) {
                    return false;
                }
                if (isset($filters['direction']) && $log['direction'] !== $filters['direction']) {
                    return false;
                }
                if (isset($filters['since']) && $log['timestamp'] < $filters['since']) {
                    return false;
                }
                return true;
            });
        }
        
        // 倒序（最新的在前）
        $logs = array_reverse($logs);
        
        $total = count($logs);
        $logs = array_slice($logs, $offset, $limit);
        
        return [
            'list' => array_values($logs),
            'total' => $total,
        ];
    }
    
    /**
     * 获取统计信息
     */
    public static function getStats(): array
    {
        $stats = [
            'total' => count(self::$logs),
            'by_type' => [],
            'by_direction' => ['up' => 0, 'down' => 0],
            'recent_errors' => 0,
        ];
        
        $now = microtime(true);
        $oneMinuteAgo = $now - 60;
        
        foreach (self::$logs as $log) {
            // 按类型统计
            $type = $log['type'];
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = 0;
            }
            $stats['by_type'][$type]++;
            
            // 按方向统计
            if ($log['direction'] === 'up') {
                $stats['by_direction']['up']++;
            } elseif ($log['direction'] === 'down') {
                $stats['by_direction']['down']++;
            }
            
            // 最近错误数
            if ($log['type'] === 'error' && $log['timestamp'] > $oneMinuteAgo) {
                $stats['recent_errors']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * 注册实时日志回调
     */
    public static function onLog(callable $callback): void
    {
        self::$realtimeCallbacks[] = $callback;
    }
    
    /**
     * 清空日志
     */
    public static function clear(): void
    {
        self::$logs = [];
        self::$counter = 0;
    }
    
    /**
     * 配置
     */
    public static function configure(array $config): void
    {
        if (isset($config['enabled'])) {
            self::$enabled = (bool)$config['enabled'];
        }
        if (isset($config['max_logs'])) {
            self::$maxLogs = (int)$config['max_logs'];
        }
        if (isset($config['log_payload'])) {
            self::$logPayload = (bool)$config['log_payload'];
        }
        if (isset($config['max_payload_size'])) {
            self::$maxPayloadSize = (int)$config['max_payload_size'];
        }
    }
}
