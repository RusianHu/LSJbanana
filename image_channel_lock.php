<?php
/**
 * 图片上游全站独占锁。
 *
 * 使用非阻塞 flock，确保同一台服务器上同时只有一个 PHP 请求进入唯一图片渠道。
 * 锁对象释放、请求结束或 PHP 进程退出时，操作系统会自动释放文件锁。
 */
final class ImageChannelLock
{
    /** @var resource|null */
    private $handle;
    private string $path;

    /**
     * @param resource $handle
     */
    private function __construct($handle, string $path)
    {
        $this->handle = $handle;
        $this->path = $path;
    }

    /**
     * 尝试立即获取独占锁；渠道忙时返回 null，不等待、不排队。
     *
     * @throws RuntimeException 锁目录或锁文件不可用时抛出
     */
    public static function tryAcquire(?string $configuredPath = null): ?self
    {
        $path = self::resolvePath($configuredPath);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建图片渠道锁目录: ' . $directory);
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('无法打开图片渠道锁文件: ' . $path);
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        // 元数据仅用于人工诊断；是否占用始终以 flock 为准，不能根据文件内容判断。
        $metadata = json_encode([
            'pid' => getmypid(),
            'acquired_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($metadata)) {
            @ftruncate($handle, 0);
            @rewind($handle);
            @fwrite($handle, $metadata . PHP_EOL);
            @fflush($handle);
        }

        return new self($handle, $path);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        $this->release();
    }

    private static function resolvePath(?string $configuredPath): string
    {
        $path = trim((string)$configuredPath);
        if ($path === '') {
            return __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'openai_images_channel.lock';
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) === 1) {
            return $path;
        }

        return __DIR__ . DIRECTORY_SEPARATOR . ltrim($path, "\\\\/ ");
    }
}
