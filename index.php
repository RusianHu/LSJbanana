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
    <div class="container">
        <header>
            <h1>🍌 老司机的香蕉 <small>LSJbanana</small></h1>
            <p>基于 gemini-3-pro-image (Nano Banana) 的图片生成与编辑工具</p>
        </header>

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
                        <textarea id="prompt" name="prompt" rows="4" placeholder="描述你想要生成的图片..." required></textarea>
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
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="resolution">分辨率:</label>
                            <select id="resolution" name="resolution">
                                <option value="1K">1K</option>
                                <option value="2K">2K</option>
                                <option value="4K">4K</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary"><i class="fas fa-magic"></i> 生成图片</button>
                </form>
            </section>

            <!-- 图生图/编辑面板 -->
            <section id="edit-panel" class="panel">
                <form id="edit-form">
                    <div class="form-group">
                        <label for="edit-image">上传参考图片 (支持多选，最多14张):</label>
                        <div class="file-upload-wrapper">
                            <input type="file" id="edit-image" name="image[]" accept="image/*" multiple required>
                            <div class="file-upload-preview" id="image-preview">
                                <i class="fas fa-cloud-upload-alt"></i> 点击或拖拽上传图片
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit-prompt">编辑指令 / 提示词:</label>
                        <textarea id="edit-prompt" name="prompt" rows="3" placeholder="描述如何修改这张图片，或者描述新图片..." required></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-aspect_ratio">宽高比 (可选):</label>
                            <select id="edit-aspect_ratio" name="aspect_ratio">
                                <option value="">保持原样 / 默认</option>
                                <option value="1:1">1:1</option>
                                <option value="16:9">16:9</option>
                                <option value="9:16">9:16</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit-resolution">分辨率 (可选):</label>
                            <select id="edit-resolution" name="resolution">
                                <option value="">默认 (1K)</option>
                                <option value="1K">1K</option>
                                <option value="2K">2K</option>
                                <option value="4K">4K</option>
                            </select>
                        </div>
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
            <h3 style="margin-bottom: 15px; color: #333;">为项目充电 ⚡</h3>
            <p style="color: #666; margin-bottom: 15px;">如果觉得好用，可以请作者喝杯雪王 🧋</p>
            <img src="pay.jpg" alt="Power Supply" class="sync-visualizer">
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>