<?php
/**
 * OpenAI 兼容 API 适配器
 *
 * 将 Gemini 原生 API 请求转换为 OpenAI 兼容格式，支持中转站调用。
 * 支持文本生成、图片生成/编辑、思考模型等功能。
 *
 * 用法:
 * $adapter = new GeminiOpenAIAdapter($config);
 * $response = $adapter->generateContent($modelName, $payload);
 */

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

    public function __construct(array $config) {
        $openaiConfig = $config['openai_compatible'] ?? [];
        $this->baseUrl = rtrim($openaiConfig['base_url'] ?? '', '/');
        $this->apiKey = $openaiConfig['api_key'] ?? '';
        $this->timeout = (int)($openaiConfig['timeout'] ?? 300);
        $this->connectTimeout = (int)($openaiConfig['connect_timeout'] ?? 30);
        $this->enableThinking = (bool)($openaiConfig['enable_thinking'] ?? true);
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
     * @return array 转换后的响应（Gemini 格式）
     * @throws OpenAIAdapterException
     */
    public function generateContent(string $modelName, array $payload): array {
        $openaiPayload = $this->convertToOpenAIFormat($modelName, $payload);
        $response = $this->sendRequest($openaiPayload);
        return $this->convertToGeminiFormat($response);
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
     */
    private function addImageConfig(array &$payload, array $genConfig): void {
        if (!isset($genConfig['imageConfig'])) {
            return;
        }
        $imgConfig = $genConfig['imageConfig'];
        if (isset($imgConfig['aspectRatio'])) {
            $payload['aspect_ratio'] = $imgConfig['aspectRatio'];
        }
        if (isset($imgConfig['imageSize'])) {
            $payload['image_size'] = $imgConfig['imageSize'];
        }
    }

    /**
     * 添加思考模式配置
     * 将 Gemini 的 thinkingConfig 转换为 OpenAI 兼容的 reasoning_effort
     *
     * 支持的级别:
     * - Gemini 3 Pro 模型: "low", "high"
     * - Gemini 3 Flash 模型: "minimal", "low", "medium", "high"
     */
    private function addThinkingConfig(array &$payload, array $genConfig): void {
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
        if (!isset($payload['reasoning_effort']) && $this->enableThinking) {
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
            throw new OpenAIAdapterException("请求中转站失败: $error", 500);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OpenAIAdapterException('中转站返回解析失败: ' . json_last_error_msg(), 500);
        }

        if ($httpCode !== 200) {
            $errMsg = $data['error']['message'] ?? '中转站接口返回异常';
            throw new OpenAIAdapterException("中转站请求失败 ($httpCode): $errMsg", $httpCode);
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
