<?php

require_once __DIR__ . '/security_utils.php';
require_once __DIR__ . '/openai_adapter.php';
require_once __DIR__ . '/openai_images_adapter.php';
require_once __DIR__ . '/gemini_proxy_adapter.php';

final class ImageGenerationExecutionException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $httpCode = 500,
        private bool $retryable = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpCode, $previous);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}

final class ImageGenerationCancelledException extends RuntimeException
{
}

final class ImageGenerationService
{
    public function __construct(private array $config)
    {
    }

    /**
     * @param array<int,array{mime_type:string,data:string}> $inputs
     */
    public function execute(array $job, array $inputs, ?callable $heartbeat = null): array
    {
        $request = json_decode((string)($job['request_json'] ?? ''), true);
        if (!is_array($request) || !is_array($request['request_data'] ?? null)) {
            throw new ImageGenerationExecutionException('任务请求数据损坏', 500, false);
        }

        $payload = $request['request_data'];
        if ($inputs !== []) {
            if (!isset($payload['contents'][0]['parts']) || !is_array($payload['contents'][0]['parts'])) {
                throw new ImageGenerationExecutionException('图片编辑任务结构无效', 500, false);
            }
            foreach ($inputs as $input) {
                if (($input['data'] ?? '') === '') {
                    throw new ImageGenerationExecutionException('图片编辑任务的输入文件已损坏', 500, false);
                }
                $payload['contents'][0]['parts'][] = [
                    'inline_data' => [
                        'mime_type' => (string)$input['mime_type'],
                        'data' => base64_encode((string)$input['data']),
                    ],
                ];
            }
        }

        $this->assertCanContinue($heartbeat);
        $provider = (string)($job['provider'] ?? 'native');
        $modelName = (string)($job['model_name'] ?? '');
        $clientRequestId = 'lsjbanana-job-' . (string)($job['public_id'] ?? 'unknown');

        try {
            $response = match ($provider) {
                'openai_images' => $this->callOpenAIImages($modelName, $payload, $clientRequestId, $heartbeat),
                'openai_compatible' => $this->callOpenAICompatible($modelName, $payload, $heartbeat),
                'gemini_proxy' => $this->callGeminiProxy($modelName, $payload, $heartbeat),
                'native' => $this->callNativeGemini($modelName, $payload, $heartbeat),
                default => throw new ImageGenerationExecutionException('未知的图片 API 提供商: ' . $provider, 500, false),
            };
        } catch (ImageGenerationExecutionException $e) {
            throw $e;
        } catch (ImageGenerationCancelledException $e) {
            throw $e;
        } catch (OpenAIImagesAdapterException|OpenAIAdapterException|GeminiProxyAdapterException $e) {
            $httpCode = $e->getHttpCode();
            if ($httpCode === 499) {
                throw new ImageGenerationCancelledException($e->getMessage(), 499, $e);
            }
            throw new ImageGenerationExecutionException(
                $e->getMessage(),
                $httpCode,
                $this->isRetryableHttpCode($httpCode),
                $e
            );
        } catch (Throwable $e) {
            throw new ImageGenerationExecutionException($e->getMessage(), 500, true, $e);
        }

        $this->assertCanContinue($heartbeat);
        $result = $this->persistResponse($response);
        try {
            $this->assertCanContinue($heartbeat);
        } catch (Throwable $e) {
            $this->discardResult($result);
            throw $e;
        }
        return $result;
    }

