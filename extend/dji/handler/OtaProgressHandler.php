<?php

namespace dji\handler;

use app\admin\model\firmware\UpgradeTask;
use think\facade\Log;

/**
 * OTA 升级进度处理器
 * 
 * 处理 DJI 设备上报的固件升级进度事件
 * Topic: thing/product/{gateway_sn}/events
 * Method: ota_progress
 */
class OtaProgressHandler
{
    /**
     * 处理升级进度事件
     *
     * @param string $gatewaySn 机场SN
     * @param array $payload MQTT消息内容
     * @return void
     */
    public function handle(string $gatewaySn, array $payload): void
    {
        $taskId = $payload['bid'] ?? '';
        $data = $payload['data'] ?? [];
        
        if (empty($taskId)) {
            Log::warning('[OtaProgress] 缺少任务ID', $payload);
            return;
        }

        $result = $data['result'] ?? 0;
        $output = $data['output'] ?? [];
        $status = $output['status'] ?? '';
        $progress = $output['progress'] ?? [];
        $percent = $progress['percent'] ?? 0;
        $currentStep = $progress['current_step'] ?? null;

        Log::info("[OtaProgress] 收到升级进度: task={$taskId}, status={$status}, progress={$percent}%");

        // 查找任务
        $task = UpgradeTask::where('task_id', $taskId)->find();
        if (!$task) {
            Log::warning("[OtaProgress] 任务不存在: {$taskId}");
            return;
        }

        // 更新任务状态
        $updateData = [
            'status' => $status,
            'progress' => $percent,
            'result_code' => $result,
        ];

        if ($currentStep) {
            $updateData['current_step'] = $currentStep;
        }

        // 如果任务完成，记录完成时间
        if (in_array($status, ['ok', 'failed', 'canceled', 'rejected', 'timeout'])) {
            $updateData['finish_time'] = date('Y-m-d H:i:s');
            
            // 如果失败，记录错误信息
            if ($status === 'failed' && $result !== 0) {
                $updateData['error_msg'] = $this->getErrorMessage($result);
            }
        }

        $task->save($updateData);

        // 推送实时进度到前端（通过 WebSocket 或其他方式）
        $this->pushProgress($gatewaySn, $taskId, [
            'status' => $status,
            'progress' => $percent,
            'current_step' => $currentStep,
            'result' => $result,
        ]);
    }

    /**
     * 推送进度到前端
     */
    private function pushProgress(string $gatewaySn, string $taskId, array $data): void
    {
        // TODO: 通过 WebSocket 或 Redis Pub/Sub 推送到前端
        // 这里可以使用 Workerman 的 Gateway 或其他实时通信方案
        
        // 示例：写入 Redis 供前端轮询
        try {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), env('REDIS_PORT', 6379));
            if ($password = env('REDIS_PASSWORD', '')) {
                $redis->auth($password);
            }
            
            $key = "ota_progress:{$taskId}";
            $redis->setex($key, 300, json_encode([
                'gateway_sn' => $gatewaySn,
                'task_id' => $taskId,
                'data' => $data,
                'timestamp' => time(),
            ]));
        } catch (\Exception $e) {
            Log::error("[OtaProgress] Redis 推送失败: " . $e->getMessage());
        }
    }

    /**
     * 获取错误信息
     */
    private function getErrorMessage(int $code): string
    {
        $errorMap = [
            1 => '未知错误',
            2 => '固件下载失败',
            3 => '固件校验失败',
            4 => '固件安装失败',
            5 => '设备存储空间不足',
            6 => '设备电量不足',
            7 => '设备正在执行任务',
            8 => '网络连接失败',
        ];

        return $errorMap[$code] ?? "错误码: {$code}";
    }
}
