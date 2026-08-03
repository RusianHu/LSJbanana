<?php
/**
 * OpenAI Images 响应解析回归测试。
 *
 * 不访问网络，不读取 config.php，不产生图片费用。
 * 运行：php verify_openai_images_response.php
 */

$_GET['lang'] = 'zh-CN';

require_once __DIR__ . '/openai_images_adapter.php';

$adapter = new OpenAIImagesAdapter([
    'openai_images' => [
        'base_url' => 'https://example.invalid',
        'api_key' => 'test-key',
        'public_base_url' => 'https://example.invalid/LSJbanana',
        'log_response_errors' => false,
    ],
]);

$method = new ReflectionMethod(OpenAIImagesAdapter::class, 'interpretHttpResponse');
$method->setAccessible(true);

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[PASS] {$message}\n";
        return;
    }

    $failed++;
    echo "[FAIL] {$message}\n";
};

$forceIpv4Property = new ReflectionProperty(OpenAIImagesAdapter::class, 'forceIpv4');
$forceIpv4Property->setAccessible(true);
$assert($forceIpv4Property->getValue($adapter) === true, 'OpenAI Images 默认使用 IPv4 长连接');
$ipv6AllowedAdapter = new OpenAIImagesAdapter([
    'openai_images' => [
        'base_url' => 'https://example.invalid',
        'api_key' => 'test-key',
        'public_base_url' => 'https://example.invalid/LSJbanana',
        'force_ipv4' => false,
        'log_response_errors' => false,
    ],
]);
$assert($forceIpv4Property->getValue($ipv6AllowedAdapter) === false, '可显式关闭 OpenAI Images IPv4 限制');

$applyImageConfigMethod = new ReflectionMethod(OpenAIImagesAdapter::class, 'applyImageConfig');
$applyImageConfigMethod->setAccessible(true);
$applyImageConfig = static function (
    OpenAIImagesAdapter $target,
    string $model,
    string $aspectRatio,
    string $imageSize
) use ($applyImageConfigMethod): array {
    $request = ['model' => $model, 'prompt' => 'test'];
    $args = [
        &$request,
        $model,
        ['aspectRatio' => $aspectRatio, 'imageSize' => $imageSize],
    ];
    $expected = $applyImageConfigMethod->invokeArgs($target, $args);
    return [$request, $expected];
};

[$wide2K, $wide2KExpected] = $applyImageConfig($adapter, 'gpt-image-2', '16:9', '2K');
$assert(($wide2K['size'] ?? '') === '2048x1152', 'GPT Image 2 的 16:9 2K 映射为明确像素尺寸');
$assert($wide2KExpected === ['width' => 2048, 'height' => 1152], '返回 16:9 2K 预期尺寸用于响应校验');
$assert(!array_key_exists('aspect_ratio', $wide2K), '不再发送非标准 aspect_ratio 字段');

[$tall2K] = $applyImageConfig($adapter, 'gpt-image-2', '9:16', '2K');
$assert(($tall2K['size'] ?? '') === '1152x2048', 'GPT Image 2 的 9:16 2K 保持竖屏方向');

[$wide1K] = $applyImageConfig($adapter, 'gpt-image-2', '16:9', '1K');
$assert(($wide1K['size'] ?? '') === '1280x720', 'GPT Image 2 的 16:9 1K 同时满足最小像素约束');

[$square4K] = $applyImageConfig($adapter, 'gpt-image-2', '1:1', '4K');
$assert(($square4K['size'] ?? '') === '2880x2880', 'GPT Image 2 的方形 4K 遵守最大总像素约束');

[$ultrawide4K] = $applyImageConfig($adapter, 'gpt-image-2', '21:9', '4K');
$assert(($ultrawide4K['size'] ?? '') === '3808x1632', 'GPT Image 2 的 21:9 4K 遵守最大边长约束');

$allGeneratedSizesValid = true;
foreach (['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'] as $ratio) {
    [$ratioWidth, $ratioHeight] = array_map('intval', explode(':', $ratio));
    foreach (['1K', '2K', '4K'] as $tier) {
        [$mapped] = $applyImageConfig($adapter, 'gpt-image-2', $ratio, $tier);
        if (!preg_match('/^(\d+)x(\d+)$/', (string)($mapped['size'] ?? ''), $matches)) {
            $allGeneratedSizesValid = false;
            continue;
        }
        $width = (int)$matches[1];
        $height = (int)$matches[2];
        $pixels = $width * $height;
        if (
            $width % 16 !== 0
            || $height % 16 !== 0
            || $width > 3840
            || $height > 3840
            || $pixels < 655_360
            || $pixels > 8_294_400
            || $width * $ratioHeight !== $height * $ratioWidth
        ) {
            $allGeneratedSizesValid = false;
        }
    }
}
$assert($allGeneratedSizesValid, '所有宽高比与分辨率组合均满足 GPT Image 2 尺寸约束');