    /**
     * 仅删除本服务生成、且严格位于 output_dir 下的结果文件。
     */
    public function discardResult(array $result): int
    {
        $directory = $this->resolveOutputDirectory();
        $root = realpath($directory);
        if ($root === false) {
            return 0;
        }

        $deleted = 0;
        foreach (is_array($result['images'] ?? null) ? $result['images'] : [] as $image) {
            if (!is_string($image)) {
                continue;
            }
            $name = basename(str_replace('\\', '/', $image));
            if (preg_match('/^gen_\d{8}_\d{6}_[a-f0-9]{32}\.(?:png|jpe?g|webp)$/i', $name) !== 1) {
                continue;
            }
            $target = $root . DIRECTORY_SEPARATOR . $name;
            $realTarget = realpath($target);
            if ($realTarget === false || !$this->isDirectChildPath($root, $realTarget)) {
                continue;
            }
            if (@unlink($realTarget)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function callOpenAIImages(
        string $modelName,
        array $payload,
        string $clientRequestId,
        ?callable $heartbeat
    ): array {
        $adapter = new OpenAIImagesAdapter($this->config);
        if (!$adapter->isAvailable()) {
            throw new ImageGenerationExecutionException('OpenAI Images API 配置不完整', 500, false);
        }
        return $adapter->generateContent($modelName, $payload, $clientRequestId, $heartbeat);
    }

    private function callOpenAICompatible(string $modelName, array $payload, ?callable $heartbeat): array
    {
        $adapter = new GeminiOpenAIAdapter($this->config);
        if (!$adapter->isAvailable()) {
            throw new ImageGenerationExecutionException('OpenAI 兼容 API 配置不完整', 500, false);
        }
        return $adapter->generateContent($modelName, $payload, $heartbeat);
    }

    private function callGeminiProxy(string $modelName, array $payload, ?callable $heartbeat): array
    {
        $adapter = new GeminiProxyAdapter($this->config);
        if (!$adapter->isAvailable()) {
            throw new ImageGenerationExecutionException('Gemini 代理 API 配置不完整', 500, false);
        }
        return $adapter->generateContent($modelName, $payload, $heartbeat);
    }

    private function callNativeGemini(string $modelName, array $payload, ?callable $heartbeat): array
    {
        $apiKey = (string)($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new ImageGenerationExecutionException('Gemini API Key 未配置', 500, false);
        }
        $timeout = max(30, (int)($this->config['native_timeout'] ?? 300));
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($modelName) . ':generateContent?key=' . rawurlencode($apiKey);
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($heartbeat !== null) {
            $jobConfig = is_array($this->config['generation_jobs'] ?? null) ? $this->config['generation_jobs'] : [];
            $heartbeatInterval = max(0.5, min(10.0, (float)($jobConfig['cancellation_check_interval'] ?? 2.0)));
            $lastHeartbeatAt = 0.0;
            $cancelledByHeartbeat = false;
            $heartbeatFailure = null;
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = static function () use (
                &$lastHeartbeatAt,
                &$cancelledByHeartbeat,
                &$heartbeatFailure,
                $heartbeat,
                $heartbeatInterval
            ): int {
                $now = microtime(true);
                if ($now - $lastHeartbeatAt < $heartbeatInterval) {
                    return 0;
                }
                $lastHeartbeatAt = $now;
                try {
                    if ($heartbeat() === false) {
                        $cancelledByHeartbeat = true;
                        return 1;
                    }
                } catch (Throwable $e) {
                    $heartbeatFailure = $e;
                    return 1;
                }
                return 0;
            };
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (($cancelledByHeartbeat ?? false) === true) {
            throw new ImageGenerationCancelledException('图片生成请求已由用户取消', 499);
        }
        if (($heartbeatFailure ?? null) instanceof Throwable) {
            throw $heartbeatFailure;
        }
        if (!is_string($response) || $error !== '') {
            throw new ImageGenerationExecutionException('Gemini 请求失败: ' . ($error ?: 'empty response'), 502, true);
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new ImageGenerationExecutionException('Gemini 返回了无效 JSON', 502, true);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string)($data['error']['message'] ?? 'Gemini 请求失败');
            throw new ImageGenerationExecutionException($message, $httpCode, $this->isRetryableHttpCode($httpCode));
        }
        return $data;
    }

    private function persistResponse(array $response): array
    {
        if (!isset($response['candidates'][0]['content']['parts']) || !is_array($response['candidates'][0]['content']['parts'])) {
            throw new ImageGenerationExecutionException('图片上游未返回可用结果', 502, true);
        }

        $warnings = $this->normalizeWarnings($response['warnings'] ?? []);
        $images = [];
        $text = '';
        $thoughts = [];
        $outputDirectory = $this->resolveOutputDirectory();
        $publicPrefix = trim((string)($this->config['output_public_path'] ?? 'images'), '/');

        foreach ($response['candidates'][0]['content']['parts'] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (($part['thought'] ?? false) === true) {
                if (isset($part['text']) && is_string($part['text']) && trim($part['text']) !== '') {
                    $thoughts[] = SecurityUtils::sanitizeHtml(trim($part['text']));
                }
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= SecurityUtils::sanitizeHtml($part['text']) . "\n";
                continue;
            }

            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (!is_array($inline) || !isset($inline['data'])) {
                continue;
            }
            $bytes = base64_decode((string)$inline['data'], true);
            if (!is_string($bytes) || strlen($bytes) < 16 || @getimagesizefromstring($bytes) === false) {
                throw new ImageGenerationExecutionException('图片上游返回了损坏的图片文件', 502, true);
            }
            $mime = strtolower((string)($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png'));
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };
            $name = 'gen_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
            $finalPath = $outputDirectory . DIRECTORY_SEPARATOR . $name;
            $temporaryPath = $finalPath . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporaryPath, $finalPath)) {
                @unlink($temporaryPath);
                throw new ImageGenerationExecutionException('生成图片无法安全写入磁盘', 500, true);
            }
            $images[] = ($publicPrefix !== '' ? $publicPrefix . '/' : '') . $name;
        }

        if ($images === []) {
            throw new ImageGenerationExecutionException('图片上游没有返回图片', 502, true);
        }

        return [
            'images' => $images,
            'text' => trim($text),
            'thoughts' => array_values(array_slice($thoughts, 0, 20)),
            'warnings' => $warnings,
            'groundingMetadata' => is_array($response['candidates'][0]['groundingMetadata'] ?? null)
                ? $response['candidates'][0]['groundingMetadata']
                : null,
        ];
    }

    private function resolveOutputDirectory(): string
    {
        $configured = trim((string)($this->config['output_dir'] ?? 'images'));
        $isAbsolute = preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $configured) === 1;
        $directory = $isAbsolute ? rtrim($configured, "\\/") : __DIR__ . DIRECTORY_SEPARATOR . trim($configured, "\\/");
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ImageGenerationExecutionException('无法创建图片输出目录', 500, true);
        }
        if (!is_writable($directory)) {
            throw new ImageGenerationExecutionException('图片输出目录不可写', 500, true);
        }
        return $directory;
    }

