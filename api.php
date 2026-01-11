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
require_once __DIR__ . '/openai_adapter.php';
require_once __DIR__ . '/gemini_proxy_adapter.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n/I18n.php';

// 引入配置
$config = require 'config.php';

// 获取计费配置
$billingConfig = $config['billing'] ?? [];
$pricePerTask = (float) ($billingConfig['price_per_task'] ?? $billingConfig['price_per_image'] ?? 0.20);

// 初始化认证
$auth = getAuth();

// 核心配置
$apiKey = $config['api_key'];
$apiProvider = $config['api_provider'] ?? 'native';  // 默认使用原生 API

// 初始化 OpenAI 适配器（如果使用中转站）
$openaiAdapter = null;
if ($apiProvider === 'openai_compatible') {
    $openaiAdapter = new GeminiOpenAIAdapter($config);
    if (!$openaiAdapter->isAvailable()) {
        $openaiAdapter = null;
        $apiProvider = 'native';  // 回退到原生 API
    }
}

// 初始化 Gemini 代理适配器（如果使用 SSE 代理）
$geminiProxyAdapter = null;
if ($apiProvider === 'gemini_proxy') {
    $geminiProxyAdapter = new GeminiProxyAdapter($config);
    if (!$geminiProxyAdapter->isAvailable()) {
        $geminiProxyAdapter = null;
        $apiProvider = 'native';  // 回退到原生 API
    }
}

