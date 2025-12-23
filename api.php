<?php
// api.php - 后端逻辑处理

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 增加执行时间和内存限制，防止超时和内存不足
set_time_limit(300); // 设置为 300 秒 (5分钟)
ini_set('memory_limit', '512M'); // 增加内存限制到 512MB
ini_set('max_execution_time', '300'); // 确保最大执行时间为 300 秒
ini_set('max_input_time', '300'); // 增加输入时间限制

require_once __DIR__ . '/security_utils.php';
require_once __DIR__ . '/prompt_optimizer.php';
require_once __DIR__ . '/speech_to_text.php';

// 引入配置
$config = require 'config.php';

// 核心配置
$apiKey = $config['api_key'];

// 错误处理函数
function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/**
 * Gemini API 异常类
 * 用于在工具类中捕获和处理 API 错误，支持回退机制
 */
class GeminiApiException extends Exception {
    private int $httpCode;

    public function __construct(string $message, int $httpCode = 500, ?Throwable $previous = null) {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int {
        return $this->httpCode;
    }
}

/**
 * 安全版本的 Gemini API 调用（抛出异常而非直接退出）
 * 供工具类使用，支持错误捕获和回退机制
 *
 * @param string $modelName 模型名称
 * @param array $payload 请求体
 * @param int $timeout 超时时间
 * @param int $connectTimeout 连接超时
 * @return array 解析后的响应数组
 * @throws GeminiApiException 当请求失败时抛出
 */
function callGeminiApiSafe($modelName, array $payload, $timeout = 120, $connectTimeout = 20): array {
    global $apiKey;

    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    // 开发环境禁用 SSL 验证（生产环境请开启）
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new GeminiApiException("请求 Gemini 失败: " . $curlError, 500);
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new GeminiApiException('Gemini 返回解析失败: ' . json_last_error_msg(), 500);
    }

    if ($httpCode !== 200) {
        $errorMessage = is_array($responseData) && isset($responseData['error']['message'])
            ? $responseData['error']['message']
            : 'Gemini 接口返回异常';
        throw new GeminiApiException("Gemini 请求失败 ({$httpCode}): " . $errorMessage, $httpCode);
    }

    return $responseData;
}

/**
 * 统一封装 Gemini REST 请求（向后兼容版本）
 * 错误时直接退出脚本，适用于 api.php 中的直接调用
 *
 * @param string $modelName 模型名称
 * @param array $payload 请求体
 * @param int $timeout 超时时间
 * @param int $connectTimeout 连接超时
 * @return array 解析后的响应数组
 */
function callGeminiApi($modelName, array $payload, $timeout = 120, $connectTimeout = 20) {
    try {
        return callGeminiApiSafe($modelName, $payload, $timeout, $connectTimeout);
    } catch (GeminiApiException $e) {
        sendError($e->getMessage(), $e->getHttpCode());
    }
}

/**
 * 提取文本结果
 */
function extractTextFromCandidates(array $responseData) {
    $result = '';
    if (isset($responseData['candidates'][0]['content']['parts'])) {
        foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $result .= $part['text'];
            }
        }
    }
    return trim($result);
}

/**
 * 根据配置生成思考模式配置
 */
function buildThinkingConfig(array $config, string $modelName): array {
    $thinkingConfig = [];
    $rawConfig = $config['thinking_config'] ?? [];

    if (!is_array($rawConfig) || empty($rawConfig)) {
        return $thinkingConfig;
    }

    if (array_key_exists('include_thoughts', $rawConfig)) {
        $thinkingConfig['includeThoughts'] = (bool) $rawConfig['include_thoughts'];
    }

    $supportsThinkingLevel = stripos($modelName, 'image') === false;
    if ($supportsThinkingLevel && !empty($rawConfig['thinking_level']) && is_string($rawConfig['thinking_level'])) {
        $thinkingConfig['thinkingLevel'] = $rawConfig['thinking_level'];
    }

    return $thinkingConfig;
}

/**
 * 提取思考内容（兼容不同返回结构）
 */
