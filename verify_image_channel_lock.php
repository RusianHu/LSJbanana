<?php
/**
 * 图片渠道锁回归测试。
 *
 * 不访问网络、不读取 config.php、不连接数据库。
 */

require_once __DIR__ . '/image_channel_lock.php';

$testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lsjbanana_lock_' . bin2hex(random_bytes(8));
$lockFile = $testDir . DIRECTORY_SEPARATOR . 'image.lock';
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

try {
    $first = ImageChannelLock::tryAcquire($lockFile);
    $assert($first instanceof ImageChannelLock, '第一个请求立即获得唯一渠道锁');

    $second = ImageChannelLock::tryAcquire($lockFile);
    $assert($second === null, '并发第二个请求立即失败，不进入上游');

    $first?->release();
    $third = ImageChannelLock::tryAcquire($lockFile);
    $assert($third instanceof ImageChannelLock, '前一请求结束后下一个请求可获得锁');
    $third?->release();
} catch (Throwable $e) {
    $assert(false, '锁测试不应抛出异常: ' . $e->getMessage());
} finally {
    if (is_file($lockFile)) {
        @unlink($lockFile);
    }
    if (is_dir($testDir)) {
        @rmdir($testDir);
    }
}

echo 'Tests: ' . ($passed + $failed) . ", Passed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
