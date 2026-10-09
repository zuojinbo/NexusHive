<?php

namespace mqtt;

/**
 * WebSocket 连接池管理
 * 管理所有 WebSocket 客户端连接，提供连接状态查询和统计
 */
class ConnectionPool
{
    /** @var array 连接存储 [id => connection_info] */
    private static $connections = [];
    
    /** @var array 连接对象映射 [id => connection_object] */
    private static $connectionObjects = [];
    
    /** @var int 连接计数器 */
    private static $counter = 0;
    
    /** @var int 最大连接数 */
    private static $maxConnections = 100;
    
    /**
     * 添加连接
     */
    public static function add($connection, array $userInfo = []): string
    {
        $id = 'ws_' . str_pad(++self::$counter, 6, '0', STR_PAD_LEFT);
        
        self::$connections[$id] = [
            'id' => $id,
            'user_id' => $userInfo['user_id'] ?? null,
            'user_name' => $userInfo['user_name'] ?? 'anonymous',
            'ip' => $connection->getRemoteIp() ?? 'unknown',
            'connected_at' => time(),
            'last_active' => time(),
            'subscriptions' => [],
            'stats' => [
                'messages_received' => 0,
                'messages_sent' => 0,
                'bytes_received' => 0,
                'bytes_sent' => 0,
            ],
            'authenticated' => false,
        ];
        
        self::$connectionObjects[$id] = $connection;
        $connection->wsId = $id;
        
        return $id;
    }
    
    /**
     * 移除连接
     */
    public static function remove(string $id): bool
    {
        if (isset(self::$connections[$id])) {
            unset(self::$connections[$id]);
            unset(self::$connectionObjects[$id]);
            return true;
        }
        return false;
    }

    /**
     * 获取连接信息
     */
    public static function get(string $id): ?array
    {
        return self::$connections[$id] ?? null;
    }
    
    /**
     * 获取连接对象
     */
    public static function getConnection(string $id)
    {
        return self::$connectionObjects[$id] ?? null;
    }
    
    /**
     * 获取所有连接
     */
    public static function getAll(): array
    {
        return self::$connections;
    }
    
    /**
     * 获取所有连接对象
     */
    public static function getAllConnections(): array
    {
        return self::$connectionObjects;
    }
    
    /**
     * 更新连接认证状态
     */
    public static function authenticate(string $id, array $userInfo): bool
    {
        if (!isset(self::$connections[$id])) {
            return false;
        }
        
        self::$connections[$id]['authenticated'] = true;
        self::$connections[$id]['user_id'] = $userInfo['user_id'] ?? null;
        self::$connections[$id]['user_name'] = $userInfo['user_name'] ?? 'anonymous';
        self::$connections[$id]['last_active'] = time();
        
        return true;
    }
    
    /**
     * 添加订阅
     */
    public static function addSubscription(string $id, string $topic): bool
    {
        if (!isset(self::$connections[$id])) {
            return false;
        }
        
        if (!in_array($topic, self::$connections[$id]['subscriptions'])) {
            self::$connections[$id]['subscriptions'][] = $topic;
        }
        self::$connections[$id]['last_active'] = time();
        
        return true;
    }
    
    /**
     * 移除订阅
     */
    public static function removeSubscription(string $id, string $topic): bool
    {
        if (!isset(self::$connections[$id])) {
            return false;
        }
        
        $key = array_search($topic, self::$connections[$id]['subscriptions']);
        if ($key !== false) {
            unset(self::$connections[$id]['subscriptions'][$key]);
            self::$connections[$id]['subscriptions'] = array_values(self::$connections[$id]['subscriptions']);
        }
        
        return true;
    }
    
    /**
     * 更新统计
     */
    public static function updateStats(string $id, string $type, int $bytes = 0): void
    {
        if (!isset(self::$connections[$id])) {
            return;
        }
        
        self::$connections[$id]['last_active'] = time();
        
        switch ($type) {
            case 'received':
                self::$connections[$id]['stats']['messages_received']++;
                self::$connections[$id]['stats']['bytes_received'] += $bytes;
                break;
            case 'sent':
                self::$connections[$id]['stats']['messages_sent']++;
                self::$connections[$id]['stats']['bytes_sent'] += $bytes;
                break;
        }
    }
    
    /**
     * 获取连接数
     */
    public static function count(): int
    {
        return count(self::$connections);
    }
    
    /**
     * 检查是否达到最大连接数
     */
    public static function isFull(): bool
    {
        return self::count() >= self::$maxConnections;
    }
    
    /**
     * 设置最大连接数
     */
    public static function setMaxConnections(int $max): void
    {
        self::$maxConnections = $max;
    }
    
    /**
     * 获取汇总统计
     */
    public static function getSummary(): array
    {
        $total_received = 0;
        $total_sent = 0;
        $total_bytes_in = 0;
        $total_bytes_out = 0;
        $all_subscriptions = [];
        
        foreach (self::$connections as $conn) {
            $total_received += $conn['stats']['messages_received'];
            $total_sent += $conn['stats']['messages_sent'];
            $total_bytes_in += $conn['stats']['bytes_received'];
            $total_bytes_out += $conn['stats']['bytes_sent'];
            $all_subscriptions = array_merge($all_subscriptions, $conn['subscriptions']);
        }
        
        return [
            'connections' => self::count(),
            'max_connections' => self::$maxConnections,
            'messages_received' => $total_received,
            'messages_sent' => $total_sent,
            'bytes_received' => $total_bytes_in,
            'bytes_sent' => $total_bytes_out,
            'unique_subscriptions' => count(array_unique($all_subscriptions)),
        ];
    }
    
    /**
     * 清理不活跃连接
     */
    public static function cleanInactive(int $timeout = 300): array
    {
        $now = time();
        $cleaned = [];
        
        foreach (self::$connections as $id => $conn) {
            if ($now - $conn['last_active'] > $timeout) {
                $cleaned[] = $id;
                if (isset(self::$connectionObjects[$id])) {
                    self::$connectionObjects[$id]->close();
                }
                self::remove($id);
            }
        }
        
        return $cleaned;
    }
}
