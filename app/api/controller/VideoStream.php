<?php
/**
 * 视频流分析接口
 */

namespace app\api\controller;

use app\common\controller\Api;
use think\exception\HttpResponseException;

class VideoStream extends Api
{
    /**
     * 无需登录的方法
     */
    protected array $noNeedLogin = ['analyzeFrame', 'test'];

    /**
     * 测试接口
     */
    public function test(): void
    {
        $this->success('VideoStream API is working', [
            'time' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION
        ]);
    }

    /**
     * 单帧图片分析
     * POST /api/video_stream/analyze_frame
     */
    public function analyzeFrame(): void
    {
        $errorMsg = '';
        $resultData = null;

        try {
            // 获取参数
            $image = $this->request->post('image', '');
            $text = $this->request->post('text', '');
            $callbackUrl = $this->request->post('callback_url', '');

            if (empty($image)) {
                $this->error('image参数不能为空');
                return;
            }

            // 清理 base64 数据
            $base64 = $this->cleanBase64($image);
            if (empty($base64)) {
                $this->error('base64数据无效', [
                    'received_length' => strlen($image),
                    'first_chars' => substr($image, 0, 50)
                ]);
                return;
            }

            // 验证图片
            $imageData = base64_decode($base64, true);
            if (!$imageData || strlen($imageData) < 100) {
                $this->error('base64解码失败或数据太小');
                return;
            }

            // 检测图片类型
            $mimeType = $this->detectImageType($imageData);
            if (empty($mimeType)) {
                $this->error('无法识别图片格式', [
                    'header_hex' => bin2hex(substr($imageData, 0, 8))
                ]);
                return;
            }

            // 获取 API Key
            $apiKey = env('DASHSCOPE.API_KEY', '');
            if (empty($apiKey)) {
                $this->error('API Key未配置，请在 .env 文件中配置 DASHSCOPE.API_KEY');
                return;
            }

            // 构建提示词
            $prompt = $this->buildPrompt($text);

            // 调用大模型
            $aiResult = $this->callQwenVL($apiKey, $base64, $mimeType, $prompt);

            if (isset($aiResult['error'])) {
                $this->error('大模型调用失败', ['detail' => $aiResult['error']]);
                return;
            }

            // 解析结果
            $content = $aiResult['content'] ?? '';
            $parsed = $this->parseResult($content);

            $resultData = [
                'raw' => $content,
                'parsed' => $parsed
            ];

            // 回调
            if (!empty($callbackUrl) && !empty($parsed['name']) && !in_array($parsed['name'], ['无', '正常'])) {
                $this->sendCallback($callbackUrl, [
                    'appeal_type' => $parsed['name'],
                    'content' => $parsed['content'] ?? '',
                    'image' => $base64
                ]);
                $resultData['callback_sent'] = true;
            }

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']';
        }

        if ($resultData !== null) {
            $this->success('分析成功', $resultData);
            return;
        }

        $this->error($errorMsg ?: '分析失败');
    }

    /**
     * 清理 base64 数据
     */
    private function cleanBase64(string $input): string
    {
        $input = trim($input);
        
        // 移除 data URI 前缀
        if (strpos($input, 'data:') === 0) {
            $parts = explode(',', $input, 2);
            $input = $parts[1] ?? '';
        }
        
        // 移除空白字符
        $input = preg_replace('/\s+/', '', $input);
        
        return $input;
    }

    /**
     * 检测图片类型
     */
    private function detectImageType(string $data): string
    {
        if (strlen($data) < 12) {
            return '';
        }
        
        $header = substr($data, 0, 12);
        
        // JPEG
        if (substr($header, 0, 2) === "\xFF\xD8") {
            return 'image/jpeg';
        }
        // PNG
        if (substr($header, 0, 4) === "\x89PNG") {
            return 'image/png';
        }
        // GIF
        if (substr($header, 0, 3) === 'GIF') {
            return 'image/gif';
        }
        // WebP
        if (substr($header, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        
        return '';
    }

    /**
     * 构建提示词
     */
    private function buildPrompt(string $userPrompt): string
    {
        if (empty($userPrompt)) {
            return '请分析这张图片，识别是否有人物或垃圾漂浮物。以JSON格式返回：{"name":"异常类型或无","content":"详细描述"}';
        }
        return $userPrompt . "\n\n请以JSON格式返回结果：{\"name\":\"类型\",\"content\":\"描述\"}";
    }

    /**
     * 调用通义千问VL
     */
    private function callQwenVL(string $apiKey, string $base64, string $mimeType, string $prompt): array
    {
        $url = 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions';

        $payload = [
            'model' => 'qwen-vl-plus',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64}"
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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => 'CURL错误: ' . $error];
        }

        if ($httpCode !== 200) {
            return ['error' => "HTTP {$httpCode}: " . substr($response, 0, 500)];
        }

        $data = json_decode($response, true);
        if (isset($data['error'])) {
            return ['error' => $data['error']['message'] ?? json_encode($data['error'])];
        }

        return [
            'content' => $data['choices'][0]['message']['content'] ?? ''
        ];
    }

    /**
     * 解析AI返回结果
     */
    private function parseResult(string $content): array
    {
        if (empty($content)) {
            return ['name' => '', 'content' => ''];
        }

        // 尝试直接解析
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['name'])) {
            return $data;
        }

        // 从代码块提取
        if (preg_match('/```(?:json)?\s*(\{.+?\})\s*```/s', $content, $m)) {
            $data = json_decode($m[1], true);
            if (is_array($data)) {
                return $data;
            }
        }

        // 提取任意JSON
        if (preg_match('/\{[^{}]*"name"[^{}]*\}/s', $content, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return ['name' => '', 'content' => $content];
    }

    /**
     * 发送回调
     */
    private function sendCallback(string $url, array $data): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
