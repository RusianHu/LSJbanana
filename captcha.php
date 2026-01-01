<?php
/**
 * 验证码图片生成端点
 *
 * 生成验证码图片并输出
 */

// 清除任何之前的输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

// 开启新的输出缓冲
ob_start();

// 开启错误报告（调试用）
error_reporting(E_ALL);
ini_set('display_errors', 0); // 不直接显示错误，避免破坏图片输出

try {
    // 检查GD库是否可用
    if (!extension_loaded('gd')) {
        throw new RuntimeException('GD library is not installed');
    }

    require_once __DIR__ . '/captcha_utils.php';

    $captcha = getCaptcha();

    // 生成验证码
    $code = $captcha->generate(4);

    // 清除之前的输出
    ob_clean();

    // 渲染图片
    $captcha->renderImage($code);

} catch (Exception $e) {
    // 清除之前的输出
    ob_clean();

    // 如果出错，输出一个简单的错误图片
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(120, 40);
    if ($img !== false) {
        $bgColor = imagecolorallocate($img, 255, 200, 200);
        $textColor = imagecolorallocate($img, 200, 0, 0);
        imagefill($img, 0, 0, $bgColor);
        imagestring($img, 2, 5, 12, 'Error: GD', $textColor);
        imagestring($img, 1, 5, 25, substr($e->getMessage(), 0, 20), $textColor);
        imagepng($img);
        imagedestroy($img);
    }

    // 记录错误到日志
    error_log('Captcha Error: ' . $e->getMessage());
}

// 刷新输出缓冲
ob_end_flush();
