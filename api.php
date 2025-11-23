<?php
// api.php - 后端逻辑处理

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 增加执行时间限制，防止超时
set_time_limit(180); // 设置为 180 秒 (3分钟)

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

// 获取操作类型
$action = $_POST['action'] ?? '';
$prompt = $_POST['prompt'] ?? '';

if (empty($prompt)) {
    sendError('提示词不能为空', 400);
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
if (!empty($_POST['aspect_ratio'])) {
    $requestData['generationConfig']['imageConfig']['aspectRatio'] = $_POST['aspect_ratio'];
}
if (!empty($_POST['resolution'])) {
    $requestData['generationConfig']['imageConfig']['imageSize'] = $_POST['resolution'];
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

    $validImageCount = 0;

    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $mimeType = $files['type'][$i];
        // 简单的 MIME 类型检查
        if (strpos($mimeType, 'image/') !== 0) {
            continue;
        }

        $tmpName = $files['tmp_name'][$i];
        $imageData = file_get_contents($tmpName);
        if ($imageData === false) {
            continue;
        }
        
        $base64Image = base64_encode($imageData);

        $parts[] = [
            'inline_data' => [
                'mime_type' => $mimeType,
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

if ($httpCode !== 200) {
    $errorResponse = json_decode($response, true);
    $errorMessage = $errorResponse['error']['message'] ?? 'API 请求失败';
    sendError("API 错误 ({$httpCode}): " . $errorMessage);
}

// 解析响应
$responseData = json_decode($response, true);
$resultImages = [];
$resultText = '';

// 确保输出目录存在
if (!is_dir($config['output_dir'])) {
    mkdir($config['output_dir'], 0755, true);
}

if (isset($responseData['candidates'][0]['content']['parts'])) {
    foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) {
            $resultText .= $part['text'] . "\n";
        } elseif (isset($part['inlineData'])) {
            $imageBytes = base64_decode($part['inlineData']['data']);
            $fileName = 'gen_' . time() . '_' . uniqid() . '.png';
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
    'text' => trim($resultText)
]);