    private function assertCanContinue(?callable $heartbeat): void
    {
        if ($heartbeat !== null && $heartbeat() === false) {
            throw new ImageGenerationCancelledException('图片生成任务已取消', 499);
        }
    }

    private function isDirectChildPath(string $root, string $target): bool
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $normalizedTarget = str_replace('\\', '/', $target);
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedRoot = strtolower($normalizedRoot);
            $normalizedTarget = strtolower($normalizedTarget);
        }
        return dirname($normalizedTarget) === $normalizedRoot;
    }

    private function normalizeWarnings(mixed $rawWarnings): array
    {
        $warnings = [];
        foreach (array_slice(is_array($rawWarnings) ? $rawWarnings : [], 0, 5) as $warning) {
            if (!is_array($warning) || ($warning['code'] ?? '') !== 'OUTPUT_SIZE_MISMATCH') {
                continue;
            }
            $expectedWidth = (int)($warning['expected']['width'] ?? 0);
            $expectedHeight = (int)($warning['expected']['height'] ?? 0);
            $actualWidth = (int)($warning['actual']['width'] ?? 0);
            $actualHeight = (int)($warning['actual']['height'] ?? 0);
            if (min($expectedWidth, $expectedHeight, $actualWidth, $actualHeight) <= 0
                || max($expectedWidth, $expectedHeight, $actualWidth, $actualHeight) > 100000) {
                continue;
            }
            $warnings[] = [
                'code' => 'OUTPUT_SIZE_MISMATCH',
                'expected' => ['width' => $expectedWidth, 'height' => $expectedHeight],
                'actual' => ['width' => $actualWidth, 'height' => $actualHeight],
            ];
        }
        return $warnings;
    }

    private function isRetryableHttpCode(int $httpCode): bool
    {
        return in_array($httpCode, [408, 409, 425, 429, 500, 502, 503, 504, 524], true);
    }
}