$tierAdapter = new OpenAIImagesAdapter([
    'openai_images' => [
        'base_url' => 'https://example.invalid',
        'api_key' => 'test-key',
        'public_base_url' => 'https://example.invalid/LSJbanana',
        'size_mode' => 'tier',
        'log_response_errors' => false,
    ],
]);
[$legacyTier2K] = $applyImageConfig($tierAdapter, 'gpt-image-2', '16:9', '2K');
$assert(($legacyTier2K['size'] ?? '') === '2048x1152', '旧 tier 配置对 GPT Image 2 自动迁移为官方像素尺寸');

[$legacyModel] = $applyImageConfig($adapter, 'gpt-image-1', '16:9', '2K');
$assert(($legacyModel['size'] ?? '') === '1536x1024', '旧图片模型继续使用兼容尺寸');

$convertMethod = new ReflectionMethod(OpenAIImagesAdapter::class, 'convertToGeminiFormat');
$convertMethod->setAccessible(true);
$sizeAcceptableMethod = new ReflectionMethod(OpenAIImagesAdapter::class, 'isOutputSizeAcceptable');
$sizeAcceptableMethod->setAccessible(true);
$assert(
    $sizeAcceptableMethod->invoke($adapter, 2048, 1153, ['width' => 2048, 'height' => 1152]) === true,
    '允许上游 2K 图片因内部取整产生 1 像素误差'
);
$assert(
    $sizeAcceptableMethod->invoke($adapter, 1024, 576, ['width' => 2048, 'height' => 1152]) === false,
    '仍然拒绝实际降为 1K 的输出'
);
$onePixelPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZqKQAAAAASUVORK5CYII=';
$converted = $convertMethod->invoke($adapter, ['data' => [['b64_json' => $onePixelPng]]], ['width' => 1, 'height' => 1]);
$assert(isset($converted['candidates'][0]['content']['parts'][0]['inlineData']), '返回尺寸匹配时正常转换图片响应');
try {
    $convertMethod->invoke($adapter, ['data' => [['b64_json' => $onePixelPng]]], ['width' => 2, 'height' => 2]);
    $assert(false, '返回尺寸不匹配时应抛出异常');
} catch (OpenAIImagesAdapterException $e) {
    $assert($e->getHttpCode() === 502, '返回尺寸不匹配映射为上游错误');
    $assert(str_contains($e->getMessage(), '1x1') && str_contains($e->getMessage(), '2x2'), '尺寸不匹配错误包含实际与期望尺寸');
}

$invoke = static function (
    string $body,
    int $httpCode = 200,
    string $contentType = 'application/json',
    array $headers = []
) use ($adapter, $method): array {
    return $method->invoke(
        $adapter,
        '/v1/images/edits',
        $body,
        $httpCode,
        $contentType,
        $headers,
        'lsjbanana-test-client-id'
    );
};

$valid = $invoke('{"created":1,"data":[{"url":"https://example.invalid/result.png"}]}');
$assert(isset($valid['data'][0]['url']), '解析标准 JSON 响应');

$bom = $invoke("\xEF\xBB\xBF" . '{"created":1,"data":[{"url":"https://example.invalid/bom.png"}]}');
$assert(($bom['data'][0]['url'] ?? '') === 'https://example.invalid/bom.png', '兼容 UTF-8 BOM');

$sse = $invoke(
    "data: {\"created\":1,\"data\":[{\"url\":\"https://example.invalid/sse.png\"}]}\n\ndata: [DONE]\n\n",
    200,
    'text/event-stream'
);
$assert(($sse['data'][0]['url'] ?? '') === 'https://example.invalid/sse.png', '兼容单结果 SSE 包装');

try {
    $invoke(
        '<html><title>524 A timeout occurred</title></html>',
        524,
        'text/html; charset=UTF-8',
        ['cf-ray' => 'test-cf-ray']
    );
    $assert(false, '非 JSON 网关错误应抛出异常');
} catch (OpenAIImagesAdapterException $e) {
    $assert($e->getHttpCode() === 524, '保留非 JSON 网关错误的 HTTP 状态');
    $assert(str_contains($e->getMessage(), '唯一图片渠道'), '网关超时返回简洁明确的用户提示');
    $assert(str_contains($e->getMessage(), 'cf=test-cf-ray'), '错误消息包含 Cloudflare 追踪 ID');
}

