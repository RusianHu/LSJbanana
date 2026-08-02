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
    $assert(str_contains($e->getMessage(), 'HTTP 524'), '错误消息包含上游 HTTP 状态');
    $assert(str_contains($e->getMessage(), 'text/html'), '错误消息包含 Content-Type');
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
    $assert(str_contains($e->getMessage(), 'Image upstream request failed'), '英文错误文案可用');
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
            'lsjbanana-log-test'
        );
    } catch (OpenAIImagesAdapterException) {
        // 预期异常，仅检查诊断日志。
    }
    $logContent = file_get_contents($logFile);
    $assert(is_string($logContent) && str_contains($logContent, '[redacted]'), '诊断日志脱敏常见 API Key');
    $assert(is_string($logContent) && !str_contains($logContent, 'sk-supersecret123456789'), '诊断日志不保留原始 API Key');
    $assert(is_string($logContent) && str_contains($logContent, 'response_sha256'), '诊断日志包含响应指纹');
    @unlink($logFile);
    ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
} else {
    $assert(false, '创建诊断日志测试文件');
}

echo "Tests: " . ($passed + $failed) . ", Passed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
