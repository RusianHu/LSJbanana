<?php
/**
 * 安全工具类
 * 
 * 这个类包含了一系列用于增强应用程序安全性的函数，
 * 包括输入验证、命令行参数转义等功能。
 *
 * 用法示例:
 * 
 * // 引入 SecurityUtils 类 (如果尚未自动加载)
 * // require_once 'path/to/security_utils.php';
 * 
 * // 1. 安全地转义命令行参数
 * $safe_arg = SecurityUtils::escapeShellArgument("user_input; rm -rf /");
 * // $safe_arg 将是类似 "'user_input; rm -rf /'"
 * 
 * // 2. 安全地转义shell命令
 * $safe_command = SecurityUtils::escapeShellCommand("ls -la; echo 'hacked'");
 * // $safe_command 将是类似 "ls -la\; echo \'hacked\'"
 * 
 * // 3. 验证并过滤整数输入
 * $age = SecurityUtils::filterInteger($_GET['age'], 1, 120, 18); // 允许1-120岁，无效则默认为18
 * 
 * // 4. 验证并过滤字符串输入（只允许字母和数字）
 * $username = SecurityUtils::filterAlphanumeric($_POST['username'], 'guest');
 * 
 * // 5. 验证并过滤URL参数
 * $query_param = SecurityUtils::filterUrlParam("some value with spaces & special chars");
 * // $query_param 将是 "some+value+with+spaces+%26+special+chars"
 * 
 * // 6. 安全地处理HTML内容（防止XSS攻击）
 * $safe_html = SecurityUtils::sanitizeHtml("<script>alert('XSS')</script>Some text");
 * // $safe_html 将是 "&lt;script&gt;alert('XSS')&lt;/script&gt;Some text"
 * 
 * // 7. 生成安全的随机令牌
 * $token = SecurityUtils::generateSecureToken(64); // 生成一个64字符长度的令牌
 */

require_once __DIR__ . '/i18n/I18n.php';

class SecurityUtils {
    /**
     * 安全地转义命令行参数
     * 
     * 使用PHP的escapeshellarg函数将字符串转义为可以在shell命令中安全使用的参数
     * 
     * @param string $arg 需要转义的参数
     * @return string 转义后的参数
     */
    public static function escapeShellArgument($arg) {
        if (!is_string($arg)) {
            throw new \InvalidArgumentException('Argument must be a string.');
        }
        return escapeshellarg($arg);
    }
    
    /**
     * 安全地转义shell命令
     *
     * 使用PHP的escapeshellcmd函数对shell元字符进行转义
     *
     * @param string $command 需要转义的命令
     * @return string 转义后的命令
     * @throws \InvalidArgumentException 如果输入不是字符串
     */
    public static function escapeShellCommand($command) {
        if (!is_string($command)) {
            throw new \InvalidArgumentException('Command must be a string.');
        }
        return escapeshellcmd($command);
    }
    
    /**
     * 验证并过滤整数输入
     *
     * @param mixed $input 输入值
     * @param int|null $min 最小允许值 (可选)
     * @param int|null $max 最大允许值 (可选)
     * @param int $default 默认值（如果输入无效或超出范围）
     * @return int 过滤后的整数
     */
    public static function filterInteger($input, $min = null, $max = null, $default = 0) {
        $options = ['options' => ['default' => $default]];
        
        if ($min !== null) {
            // 确保 $min 是整数，以防外部传入非预期类型
            if (!is_int($min)) {
                 throw new \InvalidArgumentException('Minimum value must be an integer or null.');
            }
            $options['options']['min_range'] = $min;
        }
        
        if ($max !== null) {
            // 确保 $max 是整数
            if (!is_int($max)) {
                throw new \InvalidArgumentException('Maximum value must be an integer or null.');
            }
            $options['options']['max_range'] = $max;
        }
        
        // 如果 $min > $max，filter_var 行为可能不符合预期，这里可以加一个检查
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException('Minimum value cannot be greater than maximum value.');
        }

        $filtered = filter_var($input, FILTER_VALIDATE_INT, $options);

