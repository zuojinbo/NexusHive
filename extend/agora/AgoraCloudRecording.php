<?php

namespace agora;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 声网旁路推流服务
 * 
 * 用于将声网频道内的音视频流推送到 RTMP 服务器（如 SRS）
 * 文档：https://doc.shengwang.cn/api-ref/rtmp-streaming-restful/create-converter
 */
class AgoraCloudRecording
{
    /** @var string 声网 App ID */
    private string $appId;
    
    /** @var string RESTful API Customer Key */
    private string $customerKey;
    
    /** @var string RESTful API Customer Secret */
    private string $customerSecret;
    
    /** @var string API 基础地址 */
    private string $baseUrl = 'https://api.sd-rtn.com';
    
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
     * 创建 RTMP Converter（启动旁路推流）
     * 
     * @param string $channelName 频道名
     * @param string $rtmpUrl 完整的 RTMP 推流地址
     * @param string $converterName Converter 名称（可选）
     * @param string $region 区域：cn=中国大陆, ap=亚洲, na=北美, eu=欧洲
     * @return array ['converterId' => string] 或 ['error' => string]
     */
    public function createConverter(string $channelName, string $rtmpUrl, string $converterName = '', string $region = 'cn'): array
    {
        $url = "{$this->baseUrl}/{$region}/v1/projects/{$this->appId}/rtmp-converters";
        
        // 如果没有指定名称，使用频道名
        if (empty($converterName)) {
            $converterName = $channelName . '_' . time();
        }
        
        $payload = [
            'converter' => [
                'name' => $converterName,
                'transcodeOptions' => [
                    'rtcChannel' => $channelName,
                    // 音频配置
                    'audioOptions' => [
                        'codecProfile' => 'LC-AAC',
                        'sampleRate' => 48000,
                        'bitrate' => 48,
                        'audioChannels' => 2,
                    ],
                    // 视频配置
                    'videoOptions' => [
                        'codec' => 'H264',
                        'width' => 1280,
                        'height' => 720,
                        'frameRate' => 30,
                        'bitrate' => 2000,
                        // 画布配置
                        'canvas' => [
                            'width' => 1280,
                            'height' => 720,
                            'color' => 0, // 黑色背景
                        ],
                        // 布局配置 - 自适应布局
                        'layout' => [
                            [
                                'rtcStreamUid' => 0, // 0 表示自动选择
                                'region' => [
                                    'xPos' => 0,
                                    'yPos' => 0,
                                    'zIndex' => 0,
                                    'width' => 1280,
                                    'height' => 720,
                                ],
                                'fillMode' => 'fill', // fill=填充, fit=适应
                            ],
                        ],
                    ],
                ],
                'rtmpUrl' => $rtmpUrl,
                'idleTimeout' => 300, // 空闲超时 5 分钟
            ],
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                    'Content-Type' => 'application/json',
                    'X-Request-ID' => $this->generateUuid(),
                ],
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            
            if (isset($result['converter']['id'])) {
                return [
                    'converterId' => $result['converter']['id'],
                    'converterName' => $converterName,
                    'state' => $result['converter']['state'] ?? 'connecting',
                ];
            }
            
            return ['error' => '创建 Converter 失败'];
        } catch (GuzzleException $e) {
            return ['error' => '请求失败: ' . $e->getMessage()];
        }
    }

    /**
     * 删除 RTMP Converter（停止旁路推流）
     * 
     * @param string $converterId Converter ID
     * @param string $region 区域
     * @return array ['success' => bool] 或 ['error' => string]
     */
    public function deleteConverter(string $converterId, string $region = 'cn'): array
    {
        $url = "{$this->baseUrl}/{$region}/v1/projects/{$this->appId}/rtmp-converters/{$converterId}";

        try {
            $this->client->delete($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                    'X-Request-ID' => $this->generateUuid(),
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
     * @param string $region 区域
     * @return array 状态信息或错误
     */
    public function queryConverter(string $converterId, string $region = 'cn'): array
    {
        $url = "{$this->baseUrl}/{$region}/v1/projects/{$this->appId}/rtmp-converters/{$converterId}";

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => $this->getAuthHeader(),
                    'X-Request-ID' => $this->generateUuid(),
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return ['error' => '请求失败: ' . $e->getMessage()];
        }
    }

    /**
     * 生成 UUID
     */
    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * 生成 SRS RTMP 推流地址
     * 
     * @param string $streamName 流名称（如 drone_xxx）
     * @return string 完整的 RTMP 推流地址
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
     * @return string 拉流地址
     */
    public function generateSrsPlayUrl(string $streamName, string $type = 'flv'): string
    {
        switch ($type) {
            case 'rtmp':
                // RTMP 拉流地址（边缘终端使用）
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
