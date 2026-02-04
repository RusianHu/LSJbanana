<?php
require_once __DIR__ . '/auth.php';

// 初始化错误处理
$initError = null;
$config = null;
$auth = null;

try {
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        // 在 i18n 加载之前抛出的异常使用英文
        throw new Exception('Configuration file missing: config.php. Please copy config.php.example and configure it.');
    }
    $config = require $configFile;
    require_once __DIR__ . '/i18n/I18n.php';
    $auth = getAuth();

    // 检查并自动修复核心表
    $db = Database::getInstance();
    $missingTables = $db->checkCoreTables();
    if (!empty($missingTables)) {
        $repairResult = $db->repairCoreTables();
        if (!$repairResult['success']) {
            error_log("Core tables repair failed: " . $repairResult['message']);
        }
    }
} catch (Exception $e) {
    $initError = $e->getMessage();
    error_log("Index initialization error: " . $initError);
}

// 获取用户状态
$isLoggedIn = $auth && $auth->isLoggedIn();
$currentUser = $isLoggedIn ? $auth->getCurrentUser() : null;
$billingConfig = $config ? ($config['billing'] ?? []) : [];
$pricePerTask = (float) ($billingConfig['price_per_task'] ?? $billingConfig['price_per_image'] ?? 0.20);

$supportedResolutions = $config ? ($config['image_model_supported_sizes'] ?? ['1K']) : ['1K'];
if (!is_array($supportedResolutions) || $supportedResolutions === []) {
    $supportedResolutions = ['1K'];
}