        // filter_var 在 min_range/max_range 验证失败时会返回 default
        // 但如果 $input 本身就不是一个有效的整数形式 (例如 "abc")，它也会返回 default
        // 如果需要区分这两种情况，则需要更复杂的逻辑，但当前实现符合“无效则返回默认”
        return $filtered;
    }
    
    /**
     * 验证并过滤字符串输入（只允许字母和数字）
     *
     * @param mixed $input 输入值
     * @param string $default 默认值（如果过滤后为空字符串）
     * @return string 过滤后的字符串
     * @throws \InvalidArgumentException 如果输入不是字符串
     */
    public static function filterAlphanumeric($input, $default = '') {
        if (!is_string($input)) {
            throw new \InvalidArgumentException('Input must be a string.');
        }
        
        // 使用 \p{L} 匹配任何语言的字母, \p{N} 匹配任何语言的数字，u 修饰符用于UTF-8
        $filtered = preg_replace('/[^\p{L}\p{N}]/u', '', $input);
        return $filtered !== '' ? $filtered : $default;
    }
    
    /**
     * 验证并过滤URL参数
     *
     * @param mixed $input 输入值
     * @return string 过滤后的URL参数 (已编码)
     * @throws \InvalidArgumentException 如果输入不是字符串
     */
    public static function filterUrlParam($input) { // 移除了 $default 参数，因为无效输入会抛出异常
        if (!is_string($input)) {
            throw new \InvalidArgumentException('Input must be a string.');
        }
        
        return urlencode($input);
    }
    
    /**
     * 安全地处理HTML内容（防止XSS攻击）
     *
     * 注意: 此方法使用 htmlspecialchars 进行基础的XSS防护，适用于将用户输入作为纯文本显示。
     * 如果需要允许用户输入一部分安全的HTML标签和属性（例如富文本编辑器），
     * 建议使用更强大的HTML清理库，如 HTML Purifier。
     * @param string $input HTML内容
     * @return string 过滤后的HTML
     */
    public static function sanitizeHtml($input) {
        if (!is_string($input)) {
            return '';
        }
        
        return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * 生成安全的随机令牌
     *
     * @param int $length 期望的十六进制字符串令牌长度 (例如 32, 64)
     * @return string 生成的随机令牌 (十六进制格式)
     * @throws \Exception 如果无法生成安全的随机字节
     */
    public static function generateSecureToken($length = 32) {
        if ($length <= 0 || $length % 2 !== 0) {
            throw new \InvalidArgumentException(__('error.invalid_token_length'));
        }
        $bytesNeeded = $length / 2;

        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes($bytesNeeded));
            } catch (\Exception $e) {
                // 如果 random_bytes 失败 (例如，源不可用)，则尝试下一个方法
            }
        }
        
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($bytesNeeded, $strong);
            if ($strong === true && $bytes !== false) {
                return bin2hex($bytes);
            }
        }
        
        // 如果以上方法都失败，则抛出异常
        throw new \Exception(__('error.secure_token_failed'));
    }

    /**
     * 清理普通文本输入，移除控制字符并限制长度
     *
     * @param mixed $input 用户输入
     * @param int $maxLength 最大长度（0 表示不限制）
     * @return string 清理后的字符串
     */
    public static function sanitizeTextInput($input, $maxLength = 4000) {
        if (!is_string($input)) {
            return '';
        }

        $value = trim($input);
        // 去掉常见控制字符，避免注入意外的不可见字符 (保留换行符 \n 和回车符 \r)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value);
        // 压缩连续空白 (但保留单个换行符)
        // 先统一换行符为 \n
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // 压缩连续的空格和制表符,但不压缩换行符
        $value = preg_replace('/[ \t]{2,}/u', ' ', $value);
        // 压缩连续的换行符(超过2个换行符压缩为2个)
        $value = preg_replace('/\n{3,}/u', "\n\n", $value);

        if ($maxLength > 0) {
            if (function_exists('mb_substr')) {
                $value = mb_substr($value, 0, $maxLength, 'UTF-8');
            } else {
                $value = substr($value, 0, $maxLength);
            }
        }

        return $value;
    }

    /**
     * 校验是否为允许的取值
     *
     * @param mixed $input 输入值
     * @param array $allowedValues 允许列表
     * @param mixed $default 不在列表时返回的默认值
     * @return mixed
     */
    public static function validateAllowedValue($input, array $allowedValues, $default = null) {
        if (!is_string($input)) {
            return $default;
        }

        $value = trim($input);
        return in_array($value, $allowedValues, true) ? $value : $default;
    }

    /**
     * 校验上传的图片文件是否合法
     *
     * @param array $file 单个文件的数组结构
     * @param array $allowedMimeTypes 允许的 MIME 类型
     * @param int $maxSizeBytes 最大字节数
     * @return array 包含 mime_type 和 tmp_name
     * @throws \RuntimeException 校验失败时抛出
     */
    public static function validateUploadedImage(array $file, array $allowedMimeTypes = ['image/png', 'image/jpeg', 'image/webp'], $maxSizeBytes = 8388608) {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(__('error.upload_failed'));
        }

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(__('error.invalid_upload_source'));
        }

        $size = isset($file['size']) ? (int)$file['size'] : 0;
        if ($size <= 0 || $size > $maxSizeBytes) {
            throw new \RuntimeException(__('error.file_too_large'));
        }

        $mimeType = $file['type'] ?? '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $file['tmp_name']);
                if ($detected !== false) {
                    $mimeType = $detected;
                }
                finfo_close($finfo);
            }
        }

        if ($mimeType === '' || !in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \RuntimeException(__('error.unsupported_image_format'));
        }

        return [
            'mime_type' => $mimeType,
            'tmp_name' => $file['tmp_name'],
        ];
    }
}

