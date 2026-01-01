<?php
require_once __DIR__ . '/auth.php';

// 初始化错误处理
$initError = null;
$config = null;
$auth = null;

try {
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        throw new Exception('配置文件不存在：config.php。请复制 config.php.example 并根据环境配置。');
    }
    $config = require $configFile;
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
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>老司机的香蕉</title>
    <link rel="stylesheet" href="style.css">
    <!-- 引入一些基础图标库，例如 FontAwesome 的 CDN，或者使用简单的 SVG -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- 右上角用户操作区域 (固定定位) -->
    <div class="header-user-fixed">
        <?php if ($isLoggedIn && $currentUser): ?>
            <div class="user-info">
                <div class="user-balance" id="user-balance-display">
                    <i class="fas fa-wallet"></i>
                    <span class="balance-amount"><?php echo number_format((float)$currentUser['balance'], 2); ?></span>
                    <span class="balance-unit">元</span>
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
                                余额: <strong><?php echo number_format((float)$currentUser['balance'], 2); ?></strong> 元
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="recharge.php" class="dropdown-item">
                            <i class="fas fa-coins"></i> 充值
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="?action=logout" class="dropdown-item dropdown-item-danger">
                            <i class="fas fa-sign-out-alt"></i> 退出登录
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="auth-buttons">
                <a href="login.php" class="btn-auth btn-login">
                    <i class="fas fa-sign-in-alt"></i> 登录
                </a>
                <a href="register.php" class="btn-auth btn-register">
                    <i class="fas fa-user-plus"></i> 注册
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="container">
        <header>
            <h1>老司机的香蕉 <small>LSJbanana</small></h1>
            <p>基于 gemini-3-pro-image (Nano Banana) 的图片生成与编辑工具</p>
        </header>

        <?php if ($initError): ?>
            <!-- 显示初始化错误警告 -->
            <div style="margin: 20px 0; padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; color: #856404;">
                <h3 style="margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-exclamation-triangle" style="color: #ff9800;"></i>
                    系统初始化警告
                </h3>
                <p style="margin: 0 0 10px 0;">
                    <strong>错误：</strong><?php echo htmlspecialchars($initError); ?>
                </p>
                <p style="margin: 0; font-size: 0.9em;">
                    部分功能可能无法正常使用。请检查配置文件和数据库设置。
                </p>
            </div>
        <?php endif; ?>

        <main>
            <div class="tabs">
                <button class="tab-btn active" data-tab="generate">文生图 (Generate)</button>
                <button class="tab-btn" data-tab="edit">图生图/编辑 (Edit)</button>
            </div>

            <!-- 文生图面板 -->
            <section id="generate-panel" class="panel active">
                <form id="generate-form">
                    <div class="form-group">
                        <label for="prompt">提示词 (Prompt):</label>
                        <div class="textarea-with-voice">
                            <textarea id="prompt" name="prompt" rows="4" placeholder="描述你想要生成的图片..." required></textarea>
                            <button type="button" class="voice-input-btn" data-target="prompt" title="语音输入">
                                <i class="fas fa-microphone"></i>
                            </button>
                        </div>
                        <div id="optimize-thoughts-generate" class="optimize-thoughts-container"></div>
                        <div class="prompt-optimize">
                            <div class="prompt-optimize__desc">
                                <div class="prompt-optimize__title">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                    <span>提示词优化</span>
                                </div>
                                <p class="prompt-optimize__tip">调用 gemini-2.5-flash 自动扩写提示词，生成前先润色或加强细节。</p>
                                <div class="prompt-optimize__modes" role="group" aria-label="提示词优化模式">
                                    <button type="button" class="pill-btn active" data-optimize-mode="basic" data-optimize-group="generate">增强模式</button>
                                    <button type="button" class="pill-btn" data-optimize-mode="detail" data-optimize-group="generate">细节模式</button>
                                </div>
                            </div>
                            <div class="prompt-optimize__action">
                                <button type="button" id="optimize-prompt-btn-generate" class="btn-secondary">
                                    <i class="fas fa-wand-magic-sparkles"></i> 一键优化
                                </button>
                                <div id="optimize-status-generate" class="optimize-status" aria-live="polite"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="aspect_ratio">宽高比:</label>
                            <select id="aspect_ratio" name="aspect_ratio">
                                <option value="1:1">1:1 (正方形)</option>
                                <option value="16:9">16:9 (宽屏)</option>
                                <option value="9:16">9:16 (竖屏)</option>
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
                            <label for="resolution">分辨率:</label>
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
                            <span><i class="fab fa-google"></i> 使用 Google 搜索增强 (Grounding) - <small style="color: #666;">基于实时数据生成</small></span>
                        </label>
                    </div>

                    <button type="submit" class="btn-primary"><i class="fas fa-magic"></i> 生成图片</button>
                </form>
            </section>

            <!-- 图生图/编辑面板 -->
            <section id="edit-panel" class="panel">
                <form id="edit-form">
	                    <div class="form-group">
	                        <label for="edit-image">上传参考图片 (支持多选/多次添加，最多14张):</label>
	                        <div class="file-upload-wrapper">
	                            <input type="file" id="edit-image" name="image[]" accept="image/*" multiple>
	                            <div class="file-upload-preview" id="image-preview">
                                <i class="fas fa-cloud-upload-alt"></i> 点击或拖拽上传图片
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit-prompt">编辑指令 / 提示词:</label>
                        <div class="textarea-with-voice">
                            <textarea id="edit-prompt" name="prompt" rows="3" placeholder="描述如何修改这张图片，或者描述新图片..." required></textarea>
                            <button type="button" class="voice-input-btn" data-target="edit-prompt" title="语音输入">
                                <i class="fas fa-microphone"></i>
                            </button>
                        </div>
                        <div id="optimize-thoughts-edit" class="optimize-thoughts-container"></div>
                        <div class="prompt-optimize">
                            <div class="prompt-optimize__desc">
                                <div class="prompt-optimize__title">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                    <span>提示词优化</span>
                                </div>
                                <p class="prompt-optimize__tip">调用 gemini-2.5-flash 自动扩写提示词，可在生成前快速润色或精细化。</p>
                                <div class="prompt-optimize__modes" role="group" aria-label="提示词优化模式">
                                    <button type="button" class="pill-btn active" data-optimize-mode="basic" data-optimize-group="edit">增强模式</button>
                                    <button type="button" class="pill-btn" data-optimize-mode="detail" data-optimize-group="edit">细节模式</button>
                                </div>
                            </div>
                            <div class="prompt-optimize__action">
                                <button type="button" id="optimize-prompt-btn" class="btn-secondary">
                                    <i class="fas fa-wand-magic-sparkles"></i> 一键优化
                                </button>
                                <div id="optimize-status" class="optimize-status" aria-live="polite"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-aspect_ratio">宽高比 (可选):</label>
                            <select id="edit-aspect_ratio" name="aspect_ratio">
                                <option value="">保持原样 / 默认</option>
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
                            <label for="edit-resolution">分辨率 (可选):</label>
                            <select id="edit-resolution" name="resolution">
                                <option value="">默认 (1K)</option>
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
                            <span><i class="fab fa-google"></i> 使用 Google 搜索增强 (Grounding)</span>
                        </label>
                    </div>

                    <button type="submit" class="btn-primary"><i class="fas fa-paint-brush"></i> 开始编辑</button>
                </form>
            </section>

            <!-- 结果展示区域 -->
            <section id="result-area" class="hidden">
                <h2>生成结果</h2>
                <div id="loading" class="hidden">
                    <div class="spinner"></div>
                    <p>正在疯狂生成中，请稍候...</p>
                    <p id="timer" style="font-weight: bold; color: #666; margin-top: 10px;">已耗时: 0.00 s</p>
                </div>
                <div id="error-message" class="hidden error-box"></div>
                
                <div id="output-container">
                    <!-- 图片和文本将在这里动态插入 -->
                </div>
            </section>
        </main>

        <footer>
            <p>&copy; <?php echo date('Y'); ?> LSJbanana Project. Powered by Gemini.</p>
        </footer>
    </div>

    <!-- Floating Trigger Button (Obfuscated) -->
    <a href="#" id="data-sync-trigger" class="x-float-btn" title="为项目充电">
        <i class="fas fa-gift"></i>
    </a>

    <!-- Obfuscated Support Modal -->
    <div id="data-sync-modal" class="x-modal-hidden">
        <div class="x-modal-content">
            <span class="x-close-btn">&times;</span>
            <h3 style="margin-bottom: 15px; color: #333;">为项目充电</h3>
            <p style="color: #666; margin-bottom: 15px;">如果觉得好用，可以请老司机喝杯雪王</p>
            <img src="pay.jpg" alt="Power Supply" class="sync-visualizer">
        </div>
    </div>

    <!-- 图片预览模态框 (Lightbox) -->
    <div id="image-preview-overlay" class="image-preview-overlay">
        <div class="preview-toolbar">
            <div class="preview-toolbar-left">
                <button class="preview-btn close-btn" id="preview-close" title="关闭 (Esc)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="preview-toolbar-center">
                <button class="preview-btn" id="preview-zoom-out" title="缩小 (-)">
                    <i class="fas fa-search-minus"></i>
                </button>
                <span class="preview-zoom-info" id="preview-zoom-level">100%</span>
                <button class="preview-btn" id="preview-zoom-in" title="放大 (+)">
                    <i class="fas fa-search-plus"></i>
                </button>
                <button class="preview-btn" id="preview-zoom-fit" title="适应窗口 (F)">
                    <i class="fas fa-expand"></i>
                </button>
                <button class="preview-btn" id="preview-zoom-actual" title="原始大小 (1)">
                    <i class="fas fa-compress-arrows-alt"></i>
                </button>
            </div>
            <div class="preview-toolbar-right">
                <button class="preview-btn" id="preview-download" title="下载图片 (D)">
                    <i class="fas fa-download"></i>
                </button>
                <button class="preview-btn" id="preview-help" title="快捷键帮助 (?)">
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
            <kbd>Esc</kbd> 关闭 |
            <kbd>+</kbd><kbd>-</kbd> 缩放 |
            <kbd>1</kbd> 原始大小 |
            <kbd>F</kbd> 适应窗口 |
            <kbd>D</kbd> 下载 |
            鼠标滚轮缩放 | 拖拽移动
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
    </script>
    <script src="script.js"></script>
</body>
</html>