// 处理登出请求
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $auth->logout();
    renderActionPage(
        __('auth.logout_success'),
        __('auth.logout_success_desc'),
        [
            [
                'label' => __('nav.back_home'),
                'href' => url('index.php'),
                'primary' => true
            ],
            [
                'label' => __('auth.go_login'),
                'href' => url('login.php')
            ]
        ]
    );
}
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('site.title'); ?></title>
    <link rel="stylesheet" href="style.css">
    <!-- 引入一些基础图标库，例如 FontAwesome 的 CDN，或者使用简单的 SVG -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- 右上角用户操作区域 (固定定位) -->
    <div class="header-user-fixed">
        <!-- 语言切换 -->
        <div class="language-switcher">
            <a href="?lang=zh-CN" class="lang-btn<?php echo isZhCN() ? ' active' : ''; ?>" title="简体中文">CN</a>
            <span class="lang-separator"></span>
            <a href="?lang=en" class="lang-btn<?php echo isEn() ? ' active' : ''; ?>" title="English">EN</a>
        </div>

        <?php if ($isLoggedIn && $currentUser): ?>
            <div class="user-info">
                <div class="user-balance" id="user-balance-display">
                    <i class="fas fa-wallet"></i>
                    <span class="balance-amount"><?php echo number_format((float)$currentUser['balance'], 2); ?></span>
                    <span class="balance-unit"><?php _e('user.balance_unit'); ?></span>
                    <span class="balance-separator">|</span>
                    <span class="available-times"><?php
                        $availableTimes = $pricePerTask > 0 ? floor((float)$currentUser['balance'] / $pricePerTask) : 0;
                        _e('index.available_times', ['count' => $availableTimes]);
                    ?></span>
                </div>
                <div class="user-menu">
                    <button class="user-menu-trigger" id="user-menu-trigger">
                        <i class="fas fa-user-circle"></i>
                        <span><?php echo htmlspecialchars($currentUser['username']); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-dropdown" id="user-dropdown">
                        <div class="dropdown-header">
                            <div class="dropdown-username"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                            <div class="dropdown-balance">
                                <?php _e('user.balance'); ?>: <strong><?php echo number_format((float)$currentUser['balance'], 2); ?></strong> <?php _e('user.balance_unit'); ?>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="recharge.php" class="dropdown-item">
                            <i class="fas fa-coins"></i> <?php _e('nav.recharge'); ?>
                        </a>
                        <a href="change_password.php" class="dropdown-item">
                            <i class="fas fa-key"></i> <?php _e('nav.change_password'); ?>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="?action=logout" class="dropdown-item dropdown-item-danger">
                            <i class="fas fa-sign-out-alt"></i> <?php _e('nav.logout'); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="auth-buttons">
                <a href="login.php" class="btn-auth btn-login">
                    <i class="fas fa-sign-in-alt"></i> <?php _e('nav.login'); ?>
                </a>
                <a href="register.php" class="btn-auth btn-register">
                    <i class="fas fa-user-plus"></i> <?php _e('nav.register'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="container">
        <header>
            <h1><?php _e('site.title'); ?> <small><?php _e('site.subtitle'); ?></small></h1>
            <p><?php _e('site.description'); ?></p>
        </header>

        <?php if ($initError): ?>
            <!-- 显示初始化错误警告 -->
            <div style="margin: 20px 0; padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; color: #856404;">
                <h3 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-exclamation-triangle" style="color: #ff9800;"></i>
                    <?php _e('error.init_warning'); ?>
                </h3>
                <p style="margin: 0 0 10px 0;">
                    <strong>Error:</strong><?php echo htmlspecialchars($initError); ?>
                </p>
                <p style="margin: 0; font-size: 0.9em;">
                    <?php _e('error.partial_function'); ?>
                </p>
            </div>
        <?php endif; ?>

        <main>
            <div class="tabs">
                <button class="tab-btn active" data-tab="generate"><?php _e('index.tab_generate'); ?></button>
                <button class="tab-btn" data-tab="edit"><?php _e('index.tab_edit'); ?></button>
            </div>

            <!-- 文生图面板 -->
            <section id="generate-panel" class="panel active">
                <form id="generate-form">
                    <div class="form-group">
                        <label for="prompt"><?php _e('index.prompt_label'); ?></label>
                        <div class="textarea-with-voice">
                            <textarea id="prompt" name="prompt" rows="4" placeholder="<?php _e('index.prompt_placeholder'); ?>" required></textarea>
                            <button type="button" class="voice-input-btn" data-target="prompt" title="<?php _e('index.voice_input'); ?>">
                                <i class="fas fa-microphone"></i>
                            </button>
                        </div>
                        <div id="optimize-thoughts-generate" class="optimize-thoughts-container"></div>
                        <div class="prompt-optimize">
                            <div class="prompt-optimize__desc">
                                <div class="prompt-optimize__title">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                    <span><?php _e('index.prompt_optimize'); ?></span>
                                </div>
                                <p class="prompt-optimize__tip"><?php _e('index.prompt_optimize_tip'); ?></p>
                                <div class="prompt-optimize__modes" role="group" aria-label="<?php _e('index.prompt_optimize'); ?>">
                                    <button type="button" class="pill-btn active" data-optimize-mode="basic" data-optimize-group="generate"><?php _e('index.optimize_mode_enhance'); ?></button>
                                    <button type="button" class="pill-btn" data-optimize-mode="detail" data-optimize-group="generate"><?php _e('index.optimize_mode_detail'); ?></button>
                                </div>
                            </div>
                            <div class="prompt-optimize__action">
                                <button type="button" id="optimize-prompt-btn-generate" class="btn-secondary">
                                    <i class="fas fa-wand-magic-sparkles"></i> <?php _e('index.btn_optimize'); ?>
                                </button>
                                <div id="optimize-status-generate" class="optimize-status" aria-live="polite"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="aspect_ratio"><?php _e('index.aspect_ratio'); ?></label>
                            <select id="aspect_ratio" name="aspect_ratio">
                                <option value="1:1">1:1 (<?php _e('index.aspect_square'); ?>)</option>
                                <option value="16:9">16:9 (<?php _e('index.aspect_wide'); ?>)</option>
                                <option value="9:16">9:16 (<?php _e('index.aspect_tall'); ?>)</option>
                                <option value="4:3">4:3</option>
                                <option value="3:4">3:4</option>
                                <option value="2:3">2:3</option>
                                <option value="3:2">3:2</option>
                                <option value="4:5">4:5</option>
                                <option value="5:4">5:4</option>
                                <option value="21:9">21:9</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="resolution"><?php _e('index.resolution'); ?></label>
                            <select id="resolution" name="resolution">
                                <?php foreach ($supportedResolutions as $size): ?>
                                    <option value="<?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: normal;">
                            <input type="checkbox" name="use_search" style="width: auto;">
                            <span><i class="fab fa-google"></i> <?php _e('index.use_google_search'); ?> - <small style="color: #666;"><?php _e('index.use_google_search_hint'); ?></small></span>
                        </label>
                    </div>

                    <button type="submit" class="btn-primary"><i class="fas fa-magic"></i> <?php _e('index.btn_generate'); ?></button>
                    <div class="cost-hint">
                        <i class="fas fa-coins"></i>
                        <span><?php _e('index.cost_per_task', ['price' => $pricePerTask]); ?></span>
                    </div>
                </form>
            </section>

            <!-- 图生图/编辑面板 -->
            <section id="edit-panel" class="panel">
                <form id="edit-form">
                    <div class="form-group">
                        <label for="edit-image"><?php _e('index.upload_label'); ?></label>
                        <div class="file-upload-wrapper">
                            <input type="file" id="edit-image" name="image[]" accept="image/*" multiple>
                            <div class="file-upload-preview" id="image-preview">
                                <i class="fas fa-cloud-upload-alt"></i> <?php _e('index.upload_hint'); ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit-prompt"><?php _e('index.edit_prompt_label'); ?></label>
                        <div class="textarea-with-voice">
                            <textarea id="edit-prompt" name="prompt" rows="3" placeholder="<?php _e('index.edit_prompt_placeholder'); ?>" required></textarea>
                            <button type="button" class="voice-input-btn" data-target="edit-prompt" title="<?php _e('index.voice_input'); ?>">
                                <i class="fas fa-microphone"></i>
                            </button>
                        </div>
                        <div id="optimize-thoughts-edit" class="optimize-thoughts-container"></div>
                        <div class="prompt-optimize">
                            <div class="prompt-optimize__desc">
                                <div class="prompt-optimize__title">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                    <span><?php _e('index.prompt_optimize'); ?></span>
                                </div>
                                <p class="prompt-optimize__tip"><?php _e('index.prompt_optimize_tip'); ?></p>
                                <div class="prompt-optimize__modes" role="group" aria-label="<?php _e('index.prompt_optimize'); ?>">
                                    <button type="button" class="pill-btn active" data-optimize-mode="basic" data-optimize-group="edit"><?php _e('index.optimize_mode_enhance'); ?></button>
                                    <button type="button" class="pill-btn" data-optimize-mode="detail" data-optimize-group="edit"><?php _e('index.optimize_mode_detail'); ?></button>
                                </div>
                            </div>
                            <div class="prompt-optimize__action">
                                <button type="button" id="optimize-prompt-btn" class="btn-secondary">
                                    <i class="fas fa-wand-magic-sparkles"></i> <?php _e('index.btn_optimize'); ?>
                                </button>
                                <div id="optimize-status" class="optimize-status" aria-live="polite"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-aspect_ratio"><?php _e('index.aspect_ratio'); ?></label>
                            <select id="edit-aspect_ratio" name="aspect_ratio">
                                <option value=""><?php _e('index.aspect_keep'); ?></option>
                                <option value="1:1">1:1</option>
                                <option value="16:9">16:9</option>
                                <option value="9:16">9:16</option>
                                <option value="4:3">4:3</option>
                                <option value="3:4">3:4</option>
                                <option value="2:3">2:3</option>
                                <option value="3:2">3:2</option>
                                <option value="4:5">4:5</option>
                                <option value="5:4">5:4</option>
                                <option value="21:9">21:9</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit-resolution"><?php _e('index.resolution'); ?></label>
                            <select id="edit-resolution" name="resolution">
                                <option value=""><?php _e('index.resolution_default'); ?></option>
                                <?php foreach ($supportedResolutions as $size): ?>
                                    <option value="<?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: normal;">
                            <input type="checkbox" name="use_search" style="width: auto;">
                            <span><i class="fab fa-google"></i> <?php _e('index.use_google_search'); ?></span>
                        </label>
                    </div>

                    <button type="submit" class="btn-primary"><i class="fas fa-paint-brush"></i> <?php _e('index.btn_edit'); ?></button>
                    <div class="cost-hint">
                        <i class="fas fa-coins"></i>
                        <span><?php _e('index.cost_per_task', ['price' => $pricePerTask]); ?></span>
                    </div>
                </form>
            </section>

            <!-- 结果展示区域 -->
            <section id="result-area" class="hidden">
                <h2><?php _e('index.result_title'); ?></h2>
                <div id="loading" class="hidden">
                    <div class="spinner"></div>
                    <p><?php _e('index.generating'); ?></p>
                    <p id="timer" style="font-weight: bold; color: #666; margin-top: 10px;"><?php _e('index.elapsed_time', ['time' => '0.00']); ?></p>
                </div>
                <div id="error-message" class="hidden error-box"></div>
                
                <div id="output-container">
                    <!-- 图片和文本将在这里动态插入 -->
                </div>
            </section>
        </main>

        <footer>
            <p><?php _e('site.copyright', ['year' => date('Y')]); ?></p>
        </footer>
    </div>

    <!-- GitHub Repository Link - Cyber Banana Badge -->
    <a href="https://github.com/RusianHu/LSJbanana"
       target="_blank"
       rel="noopener noreferrer"
       class="github-badge"
       title="<?php _e('site.github_title'); ?>">
        <div class="github-badge__glow"></div>
        <div class="github-badge__content">
            <i class="fab fa-github"></i>
            <span class="github-badge__text"><?php _e('site.github_text'); ?></span>
        </div>
        <div class="github-badge__stripe"></div>
    </a>

    <!-- Floating Trigger Button (Obfuscated) -->
    <a href="#" id="data-sync-trigger" class="x-float-btn" title="<?php _e('site.sponsor_title'); ?>">
        <i class="fas fa-gift"></i>
        <span class="x-float-btn__text"><?php _e('site.sponsor_text'); ?></span>
    </a>

    <!-- Obfuscated Support Modal -->
    <div id="data-sync-modal" class="x-modal-hidden">
        <div class="x-modal-content">
            <span class="x-close-btn">&times;</span>
            <h3 style="margin-bottom: 15px; color: #333;"><?php _e('site.sponsor_title'); ?></h3>
            <p style="color: #666; margin-bottom: 15px;"><?php _e('site.sponsor_desc'); ?></p>
            <img src="pay.jpg" alt="Power Supply" class="sync-visualizer">
        </div>
    </div>

    <!-- 图片预览模态框 (Lightbox) -->
    <div id="image-preview-overlay" class="image-preview-overlay">
        <div class="preview-toolbar">
            <div class="preview-toolbar-left">
                <button class="preview-btn close-btn" id="preview-close" title="<?php _e('index.lightbox_close'); ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="preview-toolbar-center">
                <button class="preview-btn" id="preview-zoom-out" title="<?php _e('index.lightbox_zoom_out'); ?>">
                    <i class="fas fa-search-minus"></i>
                </button>
                <span class="preview-zoom-info" id="preview-zoom-level">100%</span>
                <button class="preview-btn" id="preview-zoom-in" title="<?php _e('index.lightbox_zoom_in'); ?>">
                    <i class="fas fa-search-plus"></i>
                </button>
                <button class="preview-btn" id="preview-zoom-fit" title="<?php _e('index.lightbox_fit'); ?>">
                    <i class="fas fa-expand"></i>
                </button>
                <button class="preview-btn" id="preview-zoom-actual" title="<?php _e('index.lightbox_actual'); ?>">
                    <i class="fas fa-compress-arrows-alt"></i>
                </button>
            </div>
            <div class="preview-toolbar-right">
                <button class="preview-btn" id="preview-download" title="<?php _e('index.lightbox_download'); ?>">
                    <i class="fas fa-download"></i>
                </button>
                <button class="preview-btn" id="preview-help" title="<?php _e('index.lightbox_help'); ?>">
                    <i class="fas fa-keyboard"></i>
                </button>
            </div>
        </div>
        <div class="preview-image-container" id="preview-container">
            <img id="preview-image" src="" alt="Preview">
        </div>
        <div class="preview-info-bar">
            <span class="preview-image-info" id="preview-image-info"></span>
        </div>
        <div class="preview-shortcuts-hint" id="preview-shortcuts">
            <?php _e('index.lightbox_shortcuts'); ?>
        </div>
    </div>

    <!-- 用户状态数据 (供 JavaScript 使用) -->
    <script>
        window.LSJ_USER = {
            loggedIn: <?php echo $isLoggedIn ? 'true' : 'false'; ?>,
            username: <?php echo $isLoggedIn ? json_encode($currentUser['username']) : 'null'; ?>,
            balance: <?php echo $isLoggedIn ? (float)$currentUser['balance'] : 'null'; ?>,
            pricePerTask: <?php echo $pricePerTask; ?>,
            pricePerImage: <?php echo $pricePerTask; ?>
        };
        // 传递当前语言给 JS
        window.LSJ_LANG = '<?php echo currentLocale(); ?>';
    </script>
    <script src="i18n/i18n.js"></script>
    <script src="script.js"></script>
</body>
</html>
