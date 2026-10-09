<?php
/**
 * 视频流分析器 - 抽帧 + 大模型识别 + 回调
 * 部署在 Linux 上需要安装 ffmpeg
 */

namespace app\common;

class VideoStreamAnalyzer
{
    protected string $apiKey;
    protected string $model;
    protected string $callbackUrl;
    protected int $frameInterval; // 抽帧间隔(秒)

    public function __construct(
        string $apiKey = '',
        string $model = 'qwen-vl-plus',
        string $callbackUrl = '',
        int $frameInterval = 3
    ) {
        $this->apiKey = $apiKey ?: env('DASHSCOPE_API_KEY', 'sk-7a68015a28b44438a4086e3b19f1c08d');
        $this->model = $model;
        $this->callbackUrl = $callbackUrl;
        $this->frameInterval = $frameInterval;
    }

    /**
     * 开始解析视频流
     */
    public function parse(string $streamUrl, string $prompt = '', string $taskId = ''): \Generator
    {
        if (empty($streamUrl)) {
            yield ['error' => 'stream_url不能为空'];
            return;
        }

        if (empty($prompt)) {
            $prompt = $this->getDefaultPrompt();
        }

        // 创建临时目录存放抽帧图片
        $tempDir = runtime_path('frames/' . md5($streamUrl . time()));
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // 停止信号文件
        $stopFile = $taskId ? runtime_path('frames/' . $taskId . '.stop') : '';

        yield ['status' => '开始捕获视频流...', 'stream_url' => $streamUrl, 'task_id' => $taskId];

        // 查找 ffmpeg 路径（兼容不同环境）
        $ffmpegPath = '/usr/bin/ffmpeg';
        if (!file_exists($ffmpegPath)) {
            $ffmpegPath = trim(\shell_exec('which ffmpeg 2>/dev/null') ?? '');
        }
        if (empty($ffmpegPath) || !file_exists($ffmpegPath)) {
            yield ['error' => 'ffmpeg未安装，请先安装ffmpeg'];
            return;
        }
        yield ['status' => 'ffmpeg就绪', 'path' => $ffmpegPath];

        // 后台执行 FFmpeg 抽帧
        $framePattern = $tempDir . '/frame_%04d.jpg';
        $logFile = $tempDir . '/ffmpeg.log';
        $cmd = sprintf(
            '%s -rw_timeout 10000000 -i %s -vf "fps=1/%d" -q:v 2 %s > %s 2>&1 &',
            $ffmpegPath,
            escapeshellarg($streamUrl),
            $this->frameInterval,
            escapeshellarg($framePattern),
            escapeshellarg($logFile)
        );

        yield ['status' => '正在连接视频流...', 'cmd' => $cmd];
        
        // 后台启动 ffmpeg
        exec($cmd);

        $processedFrames = [];
        $maxWaitTime = 120; // 最大等待2分钟
        $startTime = time();
        $frameCount = 0;
        $lastHeartbeat = time();
        $noNewFrameCount = 0;

        while ((time() - $startTime) < $maxWaitTime) {
            // 检查停止信号
            if ($stopFile && file_exists($stopFile)) {
                @unlink($stopFile);
                yield ['status' => '收到停止信号，正在停止...'];
                break;
            }

            // 每3秒发送心跳，让前端知道还在运行
            if ((time() - $lastHeartbeat) >= 3) {
                yield ['heartbeat' => true, 'elapsed' => (time() - $startTime), 'frames_processed' => $frameCount];
                $lastHeartbeat = time();
            }

            // 扫描新生成的帧
            $frames = glob($tempDir . '/frame_*.jpg');
            $newFrames = array_diff($frames, $processedFrames);

            if (empty($newFrames)) {
                $noNewFrameCount++;
                // 如果连续20秒没有新帧，检查ffmpeg日志
                if ($noNewFrameCount >= 20 && $frameCount == 0) {
                    $log = @file_get_contents($logFile);
                    yield ['error' => '视频流连接失败', 'ffmpeg_log' => substr($log, -500)];
                    break;
                }
                // 如果已经处理过帧，且10秒没有新帧，认为结束
                if ($noNewFrameCount >= 10 && $frameCount > 0) {
                    yield ['status' => '视频流结束或无新帧'];
                    break;
                }
                sleep(1);
                continue;
            }

            $noNewFrameCount = 0;

            foreach ($newFrames as $framePath) {
                // 等待文件写入完成
                usleep(200000); // 200ms
                
                if (!file_exists($framePath) || filesize($framePath) < 1000) {
                    continue;
                }

                $processedFrames[] = $framePath;
                $frameCount++;

                yield ['status' => '捕获到新帧', 'frame' => basename($framePath), 'frame_count' => $frameCount];

                // 转 Base64
                $base64Image = base64_encode(file_get_contents($framePath));

                // 调用大模型识别
                yield ['status' => '正在AI分析...', 'frame' => basename($framePath)];
                $result = $this->callVisionModel($base64Image, $prompt);

                if (!empty($result)) {
                    yield ['frame' => basename($framePath), 'raw_result' => $result];

                    // 解析结果并回调
                    $parsed = $this->parseAndCallback($result, $base64Image);
                    if ($parsed) {
                        yield $parsed;
                    }
                } else {
                    yield ['warning' => '大模型返回空', 'frame' => basename($framePath)];
                }

                // 删除已处理的帧文件
                @unlink($framePath);
            }
        }

        // 停止 ffmpeg
        exec("pkill -f 'ffmpeg.*{$tempDir}'");

        // 清理
        $this->cleanup($tempDir);
        yield ['status' => '视频捕获结束', 'total_frames' => $frameCount];
    }

