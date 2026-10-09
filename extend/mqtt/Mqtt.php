<?php

namespace mqtt;

use app\admin\model\Equipment;
use dji\Airline;
use dji\Main;
use dji\TaskScheduler;
use think\facade\Db;
use think\facade\Log;
use think\worker\Server;
use Workerman\Lib\Timer;
use Workerman\Worker;

// mqtt类继承think\worker\Server
class Mqtt extends Server
{
    // Worker 监听地址（必须定义）
    protected $socket = 'text://0.0.0.0:8283';
    
    private $connection = null;
    private $main = null;
    private $airline = null;
    private $taskScheduler = null;
    private $isConnected = false;
    
    /** @var WebSocketServer WebSocket 代理服务 */
    private $wsServer = null;
    
    /** @var int 服务启动时间 */
    private $startTime = 0;
    
    /**
     * 订阅设备相关的所有主题
     * @param object $mqtt MQTT客户端实例
     * @param string $sn 设备序列号
     */
    private function subscribeEquipmentTopics($mqtt, $sn)
    {
        $topics = [
            "thing/product/{$sn}/events",
            "sys/product/{$sn}/status", 
            "thing/product/{$sn}/osd",
            "thing/product/{$sn}/requests",
            "thing/product/{$sn}/services_reply",
            "thing/product/{$sn}/flighttask_progress"
        ];
        
        foreach ($topics as $topic) {
            $mqtt->subscribe($topic);
        }
    }
    
