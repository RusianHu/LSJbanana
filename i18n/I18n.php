<?php
/**
 * LSJbanana 国际化 (i18n) 核心类
 * 
 * 提供轻量级的翻译机制，支持：
 * - 语言检测（URL参数 > Cookie > 浏览器偏好 > 默认语言）
 * - 参数占位符替换
 * - 嵌套键支持 (如 'auth.login')
 * - 降级处理（翻译缺失时返回 key 或默认语言文本）
 */

class I18n {
    /** @var I18n|null 单例实例 */
    private static ?I18n $instance = null;
    
    /** @var string 当前语言代码 */
    private string $locale;
    
    /** @var string 默认语言代码 */
    private string $defaultLocale = 'zh-CN';
    
    /** @var array 支持的语言列表 */
    private array $supportedLocales = ['zh-CN', 'en'];
    
    /** @var array 已加载的翻译数据 */
    private array $translations = [];
    
    /** @var array 语言显示名称 */
    private array $localeNames = [
        'zh-CN' => '简体中文',
        'en' => 'English',
    ];
    
    /** @var string Cookie 名称 */
    private const COOKIE_NAME = 'lsj_lang';
    
    /** @var int Cookie 有效期（秒，默认1年） */
    private const COOKIE_LIFETIME = 31536000;
    
    /** @var string 语言文件目录 */
    private string $langDir;
    
    /**
     * 私有构造函数（单例模式）
     */
    private function __construct() {
        $this->langDir = __DIR__ . '/lang/';
        $this->locale = $this->detectLocale();
        $this->loadTranslations($this->locale);
        
        // 如果当前语言不是默认语言，也加载默认语言作为降级
        if ($this->locale !== $this->defaultLocale) {
            $this->loadTranslations($this->defaultLocale);
        }
    }
    
    /**
     * 获取单例实例
     */
    public static function getInstance(): I18n {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 检测用户语言偏好
     * 优先级：URL参数 > Cookie > 浏览器偏好 > 默认语言
     */
    private function detectLocale(): string {
        // 1. URL 参数
        if (isset($_GET['lang']) && $this->isSupported($_GET['lang'])) {
            $locale = $this->normalizeLocale($_GET['lang']);
            $this->setLocaleCookie($locale);
            return $locale;
        }
        
        // 2. Cookie
        if (isset($_COOKIE[self::COOKIE_NAME]) && $this->isSupported($_COOKIE[self::COOKIE_NAME])) {
            return $this->normalizeLocale($_COOKIE[self::COOKIE_NAME]);
        }
        
        // 3. 浏览器偏好 (Accept-Language)
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browserLocale = $this->parseAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if ($browserLocale !== null) {
                return $browserLocale;
            }
        }
        
        // 4. 默认语言
        return $this->defaultLocale;
    }
    
