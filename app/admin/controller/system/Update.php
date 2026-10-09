<?php

namespace app\admin\controller\system;

use Throwable;
use app\common\controller\Backend;
use updater\UpdateService;
use updater\SignatureVerifier;
use updater\UpdateInstaller;
use think\exception\HttpResponseException;

/**
 * 系统更新控制器
 */
class Update extends Backend
{
    /**
     * 更新服务实例
     * @var UpdateService
     */
    protected UpdateService $updateService;
    
    /**
     * 初始化
     * @throws Throwable
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->updateService = new UpdateService();
    }
    
    /**
     * 检查更新
     */
    public function check(): void
    {
        try {
            $result = $this->updateService->checkUpdate();
            $this->success('', $result);
        } catch (HttpResponseException $e) {
            // 重新抛出 HttpResponseException，这是 ThinkPHP 的正常响应机制
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage() ?: '检查更新失败');
        }
    }
    
    /**
     * 获取更新日志
     */
    public function changelog(): void
    {
        $fromVersion = $this->request->param('from_version');
        
        try {
            $changelog = $this->updateService->getChangelog($fromVersion);
            $this->success('', ['changelog' => $changelog]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 执行更新
     */
    public function execute(): void
    {
        $downloadToken = $this->request->post('download_token');
        $toVersion = $this->request->post('to_version');
        
        if (!$downloadToken || !$toVersion) {
            $this->error('参数错误');
        }
        
        $currentVersion = $this->updateService->getCurrentVersion();
        $tempFile = runtime_path() . 'update_' . $toVersion . '.zip';
        
        try {
            // 1. 上报开始下载
            $this->updateService->reportStatus($currentVersion, $toVersion, 'downloading');
            
            // 2. 下载更新包
            $fileInfo = $this->updateService->downloadPackage($downloadToken, $tempFile);
            
            // 3. 验证文件完整性
            // 先验证哈希
            $actualHash = hash_file('sha256', $tempFile);
            if (!hash_equals($fileInfo['hash'], $actualHash)) {
                throw new \RuntimeException('文件哈希验证失败，文件可能已损坏');
            }
            
            // 验证签名（如果配置了有效的公钥且签名不为空）
            $publicKeyPath = config_path() . 'updater_public.pem';
            if (file_exists($publicKeyPath) && !empty($fileInfo['signature'])) {
                try {
                    $verifier = new SignatureVerifier();
                    $verifier->loadPublicKeyFromFile($publicKeyPath);
                    $verifier->verifySignatureOnly($fileInfo['hash'], $fileInfo['signature']);
                } catch (Throwable $e) {
                    // 签名验证失败，记录警告但不阻止更新（测试阶段）
                    // TODO: 生产环境应该抛出异常
                    trace('签名验证失败: ' . $e->getMessage(), 'warning');
                }
            }
            
            // 4. 上报开始安装
            $this->updateService->reportStatus($currentVersion, $toVersion, 'installing');
            
            // 5. 执行安装
            $installer = new UpdateInstaller();
            $installer->install($tempFile, $currentVersion, $toVersion);
            
            // 6. 上报成功
            $this->updateService->reportStatus($currentVersion, $toVersion, 'success');
            
            // 7. 清理临时文件
            @unlink($tempFile);
            
            
        } catch (Throwable $e) {
            // 构建详细错误信息
            $errorMsg = $e->getMessage();
            if (empty($errorMsg)) {
                $errorMsg = get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
            }
            
            // 上报失败（忽略上报本身的错误）
            try {
                $this->updateService->reportStatus($currentVersion, $toVersion, 'failed', $errorMsg);
            } catch (Throwable $reportError) {
                trace('上报更新失败状态时出错: ' . $reportError->getMessage(), 'warning');
            }
            
            // 清理临时文件
            @unlink($tempFile);
            
            $this->error('更新失败: ' . $errorMsg);
        }
        $this->success('更新成功，请刷新页面');
    }
    
    /**
     * 获取当前版本信息
     */
    public function info(): void
    {
        $this->success('', [
            'current_version' => $this->updateService->getCurrentVersion(),
            'product_code'    => $this->updateService->getConfig()['product_code'] ?? 'nexus_hive',
            'php_version'     => PHP_VERSION,
            'os_info'         => php_uname(),
            'client_id'       => substr($this->updateService->getClientIdPublic(), 0, 16) . '...',
        ]);
    }
    
    /**
     * 获取本机更新历史
     */
    public function history(): void
    {
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 20);
        
        try {
            $result = $this->updateService->getUpdateHistory($page, $limit);
            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            // 客户端未注册时返回空数据，不报错
            $this->success('', ['list' => [], 'total' => 0]);
        }
    }
    
    /**
     * 获取版本发布时间轴
     */
    public function timeline(): void
    {
        try {
            $result = $this->updateService->getVersionTimeline();
            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            // 获取失败时返回空数据，不报错（调试时可打开下面的注释）
            // $this->error($e->getMessage());
            $this->success('', ['timeline' => [], 'current_version' => $this->updateService->getCurrentVersion(), 'latest_version' => null]);
        }
    }
    
    /**
     * 获取更新包文件列表
     */
    public function files(): void
    {
        // 调试：记录请求进入
        trace('files方法被调用，参数: ' . json_encode($this->request->param()), 'info');
        
        $downloadToken = $this->request->param('download_token');
        
        if (!$downloadToken) {
            $this->error('缺少下载令牌 (files方法)');
        }
        
        try {
            $result = $this->updateService->getPackageFiles($downloadToken);
            $this->success('', $result);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error('获取文件列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取更新提醒（仅超管）
     */
    public function notice(): void
    {
        // 检查是否为超级管理员
        if (!$this->auth->isSuperAdmin()) {
            $this->success('', ['has_update' => false]);
            return;
        }
        
        // 检查是否已忽略该版本
        $dismissedVersion = cache('admin_dismissed_version_' . $this->auth->id);
        
        // 调试：输出当前配置
        $config = $this->updateService->getConfig();
        $clientId = $this->updateService->getClientIdPublic();
        
        // 实时检查更新
        try {
            $result = $this->updateService->checkUpdate();
            
            // 如果已忽略该版本，不显示提醒
            if ($dismissedVersion && isset($result['latest_version']) && $dismissedVersion === $result['latest_version']) {
                $this->success('', ['has_update' => false]);
                return;
            }
            
            $this->success('', $result);
        } catch (HttpResponseException $e) {
            // HttpResponseException 需要重新抛出
            throw $e;
        } catch (Throwable $e) {
            // 调试：记录详细错误信息
            $errorInfo = get_class($e) . ': ' . ($e->getMessage() ?: '(empty message)') . ' at ' . $e->getFile() . ':' . $e->getLine();
            trace('notice检查更新失败: ' . $errorInfo, 'error');
            $this->success('', [
                'has_update' => false, 
                'error' => $errorInfo,
                'debug' => [
                    'client_id' => substr($clientId, 0, 16) . '...',
                    'server_url' => $config['server_url'] ?? 'not set',
                    'product_code' => $config['product_code'] ?? 'not set',
                ]
            ]);
        }
    }
    
    /**
     * 忽略本次更新提醒
     */
    public function dismissNotice(): void
    {
        $version = $this->request->post('version');
        
        if ($version) {
            // 记录已忽略的版本（7天内不再提醒）
            cache('admin_dismissed_version_' . $this->auth->id, $version, 86400 * 7);
        }
        
        $this->success('已忽略');
    }
}
