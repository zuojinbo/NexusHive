<?php

namespace ba;

use OSS\OssClient;
use Aws\S3\S3Client;
use InvalidArgumentException;

/**
 * 统一对象存储管理类
 * 
 * 从 .env [STORAGE] 读取配置，支持阿里云OSS和MinIO
 * 平台上传、DJI存储配置统一使用此类
 * 
 * .env 配置示例：
 * 
 * [STORAGE]
 * PROVIDER = ali                                    ; ali | minio
 * BUCKET = djicloudapis
 * ENDPOINT = https://oss-cn-chengdu.aliyuncs.com
 * REGION = cn-chengdu
 * CDN_URL = https://djicloudapis.oss-cn-chengdu.aliyuncs.com
 * ; 阿里云STS配置
 * STS_URL = https://sts.aliyuncs.com
 * ACCESS_KEY_ID = your_access_key_id
 * ACCESS_KEY_SECRET = your_access_key_secret
 * ROLE_ARN = acs:ram::account_id:role/oss
 * ROLE_SESSION_NAME = djicloudapis
 * STS_DURATION = 3599
 * ; MinIO配置（provider=minio时使用）
 * ACCESS_KEY = minio_access_key
 * SECRET_KEY = minio_secret_key
 */
class Storage
{
    /**
     * 配置缓存
     */
    protected static ?array $config = null;
    
    /**
     * STS凭证缓存
     */
    protected static ?array $stsCredentials = null;
    
    /**
     * STS凭证过期时间
     */
    protected static int $stsExpireAt = 0;
    
    /**
     * 客户端缓存
     */
    protected static $client = null;

    /**
     * 获取存储配置
     */
    public static function getConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        
        self::$config = [
            'provider'        => strtolower(env('storage.provider', 'ali')),
            'bucket'          => env('storage.bucket', 'djicloudapis'),
            'endpoint'        => env('storage.endpoint', 'https://oss-cn-chengdu.aliyuncs.com'),
            'region'          => env('storage.region', 'cn-chengdu'),
            'cdn_url'         => env('storage.cdn_url', ''),
            // 阿里云STS
            'sts_url'         => env('storage.sts_url', 'https://sts.aliyuncs.com'),
            'access_key_id'   => env('storage.access_key_id', ''),
            'access_key_secret' => env('storage.access_key_secret', ''),
            'role_arn'        => env('storage.role_arn', ''),
            'role_session_name' => env('storage.role_session_name', 'djicloudapis'),
            'sts_duration'    => (int)env('storage.sts_duration', 3599),
            // MinIO
            'access_key'      => env('storage.access_key', ''),
            'secret_key'      => env('storage.secret_key', ''),
        ];
        
