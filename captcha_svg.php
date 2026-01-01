<?php
/**
 * 备用验证码生成端点（不依赖GD库）
 *
 * 使用SVG格式生成验证码，无需GD库
 */

// 清除任何之前的输出
while (ob_get_level()) {
    ob_end_clean();
}

ob_start();

try {
    require_once __DIR__ . '/captcha_utils.php';

    $captcha = getCaptcha();
    $code = $captcha->generate(4);

    // 清除输出缓冲
    ob_clean();

    // 生成SVG验证码
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $width = 120;
    $height = 40;

    // 随机背景色
    $bgColors = ['#F0F8FF', '#FFFAF0', '#F5FFFA', '#FFF8F0'];
    $bgColor = $bgColors[array_rand($bgColors)];

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">';

    // 背景
    echo '<rect width="100%" height="100%" fill="' . $bgColor . '"/>';

    // 干扰线
    for ($i = 0; $i < 3; $i++) {
        $x1 = rand(0, $width);
        $y1 = rand(0, $height);
        $x2 = rand(0, $width);
        $y2 = rand(0, $height);
        $color = sprintf('#%02X%02X%02X', rand(150, 200), rand(150, 200), rand(150, 200));
        echo '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="' . $color . '" stroke-width="1"/>';
    }

    // 绘制验证码字符
    $charWidth = $width / (strlen($code) + 1);
    for ($i = 0; $i < strlen($code); $i++) {
        $x = ($i + 0.5) * $charWidth + rand(-3, 3);
        $y = ($height / 2) + 6 + rand(-3, 3);
        $angle = rand(-15, 15);
        $color = sprintf('#%02X%02X%02X', rand(20, 100), rand(20, 100), rand(20, 100));

        echo '<text x="' . $x . '" y="' . $y . '" ';
        echo 'font-family="Arial, sans-serif" font-size="22" font-weight="bold" ';
        echo 'fill="' . $color . '" ';
        echo 'transform="rotate(' . $angle . ' ' . $x . ' ' . $y . ')">';
        echo htmlspecialchars($code[$i]);
        echo '</text>';
    }

    // 干扰点
    for ($i = 0; $i < 30; $i++) {
        $cx = rand(0, $width);
        $cy = rand(0, $height);
        $color = sprintf('#%02X%02X%02X', rand(100, 200), rand(100, 200), rand(100, 200));
        echo '<circle cx="' . $cx . '" cy="' . $cy . '" r="1" fill="' . $color . '"/>';
    }

    echo '</svg>';

} catch (Exception $e) {
    ob_clean();

    // 错误SVG
    header('Content-Type: image/svg+xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40">';
    echo '<rect width="100%" height="100%" fill="#FFE0E0"/>';
    echo '<text x="10" y="20" font-family="Arial" font-size="12" fill="#C00">';
    echo 'Error: ' . htmlspecialchars(substr($e->getMessage(), 0, 10));
    echo '</text>';
    echo '</svg>';

    error_log('Captcha SVG Error: ' . $e->getMessage());
}

ob_end_flush();
