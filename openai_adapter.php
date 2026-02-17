<?php
/**
 * OpenAI 兼容 API 适配器
 *
 * 将 Gemini 原生 API 请求转换为 OpenAI 兼容格式，支持中转站调用。
 * 支持文本生成、图片生成/编辑、思考模型等功能。
 *
 * 思考模式支持三种策略：
 * - auto:  自动降级 - 先尝试带 reasoning_effort，失败后去掉参数重试
 * - force: 强制启用 - 始终发送 reasoning_effort
 * - off:   关闭 - 不发送 reasoning_effort
 *
 * 用法:
 * $adapter = new GeminiOpenAIAdapter($config);
 * $response = $adapter->generateContent($modelName, $payload);
 */

require_once __DIR__ . '/i18n/I18n.php';

class OpenAIAdapterException extends Exception {
    private int $httpCode;

    public function __construct(string $message, int $httpCode = 500, ?Throwable $previous = null) {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int {
        return $this->httpCode;
    }
}

class GeminiOpenAIAdapter {
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private int $connectTimeout;
    private bool $enableThinking;

    // 思考模式精细配置
    private string $thinkingMode;       // 'auto' | 'force' | 'off'
    private string $thinkingLevel;      // 'low' | 'medium' | 'high'
    private int $fallbackCacheTtl;      // 自动降级缓存时间 (秒)

    // 降级缓存文件路径
    private string $fallbackCacheFile;

    public function __construct(array $config) {
        $openaiConfig = $config['openai_compatible'] ?? [];
        $this->baseUrl = rtrim($openaiConfig['base_url'] ?? '', '/');
        $this->apiKey = $openaiConfig['api_key'] ?? '';
        $this->timeout = (int)($openaiConfig['timeout'] ?? 300);
        $this->connectTimeout = (int)($openaiConfig['connect_timeout'] ?? 30);
        $this->enableThinking = (bool)($openaiConfig['enable_thinking'] ?? true);

        // 解析思考模式配置
        $thinkingModeConfig = $openaiConfig['thinking_mode'] ?? [];
        $this->thinkingMode = $this->validateThinkingMode($thinkingModeConfig['mode'] ?? 'auto');
        $this->thinkingLevel = $this->validateThinkingLevel($thinkingModeConfig['level'] ?? 'high');
        $this->fallbackCacheTtl = max(0, (int)($thinkingModeConfig['fallback_cache_ttl'] ?? 3600));

        // 降级缓存文件存放在 logs 目录
        $this->fallbackCacheFile = __DIR__ . '/logs/thinking_fallback_cache.json';
    }

    /**
     * 验证思考模式值
     */
    private function validateThinkingMode(string $mode): string {
        $allowed = ['auto', 'force', 'off'];
        return in_array($mode, $allowed, true) ? $mode : 'auto';
    }

    /**
     * 验证思考级别值
     */
    private function validateThinkingLevel(string $level): string {
        $allowed = ['low', 'medium', 'high'];
        $level = strtolower($level);
        return in_array($level, $allowed, true) ? $level : 'high';
    }

