<?php

namespace app\admin\controller\firmware;

use app\common\controller\Backend;
use app\admin\model\firmware\Firmware;
use app\admin\model\firmware\UpgradeTask;
use think\facade\Db;

/**
 * 固件升级任务管理
 */
class Upgrade extends Backend
{
    protected array|string $preExcludeFields = ['create_time', 'update_time'];

    protected string|array $quickSearchField = ['task_id', 'gateway_sn', 'device_sn'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new UpgradeTask();
    }

    /**
     * 查看
     */
    public function index(): void
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        
        $res = $this->model
            ->withAttr('upgrade_type_text', function ($value, $data) {
                return UpgradeTask::$upgradeTypeMap[$data['upgrade_type']] ?? '未知';
            })
            ->withAttr('status_text', function ($value, $data) {
                return UpgradeTask::$statusMap[$data['status']] ?? $data['status'];
            })
            ->withAttr('current_step_text', function ($value, $data) {
                return UpgradeTask::$stepMap[$data['current_step']] ?? $data['current_step'] ?? '';
            })
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $this->success('', [
            'list' => $res->items(),
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 创建升级任务
     */
    public function create(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误');
        }

        $gatewaySn = trim($this->request->post('gateway_sn', ''));
        $deviceSn = trim($this->request->post('device_sn', ''));
        $upgradeType = (int)$this->request->post('upgrade_type', 3);
        $dockFirmwareId = (int)$this->request->post('dock_firmware_id', 0);
        $droneFirmwareId = (int)$this->request->post('drone_firmware_id', 0);

        // === 基础参数验证 ===
        if (empty($gatewaySn)) {
            $this->error('机场SN不能为空');
        }

        // 验证升级类型
        if (!in_array($upgradeType, [2, 3])) {
            $this->error('无效的升级类型');
        }

        if (empty($dockFirmwareId) && empty($droneFirmwareId)) {
            $this->error('请选择要升级的固件');
        }

        // === 验证机场是否存在且在线 ===
        $device = Db::name('device')->where('device_sn', $gatewaySn)->find();
        if (!$device) {
            $this->error('机场设备不存在，请检查机场SN是否正确');
        }

        // 检查设备是否在线（可选，根据业务需求）
        // if ($device['status'] != 'online') {
        //     $this->error('机场设备当前离线，无法执行升级');
        // }

        // === 验证是否有正在进行的升级任务 ===
        $pendingTask = $this->model->where('gateway_sn', $gatewaySn)
            ->whereIn('status', ['sent', 'in_progress'])
            ->find();
        if ($pendingTask) {
            $this->error('该机场已有正在进行的升级任务，请等待完成后再试');
        }

        // === 验证固件信息 ===
        $dockFirmware = null;
        $droneFirmware = null;
        $devices = [];

        if ($dockFirmwareId) {
            $dockFirmware = Firmware::where('id', $dockFirmwareId)
                ->where('status', 1)
                ->where('device_type', 'dock')
                ->find();
            if (!$dockFirmware) {
                $this->error('机场固件不存在或已禁用');
            }
            
            // 验证固件文件URL
            if (empty($dockFirmware->file_url)) {
                $this->error('机场固件文件地址为空，无法升级');
            }

            $devices[] = [
                'sn' => $gatewaySn,
                'product_version' => $dockFirmware->version,
                'file_url' => $dockFirmware->file_url,
                'md5' => $dockFirmware->md5,
                'file_size' => (int)$dockFirmware->file_size,
                'file_name' => $dockFirmware->file_name,
                'firmware_upgrade_type' => $upgradeType,
            ];
        }

        if ($droneFirmwareId) {
            // 如果要升级无人机固件，必须提供无人机SN
            if (empty($deviceSn)) {
                $this->error('升级无人机固件时，无人机SN不能为空');
            }

            // 验证无人机是否属于该机场
            $drone = Db::name('device')->where('device_sn', $deviceSn)
                ->where('parent_id', $device['id'])
                ->find();
            if (!$drone) {
                $this->error('无人机不存在或不属于该机场');
            }

            $droneFirmware = Firmware::where('id', $droneFirmwareId)
                ->where('status', 1)
                ->where('device_type', 'drone')
                ->find();
            if (!$droneFirmware) {
                $this->error('无人机固件不存在或已禁用');
            }

            // 验证固件文件URL
            if (empty($droneFirmware->file_url)) {
                $this->error('无人机固件文件地址为空，无法升级');
            }

            $devices[] = [
                'sn' => $deviceSn,
                'product_version' => $droneFirmware->version,
                'file_url' => $droneFirmware->file_url,
                'md5' => $droneFirmware->md5,
                'file_size' => (int)$droneFirmware->file_size,
                'file_name' => $droneFirmware->file_name,
                'firmware_upgrade_type' => $upgradeType,
            ];
        }

        // === 创建任务 ===
        $taskId = $this->generateUuid();

        // 构建 MQTT 消息
        $mqttPayload = [
            'bid' => $taskId,
            'tid' => $this->generateUuid(),
            'timestamp' => time() * 1000,
            'method' => 'ota_create',
            'data' => [
                'devices' => $devices,
            ],
        ];

        // 创建任务记录
        $task = new UpgradeTask();
        $task->task_id = $taskId;
        $task->gateway_sn = $gatewaySn;
        $task->device_sn = $deviceSn ?: null;
        $task->upgrade_type = $upgradeType;
        $task->dock_firmware_id = $dockFirmwareId ?: null;
        $task->drone_firmware_id = $droneFirmwareId ?: null;
        $task->dock_version = $dockFirmware?->version;
        $task->drone_version = $droneFirmware?->version;
        $task->status = 'sent';

        $errorMsg = '';
        Db::startTrans();
        try {
            $task->save();
            
            // 发送 MQTT 消息
            $this->sendMqttMessage($gatewaySn, $mqttPayload);
            
            Db::commit();
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            $errorMsg = '创建升级任务失败: ' . $e->getMessage();
        }

        if ($errorMsg) {
            $this->error($errorMsg);
            return;
        }

        $this->success('升级任务已下发', ['task_id' => $taskId]);
    }

    /**
     * 删除任务记录
     */
    public function del(): void
    {
        $ids = $this->request->param('ids', []);
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', ['ids']));
        }

        $pk = $this->model->getPk();
        $data = $this->model->where($pk, 'in', $ids)->select();

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($data as $v) {
                // 只能删除已完成的任务
                if (!in_array($v->status, ['ok', 'failed', 'canceled', 'rejected', 'timeout'])) {
                    continue;
                }
                $count += $v->delete();
            }
            $this->model->commit();
        } catch (\Exception $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Deleted successfully'));
        } else {
            $this->error('没有可删除的记录（只能删除已完成的任务）');
        }
    }

    /**
     * 获取任务详情
     */
    public function detail(): void
    {
        $taskId = $this->request->param('task_id', '');
        if (empty($taskId)) {
            $this->error('任务ID不能为空');
        }

        $task = $this->model->where('task_id', $taskId)->find();
        if (!$task) {
            $this->error('任务不存在');
        }

        $this->success('', $task);
    }

    /**
     * 发送 MQTT 消息
     */
    private function sendMqttMessage(string $gatewaySn, array $payload): void
    {
        $topic = "thing/product/{$gatewaySn}/services";
        $message = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // 使用项目中的 MQTT 客户端发送消息
        // TODO: 根据项目实际的 MQTT 实现调整
        try {
            if (class_exists('\mqtt\MqttClient')) {
                $mqtt = new \mqtt\MqttClient();
                $mqtt->publish($topic, $message);
            } else {
                // 备用方案：写入 Redis 队列，由 Workerman 处理
                $redis = new \Redis();
                $redis->connect(env('REDIS_HOST', '127.0.0.1'), env('REDIS_PORT', 6379));
                if ($password = env('REDIS_PASSWORD', '')) {
                    $redis->auth($password);
                }
                $redis->lPush('mqtt_publish_queue', json_encode([
                    'topic' => $topic,
                    'message' => $message,
                ]));
            }
        } catch (\Exception $e) {
            throw new \Exception('MQTT 消息发送失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成 UUID
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
