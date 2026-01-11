<?php
/**
 * 语音转文字工具类
 *
 * 提供语音识别功能的统一接口，支持多种服务提供商和回退机制。
 *
 * 依赖说明:
 * - 本文件依赖 api.php 中定义的 callGeminiApiSafe()、extractTextFromCandidates() 和 GeminiApiException
 * - 必须在 api.php 中通过 require_once 引入，不可单独使用
 *
 * 用法示例:
 *
 * $sttService = SpeechToText::create($config);
 * $result = $sttService->transcribe($audioFilePath, $mimeType);
 * if ($result['success']) {
 *     echo $result['text'];
 * }
 */

/**
 * 语音转文字服务提供商接口
 */
interface SpeechToTextProvider {
    /**
     * 执行语音转文字
     * @param string $audioData base64 编码的音频数据
     * @param string $mimeType 音频 MIME 类型
     * @return array ['success' => bool, 'text' => string, 'error' => string|null]
     */
    public function transcribe(string $audioData, string $mimeType): array;
    
    /**
     * 获取提供商名称
     * @return string
     */
    public function getName(): string;
    
    /**
     * 检查提供商是否可用
     * @return bool
     */
    public function isAvailable(): bool;
}

/**
 * Gemini 语音转文字实现
 */
class GeminiSpeechToText implements SpeechToTextProvider {
    private string $model;
    private string $prompt;
    private float $temperature;
    private int $maxOutputTokens;
    private int $timeout;
    private int $connectTimeout;
    
    public function __construct(array $config) {
        // 引入国际化支持
        require_once __DIR__ . '/i18n/I18n.php';
        
        $sttConfig = $config['speech_to_text'] ?? [];
        $this->model = $sttConfig['model'] ?? 'gemini-2.5-flash';
        // 使用国际化 System Prompt，优先使用配置中的 prompt（如果存在）
        $this->prompt = $sttConfig['prompt'] ?? __('ai.speech_to_text.system_prompt');
        $this->temperature = $sttConfig['temperature'] ?? 0.1;
        $this->maxOutputTokens = $sttConfig['max_output_tokens'] ?? 2048;
        $this->timeout = $sttConfig['timeout'] ?? 60;
        $this->connectTimeout = $sttConfig['connect_timeout'] ?? 15;
    }
    
    public function getName(): string {
        return 'gemini';
    }
    
    public function isAvailable(): bool {
        return function_exists('curl_init');
    }
    
    public function transcribe(string $audioData, string $mimeType): array {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'text' => '',
                'error' => __('error.cause_extension')
            ];
        }
        
        $requestData = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $this->prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $audioData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $this->temperature,
                'maxOutputTokens' => $this->maxOutputTokens
            ]
        ];
        
        try {
            // 使用安全版本的 API 调用，支持异常捕获和回退机制
            $responseData = callGeminiApiSafe($this->model, $requestData, $this->timeout, $this->connectTimeout);
            $transcribedText = extractTextFromCandidates($responseData);

            return [
                'success' => true,
                'text' => $transcribedText,
                'error' => null,
                'provider' => $this->getName()
            ];
        } catch (GeminiApiException $e) {
            // 捕获 Gemini API 特定异常
            return [
                'success' => false,
                'text' => '',
                'error' => $e->getMessage(),
                'provider' => $this->getName(),
                'http_code' => $e->getHttpCode()
            ];
        } catch (\Throwable $e) {
            // 捕获其他未知异常
            return [
                'success' => false,
                'text' => '',
                'error' => $e->getMessage(),
                'provider' => $this->getName()
            ];
        }
    }
}

/**
 * 语音转文字服务工厂和管理器
 */
class SpeechToText {
    private array $providers = [];
    private array $config;
    private bool $enableFallback;
    
    public function __construct(array $config) {
        $this->config = $config;
        $sttConfig = $config['speech_to_text'] ?? [];
        $this->enableFallback = $sttConfig['enable_fallback'] ?? false;
        $this->initProviders();
    }
    
