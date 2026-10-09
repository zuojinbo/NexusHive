<?php

namespace app\admin\model\firmware;

use think\Model;

/**
 * 固件版本模型
 */
class Firmware extends Model
{
    // 表名
    protected $name = 'firmware';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 设备类型映射
     */
    public static array $deviceTypeMap = [
        'dock' => '机场',
        'drone' => '无人机',
    ];

    /**
     * 设备型号映射
     */
    public static array $deviceModelMap = [
        'dock' => [
            'Dock2' => 'DJI Dock 2',
            'Dock3' => 'DJI Dock 3',
        ],
        'drone' => [
            'M3TD' => 'Matrice 3TD',
            'M3D' => 'Matrice 3D',
            'M30T' => 'Matrice 30T',
            'M30' => 'Matrice 30',
        ],
    ];

    /**
     * 获取文件大小（格式化）
     */
    public function getFileSizeTextAttr($value, $data): string
    {
        $size = $data['file_size'] ?? 0;
        if ($size < 1024) {
            return $size . ' B';
        } elseif ($size < 1024 * 1024) {
            return round($size / 1024, 2) . ' KB';
        } elseif ($size < 1024 * 1024 * 1024) {
            return round($size / (1024 * 1024), 2) . ' MB';
        } else {
            return round($size / (1024 * 1024 * 1024), 2) . ' GB';
        }
    }

    /**
     * 获取设备类型文本
     */
    public function getDeviceTypeTextAttr($value, $data): string
    {
        return self::$deviceTypeMap[$data['device_type']] ?? $data['device_type'];
    }
}