try {
    $invoke(
        '{"created":1,"data":[',
        200,
        'application/json',
        ['x-request-id' => 'test-request-id']
    );
    $assert(false, '截断 JSON 应抛出异常');
} catch (OpenAIImagesAdapterException $e) {
    $assert($e->getHttpCode() === 502, '截断 JSON 映射为 502');
    $assert(str_contains($e->getMessage(), '无效 JSON'), '截断 JSON 返回明确错误类型');
    $assert(str_contains($e->getMessage(), 'request=test-request-id'), '截断 JSON 错误包含上游请求 ID');
}

try {
    $invoke(
        '{"error":{"message":"image_url fetch failed: HTTP 404"}}',
        400,
        'application/json',
        ['x-request-id' => 'json-error-request']
    );
    $assert(false, 'JSON API 错误应抛出异常');
} catch (OpenAIImagesAdapterException $e) {
    $assert($e->getHttpCode() === 400, '保留 JSON API 错误状态');
    $assert(str_contains($e->getMessage(), 'image_url fetch failed'), '保留 JSON API 错误消息');
}

try {
    $invoke('null', 200, 'application/json');
    $assert(false, 'JSON 标量响应应抛出异常');
} catch (OpenAIImagesAdapterException $e) {
    $assert(str_contains($e->getMessage(), '异常 JSON 结构'), '识别异常 JSON 根结构');
}

I18n::getInstance()->setLocale('en');
try {
    $invoke('<html><title>Bad Gateway</title></html>', 502, 'text/html');
    $assert(false, '英文非 JSON 错误应抛出异常');
} catch (OpenAIImagesAdapterException $e) {
    $assert(str_contains($e->getMessage(), 'upstream gateway'), '英文 502 网关异常文案可用');
    $assert(str_contains($e->getMessage(), 'HTTP 502'), '英文网关异常文案包含 HTTP 状态');
    $assert(str_contains($e->getMessage(), 'trace ID'), '英文错误包含追踪 ID');
}
$assert(
    __('api.upstream_error_refunded', ['message' => 'upstream failed'])
        === 'upstream failed; the pre-deducted charge has been refunded.',
    '英文退款状态文案可用'
);
I18n::getInstance()->setLocale('zh-CN');

$logFile = tempnam(sys_get_temp_dir(), 'lsjbanana_adapter_');
$previousErrorLog = ini_get('error_log');
if (is_string($logFile)) {
    ini_set('error_log', $logFile);
    $loggingAdapter = new OpenAIImagesAdapter([
        'openai_images' => [
            'base_url' => 'https://example.invalid',
            'api_key' => 'test-key',
            'public_base_url' => 'https://example.invalid/LSJbanana',
            'log_response_errors' => true,
            'response_preview_bytes' => 256,
        ],
    ]);
    try {
        $method->invoke(
            $loggingAdapter,
            '/v1/images/edits',
            '<html><body>gateway sk-supersecret123456789 failure</body></html>',
            502,
            'text/html',
            ['cf-ray' => 'redaction-test'],
            'lsjbanana-log-test',
            [
                'elapsed_ms' => 125000,
                'curl_errno' => 0,
                'primary_ip' => '203.0.113.10',
                'http_version' => 2,
            ]
        );
    } catch (OpenAIImagesAdapterException) {
        // 预期异常，仅检查诊断日志。
    }
    $logContent = file_get_contents($logFile);
    $assert(is_string($logContent) && str_contains($logContent, '[redacted]'), '诊断日志脱敏常见 API Key');
    $assert(is_string($logContent) && !str_contains($logContent, 'sk-supersecret123456789'), '诊断日志不保留原始 API Key');
    $assert(is_string($logContent) && str_contains($logContent, 'response_sha256'), '诊断日志包含响应指纹');
    $assert(is_string($logContent) && str_contains($logContent, '"elapsed_ms":125000'), '诊断日志包含上游等待耗时');
    $assert(is_string($logContent) && str_contains($logContent, '"curl_errno":0'), '诊断日志包含 cURL 错误码');
    $assert(is_string($logContent) && str_contains($logContent, '"primary_ip":"203.0.113.10"'), '诊断日志包含上游连接 IP');
    $assert(is_string($logContent) && str_contains($logContent, '"http_version":2'), '诊断日志包含 HTTP 版本');
    @unlink($logFile);
    ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
} else {
    $assert(false, '创建诊断日志测试文件');
}

echo "Tests: " . ($passed + $failed) . ", Passed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
