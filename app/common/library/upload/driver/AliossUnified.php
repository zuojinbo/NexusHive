<?php

namespace app\common\library\upload\driver;

use Throwable;
use OSS\OssClient;
use OSS\Core\OssException;
use think\file\UploadedFile;
use InvalidArgumentException;
use think\exception\FileException;
use OSS\Http\RequestCore_Exception;
use app\common\library\upload\Driver;
use ba\Storage;

/**
 * 统一对象存储上传驱动
 * 
 * 从 .env [STORAGE] 读取配置，自动适配阿里云OSS和MinIO
 * 平台上传和DJI存储共用同一套配置
 * 
 * @see Driver
 */
class AliossUnified extends Driver
{
    /**
     * 存储配置（从Storage类获取）
     */
    protected array $config = [];

    public function __construct(array $options = [])
    {
        $this->config = Storage::getConfig();
        
        if (!empty($options)) {
            $this->config = array_merge($this->config, $options);
        }
    }

    /**
     * 处理保存路径，自动添加platform/前缀
     */
    public function handleSaveName(string $saveName): string
    {
        $saveName = str_replace([full_url(), $this->url('')], '', $saveName);
        $saveName = str_replace('\\', '/', $saveName);
        $saveName = ltrim($saveName, '/');
        
        // 平台资源统一放在 platform/ 目录
        if (!str_starts_with($saveName, 'platform/')) {
            $saveName = 'platform/' . $saveName;
        }
        
        return $saveName;
    }

    /**
     * 保存文件
     */
    public function save(UploadedFile $file, string $saveName): bool
    {
        $saveName = $this->handleSaveName($saveName);
        $client = Storage::getClient();
        
        if ($this->config['provider'] === 'minio') {
            $client->putObject([
                'Bucket' => $this->config['bucket'],
                'Key'    => $saveName,
                'Body'   => fopen($file->getPathname(), 'rb'),
                'ContentType' => $file->getMime(),
            ]);
        } else {
            $client->uploadFile($this->config['bucket'], $saveName, $file->getPathname());
        }
        
        return true;
    }

    /**
     * 删除文件
     */
    public function delete(string $saveName): bool
    {
        try {
            $saveName = $this->handleSaveName($saveName);
            $client = Storage::getClient();
            
            if ($this->config['provider'] === 'minio') {
                $client->deleteObject([
                    'Bucket' => $this->config['bucket'],
                    'Key'    => $saveName,
                ]);
            } else {
                $client->deleteObject($this->config['bucket'], $saveName);
            }
        } catch (Throwable $e) {
            throw new FileException($e->getMessage());
        }
        return true;
    }

    /**
     * 获取资源URL地址
     */
    public function url(string $saveName, bool|string $domain = true, string $default = ''): string
    {
        if ($domain === true) {
            $domain = Storage::getCdnUrl();
        } elseif ($domain === false) {
            $domain = '';
        }

        $saveName = $saveName ?: $default;
        if (!$saveName) return $domain;

        $regex = "/^((?:[a-z]+:)?\/\/|data:image\/)(.*)/i";
        if (preg_match('/^http(s)?:\/\//', $saveName) || preg_match($regex, $saveName) || $domain === false) {
            return $saveName;
        }
        
        if (!str_starts_with($saveName, '/')) {
            $saveName = '/' . $saveName;
        }
        
        return str_replace('\\', '/', $domain . $saveName);
    }

    /**
     * 文件是否存在
     */
    public function exists(string $saveName): bool
    {
        try {
            $saveName = $this->handleSaveName($saveName);
            $client = Storage::getClient();
            
            if ($this->config['provider'] === 'minio') {
                $client->headObject([
                    'Bucket' => $this->config['bucket'],
                    'Key'    => $saveName,
                ]);
            } else {
                $client->getObjectMeta($this->config['bucket'], $saveName);
            }
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }
}
