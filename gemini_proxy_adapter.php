<?php
/**
 * Gemini 代理适配器
 *
 * 处理 Gemini 原生格式的代理 API，支持 SSE 流式响应。
 * 端点格式：{base_url}/v1beta/models/{model}:streamGenerateContent?alt=sse
 *
 * 该适配器与原生 Gemini API 格式完全兼容，主要区别在于：
 * 1. 通过代理站点访问，解决国内访问问题
 * 2. 支持 SSE 流式响应解析
 * 3. API Key 通过 x-goog-api-key header 传递
 */

class GeminiProxyAdapterException extends Exception {
    private int $httpCode;

    public function __construct(string $message, int $httpCode = 500, ?Throwable $previous = null) {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int {
        return $this->httpCode;
    }
}

class GeminiProxyAdapter {
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private int $connectTimeout;
    private bool $useStreaming;
    private int $thinkingBudget;
    private bool $debug;

    public function __construct(array $config) {
        $proxyConfig = $config['gemini_proxy'] ?? [];
        $this->baseUrl = rtrim($proxyConfig['base_url'] ?? '', '/');
        $this->apiKey = $proxyConfig['api_key'] ?? '';
        $this->timeout = (int)($proxyConfig['timeout'] ?? 300);
        $this->connectTimeout = (int)($proxyConfig['connect_timeout'] ?? 30);
        $this->useStreaming = (bool)($proxyConfig['use_streaming'] ?? true);
        $this->thinkingBudget = (int)($proxyConfig['thinking_budget'] ?? 26240);
        $this->debug = (bool)($config['debug'] ?? false);
    }

    /**
     * 检查适配器是否可用
     */
    public function isAvailable(): bool {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * 生成内容（兼容 Gemini generateContent 接口）
     *
     * @param string $modelName 模型名称
     * @param array $payload Gemini 格式的请求体
     * @return array Gemini 格式的响应
     * @throws GeminiProxyAdapterException
     */
    public function generateContent(string $modelName, array $payload): array {
        // 确保 generationConfig 存在
        if (!isset($payload['generationConfig'])) {
            $payload['generationConfig'] = [];
        }

        // 添加思考配置（如果尚未设置）
        if (!isset($payload['generationConfig']['thinkingConfig'])) {
            $payload['generationConfig']['thinkingConfig'] = [];
        }
        
        // 设置思考预算和包含思考内容
        if ($this->thinkingBudget > 0) {
            $payload['generationConfig']['thinkingConfig']['thinkingBudget'] = $this->thinkingBudget;
        }
        $payload['generationConfig']['thinkingConfig']['includeThoughts'] = true;

        if ($this->useStreaming) {
            return $this->streamGenerateContent($modelName, $payload);
        }
        return $this->nonStreamGenerateContent($modelName, $payload);
    }

    /**
     * 流式生成内容（SSE 格式）
     *
     * SSE 端点：{base_url}/v1beta/models/{model}:streamGenerateContent?alt=sse
     */
    private function streamGenerateContent(string $modelName, array $payload): array {
        $url = $this->baseUrl . "/v1beta/models/{$modelName}:streamGenerateContent?alt=sse";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new GeminiProxyAdapterException("请求代理站失败: $error", 500);
        }

        if ($httpCode !== 200) {
            $this->handleErrorResponse($response, $httpCode);
        }

        return $this->parseSSEResponse($response);
    }

    /**
     * 非流式生成内容
     */
    private function nonStreamGenerateContent(string $modelName, array $payload): array {
        $url = $this->baseUrl . "/v1beta/models/{$modelName}:generateContent";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new GeminiProxyAdapterException("请求代理站失败: $error", 500);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GeminiProxyAdapterException("代理站返回解析失败: " . json_last_error_msg(), 500);
        }

        if ($httpCode !== 200) {
            $errMsg = $data['error']['message'] ?? '代理站接口返回异常';
            throw new GeminiProxyAdapterException("代理站请求失败 ($httpCode): $errMsg", $httpCode);
        }

        return $data;
    }