/**
 * 获取项目的 URL 基础路径
 *
 * 自动检测项目相对于域名根目录的路径前缀
 * 例如：部署在 https://example.com/LSJbanana/ 时返回 "/LSJbanana"
 *       部署在 https://example.com/ 时返回 ""
 *
 * @return string 基础路径（不含尾部斜杠）
 */
function getBasePath(): string {
    static $basePath = null;
    
    if ($basePath !== null) {
        return $basePath;
    }
    
    // 获取当前脚本相对于文档根目录的路径
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // 项目根目录的标志文件（config.php 在项目根目录）
    $projectRoot = dirname(__DIR__ . '/../');
    
    // 从 SCRIPT_NAME 中提取基础路径
    // 例如: /LSJbanana/admin/login.php -> /LSJbanana
    // 例如: /admin/login.php -> ""
    
    // 计算当前文件相对于项目根目录的深度
    $currentFile = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    $projectRootDir = realpath(__DIR__);
    
    if ($projectRootDir === false) {
        $basePath = '';
        return $basePath;
    }
    
    // 计算脚本相对于项目根目录的相对路径
    $currentFileReal = realpath($currentFile);
    if ($currentFileReal === false) {
        $basePath = '';
        return $basePath;
    }
    
    // 获取当前脚本相对于项目根目录的部分
    $relativePath = '';
    if (strpos($currentFileReal, $projectRootDir) === 0) {
        $relativePath = substr($currentFileReal, strlen($projectRootDir));
        $relativePath = str_replace('\\', '/', $relativePath);
    }
    
    // 从 SCRIPT_NAME 中移除相对路径部分，得到基础路径
    if ($relativePath !== '' && $scriptName !== '') {
        $pos = strrpos($scriptName, $relativePath);
        if ($pos !== false) {
            $basePath = substr($scriptName, 0, $pos);
            $basePath = rtrim($basePath, '/');
            return $basePath;
        }
    }
    
    // 备选方案：从 SCRIPT_NAME 直接获取目录部分，并尝试识别项目目录
    $scriptDir = dirname($scriptName);
    if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
        $basePath = '';
    } else {
        // 检查是否在 admin 子目录中
        if (preg_match('#^(.*/)?admin$#', $scriptDir, $matches)) {
            $basePath = rtrim($matches[1] ?? '', '/');
        } else {
            $basePath = $scriptDir;
        }
    }
    
    return $basePath;
}

/**
 * 生成相对于项目根目录的 URL
 *
 * @param string $path 相对于项目根目录的路径（以 / 开头）
 * @return string 完整的 URL 路径
 */
function url(string $path): string {
    $base = getBasePath();
    // 确保路径以 / 开头
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . $path;
    }
    return $base . $path;
}

/**
 * 渲染简单的提示页面并终止执行
 *
 * @param string $title 页面标题
 * @param string $message 提示内容
 * @param array $actions 操作按钮列表
 * @param int $httpStatus HTTP 状态码
 */
function renderActionPage(string $title, string $message, array $actions = [], int $httpStatus = 200): void {
    if ($httpStatus >= 400) {
        http_response_code($httpStatus);
    }

    header('Content-Type: text/html; charset=utf-8');

    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $messageSafe = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $styleHref = htmlspecialchars(url('/style.css'), ENT_QUOTES, 'UTF-8');
    $lang = i18n()->getHtmlLang();

    if (empty($actions)) {
        $actions = [
            [
                'label' => __('nav.back_home'),
                'href' => url('index.php'),
                'primary' => true
            ]
        ];
    }

    $actionsHtml = '';
    foreach ($actions as $action) {
        $label = htmlspecialchars($action['label'] ?? __('auth.continue'), ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($action['href'] ?? '#', ENT_QUOTES, 'UTF-8');
        $primary = !empty($action['primary']);
        $newTab = !empty($action['new_tab']);
        $className = $primary ? 'btn-primary' : 'btn-secondary';
        $targetAttr = $newTab ? ' target="_blank" rel="noopener"' : '';
        $actionsHtml .= '<a class="' . $className . '" href="' . $href . '"' . $targetAttr . '>' . $label . '</a>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$titleSafe}</title>
    <link rel="stylesheet" href="{$styleHref}">
    <style>
        .action-page {
            max-width: 520px;
            margin: 80px auto;
            padding: 0 20px;
        }
        .action-card {
            background: var(--panel-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 36px 32px;
            text-align: center;
        }
        .action-title {
            font-size: 1.6rem;
            margin-bottom: 12px;
            color: #333;
        }
        .action-message {
            color: #666;
            line-height: 1.6;
        }
        .action-links {
            margin-top: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }
        .action-links a {
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: auto;
            padding: 10px 18px;
        }
        .action-links .btn-primary,
        .action-links .btn-secondary {
            width: auto;
        }
    </style>
</head>
<body>
    <div class="action-page">
        <div class="action-card">
            <div class="action-title">{$titleSafe}</div>
            <div class="action-message">{$messageSafe}</div>
            <div class="action-links">{$actionsHtml}</div>
        </div>
    </div>
</body>
</html>
HTML;

    exit;
}