        return self::$config;
    }
    
    /**
     * 获取存储提供商
     */
    public static function getProvider(): string
    {
        return self::getConfig()['provider'];
    }
    
    /**
     * 是否为MinIO
     */
    public static function isMinio(): bool
    {
        return self::getProvider() === 'minio';
    }
    
    /**
     * 获取CDN URL
     */
    public static function getCdnUrl(): string
    {
        $config = self::getConfig();
        
        if (!empty($config['cdn_url'])) {
            return rtrim($config['cdn_url'], '/');
        }
        
        if (self::isMinio()) {
            return rtrim($config['endpoint'], '/') . '/' . $config['bucket'];
        } else {
            $endpoint = str_replace(['https://', 'http://'], '', $config['endpoint']);
            return 'https://' . $config['bucket'] . '.' . $endpoint;
        }
    }
    
    /**
     * 获取STS临时凭证（阿里云专用）
     */
    public static function getStsCredentials(): array
    {
        // 检查缓存（提前5分钟刷新）
        if (self::$stsCredentials && time() < (self::$stsExpireAt - 300)) {
            return self::$stsCredentials;
        }
        
        $config = self::getConfig();
        
        if (empty($config['access_key_id']) || empty($config['access_key_secret']) || empty($config['role_arn'])) {
            throw new InvalidArgumentException('Storage STS configuration is incomplete');
        }
        
        // 调用STS获取临时凭证
        $sts = self::requestSts($config);
        
        if (empty($sts) || empty($sts['access_key_id'])) {
            throw new InvalidArgumentException('Failed to get STS credentials');
        }
        
        self::$stsCredentials = $sts;
        self::$stsExpireAt = time() + ($sts['expire'] ?? 3600);
        
        return $sts;
    }
    
    /**
     * 请求STS临时凭证
     */
    protected static function requestSts(array $config): array
    {
        date_default_timezone_set('UTC');
        
        $param = [
            'Format'           => 'JSON',
            'Version'          => '2015-04-01',
            'AccessKeyId'      => $config['access_key_id'],
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce'   => self::randomString(8),
            'Action'           => 'AssumeRole',
            'RoleArn'          => $config['role_arn'],
            'RoleSessionName'  => $config['role_session_name'],
            'DurationSeconds'  => $config['sts_duration'],
            'Timestamp'        => date('Y-m-d\TH:i:s\Z'),
        ];
        
        $param['Signature'] = self::computeSignature($param, $config['access_key_secret']);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $config['sts_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if (empty($result['Credentials'])) {
            return [];
        }
        
        $expireTimestamp = strtotime($result['Credentials']['Expiration']);
        
        return [
            'access_key_id'     => $result['Credentials']['AccessKeyId'] ?? '',
            'access_key_secret' => $result['Credentials']['AccessKeySecret'] ?? '',
            'security_token'    => $result['Credentials']['SecurityToken'] ?? '',
            'expire'            => $expireTimestamp > 0 ? ($expireTimestamp - time()) : 0,
        ];
    }
    
    /**
     * 计算签名
     */
    protected static function computeSignature(array $params, string $secret): string
    {
        ksort($params);
        $queryString = '';
        foreach ($params as $key => $value) {
            $queryString .= '&' . self::percentEncode($key) . '=' . self::percentEncode($value);
        }
        $stringToSign = 'POST&%2F&' . self::percentEncode(substr($queryString, 1));
        return base64_encode(hash_hmac('sha1', $stringToSign, $secret . '&', true));
    }
    
    /**
     * URL编码
     */
    protected static function percentEncode(string $str): string
    {
        $res = urlencode($str);
        $res = preg_replace('/\+/', '%20', $res);
        $res = preg_replace('/\*/', '%2A', $res);
        $res = preg_replace('/%7E/', '~', $res);
        return $res;
    }
    
    /**
     * 生成随机字符串
     */
    protected static function randomString(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $str;
    }
    
    /**
     * 获取存储客户端
     * @return OssClient|S3Client
     */
    public static function getClient()
    {
        $config = self::getConfig();
        
        if (self::isMinio()) {
            return self::getS3Client();
        } else {
            return self::getOssClient();
        }
    }
    
    /**
     * 获取阿里云OSS客户端
     */
    public static function getOssClient(): OssClient
    {
        $config = self::getConfig();
        $sts = self::getStsCredentials();
        
        $endpoint = $config['endpoint'];
        if (!str_starts_with($endpoint, 'http')) {
            $endpoint = 'https://' . $endpoint;
        }
        
        return new OssClient(
            $sts['access_key_id'],
            $sts['access_key_secret'],
            $endpoint,
            false,
            $sts['security_token']
        );
    }
    
    /**
     * 获取MinIO S3客户端
     */
    public static function getS3Client(): S3Client
    {
        if (self::$client instanceof S3Client) {
            return self::$client;
        }
        
        $config = self::getConfig();
        
        self::$client = new S3Client([
            'version'     => 'latest',
            'region'      => $config['region'],
            'endpoint'    => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => $config['access_key'],
                'secret' => $config['secret_key'],
            ],
            'http' => [
                'verify' => false,
            ],
        ]);
        
        return self::$client;
    }
    
    /**
     * 获取DJI存储配置（用于storageConfigReady返回给设备）
     */
    public static function getDjiStorageConfig(string $sn): array
    {
        $config = self::getConfig();
        
        $output = [
            'bucket'          => $config['bucket'],
            'endpoint'        => $config['endpoint'],
            'object_key_prefix' => $sn,
            'provider'        => self::isMinio() ? 'minio' : 'ali',
            'region'          => str_replace('oss-', '', $config['region']),
        ];
        
        if (self::isMinio()) {
            // MinIO使用固定凭证
            $output['credentials'] = [
                'access_key_id'     => $config['access_key'],
                'access_key_secret' => $config['secret_key'],
                'expire'            => 86400, // 24小时
            ];
        } else {
            // 阿里云使用STS临时凭证
            $output['credentials'] = self::getStsCredentials();
        }
        
        return $output;
    }
}
