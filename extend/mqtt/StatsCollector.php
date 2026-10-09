<?php

namespace mqtt;

/**
 * 统计数据收集器
 * 收集和聚合 MQTT 相关的统计数据
 */
class StatsCollector
{
    /** @var array 分钟级统计 [timestamp => stats] */
    private static $minuteStats = [];
    
    /** @var array 当前分钟的计数器 */
    private static $currentMinute = [
        'messages_in' => 0,
        'messages_out' => 0,
        'bytes_in' => 0,
        'bytes_out' => 0,
        'connections' => 0,
        'errors' => 0,
    ];
    
    /** @var int 当前分钟时间戳 */
    private static $currentMinuteTs = 0;
    
    /** @var int 统计数据保留时间（秒） */
    private static $retention = 3600;
    
    /** @var array 主题消息计数 */
    private static $topicCounts = [];
    
    /** @var int 服务启动时间 */
    private static $startTime = 0;
    
    /** @var array 全局计数器 */
    private static $globalStats = [
        'total_messages_in' => 0,
        'total_messages_out' => 0,
        'total_bytes_in' => 0,
        'total_bytes_out' => 0,
        'total_connections' => 0,
        'total_errors' => 0,
        'reconnect_count' => 0,
    ];
    
    /**
     * 初始化
     */
    public static function init(): void
    {
        self::$startTime = time();
        self::$currentMinuteTs = self::getMinuteTimestamp();
    }
    
    /**
     * 记录收到消息
     */
    public static function recordMessageIn(string $topic, int $bytes): void
    {
        self::checkMinuteRollover();
        
        self::$currentMinute['messages_in']++;
        self::$currentMinute['bytes_in'] += $bytes;
        
        self::$globalStats['total_messages_in']++;
        self::$globalStats['total_bytes_in'] += $bytes;
        
        // 主题计数
        if (!isset(self::$topicCounts[$topic])) {
            self::$topicCounts[$topic] = 0;
        }
        self::$topicCounts[$topic]++;
    }
    
    /**
     * 记录发送消息
     */
    public static function recordMessageOut(string $topic, int $bytes): void
    {
        self::checkMinuteRollover();
        
        self::$currentMinute['messages_out']++;
        self::$currentMinute['bytes_out'] += $bytes;
        
        self::$globalStats['total_messages_out']++;
        self::$globalStats['total_bytes_out'] += $bytes;
    }
    
    /**
     * 记录连接
     */
    public static function recordConnection(): void
    {
        self::checkMinuteRollover();
        self::$currentMinute['connections']++;
        self::$globalStats['total_connections']++;
    }
    
    /**
     * 记录错误
     */
    public static function recordError(): void
    {
        self::checkMinuteRollover();
        self::$currentMinute['errors']++;
        self::$globalStats['total_errors']++;
    }
    
    /**
     * 记录重连
     */
    public static function recordReconnect(): void
    {
        self::$globalStats['reconnect_count']++;
    }

    /**
     * 检查分钟翻转
     */
    private static function checkMinuteRollover(): void
    {
        $currentTs = self::getMinuteTimestamp();
        
        if ($currentTs !== self::$currentMinuteTs) {
            // 保存上一分钟的统计
            if (self::$currentMinuteTs > 0) {
                self::$minuteStats[self::$currentMinuteTs] = self::$currentMinute;
            }
            
            // 重置当前分钟计数器
            self::$currentMinute = [
                'messages_in' => 0,
                'messages_out' => 0,
                'bytes_in' => 0,
                'bytes_out' => 0,
                'connections' => 0,
                'errors' => 0,
            ];
            
            self::$currentMinuteTs = $currentTs;
            
            // 清理过期数据
            self::cleanup();
        }
    }
    
    /**
     * 获取分钟时间戳
     */
    private static function getMinuteTimestamp(): int
    {
        return (int)(time() / 60) * 60;
    }
    
    /**
     * 清理过期数据
     */
    private static function cleanup(): void
    {
        $cutoff = time() - self::$retention;
        
        foreach (self::$minuteStats as $ts => $stats) {
            if ($ts < $cutoff) {
                unset(self::$minuteStats[$ts]);
            }
        }
    }
    
    /**
     * 获取吞吐量统计（按分钟）
     */
    public static function getThroughput(int $minutes = 60): array
    {
        self::checkMinuteRollover();
        
        $result = [];
        $now = self::getMinuteTimestamp();
        
        for ($i = $minutes - 1; $i >= 0; $i--) {
            $ts = $now - ($i * 60);
            $stats = self::$minuteStats[$ts] ?? [
                'messages_in' => 0,
                'messages_out' => 0,
                'bytes_in' => 0,
                'bytes_out' => 0,
            ];
            
            $result[] = [
                'time' => date('H:i', $ts),
                'timestamp' => $ts,
                'in' => $stats['messages_in'],
                'out' => $stats['messages_out'],
                'bytes_in' => $stats['bytes_in'],
                'bytes_out' => $stats['bytes_out'],
            ];
        }
        
        // 添加当前分钟（进行中）
        $result[] = [
            'time' => date('H:i', $now),
            'timestamp' => $now,
            'in' => self::$currentMinute['messages_in'],
            'out' => self::$currentMinute['messages_out'],
            'bytes_in' => self::$currentMinute['bytes_in'],
            'bytes_out' => self::$currentMinute['bytes_out'],
            'current' => true,
        ];
        
        return $result;
    }
    
    /**
     * 获取热门主题
     */
    public static function getTopTopics(int $limit = 10): array
    {
        arsort(self::$topicCounts);
        
        $result = [];
        $i = 0;
        foreach (self::$topicCounts as $topic => $count) {
            if ($i >= $limit) break;
            $result[] = [
                'topic' => $topic,
                'count' => $count,
            ];
            $i++;
        }
        
        return $result;
    }
    
    /**
     * 获取全局统计
     */
    public static function getGlobalStats(): array
    {
        return array_merge(self::$globalStats, [
            'uptime' => time() - self::$startTime,
            'start_time' => self::$startTime,
        ]);
    }
    
    /**
     * 获取当前速率（每秒）
     */
    public static function getCurrentRate(): array
    {
        // 使用最近一分钟的数据计算平均速率
        $lastMinute = self::$minuteStats[self::$currentMinuteTs - 60] ?? self::$currentMinute;
        
        return [
            'messages_in_per_sec' => round($lastMinute['messages_in'] / 60, 2),
            'messages_out_per_sec' => round($lastMinute['messages_out'] / 60, 2),
            'bytes_in_per_sec' => round($lastMinute['bytes_in'] / 60, 2),
            'bytes_out_per_sec' => round($lastMinute['bytes_out'] / 60, 2),
        ];
    }
    
    /**
     * 获取完整统计报告
     */
    public static function getReport(): array
    {
        return [
            'global' => self::getGlobalStats(),
            'current_rate' => self::getCurrentRate(),
            'throughput' => self::getThroughput(30),
            'top_topics' => self::getTopTopics(10),
            'pool' => ConnectionPool::getSummary(),
            'router' => MessageRouter::getStats(),
            'logs' => ActionLogger::getStats(),
        ];
    }
    
    /**
     * 设置保留时间
     */
    public static function setRetention(int $seconds): void
    {
        self::$retention = $seconds;
    }
}
