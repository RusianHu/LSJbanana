<?php
/**
 * 验证码工具类
 *
 * 提供简单的图片验证码生成和验证功能
 */

class CaptchaUtils {
    private array $config;

    /**
     * 构造函数
     */
    public function __construct(?array $config = null) {
        if ($config === null) {
            $configFile = __DIR__ . '/config.php';
            if (!file_exists($configFile)) {
                throw new Exception('配置文件不存在：config.php。请复制 config.php.example 并根据环境配置。');
            }
            try {
                $fullConfig = require $configFile;
            } catch (Throwable $e) {
                throw new Exception('配置文件加载失败：' . $e->getMessage());
            }
            $config = $fullConfig['captcha'] ?? [];
        }
        $this->config = $config;
    }

    /**
     * 生成验证码并保存到会话
     *
     * @param int $length 验证码长度
     * @return string 生成的验证码文本
     */
    public function generate(int $length = 4): string {
        // 启动会话（如果尚未启动）
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 生成随机验证码
        $chars = $this->config['chars'] ?? '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        $charsLen = strlen($chars);

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $charsLen - 1)];
        }

        // 保存到会话
        $_SESSION['captcha_code'] = $code;
        $_SESSION['captcha_time'] = time();

        return $code;
    }

    /**
     * 验证用户输入的验证码
     *
     * @param string $userInput 用户输入的验证码
     * @return bool 验证是否通过
     */
    public function verify(string $userInput): bool {
        // 启动会话（如果尚未启动）
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 检查是否存在验证码
        if (!isset($_SESSION['captcha_code'])) {
            return false;
        }

        // 检查验证码是否过期
        $expireTime = $this->config['expire_time'] ?? 300; // 默认5分钟
        if (isset($_SESSION['captcha_time']) && (time() - $_SESSION['captcha_time']) > $expireTime) {
            $this->clear();
            return false;
        }

        // 不区分大小写比较
        $isValid = strtoupper($userInput) === strtoupper($_SESSION['captcha_code']);

        // 验证成功或失败都清除验证码（一次性）
        $this->clear();

        return $isValid;
    }

    /**
     * 清除会话中的验证码
     */
    public function clear(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION['captcha_code']);
        unset($_SESSION['captcha_time']);
    }

    /**
     * 生成验证码图片
     *
     * @param string $code 验证码文本
     */
    public function renderImage(string $code): void {
        $width = $this->config['width'] ?? 120;
        $height = $this->config['height'] ?? 40;
        $fontSize = $this->config['font_size'] ?? 18;

        // 创建图像
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('无法创建验证码图像');
        }

        // 设置背景色（浅色）
        $bgColors = [
            imagecolorallocate($image, 240, 248, 255), // AliceBlue
            imagecolorallocate($image, 255, 250, 240), // FloralWhite
            imagecolorallocate($image, 245, 255, 250), // MintCream
            imagecolorallocate($image, 255, 248, 240), // OldLace
        ];
        $bgColor = $bgColors[array_rand($bgColors)];
        imagefill($image, 0, 0, $bgColor);

        // 添加干扰线
        $this->addNoise($image, $width, $height);

        // 绘制验证码文本
        $codeLen = strlen($code);
        $charWidth = $width / ($codeLen + 1);

        for ($i = 0; $i < $codeLen; $i++) {
            // 随机颜色（深色）
            $textColor = imagecolorallocate(
                $image,
                random_int(20, 100),
                random_int(20, 100),
                random_int(20, 100)
            );

            // 随机角度
            $angle = random_int(-15, 15);

            // 计算位置
            $x = ($i + 0.5) * $charWidth + random_int(-3, 3);
            $y = ($height / 2) + ($fontSize / 2) + random_int(-3, 3);

            // 绘制字符（使用内置字体）
            imagestring($image, 5, (int)$x - 5, (int)$y - 20, $code[$i], $textColor);
        }

        // 添加干扰点
        $this->addDots($image, $width, $height);

        // 输出图像
        header('Content-Type: image/png');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        imagepng($image);
        imagedestroy($image);
    }

    /**
     * 添加干扰线
     */
    private function addNoise($image, int $width, int $height): void {
        $lineCount = $this->config['noise_lines'] ?? 3;

        for ($i = 0; $i < $lineCount; $i++) {
            $color = imagecolorallocate(
                $image,
                random_int(150, 200),
                random_int(150, 200),
                random_int(150, 200)
            );

            imageline(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $color
            );
        }
    }

    /**
     * 添加干扰点
     */
    private function addDots($image, int $width, int $height): void {
        $dotCount = $this->config['noise_dots'] ?? 50;

        for ($i = 0; $i < $dotCount; $i++) {
            $color = imagecolorallocate(
                $image,
                random_int(100, 200),
                random_int(100, 200),
                random_int(100, 200)
            );

            imagesetpixel(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                $color
            );
        }
    }

    /**
     * 检查验证码功能是否启用
     */
    public function isEnabled(): bool {
        return $this->config['enable_login'] ?? false || $this->config['enable_register'] ?? false;
    }

    /**
     * 检查登录验证码是否启用
     */
    public function isLoginEnabled(): bool {
        return $this->config['enable_login'] ?? false;
    }

    /**
     * 检查注册验证码是否启用
     */
    public function isRegisterEnabled(): bool {
        return $this->config['enable_register'] ?? false;
    }
}

/**
 * 获取全局 CaptchaUtils 实例
 */
function getCaptcha(): CaptchaUtils {
    static $captcha = null;
    if ($captcha === null) {
        $captcha = new CaptchaUtils();
    }
    return $captcha;
}
