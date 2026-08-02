<?php
/**
 * OpenAI Images API 适配器。
 *
 * 将项目内部的 Gemini generateContent 请求转换为 OpenAI Images API：
 * - 文生图：POST /v1/images/generations
 * - 图片编辑：POST /v1/images/edits
 *
 * 部分 OpenAI 兼容中转站的编辑接口要求 JSON 格式的
 * images[].image_url，而不是官方 multipart 上传。适配器会将用户上传的
 * 图片短暂发布到不可预测的公开 URL，请求结束后立即清理。
 */

require_once __DIR__ . '/i18n/I18n.php';

class OpenAIImagesAdapterException extends Exception
{
    private int $httpCode;
    private string $errorType;

    public function __construct(
        string $message,
        int $httpCode = 500,
        ?Throwable $previous = null,
        string $errorType = ''
    )
    {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
        $this->errorType = $errorType;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }
}

class OpenAIImagesAdapter
{
    private string $baseUrl;
    private string $apiKey;
    private string $publicBaseUrl;
    private string $temporaryImageDir;
    private string $temporaryPublicPath;
    private int $timeout;
    private int $connectTimeout;
    private int $downloadTimeout;
    private int $maxDownloadBytes;
    private string $quality;
    private bool $verifySsl;
    private bool $forceHttp1;
    private bool $logResponseErrors;
    private bool $verifyOutputSize;
    private int $responsePreviewBytes;
    private bool $gatewayTimeoutFallbackEnabled;
    private string $gatewayTimeoutFallbackTier;
    private int $highResolutionTimeout;
    private int $gatewayTimeoutFallbackTimeout;
    /** @var array<int,int> */
    private array $gatewayTimeoutHttpCodes;

    public function __construct(array $config)
    {
        $provider = is_array($config['openai_images'] ?? null) ? $config['openai_images'] : [];

        $this->baseUrl = rtrim((string)($provider['base_url'] ?? ''), '/');
        $this->apiKey = (string)($provider['api_key'] ?? '');
        $this->publicBaseUrl = rtrim((string)($provider['public_base_url'] ?? ''), '/');
        $this->timeout = max(30, (int)($provider['timeout'] ?? 420));
        $this->connectTimeout = max(5, (int)($provider['connect_timeout'] ?? 30));
        $this->downloadTimeout = max(10, (int)($provider['download_timeout'] ?? 120));
        $this->maxDownloadBytes = max(1024 * 1024, (int)($provider['max_download_bytes'] ?? 32 * 1024 * 1024));
        $this->verifySsl = (bool)($provider['verify_ssl'] ?? true);
        $this->forceHttp1 = (bool)($provider['force_http1'] ?? true);
        $this->logResponseErrors = (bool)($provider['log_response_errors'] ?? true);
        $this->verifyOutputSize = array_key_exists('verify_output_size', $provider)
            ? (bool)$provider['verify_output_size']
            : true;
        $this->responsePreviewBytes = max(0, min(2048, (int)($provider['response_preview_bytes'] ?? 256)));
        $this->gatewayTimeoutFallbackEnabled = array_key_exists('gateway_timeout_fallback_enabled', $provider)
            ? (bool)$provider['gateway_timeout_fallback_enabled']
            : true;
        $fallbackTier = strtoupper(trim((string)($provider['gateway_timeout_fallback_tier'] ?? '1K')));
        $this->gatewayTimeoutFallbackTier = $fallbackTier === '1K' ? $fallbackTier : '1K';
        $this->highResolutionTimeout = max(
            30,
            min($this->timeout, (int)($provider['high_resolution_timeout'] ?? 110))
        );
        $this->gatewayTimeoutFallbackTimeout = max(
            30,
            min($this->timeout, (int)($provider['gateway_timeout_fallback_timeout'] ?? 60))
        );
        $configuredGatewayCodes = $provider['gateway_timeout_http_codes'] ?? [502, 504, 524];
        $this->gatewayTimeoutHttpCodes = array_values(array_unique(array_filter(
            array_map('intval', is_array($configuredGatewayCodes) ? $configuredGatewayCodes : []),
            static fn(int $code): bool => $code >= 500 && $code <= 599
        )));
        if ($this->gatewayTimeoutHttpCodes === []) {
            $this->gatewayTimeoutHttpCodes = [502, 504, 524];
        }

        $quality = strtolower((string)($provider['quality'] ?? 'low'));
        $this->quality = in_array($quality, ['auto', 'low', 'medium', 'high'], true) ? $quality : 'low';

        $configuredDir = (string)($provider['temporary_image_dir'] ?? ($config['output_dir'] ?? 'images/'));
        $this->temporaryImageDir = $this->resolveProjectPath($configuredDir);
        $this->temporaryPublicPath = trim((string)($provider['temporary_public_path'] ?? 'images'), '/');
    }