    public function onWorkerStart($worker)
    {
        $this->startTime = time();
        $this->main = new Main();
        $this->airline = new Airline();
        $this->taskScheduler = new TaskScheduler();
        
        // 初始化统计收集器
        StatsCollector::init();
        
        // 配置日志记录器
        ActionLogger::configure([
            'enabled' => true,
            'max_logs' => 10000,
            'log_payload' => true,
            'max_payload_size' => 1024,
        ]);
        
        // 生成唯一的 Client ID，避免多实例冲突
        $baseClientId = env('MQTT_CLIENT_ID', 'PUBLISH0001');
        $uniqueClientId = $baseClientId . '_' . substr(md5(gethostname() . getmypid()), 0, 8);
        
        $options = [
            'keepalive' => 60,
            'client_id' => $uniqueClientId,
            'clean_session' => true,
            'reconnect_period' => 5,  // 5秒后自动重连
            'username' => env('MQTT_USERNAME', 'wangxudong'),
            'password' => env('MQTT_PASSWORD', 'Admin@1234567890')
        ];
        
        $mqttHost = env('MQTT_HOST', '');
        $mqttPort = env('MQTT_PORT', 1883);
        
        Log::info("【MQTT】准备连接: mqtt://{$mqttHost}:{$mqttPort}, ClientID: {$uniqueClientId}");
        
        $mqtt = new \Workerman\Mqtt\Client("mqtt://{$mqttHost}:{$mqttPort}", $options);
        
        // 获取设备
        $list = Equipment::select();
        $this->connection = $mqtt;
        
        // 初始化 WebSocket 代理服务
        $this->initWebSocketServer();
        
        // 订阅mqtt主题消息
        $mqtt->onConnect = function ($mqtt) {
            $this->isConnected = true;
            Log::info('【MQTT】连接成功，开始订阅主题...');
            ActionLogger::logConnection('mqtt', 'connected', ['broker' => env('MQTT_HOST', '')]);
            
            // 更新 WebSocket 服务的 MQTT 客户端引用
            if ($this->wsServer) {
                $this->wsServer->setMqttClient($this->connection);
                Log::info('【MQTT】已更新 WebSocket 服务的 MQTT 客户端引用');
            }
            
            // 订阅系统设备添加主题
            $mqtt->subscribe('system/equipment/set/equipment_add');
            
            // 重新获取最新的设备列表（包含动态发现并入库的子设备）
            $latestList = Equipment::select();
            foreach ($latestList as $equipment) {
                $this->subscribeEquipmentTopics($mqtt, $equipment['sn']);
            }
            
            Log::info('【MQTT】主题订阅完成，共订阅 ' . count($latestList) . ' 个设备（含子设备）');
        };
        
        // 连接断开回调
        $mqtt->onClose = function () {
            $this->isConnected = false;
            Log::warning('【MQTT】连接断开，将在 5 秒后自动重连...');
            ActionLogger::logConnection('mqtt', 'disconnected');
            
            // 清空 WebSocket 服务的 MQTT 客户端引用，避免在断开时尝试订阅
            if ($this->wsServer) {
                $this->wsServer->setMqttClient(null);
            }
        };
        
        // 连接错误回调
        $mqtt->onError = function ($exception) {
            $this->isConnected = false;
            $msg = $exception instanceof \Exception ? $exception->getMessage() : (string)$exception;
            Log::error("【MQTT】连接错误: {$msg}");
            ActionLogger::logError('mqtt', "连接错误: {$msg}");
            StatsCollector::recordError();
        };
        
        // 重连成功回调
        $mqtt->onReconnect = function () {
            Log::info('【MQTT】正在尝试重新连接...');
            ActionLogger::logConnection('mqtt', 'reconnecting');
            StatsCollector::recordReconnect();
        };
        
        $mqtt->onMessage = function ($topic, $content, $mqtt) {
            // 记录统计
            StatsCollector::recordMessageIn($topic, strlen($content));
            
            // 调试：记录收到的消息
            if (strpos($topic, '/osd') !== false) {
                Log::debug("【MQTT】收到 OSD 消息: {$topic}");
            }
            
            // 转发给 WebSocket 客户端
            if ($this->wsServer) {
                $this->wsServer->broadcastMqttMessage($topic, $content);
            }
            
            // 原有业务逻辑处理
            $topicArr = explode('/', $topic);
            if(count($topicArr) == 4){
                $data = json_decode($content, true);
                $data['sn'] = $topicArr[2];
                switch($topicArr[3]){
                    case 'equipment_add':
                        //新设备入场 增加对应订阅
                        $this->subscribeEquipmentTopics($mqtt, $data['new_sn']);
                        break;
                    case 'events':
                        $this->main->responseEvents($data);
                        break;
                    case 'requests':
                        $this->main->responseRequest($data);
                        break;
                    case 'services_reply':
                        $this->main->responseServicesReply($data);
                        break;
                    case 'osd':
                        //同步订阅子设备
                        if(isset($data['data']['sub_device']['device_sn'])){
                            $this->connection->subscribe('thing/product/' . $data['data']['sub_device']['device_sn'] . '/osd');
                        }
                        $this->main->responseOsd($data);
                        break;
                    case 'status':
                        $this->main->responseStatus($data);
                        break;
                }
            }
        };
        
        $mqtt->connect();
        
        $inner_text_worker = new Worker('text://0.0.0.0:1884'); //内部调用端口
        $inner_text_worker->onMessage = function ($connection, $message) {
            $arr = json_decode($message, true);
            $topic = $arr['topic'] ?? '';
            
            // 处理监控命令
            if (strpos($topic, 'internal/monitor/') === 0) {
                $this->handleMonitorCommand($connection, $arr);
                return;
            }
            
            unset($arr['topic']);
            
            // 检查连接状态，如果断开则直接返回错误，避免阻塞 Worker 进程
            if (!$this->connection || !$this->isConnected) {
                Log::error('【MQTT】发布失败: Mqtt client: No connection to broker (已断开)');
                $connection->send('error: Mqtt client: No connection to broker');
                return;
            }
            
            try {
                $payload = json_encode($arr);
                $this->connection->publish($topic, $payload);
                
                // 记录统计
                StatsCollector::recordMessageOut($topic, strlen($payload));
                ActionLogger::logPublish('backend', $topic, $arr);
                
                $connection->send('ok');
            } catch (\Exception $e) {
                Log::error('【MQTT】发布消息失败: ' . $e->getMessage());
                ActionLogger::logError('backend', '发布消息失败: ' . $e->getMessage());
                $connection->send('error: ' . $e->getMessage());
            }
        };
        $inner_text_worker->listen();

        // 定时任务：检查待执行的定时任务（每5秒）
        Timer::add(5, function () {
            // 检查 MQTT 连接状态
            if (!$this->isConnected) {
                Log::warning('【定时任务】MQTT 未连接，跳过任务检查');
                return;
            }
            
            $now = time();
            $taskList = Db::name('flighttask')
                ->where('status', 'sent')
                ->where('task_type', '1')
                ->where('execute_time', '<=', $now)
                ->select()
                ->toArray();
            
            if ($taskList && count($taskList) > 0) {
                Log::info('【定时任务】发现 ' . count($taskList) . ' 个待执行定时任务');
                $claimedTasks = [];
                foreach ($taskList as $task) {
                    $claimed = Db::name('flighttask')
                        ->where('id', $task['id'])
                        ->where('status', 'sent')
                        ->where('task_type', '1')
                        ->where('execute_time', '<=', $now)
                        ->update([
                            'status' => 'in_progress',
                            'update_time' => $now,
                        ]);

                    if (!$claimed) {
                        Log::info("【定时任务】任务已被其他 worker 抢占，跳过: bid={$task['bid']}");
                        continue;
                    }

                    Log::info("【定时任务】已抢占待执行任务: bid={$task['bid']}, execute_time={$task['execute_time']}, now={$now}");
                    $task['status'] = 'in_progress';
                    $claimedTasks[] = $task;
                }

                if (!empty($claimedTasks)) {
                    $this->airline->flighttaskReady($claimedTasks);
                }
            }
        });
        
        // 定时任务：扫描循环任务（每60秒）
        Timer::add(60, function () {
            if ($this->taskScheduler) {
                $this->taskScheduler->scanAndExecute();
            }
        });
        
        // 定时任务：清理不活跃的 WebSocket 连接（每60秒）
        Timer::add(60, function () {
            $cleaned = ConnectionPool::cleanInactive(300); // 5分钟不活跃则清理
            if (!empty($cleaned)) {
                Log::info('【WS】清理不活跃连接: ' . implode(', ', $cleaned));
            }
        });
    }
    