// 错误处理函数
function sendError($message, $httpCode = 500, $errorCode = null) {
    http_response_code($httpCode);
    $response = ['success' => false, 'message' => $message];
    if ($errorCode !== null) {
        $response['code'] = $errorCode;
    }
    echo json_encode($response);
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
 * 根据配置自动选择使用原生 API 或 OpenAI 兼容中转站
 *
 * @param string $modelName 模型名称
 * @param array $payload 请求体
 * @param int $timeout 超时时间
 * @param int $connectTimeout 连接超时
 * @return array 解析后的响应数组
 * @throws GeminiApiException 当请求失败时抛出
 */
function callGeminiApiSafe($modelName, array $payload, $timeout = 120, $connectTimeout = 20): array {
    global $apiKey, $apiProvider, $openaiAdapter, $geminiProxyAdapter;

    // 使用 Gemini 代理站（SSE 流式响应）
    if ($apiProvider === 'gemini_proxy' && $geminiProxyAdapter !== null) {
        try {
            return $geminiProxyAdapter->generateContent($modelName, $payload);
        } catch (GeminiProxyAdapterException $e) {
            throw new GeminiApiException($e->getMessage(), $e->getHttpCode());
        }
    }

    // 使用 OpenAI 兼容中转站
    if ($apiProvider === 'openai_compatible' && $openaiAdapter !== null) {
        try {
            return $openaiAdapter->generateContent($modelName, $payload);
        } catch (OpenAIAdapterException $e) {
            throw new GeminiApiException($e->getMessage(), $e->getHttpCode());
        }
    }

    // 原生 Gemini API 调用
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
        throw new GeminiApiException(__('api.gemini_request_failed_detail', ['error' => $curlError]), 500);
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new GeminiApiException(__('api.gemini_parse_failed_detail', ['error' => json_last_error_msg()]), 500);
    }

    if ($httpCode !== 200) {
        $errorMessage = is_array($responseData) && isset($responseData['error']['message'])
            ? $responseData['error']['message']
            : __('api.gemini_request_failed');
        throw new GeminiApiException(__("api.gemini_request_failed") . " ({$httpCode}): " . $errorMessage, $httpCode);
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
 * 提取文本结果（排除思考内容）
 */
function extractTextFromCandidates(array $responseData) {
    $result = '';
    if (isset($responseData['candidates'][0]['content']['parts'])) {
        foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
            // 跳过思考内容（thought: true 的 parts）
            if (isset($part['thought']) && $part['thought'] === true) {
                continue;
            }
            if (isset($part['text'])) {
                $result .= $part['text'];
            }
        }
    }
    return trim($result);
}

/**
 * 根据配置生成思考模式配置
 *
 * 支持的思考级别:
 * - Gemini 3 Pro 模型: "LOW", "HIGH"
 * - Gemini 3 Flash 模型: "MINIMAL", "LOW", "MEDIUM", "HIGH"
 *
 * 注意: gemini-3-pro-image-preview 模型只支持 includeThoughts，不支持 thinkingLevel
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

    // image-preview 模型不支持 thinkingLevel，只支持 includeThoughts
    $isImagePreviewModel = stripos($modelName, 'image-preview') !== false;
    if ($isImagePreviewModel) {
        return $thinkingConfig;
    }

    // 其他 Gemini 3/2 系列模型支持 thinkingLevel
    $isGemini3Model = stripos($modelName, 'gemini-3') !== false || stripos($modelName, 'gemini-2') !== false;
    if ($isGemini3Model && !empty($rawConfig['thinking_level']) && is_string($rawConfig['thinking_level'])) {
        $level = strtoupper($rawConfig['thinking_level']);
        $allowedLevels = ['MINIMAL', 'LOW', 'MEDIUM', 'HIGH'];
        if (in_array($level, $allowedLevels, true)) {
            $thinkingConfig['thinkingLevel'] = $level;
        }
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

            // Gemini 原生 API 格式：thought 是布尔值，文本在 text 字段
            // 当 part.thought === true 时，part.text 就是思考内容
            if (isset($part['thought']) && $part['thought'] === true) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $thoughts[] = $part['text'];
                }
                continue;
            }

            // 兼容旧格式：thought 字段直接包含思考文本
            if (isset($part['thought']) && is_string($part['thought'])) {
                $thoughts[] = $part['thought'];
            }

            // 兼容 thoughts 数组格式
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

/**
 * 计算最接近的支持宽高比
 */
function getClosestAspectRatio($width, $height) {
    if ($height <= 0) return '1:1';
    
    $target = $width / $height;
    $ratios = [
        '1:1' => 1.0,
        '2:3' => 2/3,
        '3:2' => 3/2,
        '3:4' => 3/4,
        '4:3' => 4/3,
        '4:5' => 4/5,
        '5:4' => 5/4,
        '9:16' => 9/16,
        '16:9' => 16/9,
        '21:9' => 21/9,
    ];
    
    $closest = '1:1';
    $minDiff = PHP_FLOAT_MAX;
    
    foreach ($ratios as $key => $val) {
        $diff = abs($target - $val);
        if ($diff < $minDiff) {
            $minDiff = $diff;
            $closest = $key;
        }
    }
    return $closest;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(__('api.only_post'), 405);
}

try {
    $action = strtolower(SecurityUtils::sanitizeTextInput($_POST['action'] ?? '', 32));
} catch (\Throwable $e) {
    sendError(__('api.invalid_action'), 400);
}

if (!in_array($action, ['generate', 'edit', 'optimize_prompt', 'transcribe', 'get_user_status'], true)) {
    sendError(__('api.unknown_action'), 400);
}

/**
 * 验证用户登录状态和账户有效性
 * 检查用户是否登录、账户是否被禁用
 *
 * @param Auth $auth 认证实例
 * @param bool $requireBalance 是否需要检查余额
 * @param float $requiredAmount 需要的最低余额
 * @return array|null 验证通过返回用户信息，失败则发送错误响应并退出
 */
function validateUserAuthAndStatus(Auth $auth, bool $requireBalance = false, float $requiredAmount = 0): ?array {
    // 检查用户是否登录
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => __('api.unauthorized_use'),
            'code' => 'UNAUTHORIZED',
            'action' => [
                'type' => 'redirect',
                'url' => 'login.php',
                'label' => __('auth.login_now')
            ]
        ]);
        exit;
    }

    // 获取用户信息并检查状态（防止被禁用用户继续操作）
    $user = $auth->getCurrentUser();
    if ($user === null || ($user['status'] ?? 0) !== 1) {
        // 用户已被禁用或不存在，强制登出
        $auth->logout();
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => __('api.account_disabled'),
            'code' => 'ACCOUNT_DISABLED',
            'action' => [
                'type' => 'redirect',
                'url' => 'login.php',
                'label' => __('auth.login')
            ]
        ]);
        exit;
    }

    // 如果需要检查余额
    if ($requireBalance) {
        $currentBalance = (float) ($user['balance'] ?? 0);
        if ($currentBalance < $requiredAmount) {
            http_response_code(402);
            echo json_encode([
                'success' => false,
                'message' => __('api.insufficient_balance'),
                'code' => 'INSUFFICIENT_BALANCE',
                'balance' => $currentBalance,
                'required' => $requiredAmount,
                'action' => [
                    'type' => 'redirect',
                    'url' => 'recharge.php',
                    'label' => __('balance.recharge')
                ]
            ]);
            exit;
        }
    }

    return $user;
}

