<?php

namespace app\api\controller;

use app\common\controller\Api;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Rtmp extends Api
{
    protected array $noNeedLogin = ['querySecret', 'updateSecret', 'streamStatus', 'kickStream', 'streamList'];

    private function getMediaConfig(): array
    {
        return [
            'apiServer' => env('SRS.API_SERVER', 'http://103.205.254.30:8088'),
            'apiSecret' => env('SRS.API_SECRET', 'Bearer srs-v2-faea67d2ef4f451082ccb864a41e1541'),
            'rtmpUrl' => env('SRS.RTMP_URL', 'rtmp://103.205.254.30:1935/live'),
            'flvUrl' => env('SRS.FLV_URL', 'http://103.205.254.30:8088/live'),
            'hlsUrl' => env('SRS.HLS_URL', 'http://103.205.254.30:8088/live'),
            'apiSchema' => env('SRS.API_SCHEMA', 'rtmp'),
            'defaultApp' => env('SRS.APP', 'live'),
        ];
    }

    private function createClient(): Client
    {
        return new Client([
            'timeout' => 10,
            'verify' => false,
        ]);
    }

    private function buildPlayUrls(array $config, string $streamKey, string $app): array
    {
        $app = $app ?: $config['defaultApp'];
        $baseFlvUrl = rtrim($config['flvUrl'], '/');
        $baseHlsUrl = rtrim($config['hlsUrl'], '/');
        $baseRtmpUrl = rtrim($config['rtmpUrl'], '/');

        return [
            'rtmp' => "{$baseRtmpUrl}/{$streamKey}",
            'flv' => "{$baseFlvUrl}/{$app}/{$streamKey}.live.flv",
            'hls' => "{$baseHlsUrl}/{$app}/{$streamKey}/hls.m3u8",
        ];
    }

    private function requestZlm(string $path, array $query = []): array
    {
        $config = $this->getMediaConfig();
        $client = $this->createClient();

        $response = $client->get(
            rtrim($config['apiServer'], '/') . $path,
            [
                'query' => array_merge(['secret' => $config['apiSecret']], $query),
            ]
        );

        $result = json_decode($response->getBody()->getContents(), true);
        if (($result['code'] ?? -1) !== 0) {
            throw new \RuntimeException($result['msg'] ?? 'ZLMediaKit api request failed');
        }

        return $result;
    }

    private function getMediaList(?string $streamKey = null): array
    {
        $config = $this->getMediaConfig();
        $query = [
            'schema' => $config['apiSchema'],
        ];

        if (!empty($streamKey)) {
            $query['stream'] = $streamKey;
        }

        $result = $this->requestZlm('/index/api/getMediaList', $query);

        return $result['data'] ?? [];
    }

    public function querySecret(): void
    {
        $this->success('查询成功', [
            'secret' => env('SRS.RTMP_SECRET', ''),
            'update' => null,
            'source' => 'config',
        ]);
    }

    public function updateSecret(): void
    {
        $this->error('ZLMediaKit 未启用全局推流密钥动态更新，请在设备 RTMP 配置或环境变量中修改');
    }

    public function streamStatus(): void
    {
        $streamKey = $this->request->get('streamKey', '');
        if (empty($streamKey)) {
            $this->error('流名称不能为空');
            return;
        }

        try {
            $config = $this->getMediaConfig();
            $streams = $this->getMediaList($streamKey);
            $stream = $streams[0] ?? null;

            if (!$stream) {
                $this->success('查询成功', [
                    'exists' => false,
                    'message' => '流不存在或未推流',
                ]);
                return;
            }

            $app = $stream['app'] ?? $config['defaultApp'];
            $this->success('查询成功', [
                'exists' => true,
                'stream' => $stream,
                'playUrls' => $this->buildPlayUrls($config, $streamKey, $app),
            ]);
        } catch (GuzzleException $e) {
            $this->error('请求 ZLMediaKit 服务失败: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->error('系统错误: ' . $e->getMessage());
        }
    }

    public function kickStream(): void
    {
        $streamKey = $this->request->delete('streamKey', '');
        if (empty($streamKey)) {
            $this->error('流名称不能为空');
            return;
        }

        try {
            $config = $this->getMediaConfig();
            $result = $this->requestZlm('/index/api/close_streams', [
                'schema' => $config['apiSchema'],
                'vhost' => '__defaultVhost__',
                'app' => $config['defaultApp'],
                'stream' => $streamKey,
                'force' => 1,
            ]);

            if (($result['count_closed'] ?? 0) < 1) {
                $this->error($result['msg'] ?? '关闭流失败');
                return;
            }

            $this->success('操作成功', [
                'streamKey' => $streamKey,
                'message' => '流已踢出',
            ]);
        } catch (GuzzleException $e) {
            $this->error('请求 ZLMediaKit 服务失败: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->error('系统错误: ' . $e->getMessage());
        }
    }

    public function streamList(): void
    {
        try {
            $config = $this->getMediaConfig();
            $streams = $this->getMediaList();
            $formattedStreams = array_map(function (array $stream) use ($config) {
                $streamName = $stream['stream'] ?? '';
                $app = $stream['app'] ?? $config['defaultApp'];

                return [
                    'name' => $streamName,
                    'app' => $app,
                    'live_ms' => (int) (($stream['aliveSecond'] ?? 0) * 1000),
                    'clients' => $stream['totalReaderCount'] ?? $stream['readerCount'] ?? 0,
                    'send_bytes' => $stream['bytesSpeed'] ?? 0,
                    'recv_bytes' => 0,
                    'playUrls' => $this->buildPlayUrls($config, $streamName, $app),
                ];
            }, $streams);

            $this->success('查询成功', [
                'total' => count($formattedStreams),
                'streams' => $formattedStreams,
            ]);
        } catch (GuzzleException $e) {
            $this->error('请求 ZLMediaKit 服务失败: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->error('系统错误: ' . $e->getMessage());
        }
    }
}