    /**
     * 检查适配器是否可用
     */
    public function isAvailable(): bool {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * 获取当前思考模式配置状态（供诊断接口使用）
     */
    public function getThinkingStatus(): array {
        return [
            'enable_thinking' => $this->enableThinking,
            'thinking_mode' => $this->thinkingMode,
            'thinking_level' => $this->thinkingLevel,
            'fallback_cache_ttl' => $this->fallbackCacheTtl,
            'is_fallback_active' => $this->isFallbackCached(),
        ];
    }

    /**
     * 生成内容（兼容 Gemini generateContent 接口）
     *
     * @param string $modelName 模型名称
     * @param array $payload Gemini 格式的请求体
     * @return array 转换后的响应（Gemini 格式）
     * @throws OpenAIAdapterException
     */
    public function generateContent(string $modelName, array $payload): array {
        $openaiPayload = $this->convertToOpenAIFormat($modelName, $payload);

        // 根据思考模式策略决定是否携带 reasoning_effort
        $shouldTryThinking = $this->shouldAttemptThinking($openaiPayload);

        if ($shouldTryThinking) {
            // 尝试带 reasoning_effort 的请求
            try {
                $response = $this->sendRequest($openaiPayload);
                return $this->convertToGeminiFormat($response);
            } catch (OpenAIAdapterException $e) {
                // 仅在 auto 模式下尝试降级
                if ($this->thinkingMode === 'auto' && $this->isThinkingNotSupportedError($e)) {
                    // 记录降级日志
                    error_log(sprintf(
                        '[OpenAIAdapter] reasoning_effort not supported for model "%s", falling back without it. Error: %s',
                        $modelName,
                        $e->getMessage()
                    ));

                    // 缓存降级状态
                    $this->cacheFallbackState($modelName);

                    // 移除 reasoning_effort 重试
                    unset($openaiPayload['reasoning_effort']);
                    $response = $this->sendRequest($openaiPayload);
                    return $this->convertToGeminiFormat($response);
                }

                // force 模式或非思考相关错误，直接抛出
                throw $e;
            }
        } else {
            // 直接发送不带 reasoning_effort 的请求
            unset($openaiPayload['reasoning_effort']);
            $response = $this->sendRequest($openaiPayload);
            return $this->convertToGeminiFormat($response);
        }
    }

    /**
     * 判断是否应该尝试带 reasoning_effort 发送请求
     */
    private function shouldAttemptThinking(array $payload): bool {
        // 如果 payload 中没有 reasoning_effort，无需尝试
        if (!isset($payload['reasoning_effort'])) {
            return false;
        }

        // off 模式: 不尝试
        if ($this->thinkingMode === 'off') {
            return false;
        }

        // force 模式: 始终尝试
        if ($this->thinkingMode === 'force') {
            return true;
        }

        // auto 模式: 检查缓存，如果已知不支持则跳过
        if ($this->thinkingMode === 'auto' && $this->isFallbackCached()) {
            return false;
        }

        return true;
    }

    /**
     * 判断异常是否表示中转站不支持 reasoning_effort
     *
     * 常见错误模式:
     * - "Thinking level is not supported for this model."
     * - "reasoning_effort is not supported"
     * - HTTP 400 + 含 "thinking" 或 "reasoning" 关键词
     */
    private function isThinkingNotSupportedError(OpenAIAdapterException $e): bool {
        $httpCode = $e->getHttpCode();
        $message = strtolower($e->getMessage());

        // 只有 400 错误才可能是参数不支持
        if ($httpCode !== 400) {
            return false;
        }

        // 关键词匹配
        $keywords = [
            'thinking level is not supported',
            'thinking is not supported',
            'reasoning_effort',
            'reasoning effort',
            'not supported for this model',
            'invalid parameter',
            'unknown parameter',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 缓存降级状态到文件
     */
    private function cacheFallbackState(string $modelName): void {
        if ($this->fallbackCacheTtl <= 0) {
            return;
        }

        $cacheData = [
            'model' => $modelName,
            'base_url' => $this->baseUrl,
            'timestamp' => time(),
            'expires_at' => time() + $this->fallbackCacheTtl,
            'reason' => 'reasoning_effort not supported',
        ];

        // 确保 logs 目录存在
        $logsDir = dirname($this->fallbackCacheFile);
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }

        @file_put_contents(
            $this->fallbackCacheFile,
            json_encode($cacheData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    /**
     * 检查降级缓存是否有效
     */
    private function isFallbackCached(): bool {
        if ($this->fallbackCacheTtl <= 0) {
            return false;
        }

        if (!file_exists($this->fallbackCacheFile)) {
            return false;
        }

        $content = @file_get_contents($this->fallbackCacheFile);
        if ($content === false) {
            return false;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return false;
        }

        // 检查是否为同一个 base_url（不同中转站可能有不同的支持情况）
        if (($data['base_url'] ?? '') !== $this->baseUrl) {
            return false;
        }

        // 检查是否过期
        $expiresAt = $data['expires_at'] ?? 0;
        if (time() >= $expiresAt) {
            // 过期了，删除缓存文件
            @unlink($this->fallbackCacheFile);
            return false;
        }

        return true;
    }

    /**
     * 清除降级缓存（可供管理接口调用）
     */
    public function clearFallbackCache(): bool {
        if (file_exists($this->fallbackCacheFile)) {
            return @unlink($this->fallbackCacheFile);
        }
        return true;
    }

    /**
     * 将 Gemini 请求格式转换为 OpenAI 格式
     */
    private function convertToOpenAIFormat(string $modelName, array $geminiPayload): array {
        $messages = [];

        // 处理系统指令
        if (isset($geminiPayload['system_instruction']['parts'])) {
            $systemText = '';
            foreach ($geminiPayload['system_instruction']['parts'] as $part) {
                if (isset($part['text'])) {
                    $systemText .= $part['text'];
                }
            }
            if ($systemText !== '') {
                $messages[] = ['role' => 'system', 'content' => $systemText];
            }
        }

        // 处理内容
        if (isset($geminiPayload['contents']) && is_array($geminiPayload['contents'])) {
            foreach ($geminiPayload['contents'] as $content) {
                $role = $content['role'] ?? 'user';
                if ($role === 'model') {
                    $role = 'assistant';
                }

                $parts = $content['parts'] ?? [];
                $contentData = $this->convertPartsToOpenAI($parts);

                if (!empty($contentData)) {
                    $messages[] = ['role' => $role, 'content' => $contentData];
                }
            }
        }

        $openaiPayload = [
            'model' => $modelName,
            'messages' => $messages
        ];

        // 处理生成配置
        $genConfig = $geminiPayload['generationConfig'] ?? [];
        if (isset($genConfig['temperature'])) {
            $openaiPayload['temperature'] = (float)$genConfig['temperature'];
        }
        if (isset($genConfig['topP'])) {
            $openaiPayload['top_p'] = (float)$genConfig['topP'];
        }
        if (isset($genConfig['maxOutputTokens'])) {
            $openaiPayload['max_tokens'] = (int)$genConfig['maxOutputTokens'];
        }

        // 处理图片生成配置
        $this->addImageConfig($openaiPayload, $genConfig);

        // 处理思考模式配置
        $this->addThinkingConfig($openaiPayload, $genConfig);

        return $openaiPayload;
    }

    /**
     * 将 parts 转换为 OpenAI content 格式
     */
    private function convertPartsToOpenAI(array $parts): mixed {
        if (count($parts) === 1 && isset($parts[0]['text'])) {
            return $parts[0]['text'];
        }

        $contentArray = [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $contentArray[] = ['type' => 'text', 'text' => $part['text']];
            } elseif (isset($part['inline_data'])) {
                $mimeType = $part['inline_data']['mime_type'] ?? 'image/png';
                $data = $part['inline_data']['data'] ?? '';
                $contentArray[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:$mimeType;base64,$data"]
                ];
            }
        }

        return count($contentArray) === 1 && $contentArray[0]['type'] === 'text'
            ? $contentArray[0]['text']
            : $contentArray;
    }

    /**
     * 添加图片生成配置
     * 将 Gemini 的 imageConfig 转换为 OpenAI 兼容格式的 image_config 对象
     */
    private function addImageConfig(array &$payload, array $genConfig): void {
        if (!isset($genConfig['imageConfig'])) {
            return;
        }
        $imgConfig = $genConfig['imageConfig'];
        $imageConfigPayload = [];

        if (isset($imgConfig['aspectRatio'])) {
            $imageConfigPayload['aspect_ratio'] = $imgConfig['aspectRatio'];
        }
        if (isset($imgConfig['imageSize'])) {
            $imageConfigPayload['image_size'] = $imgConfig['imageSize'];
        }

        if (!empty($imageConfigPayload)) {
            $payload['image_config'] = $imageConfigPayload;
        }
    }

    /**
     * 添加思考模式配置
     *
     * 根据思考模式配置 (thinking_mode) 决定是否添加 reasoning_effort:
     * - 配置了 thinking_mode 时: 使用 thinking_mode.level 作为 reasoning_effort 值
     * - 未配置 thinking_mode 时: 回退到旧逻辑 (从 thinkingConfig 映射)
     * - 思考模式为 off 时: 不添加 reasoning_effort
     *
     * 注意: reasoning_effort 的实际发送与否由 shouldAttemptThinking() 在 generateContent() 中最终决定
     */
    private function addThinkingConfig(array &$payload, array $genConfig): void {
        // 如果思考模式关闭，不添加 reasoning_effort
        if ($this->thinkingMode === 'off') {
            return;
        }

        // 如果未启用思考功能，不添加 reasoning_effort
        if (!$this->enableThinking) {
            return;
        }

        // 优先使用 thinking_mode 配置的级别
        if ($this->thinkingMode === 'auto' || $this->thinkingMode === 'force') {
            $payload['reasoning_effort'] = $this->thinkingLevel;
            return;
        }

        // 回退: 从 Gemini thinkingConfig 映射 (兼容旧逻辑)
        if (!isset($genConfig['thinkingConfig'])) {
            return;
        }

        $thinkingConfig = $genConfig['thinkingConfig'];

        // 处理 thinkingLevel -> reasoning_effort
        if (isset($thinkingConfig['thinkingLevel']) && is_string($thinkingConfig['thinkingLevel'])) {
            $level = strtolower($thinkingConfig['thinkingLevel']);
            // 验证值是否合法
            $allowedLevels = ['minimal', 'low', 'medium', 'high'];
            if (in_array($level, $allowedLevels, true)) {
                $payload['reasoning_effort'] = $level;
            }
        }

        // 如果启用了思考但没有指定级别，使用默认值
        if (!isset($payload['reasoning_effort'])) {
            if (isset($thinkingConfig['includeThoughts']) && $thinkingConfig['includeThoughts'] === true) {
                // 默认使用 high 级别
                $payload['reasoning_effort'] = 'high';
            }
        }
    }

    /**
     * 发送 HTTP 请求
     */
    private function sendRequest(array $payload): array {
        $url = $this->baseUrl . '/v1/chat/completions';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new OpenAIAdapterException(__('adapter.openai.error.request_failed', ['error' => $error]), 500);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OpenAIAdapterException(__('adapter.openai.error.parse_failed', ['error' => json_last_error_msg()]), 500);
        }

        if ($httpCode !== 200) {
            $errMsg = $data['error']['message'] ?? __('adapter.openai.error.api_error');
            throw new OpenAIAdapterException(__('adapter.openai.error.request_failed_status', ['code' => $httpCode, 'message' => $errMsg]), $httpCode);
        }

        return $data;
    }

    /**
     * 将 OpenAI 响应转换为 Gemini 格式
     */
    private function convertToGeminiFormat(array $openaiResponse): array {
        $candidates = [];

        if (isset($openaiResponse['choices']) && is_array($openaiResponse['choices'])) {
            foreach ($openaiResponse['choices'] as $choice) {
                $parts = [];
                $message = $choice['message'] ?? [];

                // 处理文本内容
                if (isset($message['content']) && $message['content'] !== null) {
                    $content = $message['content'];
                    if (is_string($content) && $content !== '') {
                        // 智能解析内容：检查是否包含 Markdown 格式的图片、纯 base64 图片或普通文本
                        $this->parseContentString($content, $parts);
                    } elseif (is_array($content)) {
                        // 处理数组格式的 content
                        foreach ($content as $item) {
                            $this->processContentItem($item, $parts);
                        }
                    }
                }

                // 处理 images 字段（中转站特有）
                if (isset($message['images']) && is_array($message['images'])) {
                    foreach ($message['images'] as $img) {
                        if (isset($img['image_url']['url'])) {
                            $imgData = $this->parseDataUri($img['image_url']['url']);
                            $parts[] = [
                                'inlineData' => [
                                    'mimeType' => $imgData['mimeType'],
                                    'data' => $imgData['data']
                                ]
                            ];
                        }
                    }
                }

                $candidate = [
                    'content' => [
                        'role' => 'model',
                        'parts' => $parts
                    ],
                    'finishReason' => $this->mapFinishReason($choice['finish_reason'] ?? 'stop')
                ];

                // 提取思考内容
                if ($this->enableThinking && isset($message['reasoning_content'])) {
                    $candidate['thoughts'] = [$message['reasoning_content']];
                }

                $candidates[] = $candidate;
            }
        }

        return ['candidates' => $candidates];
    }

    /**
     * 智能解析字符串内容
     * 支持以下格式：
     * 1. 纯 base64 图片数据 (data:image/xxx;base64,...)
     * 2. Markdown 格式图片 (![alt](data:image/xxx;base64,...))
     * 3. 混合内容（文本 + Markdown 图片）
     * 4. 普通文本
     */
    private function parseContentString(string $content, array &$parts): void {
        // 优先检查是否为纯 base64 图片（以 data:image 开头）
        if ($this->isBase64Image($content)) {
            $imgData = $this->parseDataUri($content);
            $parts[] = [
                'inlineData' => [
                    'mimeType' => $imgData['mimeType'],
                    'data' => $imgData['data']
                ]
            ];
            return;
        }

        // 检查是否包含 Markdown 格式的 base64 图片
        // 匹配 ![任意alt文本](data:image/格式;base64,数据)
        $markdownImagePattern = '/!\[[^\]]*\]\((data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+)\)/i';

        if (preg_match_all($markdownImagePattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $lastOffset = 0;

            foreach ($matches as $match) {
                $fullMatch = $match[0][0];     // 完整的 Markdown 图片标记
                $offset = $match[0][1];        // 匹配位置
                $dataUri = $match[1][0];       // data URI 部分

                // 提取图片前的文本
                if ($offset > $lastOffset) {
                    $textBefore = substr($content, $lastOffset, $offset - $lastOffset);
                    $textBefore = trim($textBefore);
                    if ($textBefore !== '') {
                        $parts[] = ['text' => $textBefore];
                    }
                }

                // 解析并添加图片
                $imgData = $this->parseDataUri($dataUri);
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $imgData['mimeType'],
                        'data' => $imgData['data']
                    ]
                ];

                $lastOffset = $offset + strlen($fullMatch);
            }

            // 提取最后一个图片后的文本
            if ($lastOffset < strlen($content)) {
                $textAfter = substr($content, $lastOffset);
                $textAfter = trim($textAfter);
                if ($textAfter !== '') {
                    $parts[] = ['text' => $textAfter];
                }
            }

            return;
        }

        // 普通文本，直接添加
        $parts[] = ['text' => $content];
    }

    /**
     * 处理单个 content 项
     */
    private function processContentItem(array $item, array &$parts): void {
        if (isset($item['type'])) {
            if ($item['type'] === 'text' && isset($item['text'])) {
                $parts[] = ['text' => $item['text']];
            } elseif ($item['type'] === 'image_url' && isset($item['image_url']['url'])) {
                $imgData = $this->parseDataUri($item['image_url']['url']);
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $imgData['mimeType'],
                        'data' => $imgData['data']
                    ]
                ];
            }
        }
    }

    /**
     * 解析 data URI 格式
     */
    private function parseDataUri(string $uri): array {
        if (preg_match('/^data:image\/([a-z]+);base64,(.+)$/is', $uri, $matches)) {
            return [
                'mimeType' => 'image/' . strtolower($matches[1]),
                'data' => $matches[2]
            ];
        }
        // 纯 base64 数据
        return [
            'mimeType' => 'image/png',
            'data' => $uri
        ];
    }

    /**
     * 检查内容是否为 base64 图片
     */
    private function isBase64Image(string $content): bool {
        return preg_match('/^data:image\/[a-z]+;base64,/i', $content) === 1
            || (strlen($content) > 1000 && preg_match('/^[A-Za-z0-9+\/=]+$/', $content) === 1);
    }

    /**
     * 映射结束原因
     */
    private function mapFinishReason(string $reason): string {
        $map = [
            'stop' => 'STOP',
            'length' => 'MAX_TOKENS',
            'content_filter' => 'SAFETY',
            'tool_calls' => 'STOP'
        ];
        return $map[$reason] ?? 'STOP';
    }
}