// 用户状态查询（不需要登录也可以查询，返回是否登录等信息）
if ($action === 'get_user_status') {
    $isLoggedIn = $auth->isLoggedIn();
    $user = $isLoggedIn ? $auth->getCurrentUser() : null;
    
    // 额外检查用户状态（被禁用的用户视为未登录）
    if ($user !== null && ($user['status'] ?? 0) !== 1) {
        $auth->logout();
        $isLoggedIn = false;
        $user = null;
    }
    
    echo json_encode([
        'success' => true,
        'logged_in' => $isLoggedIn,
        'user' => $user ? [
            'id' => $user['id'],
            'username' => $user['username'],
            'balance' => (float) ($user['balance'] ?? 0),
            'can_generate' => (float) ($user['balance'] ?? 0) >= $pricePerTask,
        ] : null,
        'price_per_task' => $pricePerTask,
        'price_per_image' => $pricePerTask,
    ]);
    exit;
}

// 对于图片生成和编辑操作，需要检查用户登录和余额
// 注意：这里只做初步检查，实际扣费在生成成功后使用原子操作
if (in_array($action, ['generate', 'edit'], true)) {
    validateUserAuthAndStatus($auth, true, $pricePerTask);
}

// 对于提示词优化和语音转文字，需要登录但不需要余额（防止匿名滥用）
if (in_array($action, ['optimize_prompt', 'transcribe'], true)) {
    validateUserAuthAndStatus($auth, false);
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
        sendError($result['error'] ?? __('api.transcribe_failed'), 500);
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
    sendError(__('api.prompt_required'), 400);
}

