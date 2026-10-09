<?php

namespace agora;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 声网 Media Push 服务（旁路推流）
 * 
 * 使用 Converter API 将声网频道内的音视频流推送到 RTMP 服务器
 * 文档：https://docs.agora.io/en/media-push/develop/restful-api
 */
class AgoraMediaPush
{
    /** @var string 声网 App ID */
    private string $appId;
    
    /** @var string RESTful API Customer Key */
    private string $customerKey;
    
    /** @var string RESTful API Customer Secret */
    private string $customerSecret;
    
    /** @var string API 基础地址 */
    private string $baseUrl = 'https://api.agora.io';
    
    /** @var Client HTTP 客户端 */
    private Client $client;

    public function __construct()
    {
        $this->appId = env('AGORA.APP_ID', '');
        $this->customerKey = env('AGORA.CUSTOMER_KEY', '');
        $this->customerSecret = env('AGORA.CUSTOMER_SECRET', '');
        
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false,
        ]);
    }

    /**
     * 获取 HTTP Basic Auth 头
     */
    private function getAuthHeader(): string
    {
        return 'Basic ' . base64_encode($this->customerKey . ':' . $this->customerSecret);
    }

    /**
     * 创建 Converter（启动旁路推流）- 不转码模式
     * 
     * 适用于单主播场景，直接转发流到 RTMP
     * 
     * @param string $channelName 频道名
     * @param int $rtcStreamUid 要推流的用户 UID
     * @param string $rtmpUrl RTMP 推流地址
     * @param string $converterName Converter 名称（可选）
     * @param int $idleTimeout 空闲超时时间（秒）
     * @return array
     */
    public function createConverterRaw(
        string $channelName, 
        int $rtcStreamUid, 
        string $rtmpUrl, 
        string $converterName = '',
        int $idleTimeout = 300
    ): array {
        $url = "{$this->baseUrl}/v1/projects/{$this->appId}/rtmp-converters";
        
        $payload = [
            'converter' => [
                'name' => $converterName ?: "converter_{$channelName}_{$rtcStreamUid}",
                'rawOptions' => [
                    'rtcChannel' => $channelName,
                    'rtcStreamUid' => $rtcStreamUid,
                ],
                'rtmpUrl' => $rtmpUrl,
                'idleTimeOut' => $idleTimeout,
            ],
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            
            return [
                'success' => true,
                'converterId' => $result['sid'] ?? '',
                'state' => $result['state'] ?? 'connecting',
                'createTs' => $result['createTs'] ?? time(),
            ];
        } catch (GuzzleException $e) {
            return ['error' => '请求失败: ' . $e->getMessage()];
        }
    }

    /**
     * 创建 Converter（启动旁路推流）- 转码模式
     * 
     * 适用于多主播场景，混流后推送到 RTMP
     * 使用 layoutType=1 垂直布局，自动排列所有用户
     * 
     * @param string $channelName 频道名
     * @param string $rtmpUrl RTMP 推流地址
     * @param string $converterName Converter 名称（可选）
     * @param int $idleTimeout 空闲超时时间（秒）
     * @param array $videoOptions 视频配置
     * @return array
     */
    public function createConverterTranscode(
        string $channelName, 
        string $rtmpUrl, 
        string $converterName = '',
        int $idleTimeout = 300,
        array $videoOptions = []
    ): array {
        $url = "{$this->baseUrl}/v1/projects/{$this->appId}/rtmp-converters";
        
        // 默认视频配置 - 使用垂直布局
        $defaultVideoOptions = [
            'canvas' => [
                'width' => 1280,
                'height' => 720,
                'color' => 0,
            ],
            'layoutType' => 1, // 垂直布局，自动排列
            'vertical' => [
                'maxResolutionUid' => 0, // 0 表示自动选择最大分辨率的用户
                'fillMode' => 'fill',
            ],
            'codec' => 'H.264',
            'codecProfile' => 'high',
            'frameRate' => 30,
            'gop' => 60,
            'bitrate' => 2000,
        ];
        
        $videoOptions = array_merge($defaultVideoOptions, $videoOptions);
        
        $payload = [
            'converter' => [
                'name' => $converterName ?: "converter_{$channelName}",
                'transcodeOptions' => [
                    'rtcChannel' => $channelName,
                    'audioOptions' => [
                        'codecProfile' => 'LC-AAC',
                        'sampleRate' => 48000,
                        'bitrate' => 48,
                        'audioChannels' => 1,
                    ],
                    'videoOptions' => $videoOptions,
                ],
                'rtmpUrl' => $rtmpUrl,
                'idleTimeOut' => $idleTimeout,
            ],
        ];

        try {
            // 记录请求日志
            \think\facade\Log::info('Agora Media Push Request (Transcode): ' . json_encode([
                'url' => $url,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE));
            
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);
            
            // 记录响应日志
            \think\facade\Log::info('Agora Media Push Response (Transcode): ' . $responseBody);
            
            // Agora API 返回的 converter ID 在 converter.id 或 sid 字段
            $converterId = $result['converter']['id'] ?? $result['sid'] ?? $result['id'] ?? '';
            $state = $result['converter']['state'] ?? $result['state'] ?? 'connecting';
            
            return [
                'success' => true,
                'converterId' => $converterId,
                'state' => $state,
                'createTs' => $result['createTs'] ?? time(),
                'rawResponse' => $result, // 返回原始响应用于调试
            ];
        } catch (GuzzleException $e) {
            // 尝试获取响应体
            $responseBody = '';
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
            }
            
            // 记录错误日志
            \think\facade\Log::error('Agora Media Push Error (Transcode): ' . json_encode([
                'url' => $url,
                'error' => $e->getMessage(),
                'response' => $responseBody,
            ], JSON_UNESCAPED_UNICODE));
            
            return ['error' => '请求失败: ' . $e->getMessage()];
        }
    }

    /**
     * 删除 Converter（停止旁路推流）
     * 
     * @param string $converterId Converter ID
     * @return array
     */
    public function deleteConverter(string $converterId): array
    {
        $url = "{$this->baseUrl}/v1/projects/{$this->appId}/rtmp-converters/{$converterId}";

        try {
            $this->client->delete($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                ],
            ]);

            return ['success' => true];
        } catch (GuzzleException $e) {
            return ['error' => '请求失败: ' . $e->getMessage()];
        }
    }

    /**
     * 查询 Converter 状态
     * 
     * @param string $converterId Converter ID
     * @return array
     */
    public function getConverter(string $converterId): array
    {
        $url = "{$this->baseUrl}/v1/projects/{$this->appId}/rtmp-converters/{$converterId}";

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => '请求失败: ' . $e->getMessage()];
        }
    }

    /**
     * 列出频道下的所有 Converter
     * 
     * @param string $channelName 频道名（可选）
     * @return array
     */
    public function listConverters(string $channelName = ''): array
    {
        $url = "{$this->baseUrl}/v1/projects/{$this->appId}/rtmp-converters";
        
        if ($channelName) {
            $url .= "?cname=" . urlencode($channelName);
        }

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => '请求失败: ' . $e->getMessage()];
        }
    }

    /**
     * 生成 SRS RTMP 推流地址
     * 
     * @param string $streamName 流名称
     * @return string
     */
    public function generateSrsRtmpUrl(string $streamName): string
    {
        $rtmpUrl = env('SRS.RTMP_URL', 'rtmp://103.205.254.30/live');
        $secret = env('SRS.RTMP_SECRET', '');
        
        if ($secret) {
            return "{$rtmpUrl}/{$streamName}?secret={$secret}";
        }
        
        return "{$rtmpUrl}/{$streamName}";
    }

    /**
     * 生成 SRS 拉流地址
     * 
     * @param string $streamName 流名称
     * @param string $type 类型：rtmp, flv, hls, webrtc
     * @return string
     */
    public function generateSrsPlayUrl(string $streamName, string $type = 'flv'): string
    {
        switch ($type) {
            case 'rtmp':
                $rtmpUrl = env('SRS.RTMP_URL', 'rtmp://103.205.254.30/live');
                return "{$rtmpUrl}/{$streamName}";
            case 'hls':
                $baseUrl = env('SRS.HLS_URL', 'http://103.205.254.30:20221/live');
                return "{$baseUrl}/{$streamName}.m3u8";
            case 'webrtc':
                return "webrtc://103.205.254.30:20221/live/{$streamName}";
            case 'flv':
            default:
                $baseUrl = env('SRS.FLV_URL', 'http://103.205.254.30:20221/live');
                return "{$baseUrl}/{$streamName}.flv";
        }
    }
}
