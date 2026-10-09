<?php

namespace app\admin\model\firmware;

use think\Model;

/**
 * 固件升级任务模型
 */
class UpgradeTask extends Model
{
    // 表名
    protected $name = 'firmware_upgrade_task';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 升级类型映射
     */
    public static array $upgradeTypeMap = [
        2 => '一致性升级',
        3 => '普通升级',
    ];

    /**
     * 状态映射
     */
    public static array $statusMap = [
        'sent' => '已下发',
        'in_progress' => '执行中',
        'ok' => '成功',
        'failed' => '失败',
        'canceled' => '已取消',
        'rejected' => '已拒绝',
        'paused' => '已暂停',
        'timeout' => '超时',
    ];

    /**
     * 步骤映射
     */
    public static array $stepMap = [
        'download_firmware' => '下载固件',
        'upgrade_firmware' => '更新固件',
    ];

    /**
     * 关联机场固件
     */
    public function dockFirmware()
    {
        return $this->belongsTo(Firmware::class, 'dock_firmware_id', 'id');
    }

    /**
     * 关联无人机固件
     */
    public function droneFirmware()
    {
        return $this->belongsTo(Firmware::class, 'drone_firmware_id', 'id');
    }

    /**
     * 获取升级类型文本
     */
    public function getUpgradeTypeTextAttr($value, $data): string
    {
        return self::$upgradeTypeMap[$data['upgrade_type']] ?? '未知';
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data): string
    {
        return self::$statusMap[$data['status']] ?? $data['status'];
    }

    /**
     * 获取当前步骤文本
     */
    public function getCurrentStepTextAttr($value, $data): string
    {
        return self::$stepMap[$data['current_step']] ?? $data['current_step'] ?? '';
    }

    /**
     * 是否已完成（成功或失败）
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['ok', 'failed', 'canceled', 'rejected', 'timeout']);
    }
}