// 提示词优化分支（返回优化结果和思考内容）
if ($action === 'optimize_prompt') {
    $optModel = SecurityUtils::sanitizeTextInput($config['prompt_optimize_model'] ?? 'gemini-2.5-flash', 64);
    $mode = SecurityUtils::validateAllowedValue(
        SecurityUtils::sanitizeTextInput($_POST['mode'] ?? 'basic', 16),
        ['basic', 'detail'],
        'basic'
    );
    $result = PromptOptimizer::optimizePromptWithThoughts($prompt, $mode, $config);

    echo json_encode([
        'success' => true,
        'optimized_prompt' => $result['optimized_prompt'],
        'thoughts' => $result['thoughts']
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
// 获取图片生成前缀配置（仅用于文生图，强制 AI 理解这是图片生成请求）
// 注意：编辑模式不使用前缀，避免"请生成图片"干扰编辑意图
$imageGenPrefix = $config['image_generation_prefix'] ?? '';

if ($action === 'generate') {
    // 文生图：使用前缀增强提示词
    $enhancedPrompt = ($imageGenPrefix !== '') ? ($imageGenPrefix . $prompt) : $prompt;
    $requestData['contents'][] = [
        'parts' => [
            ['text' => $enhancedPrompt]
        ]
    ];
} elseif ($action === 'edit') {
    // 图生图/编辑：直接使用原始提示词，不添加前缀
    if (!isset($_FILES['image'])) {
        sendError(__('api.image_required'), 400);
    }

    $parts = [];
    // 添加提示词（不带前缀，保持编辑指令的语义清晰）
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
        sendError(__('api.image_max_count', ['max' => 14]), 400);
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

        // 如果未指定宽高比（保持原样），则根据第一张图片自动计算
        if ($validImageCount === 0 && empty($aspectRatio) && !$isFlashImage) {
            list($width, $height) = getimagesize($validated['tmp_name']);
            if ($width && $height) {
                $detectedRatio = getClosestAspectRatio($width, $height);
                if (!isset($requestData['generationConfig']['imageConfig'])) {
                    $requestData['generationConfig']['imageConfig'] = [];
                }
                $requestData['generationConfig']['imageConfig']['aspectRatio'] = $detectedRatio;
            }
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
        sendError(__('api.image_invalid'), 400);
    }

    $requestData['contents'][] = [
        'parts' => $parts
    ];
} else {
    sendError(__('api.unknown_action'), 400);
}

// === 预扣费机制：在调用 API 前使用原子操作扣除余额 ===
// 这可以防止并发请求导致的余额透支问题（竞态条件）
$db = Database::getInstance();
$userId = $auth->getCurrentUserId();
$preDeductResult = $db->atomicDeductBalance($userId, $pricePerTask);

if (!$preDeductResult['success']) {
    // 原子扣费失败，说明余额不足或用户不存在
    $errorCode = $preDeductResult['error'] ?? 'DEDUCT_FAILED';
    if ($errorCode === 'INSUFFICIENT_BALANCE') {
        http_response_code(402);
        echo json_encode([
            'success' => false,
            'message' => __('api.balance_low'),
            'code' => 'INSUFFICIENT_BALANCE',
            'balance' => $preDeductResult['balance_before'] ?? 0,
            'required' => $pricePerTask,
            'action' => [
                'type' => 'redirect',
                'url' => 'recharge.php',
                'label' => __('balance.recharge')
            ]
        ]);
        exit;
    }
    sendError(__('api.deduct_failed') . ': ' . $errorCode, 500, $errorCode);
}

// 记录预扣费信息，用于后续日志
$balanceBeforeDeduct = $preDeductResult['balance_before'];
$balanceAfterDeduct = $preDeductResult['balance_after'];

// 调用 Gemini 生成/编辑
// 注意：如果 API 调用失败，需要退还已扣除的余额
try {
    $responseData = callGeminiApiSafe($modelName, $requestData, 300, 30);
} catch (GeminiApiException $e) {
    // API 调用失败，退还余额
    $db->atomicRefundBalance($userId, $pricePerTask);
    error_log("API call failed, refunded {$pricePerTask} to user {$userId}: " . $e->getMessage());
    sendError($e->getMessage(), $e->getHttpCode());
}

if (!isset($responseData['candidates'][0])) {
    sendError(__('api.no_result'), 502);
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
        // 跳过思考内容 parts（Gemini 原生 API 格式：thought 为布尔值 true）
        if (isset($part['thought']) && $part['thought'] === true) {
            continue;
        }
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

// === 计费结果处理 ===
// 预扣费已在 API 调用前完成，这里只需要处理结果
$billingResult = null;
$imageCount = count($resultImages);

if (!empty($resultImages)) {
    // 构建增强的消费记录备注（包含提示词摘要和图片标识）
    $remarkData = [
        'prompt' => mb_substr($prompt, 0, 200, 'UTF-8'),  // 提示词摘要
        'images' => array_map(function($path) {
            return basename($path);  // 只保存文件名
        }, $resultImages)
    ];
    $remarkJson = json_encode($remarkData, JSON_UNESCAPED_UNICODE);
    
    // 生成成功，记录消费日志
    $db->logConsumption(
        $userId,
        $action,
        $pricePerTask,
        $balanceBeforeDeduct,
        $balanceAfterDeduct,
        $imageCount,
        $modelName,
        $remarkJson
    );
    
    $billingResult = [
        'success' => true,
        'amount' => $pricePerTask,
        'balance_before' => $balanceBeforeDeduct,
        'balance_after' => $balanceAfterDeduct,
    ];
} else {
    // 没有生成任何图片，退还预扣的余额
    $refundSuccess = $db->atomicRefundBalance($userId, $pricePerTask);
    if ($refundSuccess) {
        error_log("No images generated, refunded {$pricePerTask} to user {$userId}");
    } else {
        error_log("Failed to refund {$pricePerTask} to user {$userId} after no images generated");
    }
    
    $billingResult = [
        'success' => false,
        'amount' => 0,
        'message' => __('api.refund_success'),
        'refunded' => $refundSuccess,
    ];
}

// 获取更新后的余额
$updatedUser = $auth->refreshCurrentUser();
$updatedBalance = $updatedUser ? (float) ($updatedUser['balance'] ?? 0) : null;

// 返回结果
echo json_encode([
    'success' => true,
    'images' => $resultImages,
    'text' => trim($resultText),
    'thoughts' => $resultThoughts,
    'groundingMetadata' => $groundingMetadata,
    'billing' => $billingResult ? [
        'charged' => $billingResult['success'],
        'amount' => $billingResult['amount'] ?? 0,
        'balance' => $updatedBalance,
    ] : null,
]);
