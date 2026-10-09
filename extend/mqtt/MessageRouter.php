<?php

namespace mqtt;

/**
 * 消息路由器
 * 维护 Topic 到 WebSocket 连接的映射关系
 */
class MessageRouter
{
    /** @var array Topic 订阅映射 [topic => [ws_id => true]] */
    private static $subscriptions = [];
    
    /** @var array 通配符订阅 [pattern => [ws_id => true]] */
    private static $wildcardSubscriptions = [];
    
    /**
     * 添加订阅
     */
    public static function subscribe(string $wsId, string $topic): void
    {
        if (self::isWildcard($topic)) {
            if (!isset(self::$wildcardSubscriptions[$topic])) {
                self::$wildcardSubscriptions[$topic] = [];
            }
            self::$wildcardSubscriptions[$topic][$wsId] = true;
        } else {
            if (!isset(self::$subscriptions[$topic])) {
                self::$subscriptions[$topic] = [];
            }
            self::$subscriptions[$topic][$wsId] = true;
        }
        
        // 同步到连接池
        ConnectionPool::addSubscription($wsId, $topic);
    }
    
    /**
     * 取消订阅
     */
    public static function unsubscribe(string $wsId, string $topic): void
    {
        if (self::isWildcard($topic)) {
            if (isset(self::$wildcardSubscriptions[$topic][$wsId])) {
                unset(self::$wildcardSubscriptions[$topic][$wsId]);
                if (empty(self::$wildcardSubscriptions[$topic])) {
                    unset(self::$wildcardSubscriptions[$topic]);
                }
            }
        } else {
            if (isset(self::$subscriptions[$topic][$wsId])) {
                unset(self::$subscriptions[$topic][$wsId]);
                if (empty(self::$subscriptions[$topic])) {
                    unset(self::$subscriptions[$topic]);
                }
            }
        }
        
        ConnectionPool::removeSubscription($wsId, $topic);
    }
    
    /**
     * 移除连接的所有订阅
     */
    public static function removeAll(string $wsId): void
    {
        // 移除精确订阅
        foreach (self::$subscriptions as $topic => &$subscribers) {
            unset($subscribers[$wsId]);
            if (empty($subscribers)) {
                unset(self::$subscriptions[$topic]);
            }
        }
        
        // 移除通配符订阅
        foreach (self::$wildcardSubscriptions as $pattern => &$subscribers) {
            unset($subscribers[$wsId]);
            if (empty($subscribers)) {
                unset(self::$wildcardSubscriptions[$pattern]);
            }
        }
    }

    /**
     * 获取订阅了指定 Topic 的所有连接ID
     */
    public static function getSubscribers(string $topic): array
    {
        $subscribers = [];
        
        // 精确匹配
        if (isset(self::$subscriptions[$topic])) {
            $subscribers = array_merge($subscribers, array_keys(self::$subscriptions[$topic]));
        }
        
        // 通配符匹配
        foreach (self::$wildcardSubscriptions as $pattern => $wsIds) {
            if (self::matchWildcard($pattern, $topic)) {
                $subscribers = array_merge($subscribers, array_keys($wsIds));
            }
        }
        
        return array_unique($subscribers);
    }
    
    /**
     * 检查是否为通配符主题
     */
    private static function isWildcard(string $topic): bool
    {
        return strpos($topic, '+') !== false || strpos($topic, '#') !== false;
    }
    
    /**
     * 通配符匹配
     * + 匹配单层
     * # 匹配多层（只能在末尾）
     */
    private static function matchWildcard(string $pattern, string $topic): bool
    {
        $patternParts = explode('/', $pattern);
        $topicParts = explode('/', $topic);
        
        $patternLen = count($patternParts);
        $topicLen = count($topicParts);
        
        for ($i = 0; $i < $patternLen; $i++) {
            $p = $patternParts[$i];
            
            // # 匹配剩余所有层级
            if ($p === '#') {
                return true;
            }
            
            // 主题层级不足
            if ($i >= $topicLen) {
                return false;
            }
            
            // + 匹配单层，任意值都可以
            if ($p === '+') {
                continue;
            }
            
            // 精确匹配
            if ($p !== $topicParts[$i]) {
                return false;
            }
        }
        
        // 长度必须相等（除非有 #）
        return $patternLen === $topicLen;
    }
    
    /**
     * 获取所有订阅的主题
     */
    public static function getAllTopics(): array
    {
        return array_merge(
            array_keys(self::$subscriptions),
            array_keys(self::$wildcardSubscriptions)
        );
    }
    
    /**
     * 获取需要向 MQTT Broker 订阅的主题
     * 返回去重后的主题列表
     */
    public static function getMqttTopics(): array
    {
        $topics = [];
        
        // 收集所有精确主题
        foreach (array_keys(self::$subscriptions) as $topic) {
            $topics[$topic] = true;
        }
        
        // 收集所有通配符主题
        foreach (array_keys(self::$wildcardSubscriptions) as $pattern) {
            $topics[$pattern] = true;
        }
        
        return array_keys($topics);
    }
    
    /**
     * 检查主题是否有订阅者
     */
    public static function hasSubscribers(string $topic): bool
    {
        return !empty(self::getSubscribers($topic));
    }
    
    /**
     * 获取订阅统计
     */
    public static function getStats(): array
    {
        $totalSubscribers = 0;
        $topicStats = [];
        
        foreach (self::$subscriptions as $topic => $subscribers) {
            $count = count($subscribers);
            $totalSubscribers += $count;
            $topicStats[$topic] = $count;
        }
        
        foreach (self::$wildcardSubscriptions as $pattern => $subscribers) {
            $count = count($subscribers);
            $totalSubscribers += $count;
            $topicStats[$pattern] = $count;
        }
        
        return [
            'total_topics' => count(self::$subscriptions) + count(self::$wildcardSubscriptions),
            'total_subscriptions' => $totalSubscribers,
            'exact_topics' => count(self::$subscriptions),
            'wildcard_topics' => count(self::$wildcardSubscriptions),
            'topic_stats' => $topicStats,
        ];
    }
    
    /**
     * 清空所有订阅
     */
    public static function clear(): void
    {
        self::$subscriptions = [];
        self::$wildcardSubscriptions = [];
    }
}