    public function isAvailable(): bool
    {
        if ($this->baseUrl === '' || $this->apiKey === '' || $this->publicBaseUrl === '') {
            return false;
        }

        $scheme = strtolower((string)parse_url($this->publicBaseUrl, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * 将 Gemini 风格请求转换为 OpenAI Images 请求，并返回 Gemini 风格响应。
     */
    public function generateContent(string $modelName, array $payload): array
    {
        [$prompt, $sourceImages] = $this->extractPromptAndImages($payload);
        if ($prompt === '') {
            throw new OpenAIImagesAdapterException('OpenAI 图片请求缺少提示词', 400);
        }

        $request = [
            'model' => $modelName,
            'prompt' => $prompt,
        ];

        $imageConfig = $payload['generationConfig']['imageConfig'] ?? [];
        $expectedDimensions = $this->applyImageConfig(
            $request,
            $modelName,
            $imageConfig
        );
        $requestedTier = is_array($imageConfig)
            ? strtoupper(trim((string)($imageConfig['imageSize'] ?? '')))
            : '';
        $requestedAspectRatio = is_array($imageConfig)
            ? trim((string)($imageConfig['aspectRatio'] ?? ''))
            : '';

        $temporaryFiles = [];
        try {
            if ($sourceImages === []) {
                $path = '/v1/images/generations';
            } else {
                $publicImages = [];
                foreach ($sourceImages as $image) {
                    [$publicUrl, $temporaryFile] = $this->publishTemporaryImage($image);
                    $publicImages[] = ['image_url' => $publicUrl];
                    $temporaryFiles[] = $temporaryFile;
                }

                // aihub.top 实测要求 JSON images[].image_url，而不是 multipart。
                $request['images'] = $publicImages;
                $path = '/v1/images/edits';
            }

            [$response, $effectiveDimensions, $fallbackInfo] = $this->sendWithGatewayTimeoutFallback(
                $path,
                $request,
                $modelName,
                $requestedAspectRatio,
                $requestedTier,
                $expectedDimensions
            );

            return $this->convertToGeminiFormat($response, $effectiveDimensions, $fallbackInfo);
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }
    }

    private function resolveProjectPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return __DIR__ . DIRECTORY_SEPARATOR . 'images';
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path)) {
            return rtrim($path, "\\/ ");
        }

        return __DIR__ . DIRECTORY_SEPARATOR . trim($path, "\\/ ");
    }

    /**
     * @return array{0:string,1:array<int,array{mime_type:string,data:string}>}
     */
    private function extractPromptAndImages(array $payload): array
    {
        $textParts = [];
        $images = [];

        foreach (($payload['contents'] ?? []) as $content) {
            if (!is_array($content)) {
                continue;
            }

            foreach (($content['parts'] ?? []) as $part) {
                if (!is_array($part)) {
                    continue;
                }

                if (isset($part['text']) && is_string($part['text'])) {
                    $text = trim($part['text']);
                    if ($text !== '') {
                        $textParts[] = $text;
                    }
                }

                $inline = null;
                if (isset($part['inline_data']) && is_array($part['inline_data'])) {
                    $inline = $part['inline_data'];
                } elseif (isset($part['inlineData']) && is_array($part['inlineData'])) {
                    $inline = $part['inlineData'];
                }

                if ($inline !== null) {
                    $mimeType = (string)($inline['mime_type'] ?? $inline['mimeType'] ?? '');
                    $data = (string)($inline['data'] ?? '');
                    if ($data !== '') {
                        $images[] = [
                            'mime_type' => $mimeType,
                            'data' => $data,
                        ];
                    }
                }
            }
        }

        return [trim(implode("\n", $textParts)), $images];
    }

