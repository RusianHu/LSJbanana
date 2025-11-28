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

// 引入配置
$config = require 'config.php';

// 错误处理函数
function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
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

$prompt = SecurityUtils::sanitizeTextInput($_POST['prompt'] ?? '', 4000);
if ($prompt === '') {
    sendError('提示词不能为空', 400);
}

if (!in_array($action, ['generate', 'edit'], true)) {
    sendError('未知的操作类型', 400);
}

// 准备 API 请求数据
$apiKey = $config['api_key'];
$modelName = $config['model_name'];
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";

// 构建请求体
$requestData = [
    'contents' => [],
    'generationConfig' => $config['generation_config']
];

// 处理宽高比和分辨率
$aspectRatio = SecurityUtils::validateAllowedValue(
    SecurityUtils::sanitizeTextInput($_POST['aspect_ratio'] ?? '', 10),
    ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
    ''
);
$resolution = SecurityUtils::validateAllowedValue(
    SecurityUtils::sanitizeTextInput($_POST['resolution'] ?? '', 5),
    ['1K', '2K', '4K'],
    ''
);

if ($aspectRatio !== '') {
    $requestData['generationConfig']['imageConfig']['aspectRatio'] = $aspectRatio;
}
if ($resolution !== '') {
    $requestData['generationConfig']['imageConfig']['imageSize'] = $resolution;
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

// 使用 cURL 发送请求 (模拟多线程/异步处理，虽然 PHP 本身是同步的，但 cURL 可以高效处理网络请求)
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 设置 cURL 超时为 300 秒
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // 连接超时 30 秒
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
// 禁用 SSL 验证 (仅用于本地开发测试，生产环境请配置证书)
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

if ($curlError) {
    sendError("cURL 错误: " . $curlError);
}

// 解析响应
$responseData = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('API 返回格式错误: ' . json_last_error_msg() . " (HTTP {$httpCode})");
}

if ($httpCode !== 200) {
    $errorMessage = is_array($responseData) && isset($responseData['error']['message'])
        ? $responseData['error']['message']
        : 'API 请求失败';
    sendError("API 错误 ({$httpCode}): " . $errorMessage);
}

if (!isset($responseData['candidates'][0])) {
    sendError('API 未返回任何候选结果', 502);
}
$resultImages = [];
$resultText = '';
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

// 返回结果
echo json_encode([
    'success' => true,
    'images' => $resultImages,
    'text' => trim($resultText),
    'groundingMetadata' => $groundingMetadata
]);