    /**
     * 初始化 WebSocket 代理服务
     */
    private function initWebSocketServer(): void
    {
        $wsPort = env('WS_PORT', 8282);
        $wsMaxConnections = env('WS_MAX_CONNECTIONS', 100);
        
        $this->wsServer = new WebSocketServer([
            'host' => '0.0.0.0',
            'port' => $wsPort,
            'max_connections' => $wsMaxConnections,
            'heartbeat_interval' => 30,
        ]);
        
        // 设置 MQTT 客户端引用
        $this->wsServer->setMqttClient($this->connection);
        
        // 启动 WebSocket 服务
        $wsWorker = $this->wsServer->start();
        $wsWorker->listen();
        
        Log::info("【WS】WebSocket 代理服务已启动，端口: {$wsPort}");
    }
    
    /**
     * 处理监控命令
     */
    private function handleMonitorCommand($connection, array $data): void
    {
        $action = $data['action'] ?? '';
        $params = $data['params'] ?? [];
        
        $response = match($action) {
            'status' => $this->getMonitorStatus(),
            'connections' => $this->getMonitorConnections(),
            'logs' => $this->getMonitorLogs($params),
            'stats' => $this->getMonitorStats($params),
            'report' => StatsCollector::getReport(),
            'clearLogs' => $this->clearMonitorLogs(),
            'subscriptions' => $this->getMonitorSubscriptions(),
            'disconnect' => $this->disconnectClient($params['connection_id'] ?? ''),
            default => ['error' => 'Unknown action'],
        };
        
        // text 协议需要换行符结束
        $connection->send(json_encode($response) . "\n");
    }
    
    /**
     * 获取监控状态
     */
    private function getMonitorStatus(): array
    {
        $poolSummary = ConnectionPool::getSummary();
        $routerStats = MessageRouter::getStats();
        $globalStats = StatsCollector::getGlobalStats();
        $currentRate = StatsCollector::getCurrentRate();
        
        return [
            'mqtt' => [
                'connected' => $this->isConnected,
                'broker' => env('MQTT_HOST', '') . ':' . env('MQTT_PORT', 1883),
                'client_id' => env('MQTT_CLIENT_ID', 'PUBLISH0001222222'),
                'uptime' => time() - $this->startTime,
                'reconnect_count' => $globalStats['reconnect_count'] ?? 0,
            ],
            'websocket' => [
                'port' => env('WS_PORT', 8282),
                'connections' => $poolSummary['connections'],
                'max_connections' => $poolSummary['max_connections'],
            ],
            'stats' => [
                'messages_in' => $globalStats['total_messages_in'] ?? 0,
                'messages_out' => $globalStats['total_messages_out'] ?? 0,
                'bytes_in' => $globalStats['total_bytes_in'] ?? 0,
                'bytes_out' => $globalStats['total_bytes_out'] ?? 0,
                'subscriptions' => $routerStats['total_subscriptions'] ?? 0,
                'unique_topics' => $routerStats['total_topics'] ?? 0,
            ],
            'rate' => $currentRate,
        ];
    }
    