    /**
     * 调用阿里百炼视觉大模型（OpenAI 兼容模式）
     */
    protected function callVisionModel(string $base64Image, string $prompt): string
    {
        if (empty($this->apiKey)) {
            return '';
        }

        // 使用 OpenAI 兼容模式端点
        $url = 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions';

        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:image/jpeg;base64,' . $base64Image
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ]
                    ]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $result = json_decode($response, true);
            // OpenAI 兼容模式的响应格式
            return $result['choices'][0]['message']['content'] ?? '';
        }

        return '';
    }

    /**
     * 解析结果并回调
     */
    protected function parseAndCallback(string $result, string $base64Image): ?array
    {
        // 提取 JSON（AI 可能返回 markdown 代码块格式）
        $jsonStr = $result;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $result, $matches)) {
            $jsonStr = trim($matches[1]);
        }
        
        $data = json_decode($jsonStr, true);
        if (!is_array($data)) {
            return ['type' => 'normal', 'content' => '未捕获到人物闯入和垃圾漂浮物', 'parse_error' => 'JSON解析失败'];
        }

        $content = $data['content'] ?? '';
        $name = $data['name'] ?? '';

        $callbackData = null;

        if ($name === '发现人物闯入') {
            $callbackData = [
                'appeal_type' => '人脸识别与追踪',
                'content' => $content,
                'image' => $base64Image
            ];
        } elseif ($name === '识别到垃圾漂浮物') {
            $callbackData = [
                'appeal_type' => '垃圾检测',
                'content' => $content,
                'image' => $base64Image
            ];
        }

        if ($callbackData && !empty($this->callbackUrl)) {
            $this->sendCallback($callbackData);
            return ['type' => 'alert', 'name' => $name, 'content' => $content];
        }

        return ['type' => 'normal', 'content' => '未捕获到人物闯入和垃圾漂浮物'];
    }

    /**
     * 发送回调请求
     */
    protected function sendCallback(array $data): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->callbackUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        $result = curl_exec($ch);
        $success = curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200;
        curl_close($ch);

        return $success;
    }

    /**
     * 后台执行命令
     */
    protected function execBackground(string $cmd): int
    {
        $pid = 0;
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            $output = [];
            exec($cmd . ' echo $!', $output);
            $pid = (int)($output[0] ?? 0);
        }
        return $pid;
    }

    /**
     * 检查进程是否运行
     */
    protected function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        return file_exists("/proc/{$pid}");
    }

    /**
     * 清理临时目录
     */
    protected function cleanup(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /**
     * 默认识别提示词
     */
    protected function getDefaultPrompt(): string
    {
        return <<<PROMPT
如图，需要你帮我识别一下图中是否有人 和 是否有垃圾漂浮物
我需要你按照json格式给我返回
第一，我需要一个content来存储你直接返回给我的内容，即 是否有人和垃圾漂浮物
第二，我要你按照key=name，如果有人，则返回发现人物闯入，如果有垃圾漂浮物，返回识别到垃圾漂浮物
如{"content": "图片中未识别到人物。图片中识别到水面有大量垃圾漂浮物。","name": "识别到垃圾漂浮物"}
PROMPT;
    }
}
