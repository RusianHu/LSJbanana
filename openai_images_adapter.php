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

    public function __construct(string $message, int $httpCode = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
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

        $this->applyImageConfig($request, $payload['generationConfig']['imageConfig'] ?? []);

        $temporaryFiles = [];
        try {
            if ($sourceImages === []) {
                $response = $this->sendJsonRequest('/v1/images/generations', $request);
            } else {
                $publicImages = [];
                foreach ($sourceImages as $image) {
                    [$publicUrl, $temporaryFile] = $this->publishTemporaryImage($image);
                    $publicImages[] = ['image_url' => $publicUrl];
                    $temporaryFiles[] = $temporaryFile;
                }

                // aihub.top 实测要求 JSON images[].image_url，而不是 multipart。
                $request['images'] = $publicImages;
                $response = $this->sendJsonRequest('/v1/images/edits', $request);
            }

            return $this->convertToGeminiFormat($response);
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

    private function applyImageConfig(array &$request, mixed $imageConfig): void
    {
        if (!is_array($imageConfig)) {
            $imageConfig = [];
        }

        $aspectRatio = (string)($imageConfig['aspectRatio'] ?? '');
        $size = $this->mapAspectRatioToSize($aspectRatio);
        if ($size !== '') {
            $request['size'] = $size;
        }

        if ($this->quality !== 'auto') {
            $request['quality'] = $this->quality;
        }
    }

    private function mapAspectRatioToSize(string $aspectRatio): string
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

    private function sendJsonRequest(string $path, array $payload): array
    {
        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenAIImagesAdapterException(__('adapter.openai.error.parse_failed', ['error' => $e->getMessage()]), 500, $e);
        }

        $ch = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Expect:',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];
        if ($this->forceHttp1 && defined('CURL_HTTP_VERSION_1_1')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new OpenAIImagesAdapterException(__('adapter.openai.error.request_failed', ['error' => $error ?: 'empty response']), 502);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new OpenAIImagesAdapterException(__('adapter.openai.error.parse_failed', ['error' => json_last_error_msg()]), 502);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $data['error']['message'] ?? $data['message'] ?? __('adapter.openai.error.api_error');
            if (is_array($message)) {
                $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            throw new OpenAIImagesAdapterException(
                __('adapter.openai.error.request_failed_status', ['code' => $httpCode, 'message' => (string)$message]),
                $httpCode
            );
        }

        return $data;
    }

    private function convertToGeminiFormat(array $response): array
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

        if (!is_string($imageBytes) || strlen($imageBytes) < 16 || @getimagesizefromstring($imageBytes) === false) {
            throw new OpenAIImagesAdapterException('OpenAI 图片接口返回的图片文件无效', 502);
        }

        if ($mimeType === 'image/png' && class_exists('finfo')) {
            $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageBytes);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
                $mimeType = $detected;
            }
        }

        $parts = [];
        if (isset($item['revised_prompt']) && is_string($item['revised_prompt']) && trim($item['revised_prompt']) !== '') {
            $parts[] = ['text' => trim($item['revised_prompt'])];
        }
        $parts[] = [
            'inlineData' => [
                'mimeType' => $mimeType,
                'data' => base64_encode($imageBytes),
            ],
        ];

        return [
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => $parts,
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => $response['usage'] ?? null,
        ];
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