    /**
     * 获取连接列表
     */
    private function getMonitorConnections(): array
    {
        $connections = ConnectionPool::getAll();
        
        $list = array_map(function ($conn) {
            return [
                'id' => $conn['id'],
                'user_id' => $conn['user_id'],
                'user_name' => $conn['user_name'],
                'ip' => $conn['ip'],
                'connected_at' => $conn['connected_at'],
                'connected_at_str' => date('Y-m-d H:i:s', $conn['connected_at']),
                'last_active' => $conn['last_active'],
                'last_active_str' => date('Y-m-d H:i:s', $conn['last_active']),
                'authenticated' => $conn['authenticated'],
                'subscriptions' => $conn['subscriptions'],
                'subscription_count' => count($conn['subscriptions']),
                'stats' => $conn['stats'],
            ];
        }, $connections);
        
        usort($list, fn($a, $b) => $b['connected_at'] - $a['connected_at']);
        
        return [
            'list' => array_values($list),
            'total' => count($list),
            'summary' => ConnectionPool::getSummary(),
        ];
    }
    
    /**
     * 获取日志
     */
    private function getMonitorLogs(array $params): array
    {
        $limit = (int)($params['limit'] ?? 100);
        $offset = (int)($params['offset'] ?? 0);
        
        $filters = [];
        if (!empty($params['type'])) $filters['type'] = $params['type'];
        if (!empty($params['source'])) $filters['source'] = $params['source'];
        if (!empty($params['topic'])) $filters['topic'] = $params['topic'];
        if (!empty($params['direction'])) $filters['direction'] = $params['direction'];
        if (!empty($params['since'])) $filters['since'] = (float)$params['since'];
        
        return ActionLogger::getLogs($filters, $limit, $offset);
    }
    
    /**
     * 获取统计
     */
    private function getMonitorStats(array $params): array
    {
        $period = $params['period'] ?? 'hour';
        
        $minutes = match($period) {
            'minute' => 1,
            'hour' => 60,
            'day' => 1440,
            default => 60,
        };
        
        return [
            'throughput' => StatsCollector::getThroughput($minutes),
            'top_topics' => StatsCollector::getTopTopics(20),
            'global' => StatsCollector::getGlobalStats(),
            'current_rate' => StatsCollector::getCurrentRate(),
            'connections' => [['time' => date('H:i'), 'count' => ConnectionPool::count()]],
        ];
    }
    
    /**
     * 清空日志
     */
    private function clearMonitorLogs(): array
    {
        ActionLogger::clear();
        return ['success' => true];
    }
    
    /**
     * 获取订阅统计
     */
    private function getMonitorSubscriptions(): array
    {
        $stats = MessageRouter::getStats();
        $topics = MessageRouter::getAllTopics();
        
        $topicDetails = [];
        foreach ($topics as $topic) {
            $subscribers = MessageRouter::getSubscribers($topic);
            $topicDetails[] = [
                'topic' => $topic,
                'subscriber_count' => count($subscribers),
                'subscribers' => $subscribers,
            ];
        }
        
        usort($topicDetails, fn($a, $b) => $b['subscriber_count'] - $a['subscriber_count']);
        
        return [
            'stats' => $stats,
            'topics' => $topicDetails,
        ];
    }
    
    /**
     * 断开客户端连接
     */
    private function disconnectClient(string $connectionId): array
    {
        if (empty($connectionId)) {
            return ['error' => '连接ID不能为空'];
        }
        
        $connection = ConnectionPool::getConnection($connectionId);
        if (!$connection) {
            return ['error' => '连接不存在'];
        }
        
        $connection->close();
        MessageRouter::removeAll($connectionId);
        ConnectionPool::remove($connectionId);
        ActionLogger::logConnection($connectionId, 'force_disconnected');
        
        return ['success' => true];
    }
    
    /**
     * 获取 MQTT 连接状态
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }
    
    /**
     * 获取服务状态
     */
    public function getStatus(): array
    {
        return [
            'mqtt_connected' => $this->isConnected,
            'uptime' => time() - $this->startTime,
            'websocket' => $this->wsServer ? $this->wsServer->getStatus() : null,
        ];
    }
}
