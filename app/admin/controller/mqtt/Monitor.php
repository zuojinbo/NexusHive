<?php

namespace app\admin\controller\mqtt;

use app\common\controller\Backend;

/**
 * MQTT 监控控制器
 * 通过内部端口与 Workerman 进程通信获取数据
 */
class Monitor extends Backend
{
    protected array $noNeedLogin = [];
    protected array $noNeedPermission = ['status', 'connections', 'logs', 'stats'];
    
    /**
     * 向 Workerman 发送命令并获取响应
     */
    private function sendCommand(string $action, array $params = []): ?array
    {
        $socket = @fsockopen('127.0.0.1', 1884, $errno, $errstr, 2);
        if (!$socket) {
            return null;
        }
        
        // 设置读取超时
        stream_set_timeout($socket, 2);
        
        $command = json_encode([
            'topic' => 'internal/monitor/' . $action,
            'action' => $action,
            'params' => $params,
        ]) . "\n"; // text 协议需要换行符
        
        fwrite($socket, $command);
        
        // 读取响应
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 65535);
            if ($line === false) break;
            $response .= $line;
            // text 协议以换行结束
            if (substr($line, -1) === "\n") break;
        }
        
        fclose($socket);
        
        $response = trim($response);
        
        if ($response === 'ok' || empty($response)) {
            return ['success' => true];
        }
        
        // 尝试解析 JSON 响应
        $data = json_decode($response, true);
        return $data ?: ['raw' => $response];
    }
    
    /**
     * 获取 MQTT 整体状态
     */
    public function status()
    {
        $result = $this->sendCommand('status');
        
        if (!$result) {
            // Workerman 未运行，返回离线状态
            $this->success('', [
                'mqtt' => [
                    'connected' => false,
                    'broker' => env('MQTT_HOST', '') . ':' . env('MQTT_PORT', 1883),
                    'client_id' => env('MQTT_CLIENT_ID', 'PUBLISH0001'),
                    'uptime' => 0,
                    'reconnect_count' => 0,
                ],
                'websocket' => [
                    'port' => env('WS_PORT', 8282),
                    'connections' => 0,
                    'max_connections' => env('WS_MAX_CONNECTIONS', 100),
                ],
                'stats' => [
                    'messages_in' => 0,
                    'messages_out' => 0,
                    'bytes_in' => 0,
                    'bytes_out' => 0,
                    'subscriptions' => 0,
                    'unique_topics' => 0,
                ],
                'rate' => [
                    'messages_in_per_sec' => 0,
                    'messages_out_per_sec' => 0,
                ],
            ]);
            return;
        }
        
        $this->success('', $result);
    }
    
    /**
     * 获取连接池列表
     */
    public function connections()
    {
        $result = $this->sendCommand('connections');
        
        if (!$result || isset($result['success'])) {
            $this->success('', [
                'list' => [],
                'total' => 0,
                'summary' => ['connections' => 0, 'max_connections' => 100],
            ]);
            return;
        }
        
        $this->success('', $result);
    }

    /**
     * 断开指定连接
     */
    public function disconnect()
    {
        $connectionId = $this->request->post('connection_id');
        
        if (empty($connectionId)) {
            $this->error('连接ID不能为空');
        }
        
        $result = $this->sendCommand('disconnect', ['connection_id' => $connectionId]);
        
        if ($result) {
            $this->success('连接已断开');
        } else {
            $this->error('操作失败，Workerman 未运行');
        }
    }
    
    /**
     * 获取消息日志
     */
    public function logs()
    {
        $params = [
            'limit' => $this->request->get('limit', 100),
            'offset' => $this->request->get('offset', 0),
            'type' => $this->request->get('type'),
            'source' => $this->request->get('source'),
            'topic' => $this->request->get('topic'),
            'direction' => $this->request->get('direction'),
            'since' => $this->request->get('since'),
        ];
        
        $result = $this->sendCommand('logs', $params);
        
        if (!$result || isset($result['success'])) {
            $this->success('', ['list' => [], 'total' => 0]);
            return;
        }
        
        $this->success('', $result);
    }
    
    /**
     * 获取统计数据
     */
    public function stats()
    {
        $period = $this->request->get('period', 'hour');
        
        $result = $this->sendCommand('stats', ['period' => $period]);
        
        if (!$result || isset($result['success'])) {
            $this->success('', [
                'throughput' => [],
                'top_topics' => [],
                'global' => [],
                'current_rate' => [],
                'connections' => [],
            ]);
            return;
        }
        
        $this->success('', $result);
    }
    
    /**
     * 获取完整报告
     */
    public function report()
    {
        $result = $this->sendCommand('report');
        
        if (!$result || isset($result['success'])) {
            $this->success('', []);
            return;
        }
        
        $this->success('', $result);
    }
    
    /**
     * 清空日志
     */
    public function clearLogs()
    {
        $result = $this->sendCommand('clearLogs');
        
        if ($result) {
            $this->success('日志已清空');
        } else {
            $this->error('操作失败');
        }
    }
    
    /**
     * 获取订阅统计
     */
    public function subscriptions()
    {
        $result = $this->sendCommand('subscriptions');
        
        if (!$result || isset($result['success'])) {
            $this->success('', ['stats' => [], 'topics' => []]);
            return;
        }
        
        $this->success('', $result);
    }
}
