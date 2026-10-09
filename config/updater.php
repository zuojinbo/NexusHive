<?php
// +----------------------------------------------------------------------
// | 在线更新配置
// +----------------------------------------------------------------------

return [
    // 客户端ID（首次运行时自动生成并保存到 .env）
    'client_id' => env('UPDATER_CLIENT_ID', ''),
    
    // 授权平台地址
    'server_url' => env('UPDATER_SERVER_URL', 'http://shouquan.chuangxing.ren'),
    
    // 产品代码（需与授权平台配置一致）
    'product_code' => 'nexushive',
    
    // 当前版本号（从 version.php 读取）
    'current_version' => include __DIR__ . '/version.php',
    
    // 更新检查间隔（秒），0 表示不自动检查
    'check_interval' => env('UPDATER_CHECK_INTERVAL', 86400),
    
    // 是否允许自动更新
    'auto_update' => env('UPDATER_AUTO_UPDATE', false),
    
    // 备份保留数量
    'backup_keep' => env('UPDATER_BACKUP_KEEP', 5),
    
    // 排除更新的文件/目录（这些文件不会被更新覆盖）
    'exclude_paths' => [
        '.env',
        'config/database.php',
        'config/updater.php',
        'runtime/',
        'public/storage/',
        'public/uploads/',
    ],
];