    /**
     * 解析 Accept-Language 头
     */
    private function parseAcceptLanguage(string $acceptLanguage): ?string {
        $languages = [];
        
        // 解析格式：zh-CN,zh;q=0.9,en;q=0.8
        $parts = explode(',', $acceptLanguage);
        foreach ($parts as $part) {
            $part = trim($part);
            $q = 1.0;
            
            if (strpos($part, ';') !== false) {
                list($lang, $qPart) = explode(';', $part, 2);
                $lang = trim($lang);
                if (preg_match('/q=([0-9.]+)/', $qPart, $matches)) {
                    $q = (float)$matches[1];
                }
            } else {
                $lang = $part;
            }
            
            $languages[$lang] = $q;
        }
        
        // 按优先级排序
        arsort($languages);
        
        // 查找匹配的支持语言
        foreach (array_keys($languages) as $lang) {
            // 完全匹配
            if ($this->isSupported($lang)) {
                return $this->normalizeLocale($lang);
            }
            
            // 前缀匹配（如 'zh' 匹配 'zh-CN'）
            $prefix = substr($lang, 0, 2);
            foreach ($this->supportedLocales as $supported) {
                if (strpos($supported, $prefix) === 0) {
                    return $supported;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 检查语言是否支持
     */
    public function isSupported(string $locale): bool {
        $normalized = $this->normalizeLocale($locale);
        return in_array($normalized, $this->supportedLocales, true);
    }
    
    /**
     * 规范化语言代码
     */
    private function normalizeLocale(string $locale): string {
        $locale = trim($locale);
        
        // 映射常见变体
        $map = [
            'zh' => 'zh-CN',
            'zh_CN' => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'zh_cn' => 'zh-CN',
            'zh-Hans' => 'zh-CN',
            'en_US' => 'en',
            'en-US' => 'en',
            'en_GB' => 'en',
            'en-GB' => 'en',
        ];
        
        return $map[$locale] ?? $locale;
    }
    
    /**
     * 设置语言 Cookie
     */
    private function setLocaleCookie(string $locale): void {
        if (headers_sent()) {
            return;
        }
        
        setcookie(
            self::COOKIE_NAME,
            $locale,
            [
                'expires' => time() + self::COOKIE_LIFETIME,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => false, // 允许 JS 读取
                'samesite' => 'Lax'
            ]
        );
    }
    
    /**
     * 加载语言文件
     */
    private function loadTranslations(string $locale): void {
        if (isset($this->translations[$locale])) {
            return;
        }
        
        $file = $this->langDir . $locale . '.php';
        if (file_exists($file)) {
            $this->translations[$locale] = require $file;
        } else {
            $this->translations[$locale] = [];
            error_log("I18n: Language file not found: {$file}");
        }
    }
    
    /**
     * 获取翻译文本
     * 
     * @param string $key 翻译键（支持点分隔，如 'auth.login'）
     * @param array $params 替换参数（使用 :key 格式）
     * @param string|null $locale 指定语言（可选）
     * @return string 翻译后的文本
     */
    public function get(string $key, array $params = [], ?string $locale = null): string {
        $locale = $locale ?? $this->locale;
        
        // 尝试从当前语言获取
        $text = $this->getFromLocale($key, $locale);
        
        // 降级到默认语言
        if ($text === null && $locale !== $this->defaultLocale) {
            $text = $this->getFromLocale($key, $this->defaultLocale);
        }
        
        // 仍然找不到，返回 key 并记录日志
        if ($text === null) {
            error_log("I18n: Missing translation for key: {$key} in locale: {$locale}");
            return $key;
        }
        
        // 参数替换
        return $this->interpolate($text, $params);
    }
    
    /**
     * 从指定语言获取翻译
     */
    private function getFromLocale(string $key, string $locale): ?string {
        if (!isset($this->translations[$locale])) {
            $this->loadTranslations($locale);
        }
        
        $data = $this->translations[$locale];
        
        // 支持点分隔的键
        $keys = explode('.', $key);
        foreach ($keys as $k) {
            if (!is_array($data) || !isset($data[$k])) {
                return null;
            }
            $data = $data[$k];
        }
        
        return is_string($data) ? $data : null;
    }
    
    /**
     * 参数替换
     * 支持 :param 格式
     */
    private function interpolate(string $text, array $params): string {
        if (empty($params)) {
            return $text;
        }
        
        foreach ($params as $key => $value) {
            // 移除前导冒号（如果有）
            $key = ltrim($key, ':');
            $text = str_replace(':' . $key, (string)$value, $text);
        }
        
        return $text;
    }
    
    /**
     * 获取当前语言
     */
    public function getLocale(): string {
        return $this->locale;
    }
    
    /**
     * 设置当前语言
     */
    public function setLocale(string $locale): bool {
        if (!$this->isSupported($locale)) {
            return false;
        }
        
        $this->locale = $this->normalizeLocale($locale);
        $this->setLocaleCookie($this->locale);
        $this->loadTranslations($this->locale);
        
        return true;
    }
    
    /**
     * 获取支持的语言列表
     */
    public function getSupportedLocales(): array {
        return $this->supportedLocales;
    }
    
    /**
     * 获取语言显示名称
     */
    public function getLocaleName(string $locale): string {
        return $this->localeNames[$locale] ?? $locale;
    }
    
    /**
     * 获取所有语言名称
     */
    public function getAllLocaleNames(): array {
        return $this->localeNames;
    }
    
    /**
     * 检查是否为中文
     */
    public function isChinese(): bool {
        return $this->locale === 'zh-CN';
    }
    
    /**
     * 检查是否为英文
     */
    public function isEnglish(): bool {
        return $this->locale === 'en';
    }
    
    /**
     * 获取前端 JavaScript 翻译数据
     * 返回当前语言的翻译数据（用于前端）
     */
    public function getJsTranslations(): array {
        $jsFile = $this->langDir . 'js/' . $this->locale . '.json';
        if (file_exists($jsFile)) {
            $content = file_get_contents($jsFile);
            return json_decode($content, true) ?? [];
        }
        
        // 降级到默认语言
        $defaultFile = $this->langDir . 'js/' . $this->defaultLocale . '.json';
        if (file_exists($defaultFile)) {
            $content = file_get_contents($defaultFile);
            return json_decode($content, true) ?? [];
        }
        
        return [];
    }
    
    /**
     * 生成语言切换 URL
     */
    public function getSwitchUrl(string $locale): string {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
        $parsedUrl = parse_url($currentUrl);
        $path = $parsedUrl['path'] ?? '';
        $query = [];
        
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $query);
        }
        
        $query['lang'] = $locale;
        
        return $path . '?' . http_build_query($query);
    }
    
    /**
     * 输出 HTML 语言属性
     */
    public function getHtmlLang(): string {
        return $this->locale === 'zh-CN' ? 'zh-CN' : 'en';
    }
}

// ============================================================
// 全局辅助函数
// ============================================================

/**
 * 获取翻译文本
 * 
 * @param string $key 翻译键
 * @param array $params 替换参数
 * @return string 翻译后的文本
 */
function __($key, array $params = []): string {
    return I18n::getInstance()->get($key, $params);
}

/**
 * 输出翻译文本（带 HTML 转义）
 * 
 * @param string $key 翻译键
 * @param array $params 替换参数
 */
function _e($key, array $params = []): void {
    echo htmlspecialchars(I18n::getInstance()->get($key, $params), ENT_QUOTES, 'UTF-8');
}

/**
 * 输出翻译文本（不转义，用于 HTML 内容）
 * 
 * @param string $key 翻译键
 * @param array $params 替换参数
 */
function _h($key, array $params = []): void {
    echo I18n::getInstance()->get($key, $params);
}

/**
 * 获取 I18n 实例
 */
function i18n(): I18n {
    return I18n::getInstance();
}

/**
 * 获取当前语言代码
 */
function currentLocale(): string {
    return I18n::getInstance()->getLocale();
}

/**
 * 检查当前是否为中文
 */
function isZhCN(): bool {
    return I18n::getInstance()->isChinese();
}

/**
 * 检查当前是否为英文
 */
function isEn(): bool {
    return I18n::getInstance()->isEnglish();
}