    /**
     * @return array{width:int,height:int}|null
     */
    private function applyImageConfig(array &$request, string $modelName, mixed $imageConfig): ?array
    {
        if (!is_array($imageConfig)) {
            $imageConfig = [];
        }

        $aspectRatio = trim((string)($imageConfig['aspectRatio'] ?? ''));
        $imageSize = strtoupper(trim((string)($imageConfig['imageSize'] ?? '')));

        $size = $this->mapAspectRatioToSize($aspectRatio, $imageSize, $modelName);
        if ($size !== '') {
            // OpenAI Images uses a WIDTHxHEIGHT string. `2K` and `aspect_ratio`
            // are not official Images API request fields; sub2api may silently
            // ignore them on its OAuth/Codex route.
            $request['size'] = $size;
        }

        if ($this->quality !== 'auto') {
            $request['quality'] = $this->quality;
        }

        return $this->parseDimensions($size);
    }

    /**
     * 高分辨率图片在 Cloudflare 类网关后可能先于模型完成而超时。
     * 对明确的网关超时，仅降级到 1K 重试一次，并把降级信息返回给前端。
     *
     * @param array<string,mixed> $request
     * @param array{width:int,height:int}|null $expectedDimensions
     * @return array{0:array,1:array{width:int,height:int}|null,2:array<string,string>|null}
     */
    private function sendWithGatewayTimeoutFallback(
        string $path,
        array $request,
        string $modelName,
        string $aspectRatio,
        string $requestedTier,
        ?array $expectedDimensions
    ): array {
        $primaryTimeout = $this->shouldBoundHighResolutionRequest($requestedTier)
            ? $this->highResolutionTimeout
            : null;

        try {
            return [
                $this->sendJsonRequest($path, $request, $primaryTimeout),
                $expectedDimensions,
                null,
            ];
        } catch (OpenAIImagesAdapterException $primaryError) {
            if (!$this->shouldUseGatewayTimeoutFallback($primaryError, $requestedTier)) {
                throw $primaryError;
            }

            $fallbackSize = $this->mapAspectRatioToSize(
                $aspectRatio,
                $this->gatewayTimeoutFallbackTier,
                $modelName
            );
            $primarySize = trim((string)($request['size'] ?? ''));
            if ($fallbackSize === '' || $fallbackSize === $primarySize) {
                throw $primaryError;
            }

            $fallbackRequest = $request;
            $fallbackRequest['size'] = $fallbackSize;
            $fallbackDimensions = $this->parseDimensions($fallbackSize);

            error_log('[OpenAIImagesAdapter] High-resolution request timed out; retrying once at lower resolution: '
                . json_encode([
                    'path' => $path,
                    'model' => $modelName,
                    'requested_tier' => $requestedTier,
                    'requested_size' => $primarySize,
                    'fallback_tier' => $this->gatewayTimeoutFallbackTier,
                    'fallback_size' => $fallbackSize,
                    'primary_http_code' => $primaryError->getHttpCode(),
                    'primary_error_type' => $primaryError->getErrorType(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            try {
                $response = $this->sendJsonRequest(
                    $path,
                    $fallbackRequest,
                    $this->gatewayTimeoutFallbackTimeout
                );
            } catch (OpenAIImagesAdapterException $fallbackError) {
                error_log('[OpenAIImagesAdapter] Lower-resolution gateway fallback failed: '
                    . json_encode([
                        'path' => $path,
                        'model' => $modelName,
                        'requested_size' => $primarySize,
                        'fallback_size' => $fallbackSize,
                        'fallback_http_code' => $fallbackError->getHttpCode(),
                        'fallback_error_type' => $fallbackError->getErrorType(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                throw new OpenAIImagesAdapterException(
                    __('adapter.openai_images.error.gateway_timeout_fallback_failed', [
                        'actual' => $fallbackSize,
                        'message' => $fallbackError->getMessage(),
                    ]),
                    $fallbackError->getHttpCode(),
                    $fallbackError,
                    $fallbackError->getErrorType()
                );
            }

            return [
                $response,
                $fallbackDimensions,
                [
                    'reason' => 'gateway_timeout',
                    'requested_tier' => $requestedTier,
                    'requested_size' => $primarySize,
                    'actual_tier' => $this->gatewayTimeoutFallbackTier,
                    'actual_size' => $fallbackSize,
                ],
            ];
        }
    }

    private function shouldBoundHighResolutionRequest(string $requestedTier): bool
    {
        return $this->gatewayTimeoutFallbackEnabled
            && in_array($requestedTier, ['2K', '4K'], true);
    }

    private function shouldUseGatewayTimeoutFallback(
        OpenAIImagesAdapterException $error,
        string $requestedTier
    ): bool {
        if (!$this->shouldBoundHighResolutionRequest($requestedTier)) {
            return false;
        }

        if (in_array($error->getErrorType(), ['timeout', 'gateway_timeout'], true)) {
            return true;
        }

        return in_array($error->getHttpCode(), $this->gatewayTimeoutHttpCodes, true)
            && preg_match('/(?:timed?\s*out|timeout|HTTP\s*(?:502|504|524))/i', $error->getMessage()) === 1;
    }

    private function mapAspectRatioToSize(string $aspectRatio, string $imageSize, string $modelName = ''): string
    {
        if (!$this->isSupportedAspectRatio($aspectRatio)) {
            return '';
        }

        // Keep the old OpenAI Images compatibility sizes for older models.
        // gpt-image-2 supports arbitrary dimensions, which is required to
        // preserve both the selected tier and the selected aspect ratio.
        if (!$this->supportsArbitraryImageSize($modelName)) {
            return $this->mapLegacyAspectRatioToSize($aspectRatio);
        }

        $tier = in_array($imageSize, ['1K', '2K', '4K'], true) ? $imageSize : '1K';
        [$ratioWidth, $ratioHeight] = array_map('intval', explode(':', $aspectRatio, 2));
        if ($ratioWidth <= 0 || $ratioHeight <= 0) {
            return '';
        }
        $gcd = $this->greatestCommonDivisor($ratioWidth, $ratioHeight);
        $ratioWidth = intdiv($ratioWidth, $gcd);
        $ratioHeight = intdiv($ratioHeight, $gcd);

        // OpenAI's arbitrary image sizes require dimensions divisible by 16.
        // The tier controls the target long edge; the 4K tier is additionally
        // bounded by the documented 3840x2160 / ~8.29MP ceiling.
        $baseWidth = $ratioWidth * 16;
        $baseHeight = $ratioHeight * 16;
        $baseLongEdge = max($baseWidth, $baseHeight);
        $tierLongEdge = ['1K' => 1024, '2K' => 2048, '4K' => 3840][$tier];
        $maxPixels = 8_294_400;
        $minPixels = 655_360;

        $multiplier = max(1, intdiv($tierLongEdge, $baseLongEdge));
        $minMultiplier = (int)ceil(sqrt($minPixels / ($baseWidth * $baseHeight)));
        $maxMultiplier = (int)floor(sqrt($maxPixels / ($baseWidth * $baseHeight)));
        $multiplier = max($multiplier, $minMultiplier);
        if ($maxMultiplier < 1) {
            return '';
        }
        $multiplier = min($multiplier, $maxMultiplier);

        $width = $baseWidth * $multiplier;
        $height = $baseHeight * $multiplier;
        if ($width * $height < $minPixels || $width > 3840 || $height > 3840) {
            return '';
        }

        return $width . 'x' . $height;
    }

    private function mapLegacyAspectRatioToSize(string $aspectRatio): string
    {
        $portrait = ['2:3', '3:4', '4:5', '9:16'];
        $landscape = ['3:2', '4:3', '5:4', '16:9', '21:9'];

        if (in_array($aspectRatio, $portrait, true)) {
            return '1024x1536';
        }
        if (in_array($aspectRatio, $landscape, true)) {
            return '1536x1024';
        }
        if ($aspectRatio === '1:1') {
            return '1024x1024';
        }

        return '';
    }

    private function supportsArbitraryImageSize(string $modelName): bool
    {
        return str_starts_with(strtolower(trim($modelName)), 'gpt-image-2');
    }

    private function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            $remainder = $a % $b;
            $a = $b;
            $b = $remainder;
        }

        return max(1, abs($a));
    }

    /**
     * @return array{width:int,height:int}|null
     */
    private function parseDimensions(string $size): ?array
    {
        if (!preg_match('/^(\d+)x(\d+)$/', trim($size), $matches)) {
            return null;
        }

        $width = (int)$matches[1];
        $height = (int)$matches[2];
        return $width > 0 && $height > 0 ? ['width' => $width, 'height' => $height] : null;
    }

    private function isSupportedAspectRatio(string $aspectRatio): bool
    {
        return in_array($aspectRatio, ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'], true);
    }

    /**
     * @param array{mime_type:string,data:string} $image
     * @return array{0:string,1:string}
     */
    private function publishTemporaryImage(array $image): array
    {
        $mimeType = strtolower(trim((string)($image['mime_type'] ?? '')));
        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mimeType])) {
            throw new OpenAIImagesAdapterException('OpenAI 图片编辑不支持该输入图片格式', 400);
        }

        $bytes = base64_decode((string)($image['data'] ?? ''), true);
        if ($bytes === false || strlen($bytes) < 16 || @getimagesizefromstring($bytes) === false) {
            throw new OpenAIImagesAdapterException('OpenAI 图片编辑输入图片无效', 400);
        }

        if (!is_dir($this->temporaryImageDir) && !@mkdir($this->temporaryImageDir, 0755, true) && !is_dir($this->temporaryImageDir)) {
            throw new OpenAIImagesAdapterException('无法创建 OpenAI 图片编辑临时目录', 500);
        }
        if (!is_writable($this->temporaryImageDir)) {
            throw new OpenAIImagesAdapterException('OpenAI 图片编辑临时目录不可写', 500);
        }

        try {
            $token = bin2hex(random_bytes(20));
        } catch (Throwable $e) {
            throw new OpenAIImagesAdapterException('无法生成安全的临时图片名称', 500, $e);
        }

        $fileName = 'openai_input_' . $token . '.' . $extensions[$mimeType];
        $filePath = $this->temporaryImageDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($filePath, $bytes, LOCK_EX) === false) {
            throw new OpenAIImagesAdapterException('无法写入 OpenAI 图片编辑临时文件', 500);
        }
        @chmod($filePath, 0644);

        $publicPath = $this->temporaryPublicPath === ''
            ? rawurlencode($fileName)
            : $this->temporaryPublicPath . '/' . rawurlencode($fileName);

        return [$this->publicBaseUrl . '/' . $publicPath, $filePath];
    }

    protected function sendJsonRequest(string $path, array $payload, ?int $timeoutOverride = null): array
    {
        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenAIImagesAdapterException(
                __('adapter.openai_images.error.request_encoding_failed', ['error' => $e->getMessage()]),
                500,
                $e
            );
        }

        $clientRequestId = $this->createClientRequestId();
        $responseHeaders = [];
        $requestTimeout = $timeoutOverride === null
            ? $this->timeout
            : max(1, min($this->timeout, $timeoutOverride));
        $startedAt = microtime(true);

        // 普通图片 POST 不做同参数重试，避免网关超时后重复生成和重复计费。
        // 唯一例外是在上层明确启用的高分辨率 -> 1K 有界降级，两次请求参数不同且会记录日志。
        $ch = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Expect:',
                'X-Client-Request-Id: ' . $clientRequestId,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $lineLength = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return $lineLength;
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $name = strtolower(trim($name));
                if (in_array($name, [
                    'content-type',
                    'content-length',
                    'content-encoding',
                    'transfer-encoding',
                    'server',
                    'x-request-id',
                    'cf-ray',
                    'retry-after',
                ], true)) {
                    $responseHeaders[$name] = trim($value);
                }

                return $lineLength;
            },
        ];
        if ($this->forceHttp1 && defined('CURL_HTTP_VERSION_1_1')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($contentType === '') {
            $contentType = (string)($responseHeaders['content-type'] ?? '');
        }
        $error = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        curl_close($ch);

        if ($response === false || $error !== '') {
            $errorMessage = $error !== '' ? $error : 'empty response';
            $this->logInvalidResponse(
                $path,
                $httpCode,
                $contentType,
                $responseHeaders,
                is_string($response) ? $response : '',
                $errorMessage,
                $clientRequestId,
                $elapsedMs,
                $curlErrno
            );
            throw new OpenAIImagesAdapterException(
                __('adapter.openai_images.error.request_failed', [
                    'error' => $errorMessage,
                    'trace' => $this->formatTraceSuffix($responseHeaders, $clientRequestId),
                ]),
                502,
                null,
                $curlErrno === 28 ? 'timeout' : 'transport'
            );
        }

        return $this->interpretHttpResponse(
            $path,
            $response,
            $httpCode,
            $contentType,
            $responseHeaders,
            $clientRequestId,
            $elapsedMs
        );
    }

    private function interpretHttpResponse(
        string $path,
        string $response,
        int $httpCode,
        string $contentType,
        array $responseHeaders,
        string $clientRequestId,
        int $elapsedMs = 0
    ): array {
        $data = null;
        $decodeError = null;

        try {
            $data = $this->decodeResponseBody($response, $contentType);
        } catch (JsonException $e) {
            $decodeError = $e->getMessage();
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            if ($this->isJsonObject($data)) {
                $message = $this->extractUpstreamErrorMessage($data);
                throw new OpenAIImagesAdapterException(
                    __('adapter.openai_images.error.request_failed_status', [
                        'code' => $httpCode,
                        'message' => $message,
                        'trace' => $this->formatTraceSuffix($responseHeaders, $clientRequestId),
                    ]),
                    $this->normalizeHttpCode($httpCode),
                    null,
                    $this->classifyHttpErrorType($httpCode)
                );
            }

            $this->logInvalidResponse(
                $path,
                $httpCode,
                $contentType,
                $responseHeaders,
                $response,
                $decodeError ?? 'JSON root is not an object',
                $clientRequestId,
                $elapsedMs
            );
            throw new OpenAIImagesAdapterException(
                __('adapter.openai_images.error.non_json_status', [
                    'code' => $httpCode,
                    'content_type' => $this->normalizeContentType($contentType),
                    'bytes' => strlen($response),
                    'trace' => $this->formatTraceSuffix($responseHeaders, $clientRequestId),
                ]),
                $this->normalizeHttpCode($httpCode),
                null,
                $this->classifyHttpErrorType($httpCode)
            );
        }

        if (!$this->isJsonObject($data)) {
            $failureReason = $decodeError ?? 'JSON root is not an object';
            $this->logInvalidResponse(
                $path,
                $httpCode,
                $contentType,
                $responseHeaders,
                $response,
                $failureReason,
                $clientRequestId,
                $elapsedMs
            );

            $translationKey = $decodeError === null
                ? 'adapter.openai_images.error.invalid_shape'
                : 'adapter.openai_images.error.invalid_json';
            throw new OpenAIImagesAdapterException(
                __($translationKey, [
                    'code' => $httpCode,
                    'content_type' => $this->normalizeContentType($contentType),
                    'bytes' => strlen($response),
                    'error' => $failureReason,
                    'trace' => $this->formatTraceSuffix($responseHeaders, $clientRequestId),
                ]),
                502
            );
        }

        return $data;
    }

    private function decodeResponseBody(string $response, string $contentType): mixed
    {
        $normalized = trim($response);
        if (str_starts_with($normalized, "\xEF\xBB\xBF")) {
            $normalized = substr($normalized, 3);
        }

        try {
            return json_decode($normalized, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            $sseData = $this->decodeSingleSseResponse($normalized, $contentType);
            if ($sseData !== null) {
                return $sseData;
            }
            throw $jsonException;
        }
    }

    private function decodeSingleSseResponse(string $response, string $contentType): mixed
    {
        $isEventStream = str_contains(strtolower($contentType), 'text/event-stream')
            || preg_match('/^(?:event:[^\r\n]*\R)?data:/mi', $response) === 1;
        if (!$isEventStream) {
            return null;
        }

        $decodedEvents = [];
        $eventBlocks = preg_split('/\R{2,}/', trim($response)) ?: [];
        foreach ($eventBlocks as $eventBlock) {
            $dataLines = [];
            foreach (preg_split('/\R/', $eventBlock) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }

            if ($dataLines === []) {
                continue;
            }

            $eventData = trim(implode("\n", $dataLines));
            if ($eventData === '' || $eventData === '[DONE]') {
                continue;
            }

            try {
                $decodedEvents[] = json_decode($eventData, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        if (count($decodedEvents) === 1) {
            return $decodedEvents[0];
        }

        for ($i = count($decodedEvents) - 1; $i >= 0; $i--) {
            $event = $decodedEvents[$i];
            if ($this->isJsonObject($event) && (isset($event['data']) || isset($event['error']))) {
                return $event;
            }
        }

        return null;
    }

    private function isJsonObject(mixed $data): bool
    {
        return is_array($data) && $data !== [] && !array_is_list($data);
    }

    private function extractUpstreamErrorMessage(array $data): string
    {
        $message = $data['error']['message'] ?? $data['message'] ?? __('adapter.openai_images.error.api_error');
        if (is_array($message) || is_object($message)) {
            $encoded = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : __('adapter.openai_images.error.api_error');
        }

        $message = trim((string)$message);
        return $message !== '' ? $message : __('adapter.openai_images.error.api_error');
    }

    private function normalizeHttpCode(int $httpCode): int
    {
        return $httpCode >= 400 && $httpCode <= 599 ? $httpCode : 502;
    }

    private function classifyHttpErrorType(int $httpCode): string
    {
        return in_array($httpCode, $this->gatewayTimeoutHttpCodes, true)
            ? 'gateway_timeout'
            : 'http';
    }

    private function normalizeContentType(string $contentType): string
    {
        $contentType = trim($contentType);
        return $contentType !== '' ? $contentType : 'unknown';
    }

    private function createClientRequestId(): string
    {
        try {
            return 'lsjbanana-' . bin2hex(random_bytes(12));
        } catch (Throwable) {
            return 'lsjbanana-' . str_replace('.', '', uniqid('', true));
        }
    }

    private function formatTraceSuffix(array $responseHeaders, string $clientRequestId): string
    {
        $traceParts = [];
        foreach (['x-request-id' => 'request', 'cf-ray' => 'cf'] as $header => $label) {
            $value = trim((string)($responseHeaders[$header] ?? ''));
            if ($value !== '') {
                $traceParts[] = $label . '=' . $value;
            }
        }
        $traceParts[] = 'client=' . $clientRequestId;

        return __('adapter.openai_images.error.trace_suffix', ['id' => implode(', ', $traceParts)]);
    }

    private function logInvalidResponse(
        string $path,
        int $httpCode,
        string $contentType,
        array $responseHeaders,
        string $response,
        string $failureReason,
        string $clientRequestId,
        int $elapsedMs = 0,
        int $curlErrno = 0
    ): void {
        if (!$this->logResponseErrors) {
            return;
        }

        $context = [
            'path' => $path,
            'http_code' => $httpCode,
            'content_type' => $this->normalizeContentType($contentType),
            'response_bytes' => strlen($response),
            'response_sha256' => hash('sha256', $response),
            'failure_reason' => $failureReason,
            'x_request_id' => $responseHeaders['x-request-id'] ?? null,
            'cf_ray' => $responseHeaders['cf-ray'] ?? null,
            'server' => $responseHeaders['server'] ?? null,
            'retry_after' => $responseHeaders['retry-after'] ?? null,
            'client_request_id' => $clientRequestId,
            'elapsed_ms' => $elapsedMs,
            'curl_errno' => $curlErrno,
        ];

        if ($this->responsePreviewBytes > 0 && $response !== '') {
            $preview = substr($response, 0, $this->responsePreviewBytes);
            $preview = html_entity_decode(strip_tags($preview), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $preview = preg_replace('/\s+/u', ' ', $preview) ?? '';
            $preview = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{8,}|AIza[A-Za-z0-9_-]{12,})\b/u', '[redacted]', $preview) ?? '';
            $context['body_preview'] = trim($preview);
        }

        error_log(
            '[OpenAIImagesAdapter] Invalid upstream response: '
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    /**
     * @param array{width:int,height:int}|null $expectedDimensions
     */
    private function convertToGeminiFormat(
        array $response,
        ?array $expectedDimensions = null,
        ?array $fallbackInfo = null
    ): array
    {
        $item = $response['data'][0] ?? null;
        if (!is_array($item)) {
            throw new OpenAIImagesAdapterException('OpenAI 图片接口未返回图片数据', 502);
        }

        $mimeType = 'image/png';
        $imageBytes = null;
        $base64 = $item['b64_json'] ?? null;
        if (is_string($base64) && $base64 !== '') {
            $imageBytes = base64_decode($base64, true);
            if ($imageBytes === false) {
                throw new OpenAIImagesAdapterException('OpenAI 图片接口返回了无效的 base64 图片', 502);
            }
        } else {
            $url = $item['url'] ?? null;
            if (!is_string($url) || $url === '') {
                throw new OpenAIImagesAdapterException('OpenAI 图片接口未返回可用的图片 URL', 502);
            }
            [$imageBytes, $mimeType] = $this->downloadImage($url);
        }

        $detectedDimensions = is_string($imageBytes) ? @getimagesizefromstring($imageBytes) : false;
        if (!is_string($imageBytes) || strlen($imageBytes) < 16 || $detectedDimensions === false) {
            throw new OpenAIImagesAdapterException('OpenAI 图片接口返回的图片文件无效', 502);
        }

        if ($this->verifyOutputSize && $expectedDimensions !== null) {
            $actualWidth = (int)($detectedDimensions[0] ?? 0);
            $actualHeight = (int)($detectedDimensions[1] ?? 0);
            if (
                $actualWidth !== $expectedDimensions['width']
                || $actualHeight !== $expectedDimensions['height']
            ) {
                throw new OpenAIImagesAdapterException(
                    __('adapter.openai_images.error.output_size_mismatch', [
                        'expected' => $expectedDimensions['width'] . 'x' . $expectedDimensions['height'],
                        'actual' => $actualWidth . 'x' . $actualHeight,
                    ]),
                    502
                );
            }
        }

        if ($mimeType === 'image/png' && class_exists('finfo')) {
            $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageBytes);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mimeType = $detected;
            }
        }

        $parts = [];
        if ($fallbackInfo !== null) {
            $parts[] = [
                'text' => __('adapter.openai_images.warning.gateway_timeout_fallback', [
                    'requested' => $fallbackInfo['requested_size'] ?? '',
                    'actual' => $fallbackInfo['actual_size'] ?? '',
                ]),
            ];
        }
        if (isset($item['revised_prompt']) && is_string($item['revised_prompt']) && trim($item['revised_prompt']) !== '') {
            $parts[] = ['text' => trim($item['revised_prompt'])];
        }
        $parts[] = [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data' => base64_encode($imageBytes),
            ],
        ];

        $result = [
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => $parts,
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => $response['usage'] ?? null,
        ];

        if ($fallbackInfo !== null) {
            $result['_lsjbanana'] = [
                'resolution_fallback' => $fallbackInfo,
            ];
        }

        return $result;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function downloadImage(string $url): array
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new OpenAIImagesAdapterException('OpenAI 图片接口返回了不安全的图片 URL', 502);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $this->downloadTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];
        if ($this->forceHttp1 && defined('CURL_HTTP_VERSION_1_1')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        curl_setopt_array($ch, $options);

        $bytes = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($bytes === false || $error !== '') {
            throw new OpenAIImagesAdapterException(__('adapter.openai.error.request_failed', ['error' => $error ?: 'image download failed']), 502);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new OpenAIImagesAdapterException('OpenAI 图片下载失败 (' . $httpCode . ')', 502);
        }
        if (strlen($bytes) > $this->maxDownloadBytes) {
            throw new OpenAIImagesAdapterException('OpenAI 图片响应超过允许大小', 502);
        }

        $mimeType = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if (class_exists('finfo')) {
            $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (is_string($detected) && $detected !== '') {
                $mimeType = strtolower($detected);
            }
        }
        if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new OpenAIImagesAdapterException('OpenAI 图片接口返回了不支持的文件格式', 502);
        }

        return [$bytes, $mimeType];
    }
}
