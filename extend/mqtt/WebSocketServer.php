<?php

namespace mqtt;

use think\facade\Log;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;

/**
 * WebSocket 服务器
 * 作为前端和 MQTT Broker 之间的代理
 */
class WebSocketServer
{
    /** @var Worker WebSocket Worker */
    private $wsWorker;
    
    /** @var \Workerman\Mqtt\Client MQTT 客户端引用 */
    private $mqttClient;
    
    /** @var bool MQTT 连接状态 */
    private $mqttConnected = false;
    
    /** @var array 配置 */
    private $config;
    
    /** @var int 服务启动时间 */
    private $startTime;
    
    /** @var array MQTT 已订阅的主题 */
    private $mqttSubscribedTopics = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '0.0.0.0',
            'port' => 8282,
            'max_connections' => 100,
            'heartbeat_interval' => 30,
        ], $config);
        
        ConnectionPool::setMaxConnections($this->config['max_connections']);
    }
    
    /**
     * 设置 MQTT 客户端引用
     */
    public function setMqttClient($mqttClient): void
    {
        $this->mqttClient = $mqttClient;
        $this->mqttConnected = ($mqttClient !== null);
        
        if ($mqttClient === null) {
            Log::debug("【WS】MQTT 客户端已断开，清空引用");
        }
    }
    
    /**
     * 设置 MQTT 连接状态
     */
    public function setMqttConnected(bool $connected): void
    {
        $this->mqttConnected = $connected;
    }
    
    /**
     * 启动 WebSocket 服务
     */
    public function start(): Worker
    {
        $this->startTime = time();
        $address = "websocket://{$this->config['host']}:{$this->config['port']}";
        
        $this->wsWorker = new Worker($address);
        $this->wsWorker->name = 'MqttWebSocketProxy';
        
        $this->wsWorker->onConnect = [$this, 'onConnect'];
        $this->wsWorker->onMessage = [$this, 'onMessage'];
        $this->wsWorker->onClose = [$this, 'onClose'];
        $this->wsWorker->onError = [$this, 'onError'];
        
        Log::info("【WS】WebSocket 服务启动: {$address}");
        
        return $this->wsWorker;
    }

    /**
     * 连接建立
     */
    public function onConnect(TcpConnection $connection): void
    {
        if (ConnectionPool::isFull()) {
            $connection->send(json_encode([
                'type' => 'error',
                'code' => 'MAX_CONNECTIONS',
                'message' => '连接数已达上限',
            ]));
            $connection->close();
            return;
        }
        
        $wsId = ConnectionPool::add($connection);
        
        ActionLogger::logConnection($wsId, 'connected', [
            'ip' => $connection->getRemoteIp(),
        ]);
        
        Log::info("【WS】新连接: {$wsId}, IP: {$connection->getRemoteIp()}");
        
        // 发送欢迎消息
        $connection->send(json_encode([
            'type' => 'welcome',
            'client_id' => $wsId,
            'message' => '请发送 auth 消息进行认证',
        ]));
    }
    
    /**
     * 收到消息
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $wsId = $connection->wsId ?? null;
        if (!$wsId) {
            return;
        }
        
        $msg = json_decode($data, true);
        if (!$msg || !isset($msg['action'])) {
            $this->sendError($connection, 'INVALID_MESSAGE', '无效的消息格式');
            return;
        }
        
        ConnectionPool::updateStats($wsId, 'received', strlen($data));
        
        $action = $msg['action'];
        
        switch ($action) {
            case 'auth':
                $this->handleAuth($connection, $wsId, $msg);
                break;
                
            case 'subscribe':
                $this->handleSubscribe($connection, $wsId, $msg);
                break;
                
            case 'unsubscribe':
                $this->handleUnsubscribe($connection, $wsId, $msg);
                break;
                
            case 'publish':
                $this->handlePublish($connection, $wsId, $msg);
                break;
                
            case 'status':
                $this->handleStatus($connection, $wsId);
                break;
                
            case 'ping':
                $connection->send(json_encode(['type' => 'pong', 'timestamp' => microtime(true)]));
                break;
                
            default:
                $this->sendError($connection, 'UNKNOWN_ACTION', "未知的操作: {$action}");
        }
    }
    
    /**
     * 处理认证
     */
    private function handleAuth(TcpConnection $connection, string $wsId, array $msg): void
    {
        $token = $msg['token'] ?? '';
        
        // TODO: 实现真正的 Token 验证
        // 这里简化处理，实际应该验证 JWT Token
        $userInfo = $this->validateToken($token);
        
        if ($userInfo) {
            ConnectionPool::authenticate($wsId, $userInfo);
            ActionLogger::logConnection($wsId, 'authenticated', ['user' => $userInfo['user_name']]);
            
            $connection->send(json_encode([
                'type' => 'auth_result',
                'success' => true,
                'client_id' => $wsId,
                'user' => $userInfo['user_name'],
            ]));
            
            Log::info("【WS】认证成功: {$wsId}, 用户: {$userInfo['user_name']}");
        } else {
            ActionLogger::logConnection($wsId, 'auth_failed');
            $this->sendError($connection, 'AUTH_FAILED', '认证失败');
        }
    }
    
    /**
     * 验证 Token（简化版）
     */
    private function validateToken(string $token): ?array
    {
        // TODO: 实现真正的 JWT 验证
        // 暂时允许所有连接
        if (empty($token)) {
            return ['user_id' => 0, 'user_name' => 'anonymous'];
        }
        
        // 简单解析 Bearer token
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }
        
        // 这里应该调用 ThinkPHP 的 Token 验证
        return ['user_id' => 1, 'user_name' => 'admin'];
    }
    
    /**
     * 处理订阅
     */
    private function handleSubscribe(TcpConnection $connection, string $wsId, array $msg): void
    {
        $topic = $msg['topic'] ?? '';
        if (empty($topic)) {
            $this->sendError($connection, 'INVALID_TOPIC', '主题不能为空');
            return;
        }
        
        // 添加到消息路由
        MessageRouter::subscribe($wsId, $topic);
        
        // 确保 MQTT 客户端订阅了该主题
        $this->ensureMqttSubscription($topic);
        
        ActionLogger::logSubscribe($wsId, $topic);
        
        // 调试：打印当前订阅统计
        $stats = MessageRouter::getStats();
        Log::info("【WS】订阅: {$wsId} -> {$topic}, 当前总订阅数: {$stats['total_subscriptions']}, 主题数: {$stats['total_topics']}");
        
        $connection->send(json_encode([
            'type' => 'subscribed',
            'topic' => $topic,
        ]));
    }

    /**
     * 确保 MQTT 订阅
     */
    private function ensureMqttSubscription(string $topic): void
    {
        if (!$this->mqttClient || !$this->mqttConnected) {
            Log::debug("【WS】MQTT 未连接，跳过订阅: {$topic}");
            return;
        }
        
        // 检查是否已订阅
        if (isset($this->mqttSubscribedTopics[$topic])) {
            return;
        }
        
        // 检查 MQTT 连接状态（通过尝试订阅来判断）
        try {
            // 订阅 MQTT 主题
            $this->mqttClient->subscribe($topic);
            $this->mqttSubscribedTopics[$topic] = true;
            
            Log::info("【WS】MQTT 新订阅: {$topic}");
        } catch (\Exception $e) {
            Log::warning("【WS】MQTT 订阅失败 (可能断开): {$topic}, 错误: " . $e->getMessage());
        }
    }
    
    /**
     * 处理取消订阅
     */
    private function handleUnsubscribe(TcpConnection $connection, string $wsId, array $msg): void
    {
        $topic = $msg['topic'] ?? '';
        if (empty($topic)) {
            $this->sendError($connection, 'INVALID_TOPIC', '主题不能为空');
            return;
        }
        
        MessageRouter::unsubscribe($wsId, $topic);
        ActionLogger::logUnsubscribe($wsId, $topic);
        
        $connection->send(json_encode([
            'type' => 'unsubscribed',
            'topic' => $topic,
        ]));
        
        Log::info("【WS】取消订阅: {$wsId} -> {$topic}");
    }
    
    /**
     * 处理发布
     */
    private function handlePublish(TcpConnection $connection, string $wsId, array $msg): void
    {
        $topic = $msg['topic'] ?? '';
        $payload = $msg['payload'] ?? [];
        
        if (empty($topic)) {
            $this->sendError($connection, 'INVALID_TOPIC', '主题不能为空');
            return;
        }
        
        if (!$this->mqttClient) {
            $this->sendError($connection, 'MQTT_NOT_CONNECTED', 'MQTT 未连接');
            return;
        }
        
        $startTime = microtime(true);
        
        // 发布到 MQTT
        $payloadStr = is_string($payload) ? $payload : json_encode($payload);
        $this->mqttClient->publish($topic, $payloadStr);
        
        $latency = (microtime(true) - $startTime) * 1000;
        
        ActionLogger::logPublish($wsId, $topic, $payload, $latency);
        ConnectionPool::updateStats($wsId, 'sent', strlen($payloadStr));
        
        $connection->send(json_encode([
            'type' => 'published',
            'topic' => $topic,
            'latency' => round($latency, 2),
        ]));
        
        Log::debug("【WS】发布: {$wsId} -> {$topic}, 延迟: {$latency}ms");
    }
    
    /**
     * 处理状态查询
     */
    private function handleStatus(TcpConnection $connection, string $wsId): void
    {
        $connInfo = ConnectionPool::get($wsId);
        $poolSummary = ConnectionPool::getSummary();
        $routerStats = MessageRouter::getStats();
        
        $connection->send(json_encode([
            'type' => 'status',
            'mqtt' => [
                'connected' => $this->mqttConnected,
                'uptime' => time() - $this->startTime,
                'subscribed_topics' => count($this->mqttSubscribedTopics),
            ],
            'websocket' => [
                'port' => $this->config['port'],
                'connections' => $poolSummary['connections'],
                'max_connections' => $poolSummary['max_connections'],
            ],
            'your_connection' => [
                'id' => $wsId,
                'subscriptions' => $connInfo['subscriptions'] ?? [],
                'stats' => $connInfo['stats'] ?? [],
            ],
            'router' => $routerStats,
        ]));
    }
    
    /**
     * 连接关闭
     */
    public function onClose(TcpConnection $connection): void
    {
        $wsId = $connection->wsId ?? null;
        if (!$wsId) {
            return;
        }
        
        // 清理订阅
        MessageRouter::removeAll($wsId);
        
        // 移除连接
        ConnectionPool::remove($wsId);
        
        ActionLogger::logConnection($wsId, 'disconnected');
        
        Log::info("【WS】连接关闭: {$wsId}");
    }
    
    /**
     * 连接错误
     */
    public function onError(TcpConnection $connection, $code, $msg): void
    {
        $wsId = $connection->wsId ?? 'unknown';
        ActionLogger::logError($wsId, "连接错误: {$msg}", ['code' => $code]);
        Log::error("【WS】连接错误: {$wsId}, code={$code}, msg={$msg}");
    }
    
    /**
     * 发送错误消息
     */
    private function sendError(TcpConnection $connection, string $code, string $message): void
    {
        $connection->send(json_encode([
            'type' => 'error',
            'code' => $code,
            'message' => $message,
        ]));
    }
    
    /**
     * 转发 MQTT 消息到 WebSocket 客户端
     */
    public function broadcastMqttMessage(string $topic, string $content): void
    {
        $subscribers = MessageRouter::getSubscribers($topic);
        
        // 调试：记录所有 OSD 消息的转发情况
        if (strpos($topic, '/osd') !== false) {
            $stats = MessageRouter::getStats();
            Log::info("【WS】OSD 消息转发检查: {$topic}, 订阅者数: " . count($subscribers) . ", 总订阅数: {$stats['total_subscriptions']}, 主题数: {$stats['total_topics']}");
        }
        
        if (empty($subscribers)) {
            return;
        }
        
        ActionLogger::logMessage($topic, $content);
        
        // 尝试解析 JSON，如果失败则保持原样
        $payload = json_decode($content, true);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            $payload = $content;
        }
        
        $message = json_encode([
            'type' => 'message',
            'topic' => $topic,
            'payload' => $payload,
            'timestamp' => microtime(true) * 1000,
        ]);
        
        $messageLen = strlen($message);
        
        Log::info("【WS】转发消息: {$topic} -> " . count($subscribers) . " 个订阅者");
        
        foreach ($subscribers as $wsId) {
            $connection = ConnectionPool::getConnection($wsId);
            if ($connection) {
                $connection->send($message);
                ConnectionPool::updateStats($wsId, 'sent', $messageLen);
                Log::debug("【WS】消息已发送到: {$wsId}");
            } else {
                Log::warning("【WS】订阅者连接不存在: {$wsId}");
            }
        }
    }
    
    /**
     * 获取服务状态
     */
    public function getStatus(): array
    {
        return [
            'port' => $this->config['port'],
            'uptime' => time() - $this->startTime,
            'connections' => ConnectionPool::count(),
            'max_connections' => $this->config['max_connections'],
            'mqtt_topics' => count($this->mqttSubscribedTopics),
        ];
    }
}