    /**
     * 处理错误响应
     */
    private function handleErrorResponse(string $response, int $httpCode): void {
        // 尝试从 SSE 格式中解析错误信息
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                $data = json_decode($json, true);
                if (isset($data['error']['message'])) {
                    throw new GeminiProxyAdapterException(
                        "代理站请求失败 ($httpCode): " . $data['error']['message'],
                        $httpCode
                    );
                }
            }
        }

        // 尝试直接解析 JSON 错误
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['error']['message'])) {
            throw new GeminiProxyAdapterException(
                "代理站请求失败 ($httpCode): " . $data['error']['message'],
                $httpCode
            );
        }

        throw new GeminiProxyAdapterException("代理站请求失败 ($httpCode)", $httpCode);
    }

    /**
     * 解析 SSE 响应
     *
     * SSE 格式示例：
     * data: {"candidates": [{"content": {"parts": [...]}}], ...}
     *
     * data: {"candidates": [{"content": {"parts": [...]}}], ...}
     *
     * data: [DONE]
     *
     * 将多个 SSE 事件的内容合并为单个 Gemini 格式响应
     */
    private function parseSSEResponse(string $response): array {
        $lines = explode("\n", $response);

        // 用于收集所有内容
        $allParts = [];
        $allThoughts = [];
        $finishReason = null;
        $groundingMetadata = null;
        $usageMetadata = null;
        $modelVersion = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // 跳过空行和非 data 行
            if ($line === '' || !str_starts_with($line, 'data: ')) {
                continue;
            }

            $json = substr($line, 6); // 移除 "data: " 前缀

            // 检查是否为结束标记
            if ($json === '[DONE]') {
                break;
            }

            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // 记录解析失败但继续处理
                if ($this->debug) {
                    error_log("SSE JSON 解析失败: " . json_last_error_msg() . " - 内容: " . substr($json, 0, 200));
                }
                continue;
            }

            // 提取元数据
            if (isset($data['usageMetadata'])) {
                $usageMetadata = $data['usageMetadata'];
            }
            if (isset($data['modelVersion'])) {
                $modelVersion = $data['modelVersion'];
            }

            // 合并 candidates 中的 parts
            if (isset($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
                foreach ($data['candidates'][0]['content']['parts'] as $part) {
                    // 检查是否为思考内容（Gemini 原生格式：thought 为布尔值 true）
                    if (isset($part['thought']) && $part['thought'] === true) {
                        if (isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                            $allThoughts[] = $part['text'];
                        }
                    } else {
                        // 处理普通内容（文本或图片）
                        $this->mergePart($allParts, $part);
                    }
                }
            }

            // 提取 finishReason
            if (isset($data['candidates'][0]['finishReason'])) {
                $finishReason = $data['candidates'][0]['finishReason'];
            }

            // 提取 groundingMetadata
            if (isset($data['candidates'][0]['groundingMetadata'])) {
                $groundingMetadata = $data['candidates'][0]['groundingMetadata'];
            }
        }

        // 构建合并后的 Gemini 格式响应
        $result = [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => []
                    ]
                ]
            ]
        ];

        // 将思考内容作为带 thought=true 的 parts 添加到开头
        foreach ($allThoughts as $thought) {
            $result['candidates'][0]['content']['parts'][] = [
                'thought' => true,
                'text' => $thought
            ];
        }

        // 添加普通内容 parts
        foreach ($allParts as $part) {
            $result['candidates'][0]['content']['parts'][] = $part;
        }

        // 添加其他元数据
        if ($finishReason !== null) {
            $result['candidates'][0]['finishReason'] = $finishReason;
        }

        if ($groundingMetadata !== null) {
            $result['candidates'][0]['groundingMetadata'] = $groundingMetadata;
        }

        if ($usageMetadata !== null) {
            $result['usageMetadata'] = $usageMetadata;
        }

        if ($modelVersion !== null) {
            $result['modelVersion'] = $modelVersion;
        }

        return $result;
    }

    /**
     * 合并 part 到 parts 数组
     *
     * 对于文本类型的 part，如果最后一个 part 也是文本，则合并文本内容
     * 对于图片类型的 part，直接添加
     */
    private function mergePart(array &$parts, array $part): void {
        // 处理文本 part
        if (isset($part['text']) && is_string($part['text'])) {
            $text = $part['text'];

            // 如果最后一个 part 也是文本，合并
            if (!empty($parts) && isset($parts[count($parts) - 1]['text'])) {
                $parts[count($parts) - 1]['text'] .= $text;
            } else {
                $parts[] = ['text' => $text];
            }
            return;
        }

        // 处理 inlineData（图片）part
        if (isset($part['inlineData'])) {
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $part['inlineData']['mimeType'] ?? $part['inlineData']['mime_type'] ?? 'image/png',
                    'data' => $part['inlineData']['data'] ?? ''
                ]
            ];
            return;
        }

        // 其他类型直接添加
        if (!empty($part)) {
            $parts[] = $part;
        }
    }
}