    /**
     * 创建 SpeechToText 实例
     */
    public static function create(array $config): self {
        return new self($config);
    }
    
    /**
     * 初始化服务提供商
     */
    private function initProviders(): void {
        $sttConfig = $this->config['speech_to_text'] ?? [];
        $providerOrder = $sttConfig['providers'] ?? ['gemini'];
        
        foreach ($providerOrder as $providerName) {
            $provider = $this->createProvider($providerName);
            if ($provider !== null) {
                $this->providers[] = $provider;
            }
        }
        
        // 确保至少有一个提供商
        if (empty($this->providers)) {
            $this->providers[] = new GeminiSpeechToText($this->config);
        }
    }
    
    /**
     * 根据名称创建提供商实例
     */
    private function createProvider(string $name): ?SpeechToTextProvider {
        switch (strtolower($name)) {
            case 'gemini':
                return new GeminiSpeechToText($this->config);
            // 未来可以在这里添加更多提供商
            // case 'whisper':
            //     return new WhisperSpeechToText($this->config);
            // case 'azure':
            //     return new AzureSpeechToText($this->config);
            default:
                return null;
        }
    }

    /**
     * 执行语音转文字（带回退机制）
     * @param string $audioData base64 编码的音频数据
     * @param string $mimeType 音频 MIME 类型
     * @return array ['success' => bool, 'text' => string, 'error' => string|null, 'provider' => string]
     */
    public function transcribe(string $audioData, string $mimeType): array {
        $lastError = null;

        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            $result = $provider->transcribe($audioData, $mimeType);

            if ($result['success']) {
                return $result;
            }

            $lastError = $result['error'];

            // 如果未启用回退机制，则直接返回错误
            if (!$this->enableFallback) {
                return $result;
            }
        }

        return [
            'success' => false,
            'text' => '',
            'error' => $lastError ?? __('api.no_speech'),
            'provider' => null
        ];
    }

    /**
     * 获取所有已配置的提供商名称
     * @return array
     */
    public function getProviderNames(): array {
        return array_map(fn($p) => $p->getName(), $this->providers);
    }

    /**
     * 验证音频文件
     * @param array $file $_FILES 数组中的单个文件
     * @return array ['valid' => bool, 'error' => string|null, 'mime_type' => string, 'data' => string]
     */
    public function validateAndReadAudio(array $file): array {
        $sttConfig = $this->config['speech_to_text'] ?? [];
        $maxSize = $sttConfig['max_audio_size'] ?? 10 * 1024 * 1024;
        $allowedMimeTypes = $sttConfig['allowed_mime_types'] ?? [
            'audio/webm', 'audio/mp4', 'audio/ogg',
            'audio/wav', 'audio/mpeg', 'audio/mp3'
        ];

        // 检查上传错误
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => __('validation.required', ['field' => 'audio'])];
        }

        // 验证文件大小
        if ($file['size'] > $maxSize) {
            $maxMB = $maxSize / 1024 / 1024;
            return ['valid' => false, 'error' => __('validation.max_size', ['max' => $maxMB])];
        }

        // 检测 MIME 类型
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        $baseMime = explode(';', $detectedMime)[0];
        $uploadMime = explode(';', $file['type'])[0];

        // 验证 MIME 类型
        $isValidMime = false;
        foreach ($allowedMimeTypes as $allowed) {
            if ($baseMime === $allowed || $uploadMime === $allowed) {
                $isValidMime = true;
                break;
            }
        }

        if (!$isValidMime) {
            return ['valid' => false, 'error' => __('error.invalid_format', ['format' => $uploadMime])];
        }

        // 读取音频数据
        $audioData = file_get_contents($file['tmp_name']);
        if ($audioData === false) {
            return ['valid' => false, 'error' => __('error.read_failed')];
        }

        return [
            'valid' => true,
            'error' => null,
            'mime_type' => $uploadMime ?: $baseMime,
            'data' => base64_encode($audioData)
        ];
    }
}

