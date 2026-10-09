<?php

namespace app\api\controller;

use app\common\controller\Api;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use agora\AgoraMediaPush;

/**
 * 声网 Token 代理接口
 * 解决前端直接请求声网服务的跨域问题
 */
class Agora extends Api
{
    /**
     * 无需登录的方法
     */
    protected array $noNeedLogin = ['token', 'batchToken', 'startPush', 'stopPush', 'queryPush', 'getPlayUrl'];

    /**
     * 获取声网 RTC Token
     * 
     * @return void
     */
    public function token(): void
    {
        $channelName = $this->request->post('channelName', '');
        $uid = $this->request->post('uid', '');
        $tokenExpireTs = $this->request->post('tokenExpireTs', 3600);
        $privilegeExpireTs = $this->request->post('privilegeExpireTs', 3600);
        $serviceRtc = $this->request->post('serviceRtc', ['enable' => true, 'role' => 1]);

        if (empty($channelName)) {
            $this->error('频道名称不能为空');
            return;
        }

        if (empty($uid)) {
            $this->error('用户ID不能为空');
            return;
        }

        // 确保 serviceRtc 的类型正确
        if (isset($serviceRtc['enable'])) {
            $serviceRtc['enable'] = (bool)$serviceRtc['enable'];
        }
        if (isset($serviceRtc['role'])) {
            $serviceRtc['role'] = (int)$serviceRtc['role'];
        }

        // 从配置获取声网 Token 服务地址
        $agoraTokenUrl = env('AGORA.TOKEN_URL', '');
        
        if (empty($agoraTokenUrl)) {
            $this->error('声网服务未配置');
            return;
        }

        $errorMsg = '';
        $tokenData = null;
        
        try {
            $client = new Client([
                'timeout' => 10,
                'verify' => false,
            ]);

            $response = $client->post($agoraTokenUrl . '?server=1', [
                'json' => [
                    'channelName' => (string)$channelName,
                    'uid' => (string)$uid,
                    'tokenExpireTs' => (int)$tokenExpireTs,
                    'privilegeExpireTs' => (int)$privilegeExpireTs,
                    'serviceRtc' => $serviceRtc,
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            
            if (isset($result['data']['token'])) {
                $tokenData = [
                    'token' => $result['data']['token'],
                    'channel' => $channelName,
                    'uid' => $uid,
                ];
            } else {
                $errorMsg = $result['msg'] ?? '获取Token失败';
            }
        } catch (GuzzleException $e) {
            $errorMsg = '请求声网服务失败: ' . $e->getMessage();
        } catch (\think\exception\HttpResponseException $e) {
            throw $e; // 重新抛出 HttpResponseException
        } catch (\Throwable $e) {
            $errorMsg = '系统错误: ' . $e->getMessage();
        }

        // success 放在 try-catch 外部
        if ($tokenData) {
            $this->success('获取成功', $tokenData);
            return;
        }

        $this->error($errorMsg ?: '未知错误');
    }

    /**
     * 批量获取声网 Token（同时获取机舱和飞行器的 Token）
     * 
     * @return void
     */
    public function batchToken(): void
    {
        $channels = $this->request->post('channels', []);
        
        if (empty($channels) || !is_array($channels)) {
            $this->error('频道配置不能为空');
            return;
        }

        $agoraTokenUrl = env('AGORA.TOKEN_URL', '');
        
        if (empty($agoraTokenUrl)) {
            $this->error('声网服务未配置');
            return;
        }

        $errorMsg = '';
        $tokens = [];
        try {
            $client = new Client([
                'timeout' => 10,
                'verify' => false,
            ]);
            
            foreach ($channels as $key => $channel) {
                if (empty($channel['channelName']) || empty($channel['uid'])) {
                    continue;
                }

                // 确保 serviceRtc 的类型正确
                $serviceRtc = $channel['serviceRtc'] ?? ['enable' => true, 'role' => 1];
                if (isset($serviceRtc['enable'])) {
                    $serviceRtc['enable'] = (bool)$serviceRtc['enable'];
                }
                if (isset($serviceRtc['role'])) {
                    $serviceRtc['role'] = (int)$serviceRtc['role'];
                }

                $response = $client->post($agoraTokenUrl . '?server=1', [
                    'json' => [
                        'channelName' => (string)$channel['channelName'],
                        'uid' => (string)$channel['uid'],
                        'tokenExpireTs' => (int)($channel['tokenExpireTs'] ?? 3600),
                        'privilegeExpireTs' => (int)($channel['privilegeExpireTs'] ?? 3600),
                        'serviceRtc' => $serviceRtc,
                    ],
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                
                if (isset($result['data']['token'])) {
                    $tokens[$key] = [
                        'token' => $result['data']['token'],
                        'channel' => $channel['channelName'],
                        'uid' => $channel['uid'],
                    ];
                } else {
                    // 记录外部服务返回的错误
                    $errorMsg = '外部服务返回: ' . json_encode($result, JSON_UNESCAPED_UNICODE);
                }
            }
        } catch (GuzzleException $e) {
            $errorMsg = '请求声网服务失败: ' . $e->getMessage();
        } catch (\think\exception\HttpResponseException $e) {
            throw $e; // 重新抛出 HttpResponseException
        } catch (\Throwable $e) {
            $errorMsg = '系统错误: ' . $e->getMessage();
        }

        if (!empty($tokens)) {
            $this->success('获取成功', $tokens);
            return;
        }
        
        if (empty($errorMsg)) {
            $errorMsg = '未能获取任何Token';
        }

        $this->error($errorMsg);
    }

    /**
     * 启动旁路推流到 SRS
     * 
     * @return void
     */
    public function startPush(): void
    {
        $channelName = $this->request->post('channelName', '');
        $streamName = $this->request->post('streamName', '');

        if (empty($channelName)) {
            $this->error('频道名称不能为空');
            return;
        }

        if (empty($streamName)) {
            // 默认使用频道名作为流名
            $streamName = $channelName;
        }

        $errorMsg = '';
        $pushData = null;

        try {
            $agora = new AgoraMediaPush();
            
            // 1. 生成 SRS RTMP 推流地址
            $rtmpUrl = $agora->generateSrsRtmpUrl($streamName);
            
            // 2. 创建 Converter 启动推流（使用转码模式）
            $converterName = "push_{$channelName}_" . time();
            $result = $agora->createConverterTranscode($channelName, $rtmpUrl, $converterName);
            if (isset($result['error'])) {
                $this->error('启动推流失败: ' . $result['error']);
                return;
            }
            
            // 3. 生成拉流地址
            $pushData = [
                'converterId' => $result['converterId'],
                'converterName' => $converterName,
                'state' => $result['state'],
                'channelName' => $channelName,
                'streamName' => $streamName,
                'rtmpUrl' => $rtmpUrl,
                'playUrls' => [
                    'rtmp' => $agora->generateSrsPlayUrl($streamName, 'rtmp'),
                    'flv' => $agora->generateSrsPlayUrl($streamName, 'flv'),
                    'hls' => $agora->generateSrsPlayUrl($streamName, 'hls'),
                    'webrtc' => $agora->generateSrsPlayUrl($streamName, 'webrtc'),
                ],
            ];
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $errorMsg = '系统错误: ' . $e->getMessage();
        }

        if ($pushData) {
            $this->success('推流已启动', $pushData);
            return;
        }

        $this->error($errorMsg ?: '启动推流失败');
    }

    /**
     * 停止旁路推流
     * 
     * @return void
     */
    public function stopPush(): void
    {
        $converterId = $this->request->post('converterId', '');

        if (empty($converterId)) {
            $this->error('Converter ID 不能为空');
            return;
        }

        $errorMsg = '';
        $stopResult = null;

        try {
            $agora = new AgoraMediaPush();
            $stopResult = $agora->deleteConverter($converterId);
            
            if (isset($stopResult['error'])) {
                $this->error('停止推流失败: ' . $stopResult['error']);
                return;
            }
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $errorMsg = '系统错误: ' . $e->getMessage();
        }

        if ($stopResult && isset($stopResult['success'])) {
            $this->success('推流已停止');
            return;
        }

        $this->error($errorMsg ?: '停止推流失败');
    }

    /**
     * 查询推流状态
     * 
     * @return void
     */
    public function queryPush(): void
    {
        $converterId = $this->request->post('converterId', '');
        $channelName = $this->request->post('channelName', '');

        $errorMsg = '';
        $queryResult = null;

        try {
            $agora = new AgoraMediaPush();
            
            if ($converterId) {
                $queryResult = $agora->getConverter($converterId);
                
                // 添加状态说明
                if (isset($queryResult['state'])) {
                    $stateDesc = match($queryResult['state']) {
                        'connecting' => '正在连接 - 请确保频道中有用户在推流',
                        'running' => '推流中 - 可以播放',
                        'failed' => '推流失败 - 请检查频道是否有用户在推流，或RTMP服务器是否可达',
                        default => $queryResult['state'],
                    };
                    $queryResult['stateDesc'] = $stateDesc;
                }
            } elseif ($channelName) {
                $queryResult = $agora->listConverters($channelName);
            } else {
                $queryResult = $agora->listConverters();
            }
            
            if (isset($queryResult['error'])) {
                $this->error('查询失败: ' . $queryResult['error']);
                return;
            }
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $errorMsg = '系统错误: ' . $e->getMessage();
        }

        if ($queryResult) {
            $this->success('查询成功', $queryResult);
            return;
        }

        $this->error($errorMsg ?: '查询失败');
    }

    /**
     * 获取 SRS 拉流地址
     * 
     * @return void
     */
    public function getPlayUrl(): void
    {
        $streamName = $this->request->post('streamName', '');
        $type = $this->request->post('type', 'flv');

        if (empty($streamName)) {
            $this->error('流名称不能为空');
            return;
        }

        $agora = new AgoraMediaPush();
        $playUrl = $agora->generateSrsPlayUrl($streamName, $type);

        $this->success('获取成功', [
            'streamName' => $streamName,
            'type' => $type,
            'playUrl' => $playUrl,
            'allUrls' => [
                'rtmp' => $agora->generateSrsPlayUrl($streamName, 'rtmp'),
                'flv' => $agora->generateSrsPlayUrl($streamName, 'flv'),
                'hls' => $agora->generateSrsPlayUrl($streamName, 'hls'),
                'webrtc' => $agora->generateSrsPlayUrl($streamName, 'webrtc'),
            ],
        ]);
    }
}