function extractThoughtsFromResponse(array $responseData): array {
    $thoughts = [];

    if (!isset($responseData['candidates'][0]) || !is_array($responseData['candidates'][0])) {
        return $thoughts;
    }

    $candidate = $responseData['candidates'][0];

    $appendThoughts = function ($value) use (&$thoughts) {
        if (is_string($value)) {
            $thoughts[] = $value;
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    $thoughts[] = $item;
                } elseif (is_array($item)) {
                    if (isset($item['text']) && is_string($item['text'])) {
                        $thoughts[] = $item['text'];
                    } elseif (isset($item['thought']) && is_string($item['thought'])) {
                        $thoughts[] = $item['thought'];
                    }
                }
            }
        }
    };

    if (isset($candidate['thoughts'])) {
        $appendThoughts($candidate['thoughts']);
    }

    if (isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
        foreach ($candidate['content']['parts'] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['thought'])) {
                $appendThoughts($part['thought']);
            }
            if (isset($part['thoughts'])) {
                $appendThoughts($part['thoughts']);
            }
        }
    }

    $cleaned = [];
    foreach ($thoughts as $thought) {
        if (!is_string($thought)) {
            continue;
        }
        $normalized = trim($thought);
        if ($normalized !== '') {
            $cleaned[] = SecurityUtils::sanitizeHtml($normalized);
        }
    }

    return $cleaned;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('只支持 POST 请求', 405);
}

try {
    $action = strtolower(SecurityUtils::sanitizeTextInput($_POST['action'] ?? '', 32));
} catch (\Throwable $e) {
    sendError('无效的操作类型', 400);
}

if (!in_array($action, ['generate', 'edit', 'optimize_prompt', 'transcribe'], true)) {
    sendError('未知的操作类型', 400);
}

// Gemini API Key
$apiKey = $config['api_key'];

// 语音转文字分支
if ($action === 'transcribe') {
    // 使用 SpeechToText 工具类处理语音转文字
    $sttService = SpeechToText::create($config);

    // 验证并读取音频文件
    $audioValidation = $sttService->validateAndReadAudio($_FILES['audio'] ?? []);
    if (!$audioValidation['valid']) {
        sendError($audioValidation['error'], 400);
    }

    // 执行语音转文字
    $result = $sttService->transcribe($audioValidation['data'], $audioValidation['mime_type']);

    if (!$result['success']) {
        sendError($result['error'] ?? '语音转文字失败', 500);
    }

    echo json_encode([
        'success' => true,
        'text' => $result['text'],
        'provider' => $result['provider'] ?? null
    ]);
    exit;
}

// 对于其他操作，需要 prompt
$prompt = SecurityUtils::sanitizeTextInput($_POST['prompt'] ?? '', 4000);
if ($prompt === '') {
    sendError('提示词不能为空', 400);
}

// 提示词优化分支（直接返回文本）
if ($action === 'optimize_prompt') {
    $optModel = SecurityUtils::sanitizeTextInput($config['prompt_optimize_model'] ?? 'gemini-2.5-flash', 64);
    $mode = SecurityUtils::validateAllowedValue(
        SecurityUtils::sanitizeTextInput($_POST['mode'] ?? 'basic', 16),
        ['basic', 'detail'],
        'basic'
    );
    $cleanOptimized = PromptOptimizer::optimizePrompt($prompt, $mode, $config);

    echo json_encode([
        'success' => true,
        'optimized_prompt' => $cleanOptimized
    ]);
    exit;
}

// 准备生成/编辑 API 请求数据
$modelName = $config['model_name'];
$isFlashImage = stripos($modelName, 'flash-image') !== false;
// 构建请求体
$requestData = [
    'contents' => [],
    'generationConfig' => $config['generation_config']
];

$thinkingConfig = buildThinkingConfig($config, $modelName);
if (!empty($thinkingConfig)) {
    $requestData['generationConfig']['thinkingConfig'] = $thinkingConfig;
}

if ($isFlashImage && isset($requestData['generationConfig']['imageConfig'])) {
    unset($requestData['generationConfig']['imageConfig']);
}

