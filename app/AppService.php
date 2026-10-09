<?php
declare (strict_types=1);

namespace app;

use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 服务注册
    }

    public function boot()
    {
        // 服务启动
        $this->initClientId();
    }
    
    /**
     * 初始化客户端唯一标识
     * 如果 .env 中没有 UPDATER_CLIENT_ID，则自动生成
     */
    protected function initClientId(): void
    {
        // 检查配置中是否已有 client_id
        $clientId = config('updater.client_id');
        
        if (!empty($clientId)) {
            return;
        }
        
        // 生成 client_id
        $factors = [
            php_uname('n'),      // 主机名
            php_uname('m'),      // 机器类型
            $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()),  // 服务器IP
            root_path(),         // 项目根目录
        ];
        $clientId = hash('sha256', implode('|', $factors));
        
        // 保存到 .env 文件
        $envFile = root_path() . '.env';
        if (!file_exists($envFile)) {
            return;
        }
        
        $content = file_get_contents($envFile);
        
        // 检查是否已有 UPDATER_CLIENT_ID 配置行（非注释）
        if (preg_match('/^UPDATER_CLIENT_ID\s*=/m', $content)) {
            // 已存在配置行，替换它
            $content = preg_replace(
                '/^UPDATER_CLIENT_ID\s*=\s*.*/m',
                "UPDATER_CLIENT_ID = {$clientId}",
                $content
            );
        } else {
            // 不存在，追加到文件末尾
            $content = rtrim($content) . "\n\n# 客户端唯一标识（自动生成，请勿修改）\nUPDATER_CLIENT_ID = {$clientId}\n";
        }
        
        file_put_contents($envFile, $content);
    }
}
