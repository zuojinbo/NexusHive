<?php

namespace updater;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * 在线更新服务
 * 
 * 负责与授权平台通信，实现检查更新、下载更新包、上报状态等功能
 */
class UpdateService
{
    /**
     * 配置信息
     * @var array
     */
    protected array $config;
    
    /**
     * HTTP 客户端
     * @var Client
     */
    protected Client $httpClient;
    
    /**
     * 客户端唯一标识
     * @var string
     */
    protected string $clientId;
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->config = config('updater');
        $this->clientId = $this->getClientId();
        
        // 初始化 HTTP 客户端，全局添加 server=1 参数
        $this->httpClient = new Client([
            'base_uri' => $this->config['server_url'],
            'timeout'  => 30,
            'verify'   => false,  // 禁用 SSL 验证（HTTP 不需要）
            'query'    => ['server' => 1],
            'http_errors' => false,  // 不抛出 HTTP 错误异常
        ]);
    }
    
    /**
     * 获取或生成客户端ID
     */
    protected function getClientId(): string
    {
        $clientId = $this->config['client_id'] ?? '';
        
        if (empty($clientId)) {
            $clientId = $this->generateClientId();
            $this->saveClientId($clientId);
        }
        
        return $clientId;
    }
    
    /**
     * 生成客户端唯一标识
     */
    public function generateClientId(): string
    {
        $factors = [
            php_uname('n'),
            php_uname('m'),
            $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname()),
            root_path(),
        ];
        
        return hash('sha256', implode('|', $factors));
    }
    
    /**
     * 保存客户端ID到 .env 文件
     */
    protected function saveClientId(string $clientId): void
    {
        $envFile = root_path() . '.env';
        
        if (!file_exists($envFile)) {
            return;
        }
        
        $content = file_get_contents($envFile);
        
        if (strpos($content, 'UPDATER_CLIENT_ID') !== false) {
            $content = preg_replace(
                '/UPDATER_CLIENT_ID\s*=\s*.*/',
                "UPDATER_CLIENT_ID = {$clientId}",
                $content
            );
        } else {
            $content .= "\n# 客户端唯一标识（自动生成，请勿修改）\nUPDATER_CLIENT_ID = {$clientId}\n";
        }
        
        file_put_contents($envFile, $content);
    }

    /**
     * 检查更新
     */
    public function checkUpdate(): array
    {
        try {
            $url = $this->config['server_url'] . '/api/update/check?server=1';
            $response = $this->httpClient->post('/api/update/check', [
                'form_params' => [
                    'product_code'    => $this->config['product_code'],
                    'client_id'       => $this->clientId,
                    'current_version' => $this->getCurrentVersion(),
                    'php_version'     => PHP_VERSION,
                    'os_info'         => php_uname(),
                    'hostname'        => gethostname(),
                ],
            ]);
            
            $body = $response->getBody()->getContents();
            $statusCode = $response->getStatusCode();
            
            // 检查 HTTP 状态码
            if ($statusCode !== 200) {
                throw new RuntimeException("HTTP {$statusCode}: {$body}");
            }
            
            $result = json_decode($body, true);
            
            if (!$result) {
                throw new RuntimeException("JSON 解析失败: {$body}");
            }
            
            if ($result['code'] !== 1) {
                $msg = $result['msg'] ?? '';
                throw new RuntimeException($msg ?: "服务端返回错误 (code={$result['code']}): {$body}");
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('网络请求失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 下载更新包
     */
    public function downloadPackage(string $downloadToken, string $savePath): array
    {
        try {
            $response = $this->httpClient->get('/api/update/download', [
                'query' => [
                    'server'    => 1,
                    'token'     => $downloadToken,
                    'client_id' => $this->clientId,
                ],
                'sink' => $savePath,
            ]);
            
            return [
                'hash'      => $response->getHeaderLine('X-File-Hash'),
                'signature' => $response->getHeaderLine('X-File-Signature'),
            ];
        } catch (GuzzleException $e) {
            throw new RuntimeException('下载更新包失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 上报更新状态
     */
    public function reportStatus(string $fromVersion, string $toVersion, string $status, ?string $errorMessage = null): void
    {
        try {
            $this->httpClient->post('/api/update/report', [
                'form_params' => [
                    'client_id'     => $this->clientId,
                    'from_version'  => $fromVersion,
                    'to_version'    => $toVersion,
                    'status'        => $status,
                    'error_message' => $errorMessage,
                ],
            ]);
        } catch (GuzzleException $e) {
            error_log('状态上报失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取更新日志
     */
    public function getChangelog(?string $fromVersion = null): array
    {
        try {
            $query = ['server' => 1, 'product_code' => $this->config['product_code']];
            if ($fromVersion) {
                $query['from_version'] = $fromVersion;
            }
            
            $response = $this->httpClient->get('/api/update/changelog', ['query' => $query]);
            $result = json_decode($response->getBody()->getContents(), true);
            
            if (!$result || $result['code'] !== 1) {
                $msg = $result['msg'] ?? '';
                throw new RuntimeException($msg ?: '获取更新日志失败，请检查产品配置');
            }
            
            return $result['data']['changelog'] ?? [];
        } catch (GuzzleException $e) {
            throw new RuntimeException('获取更新日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取本机更新历史
     */
    public function getUpdateHistory(int $page = 1, int $limit = 20): array
    {
        try {
            $response = $this->httpClient->get('/api/update/history', [
                'query' => ['server' => 1, 'client_id' => $this->clientId, 'page' => $page, 'limit' => $limit],
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if (!$result || $result['code'] !== 1) {
                $msg = $result['msg'] ?? '';
                throw new RuntimeException($msg ?: '获取更新历史失败，客户端可能未注册');
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('获取更新历史失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取版本发布时间轴
     */
    public function getVersionTimeline(): array
    {
        try {
            $response = $this->httpClient->get('/api/update/timeline', [
                'query' => [
                    'server'          => 1,
                    'product_code'    => $this->config['product_code'],
                    'current_version' => $this->getCurrentVersion(),
                ],
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if (!$result || $result['code'] !== 1) {
                $msg = $result['msg'] ?? '';
                throw new RuntimeException($msg ?: '获取版本时间轴失败，请检查产品配置');
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('获取版本时间轴失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取更新包文件列表
     */
    public function getPackageFiles(string $downloadToken): array
    {
        try {
            $response = $this->httpClient->get('/api/update/files', [
                'query' => ['server' => 1, 'token' => $downloadToken, 'client_id' => $this->clientId],
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if (!$result || $result['code'] !== 1) {
                $msg = $result['msg'] ?? '';
                throw new RuntimeException($msg ?: '获取文件列表失败，令牌可能已过期');
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('获取文件列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取当前版本号
     */
    public function getCurrentVersion(): string
    {
        $version = $this->config['current_version'];
        return is_array($version) ? ($version['version'] ?? '1.0.0') : ($version ?: '1.0.0');
    }
    
    /**
     * 获取客户端ID（公开方法）
     */
    public function getClientIdPublic(): string
    {
        return $this->clientId;
    }
    
    /**
     * 获取配置信息
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * 从授权平台获取公钥
     * 
     * @return array ['public_key' => string, 'algorithm' => string, 'format' => string]
     */
    public function getPublicKey(): array
    {
        try {
            $response = $this->httpClient->get('/api/update/publicKey');
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if (!$result || $result['code'] !== 1) {
                $msg = $result['msg'] ?? '';
                throw new RuntimeException($msg ?: '获取公钥失败');
            }
            
            return $result['data'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('获取公钥失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取并保存公钥到本地文件
     * 
     * @param string|null $savePath 保存路径，默认为 config/updater_public.pem
     * @return bool
     */
    public function fetchAndSavePublicKey(?string $savePath = null): bool
    {
        $savePath = $savePath ?? config_path() . 'updater_public.pem';
        
        $data = $this->getPublicKey();
        
        if (empty($data['public_key'])) {
            throw new RuntimeException('获取的公钥内容为空');
        }
        
        $result = file_put_contents($savePath, $data['public_key']);
        
        return $result !== false;
    }
}