// 处理宽高比和分辨率
if (!$isFlashImage) {
    $aspectRatio = SecurityUtils::validateAllowedValue(
        SecurityUtils::sanitizeTextInput($_POST['aspect_ratio'] ?? '', 10),
        ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
        ''
    );
    $allowedResolutions = $config['image_model_supported_sizes'] ?? ['1K', '2K', '4K'];
    if (!is_array($allowedResolutions) || $allowedResolutions === []) {
        $allowedResolutions = ['1K', '2K', '4K'];
    }
    $resolution = SecurityUtils::validateAllowedValue(
        SecurityUtils::sanitizeTextInput($_POST['resolution'] ?? '', 5),
        $allowedResolutions,
        ''
    );

    if (!isset($requestData['generationConfig']['imageConfig']) || !is_array($requestData['generationConfig']['imageConfig'])) {
        $requestData['generationConfig']['imageConfig'] = [];
    }
    if ($aspectRatio !== '') {
        $requestData['generationConfig']['imageConfig']['aspectRatio'] = $aspectRatio;
    }
    if ($resolution !== '') {
        $requestData['generationConfig']['imageConfig']['imageSize'] = $resolution;
    }
}

// 处理 Google 搜索工具 (Grounding)
if (isset($_POST['use_search']) && $_POST['use_search'] === 'on') {
    $requestData['tools'] = [
        ['google_search' => new stdClass()]
    ];
}

// 根据操作类型构建 contents
if ($action === 'generate') {
    // 文生图
    $requestData['contents'][] = [
        'parts' => [
            ['text' => $prompt]
        ]
    ];
} elseif ($action === 'edit') {
    // 图生图/编辑
    if (!isset($_FILES['image'])) {
        sendError('请上传至少一张图片', 400);
    }

    $parts = [];
    // 添加提示词
    $parts[] = ['text' => $prompt];

    // 规范化 $_FILES 数组
    $files = $_FILES['image'];
    
    // 如果是单文件上传（未修改前端的情况），将其转换为数组结构以便统一处理
    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']]
        ];
    }

    $fileCount = count($files['name']);
    if ($fileCount > 14) {
        sendError('最多支持 14 张参考图片', 400);
    }

    $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/webp'];
    $maxFileSize = 8 * 1024 * 1024; // 8MB
    $validImageCount = 0;

    for ($i = 0; $i < $fileCount; $i++) {
        $filePayload = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];

        try {
            $validated = SecurityUtils::validateUploadedImage($filePayload, $allowedMimeTypes, $maxFileSize);
        } catch (\RuntimeException $e) {
            continue;
        }

        $imageData = file_get_contents($validated['tmp_name']);
        if ($imageData === false) {
            continue;
        }
        
        $base64Image = base64_encode($imageData);

        $parts[] = [
            'inline_data' => [
                'mime_type' => $validated['mime_type'],
                'data' => $base64Image
            ]
        ];
        $validImageCount++;
    }

    if ($validImageCount === 0) {
        sendError('未能处理任何有效的图片文件', 400);
    }

    $requestData['contents'][] = [
        'parts' => $parts
    ];
} else {
    sendError('未知的操作类型', 400);
}

// 调用 Gemini 生成/编辑
$responseData = callGeminiApi($modelName, $requestData, 300, 30);

if (!isset($responseData['candidates'][0])) {
    sendError('API 未返回任何候选结果', 502);
}
$resultImages = [];
$resultText = '';
$resultThoughts = [];
$groundingMetadata = null;

// 提取 Grounding Metadata
if (isset($responseData['candidates'][0]['groundingMetadata'])) {
    $groundingMetadata = $responseData['candidates'][0]['groundingMetadata'];
}

// 确保输出目录存在
if (!is_dir($config['output_dir'])) {
    mkdir($config['output_dir'], 0755, true);
}

if (isset($responseData['candidates'][0]['content']['parts'])) {
    foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) {
            $resultText .= SecurityUtils::sanitizeHtml($part['text']) . "\n";
        } elseif (isset($part['inlineData'])) {
            $imageBytes = base64_decode($part['inlineData']['data']);
            try {
                $token = SecurityUtils::generateSecureToken(16);
            } catch (\Exception $e) {
                $token = uniqid();
            }
            $fileName = 'gen_' . date('Ymd_His') . '_' . $token . '.png';
            $filePath = $config['output_dir'] . $fileName;
            
            if (file_put_contents($filePath, $imageBytes)) {
                $resultImages[] = $filePath;
            }
        }
    }
}

$resultThoughts = extractThoughtsFromResponse($responseData);

// 返回结果
echo json_encode([
    'success' => true,
    'images' => $resultImages,
    'text' => trim($resultText),
    'thoughts' => $resultThoughts,
    'groundingMetadata' => $groundingMetadata
]);
