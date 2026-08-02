// 等待 i18n 就绪后再初始化
const IMAGE_RESOLUTION_TIERS = Object.freeze([
    Object.freeze({minDimension: 3840, label: '4K', className: 'resolution-4k', descriptionKey: 'resolution.4k'}),
    Object.freeze({minDimension: 2000, label: '2K', className: 'resolution-2k', descriptionKey: 'resolution.2k'}),
    Object.freeze({minDimension: 1000, label: '1K', className: 'resolution-1k', descriptionKey: 'resolution.1k'}),
    Object.freeze({minDimension: 0, label: 'SD', className: 'resolution-low', descriptionKey: 'resolution.sd'})
]);

/**
 * 根据图片实际像素尺寸判断显示档位。
 *
 * 图片接口的 1K / 2K / 4K 是近似档位，不同宽高比的精确长边并不相同，
 * 因此这里按最长边自高到低匹配，并兼容常见的 3840 像素 4K 输出。
 */
function classifyImageResolution(width, height) {
    const normalizedWidth = Number(width);
    const normalizedHeight = Number(height);

    if (!Number.isInteger(normalizedWidth)
        || !Number.isInteger(normalizedHeight)
        || normalizedWidth <= 0
        || normalizedHeight <= 0) {
        return null;
    }

    const maxDimension = Math.max(normalizedWidth, normalizedHeight);
    const tier = IMAGE_RESOLUTION_TIERS.find(item => maxDimension >= item.minDimension);

    return {
        width: normalizedWidth,
        height: normalizedHeight,
        maxDimension,
        label: tier.label,
        className: tier.className,
        descriptionKey: tier.descriptionKey
    };
}

// 暴露纯函数，便于浏览器诊断和 Node.js 回归测试。
if (typeof window !== 'undefined') {
    window.LSJBananaResolution = Object.freeze({classify: classifyImageResolution});
}
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {classifyImageResolution, IMAGE_RESOLUTION_TIERS};
}

window.addEventListener('i18nReady', () => {
    // 初始化用户菜单
    initUserMenu();

    // Tab 切换逻辑
    const tabs = document.querySelectorAll('.tab-btn');
    const panels = document.querySelectorAll('.panel');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            // 移除所有 active 类
            tabs.forEach(t => t.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));

            // 添加 active 类到当前点击的 tab 和对应的 panel
            tab.classList.add('active');
            const panelId = `${tab.dataset.tab}-panel`;
            document.getElementById(panelId).classList.add('active');
        });
    });

	    // 文件上传预览（编辑面板，多次选择累积）
	    const fileInput = document.getElementById('edit-image');
	    const filePreview = document.getElementById('image-preview');
	    const selectedEditFiles = [];
	    const MAX_EDIT_IMAGES = 14;

	    function renderEditPreview() {
	        if (!filePreview) {
	            return;
	        }

	        if (selectedEditFiles.length === 0) {
	            filePreview.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> ' + window.i18n.t('index.upload_hint');
	            filePreview.style.padding = '30px';
	            filePreview.style.display = 'block';
	            filePreview.style.flexWrap = '';
	            filePreview.style.gap = '';
	            filePreview.style.justifyContent = '';
	            return;
	        }

	        filePreview.innerHTML = '';
	        filePreview.style.padding = '10px';
	        filePreview.style.display = 'flex';
	        filePreview.style.flexWrap = 'wrap';
	        filePreview.style.gap = '10px';
	        filePreview.style.justifyContent = 'center';

	        selectedEditFiles.forEach((file, index) => {
	            const wrapper = document.createElement('div');
	            wrapper.style.position = 'relative';
	            wrapper.style.display = 'inline-block';

	            const img = document.createElement('img');
	            img.style.maxHeight = '100px';
	            img.style.maxWidth = '100px';
	            img.style.objectFit = 'cover';
	            img.style.borderRadius = '4px';
	            img.style.display = 'block';

	            const reader = new FileReader();
	            reader.onload = function (e) {
	                img.src = e.target.result;
	            };
	            reader.readAsDataURL(file);

	            const removeBtn = document.createElement('button');
	            removeBtn.type = 'button';
	            removeBtn.textContent = '×';
	            removeBtn.className = 'preview-remove-btn';
	            removeBtn.dataset.index = String(index);
	            removeBtn.style.position = 'absolute';
	            removeBtn.style.top = '2px';
	            removeBtn.style.right = '2px';
	            removeBtn.style.width = '18px';
	            removeBtn.style.height = '18px';
	            removeBtn.style.border = 'none';
	            removeBtn.style.borderRadius = '50%';
	            removeBtn.style.background = 'rgba(0, 0, 0, 0.6)';
	            removeBtn.style.color = '#fff';
	            removeBtn.style.cursor = 'pointer';
	            removeBtn.style.fontSize = '12px';
	            removeBtn.style.lineHeight = '18px';
	            removeBtn.style.padding = '0';

	            wrapper.appendChild(img);
	            wrapper.appendChild(removeBtn);
	            filePreview.appendChild(wrapper);
	        });
	    }

	    if (fileInput && filePreview) {
	        fileInput.addEventListener('change', function () {
	            const files = Array.from(this.files || []);
	            if (!files.length) {
	                return;
	            }

	            let remaining = MAX_EDIT_IMAGES - selectedEditFiles.length;
	            if (remaining <= 0) {
	                alert(window.i18n.t('index.max_images_error', {max: MAX_EDIT_IMAGES}));
	                this.value = '';
	                return;
	            }

	            files.slice(0, remaining).forEach(file => {
	                selectedEditFiles.push(file);
	            });

	            // 清空原生 file input，方便下次从其他文件夹继续选择
	            this.value = '';

	            renderEditPreview();
	        });

	        filePreview.addEventListener('click', function (e) {
	            const target = e.target;
	            if (!target || !(target instanceof Element)) {
	                return;
	            }
	            const removeBtn = target.closest('.preview-remove-btn');
	            if (!removeBtn) return;
	            const index = parseInt(removeBtn.dataset.index || '-1', 10);
	            if (!Number.isNaN(index) && index >= 0 && index < selectedEditFiles.length) {
	                selectedEditFiles.splice(index, 1);
	                renderEditPreview();
	            }
	        });
	    }

	    // 初始渲染一次
	    renderEditPreview();

    // 提示词优化组件（文生图 & 图生图共用）
    function setupPromptOptimizer({ textareaId, buttonId, statusId, modeGroup, thoughtsContainerId }) {
        const promptInput = document.getElementById(textareaId);
        const actionBtn = document.getElementById(buttonId);
        const statusEl = document.getElementById(statusId);
        const thoughtsContainer = document.getElementById(thoughtsContainerId);
        const modeButtons = document.querySelectorAll(`[data-optimize-mode][data-optimize-group="${modeGroup}"]`);
        let optimizeMode = 'basic';

        function hideThoughtsPanel() {
            if (!thoughtsContainer) return;
            thoughtsContainer.classList.add('optimize-thoughts-hidden');
        }

        function showThoughtsPanel() {
            if (!thoughtsContainer) return;
            thoughtsContainer.classList.remove('optimize-thoughts-hidden');
        }

        function setStatus(message, isError = false) {
            if (!statusEl) return;
            statusEl.textContent = message;
            statusEl.style.color = isError ? '#c62828' : '#888';
        }

        function setMode(mode) {
            optimizeMode = mode;
            modeButtons.forEach(btn => {
                if (btn.dataset.optimizeMode === mode) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }

        // 渲染提示词优化的思考过程面板
        function renderOptimizeThoughts(thoughts, elapsedTime) {
            if (!thoughtsContainer) return;
            thoughtsContainer.innerHTML = '';
            showThoughtsPanel();

            if (!thoughts || !Array.isArray(thoughts) || thoughts.length === 0) {
                return;
            }

            // 合并所有思考内容
            const combinedThoughts = thoughts
                .filter(t => typeof t === 'string' && t.trim())
                .join('\n\n');

            if (!combinedThoughts.trim()) return;

            const details = document.createElement('details');
            details.className = 'optimize-thoughts-panel';
            details.open = false; // 默认折叠

            const summary = document.createElement('summary');
            summary.className = 'optimize-thoughts-summary';
            summary.innerHTML = `
                <span class="optimize-thoughts-icon"><i class="fas fa-brain"></i></span>
                <span class="optimize-thoughts-title">${window.i18n.t('result.ai_thinking')}</span>
                <span class="optimize-thoughts-time">${elapsedTime}s</span>
                <span class="optimize-thoughts-toggle"><i class="fas fa-chevron-down"></i></span>
                <button type="button" class="optimize-thoughts-close" aria-label="${window.i18n.t('form.close')}">
                    <i class="fas fa-xmark"></i>
                </button>
            `;

            const content = document.createElement('div');
            content.className = 'optimize-thoughts-content';
            content.textContent = combinedThoughts;

            details.appendChild(summary);
            details.appendChild(content);

            // 切换展开/折叠图标
            details.addEventListener('toggle', () => {
                const icon = summary.querySelector('.optimize-thoughts-toggle i');
                if (icon) {
                    icon.className = details.open ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
                }
            });

            const closeBtn = summary.querySelector('.optimize-thoughts-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    hideThoughtsPanel();
                });
            }

            thoughtsContainer.appendChild(details);
        }

        modeButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                setMode(btn.dataset.optimizeMode || 'basic');
            });
        });
        setMode(optimizeMode);

        if (promptInput && statusEl) {
            promptInput.addEventListener('input', () => setStatus(''));
        }

        async function triggerOptimize() {
            if (!promptInput || !actionBtn) return;
            const rawPrompt = (promptInput.value || '').trim();
            if (!rawPrompt) {
                setStatus(window.i18n.t('api.prompt_required'), true);
                promptInput.focus();
                return;
            }

            const originalHtml = actionBtn.innerHTML;
            const startTime = Date.now();
            setStatus(window.i18n.t('index.optimize_processing'));
            actionBtn.disabled = true;
            actionBtn.innerHTML = `<i class="fas fa-circle-notch fa-spin"></i> ${window.i18n.t('form.processing')}`;

            // 清除之前的思考内容
            if (thoughtsContainer) thoughtsContainer.innerHTML = '';

            const formData = new FormData();
            formData.append('action', 'optimize_prompt');
            formData.append('prompt', rawPrompt);
            formData.append('mode', optimizeMode);

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || window.i18n.t('index.optimize_failed', {message: 'Unknown error'}));
                }

                const elapsedTime = ((Date.now() - startTime) / 1000).toFixed(1);

                if (data.optimized_prompt) {
                    promptInput.value = data.optimized_prompt;
                    setStatus(window.i18n.t('index.optimize_done'));

                    // 显示思考内容
                    if (data.thoughts && data.thoughts.length > 0) {
                        renderOptimizeThoughts(data.thoughts, elapsedTime);
                    }
                } else {
                    throw new Error(window.i18n.t('index.optimize_no_result'));
                }
            } catch (err) {
                setStatus(window.i18n.t('index.optimize_failed', {message: err.message}), true);
            } finally {
                actionBtn.disabled = false;
                actionBtn.innerHTML = originalHtml;
            }
        }

        if (actionBtn) {
            actionBtn.addEventListener('click', triggerOptimize);
        }
    }

    setupPromptOptimizer({
        textareaId: 'prompt',
        buttonId: 'optimize-prompt-btn-generate',
        statusId: 'optimize-status-generate',
        modeGroup: 'generate',
        thoughtsContainerId: 'optimize-thoughts-generate'
    });

    setupPromptOptimizer({
        textareaId: 'edit-prompt',
        buttonId: 'optimize-prompt-btn',
        statusId: 'optimize-status',
        modeGroup: 'edit',
        thoughtsContainerId: 'optimize-thoughts-edit'
    });

    // 表单提交处理
    const generateForm = document.getElementById('generate-form');
    const editForm = document.getElementById('edit-form');
    const resultArea = document.getElementById('result-area');
    const loading = document.getElementById('loading');
    const errorMessage = document.getElementById('error-message');
    const outputContainer = document.getElementById('output-container');
    const timerDisplay = document.getElementById('timer');

    function resetOptimizeThoughts(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '';
        container.classList.add('optimize-thoughts-hidden');
    }

    function splitThoughtsIntoStages(thoughts) {
        const stages = [];
        let stageIndex = 1;

        if (!Array.isArray(thoughts)) {
            return stages;
        }

        const normalizeChunk = (chunk) => chunk.replace(/\r\n/g, '\n').trim();

        thoughts.forEach((thought) => {
            if (!thought || typeof thought !== 'string') {
                return;
            }

            const normalized = normalizeChunk(thought);
            if (!normalized) {
                return;
            }

            const chunks = normalized.split(/\n{2,}/);
            chunks.forEach((chunk) => {
                const cleaned = normalizeChunk(chunk);
                if (!cleaned) {
                    return;
                }

                const lines = cleaned.split('\n').map(line => line.trim()).filter(Boolean);
                let title = '';
                let body = cleaned;

                if (lines.length > 1) {
                    const firstLine = lines[0];
                    const rest = lines.slice(1).join('\n').trim();
                    const strippedTitle = firstLine.replace(/^#{1,4}\s+/, '').replace(/[：:]\s*$/, '');
                    const isShortTitle = strippedTitle.length > 0 && strippedTitle.length <= 60;
                    const endsWithSentence = /[。.!?]$/.test(strippedTitle);

                    if (rest && isShortTitle && (!endsWithSentence || firstLine.startsWith('#') || /[:：]$/.test(firstLine))) {
                        title = strippedTitle;
                        body = rest;
                    }
                }

                if (!title) {
                    title = `${window.i18n.t('result.stage')} ${stageIndex}`;
                }

                stages.push({
                    title,
                    body
                });
                stageIndex += 1;
            });
        });

        return stages;
    }

    function renderThinkingPanel(thoughts, elapsedSeconds) {
        if (!outputContainer) {
            return;
        }

        const stages = splitThoughtsIntoStages(thoughts);
        if (stages.length === 0) {
            return;
        }

        const details = document.createElement('details');
        details.className = 'thinking-panel';
        details.open = true;

        const summary = document.createElement('summary');
        summary.className = 'thinking-summary';

        const summaryLeft = document.createElement('div');
        summaryLeft.className = 'thinking-summary__left';

        const badge = document.createElement('span');
        badge.className = 'thinking-badge';
        badge.textContent = window.i18n.t('result.thinking_process');

        const time = document.createElement('span');
        time.className = 'thinking-time';
        time.textContent = window.i18n.t('result.thinking_time', {seconds: elapsedSeconds});

        summaryLeft.appendChild(badge);
        summaryLeft.appendChild(time);

        const toggleHint = document.createElement('span');
        toggleHint.className = 'thinking-toggle';
        toggleHint.textContent = window.i18n.t('result.thinking_collapse');

        summary.appendChild(summaryLeft);
        summary.appendChild(toggleHint);

        const content = document.createElement('div');
        content.className = 'thinking-content';

        stages.forEach((stage) => {
            const step = document.createElement('div');
            step.className = 'thinking-step';

            const stepTitle = document.createElement('div');
            stepTitle.className = 'thinking-step__title';
            stepTitle.textContent = stage.title;

            const stepBody = document.createElement('div');
            stepBody.className = 'thinking-step__body';
            stepBody.textContent = stage.body;

            step.appendChild(stepTitle);
            step.appendChild(stepBody);
            content.appendChild(step);
        });

        details.appendChild(summary);
        details.appendChild(content);

        details.addEventListener('toggle', () => {
            toggleHint.textContent = details.open ? window.i18n.t('result.thinking_collapse') : window.i18n.t('result.thinking_expand');
        });

        outputContainer.appendChild(details);
    }

    // 通用提交函数
    async function handleFormSubmit(event, type) {
        event.preventDefault();

        if (type === 'generate') {
            resetOptimizeThoughts('optimize-thoughts-generate');
        } else if (type === 'edit') {
            resetOptimizeThoughts('optimize-thoughts-edit');
        }

	        if (errorMessage) {
	            errorMessage.classList.add('hidden');
	            errorMessage.textContent = '';
	        }

	        // 编辑模式下，至少需要一张图片
	        if (type === 'edit' && selectedEditFiles.length === 0) {
	            if (resultArea) {
	                resultArea.classList.remove('hidden');
	            }
	            if (loading) {
	                loading.classList.add('hidden');
	            }
	            if (errorMessage) {
	                errorMessage.textContent = window.i18n.t('index.no_image_error');
	                errorMessage.classList.remove('hidden');
	            }
	            return;
	        }

	        // UI 状态更新
	        if (resultArea) {
	            resultArea.classList.remove('hidden');
	        }
	        if (loading) {
	            loading.classList.remove('hidden');
	        }
	        if (outputContainer) {
	            outputContainer.innerHTML = '';
	        }
	        
	        // 重置并启动计时器
	        if (timerDisplay) {
	            timerDisplay.textContent = window.i18n.t('index.elapsed_time', {time: '0.00'});
	        }
	        let startTime = Date.now();
	        let timerInterval = setInterval(() => {
	            const elapsedTime = (Date.now() - startTime) / 1000;
	            if (timerDisplay) {
	                   timerDisplay.textContent = window.i18n.t('index.elapsed_time', {time: elapsedTime.toFixed(2)});
	            }
	        }, 10);
	        
	        // 滚动到结果区域
	        if (resultArea) {
	            resultArea.scrollIntoView({ behavior: 'smooth' });
	        }

	        let formData;
	        if (type === 'edit') {
	            formData = new FormData();
	            
	            const editPromptEl = document.getElementById('edit-prompt');
	            const editAspectEl = document.getElementById('edit-aspect_ratio');
	            const editResolutionEl = document.getElementById('edit-resolution');
	            const editUseSearchEl = editForm ? editForm.querySelector('input[name="use_search"]') : null;
	            
	            formData.append('prompt', editPromptEl ? (editPromptEl.value || '') : '');
	            formData.append('aspect_ratio', editAspectEl ? (editAspectEl.value || '') : '');
	            formData.append('resolution', editResolutionEl ? (editResolutionEl.value || '') : '');
	            if (editUseSearchEl && editUseSearchEl.checked) {
	                formData.append('use_search', 'on');
	            }

	            selectedEditFiles.forEach(file => {
	                formData.append('image[]', file, file.name);
	            });
	        } else {
	            formData = new FormData(event.target);
	        }

	        formData.append('action', type); // 添加操作类型

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            // 尝试解析响应体（无论 HTTP 状态码如何）
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                // 如果无法解析 JSON，抛出 HTTP 错误
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                throw new Error(window.i18n.t('error.parse_failed'));
            }

            // 处理 HTTP 错误状态码（401、402 等）
            if (!response.ok) {
                // 优先使用服务器返回的结构化错误
                if (data.code === 'UNAUTHORIZED') {
                    showLoginRequiredError();
                    return;
                }
                if (data.code === 'INSUFFICIENT_BALANCE') {
                    showInsufficientBalanceError(data.balance, data.required);
                    return;
                }
                // 其他 HTTP 错误
                throw new Error(data.message || `HTTP error! status: ${response.status}`);
            }

            if (data.success) {
                // 计算最终耗时
                const finalTime = ((Date.now() - startTime) / 1000).toFixed(2);

                // 更新用户余额显示
                if (data.billing && data.billing.balance !== null) {
                    updateBalanceDisplay(data.billing.balance);
                }

                if (data.thoughts && data.thoughts.length > 0) {
                    renderThinkingPanel(data.thoughts, finalTime);
                }

                // 显示结果
                if (data.images && data.images.length > 0) {
                    // 添加保存提示
                    const saveNotice = document.createElement('div');
                    saveNotice.className = 'output-item save-notice';
                    saveNotice.innerHTML = `
                        <div class="save-notice-content">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div class="save-notice-text">
                                <strong>${window.i18n.t('index.save_notice_title')}</strong>
                                <p>${window.i18n.t('index.save_notice_desc')}</p>
                            </div>
                        </div>
                    `;
                    outputContainer.appendChild(saveNotice);

                    data.images.forEach((imgUrl, index) => {
                        const imgDiv = document.createElement('div');
                        imgDiv.className = 'output-item';
                        
                        // 创建图片容器（用于定位分辨率标签）
                        const imgWrapper = document.createElement('div');
                        imgWrapper.className = 'output-image-wrapper';
                        
                        // 创建图片元素
                        const img = document.createElement('img');
                        img.alt = `Generated Image ${index + 1}`;
                        
                        // 创建分辨率标签（初始加载状态）
                        const resLabel = document.createElement('div');
                        resLabel.className = 'resolution-label resolution-loading';
                        resLabel.setAttribute('aria-label', window.i18n.t('resolution.loading'));
                        resLabel.setAttribute('role', 'status');
                        resLabel.setAttribute('aria-live', 'polite');
                        resLabel.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">' + window.i18n.t('resolution.loading') + '</span>';
                        
                        // 图片加载完成后读取尺寸
                        img.onload = function() {
                            const w = img.naturalWidth;
                            const h = img.naturalHeight;

                            const resolutionInfo = classifyImageResolution(w, h);
                            if (!resolutionInfo) {
                                resLabel.className = 'resolution-label resolution-error';
                                resLabel.setAttribute('aria-label', window.i18n.t('resolution.unknown'));
                                resLabel.removeAttribute('data-resolution-tier');
                                resLabel.innerHTML = '<i class="fas fa-question-circle" aria-hidden="true"></i> ' + window.i18n.t('resolution.unknown');
                                return;
                            }

                            const tierDescription = window.i18n.t(resolutionInfo.descriptionKey);
                            const ariaText = window.i18n.t('resolution.details', {
                                tier: tierDescription,
                                width: resolutionInfo.width,
                                height: resolutionInfo.height
                            });
                            resLabel.className = `resolution-label ${resolutionInfo.className}`;
                            resLabel.setAttribute('role', 'button');
                            resLabel.setAttribute('tabindex', '0');
                            resLabel.setAttribute('data-resolution-tier', resolutionInfo.label);
                            resLabel.setAttribute('aria-label', ariaText);
                            resLabel.setAttribute('title', `${resolutionInfo.label} ${resolutionInfo.width}×${resolutionInfo.height}`);
                            resLabel.innerHTML = `<span class="resolution-tier" aria-hidden="true">${resolutionInfo.label}</span><span class="resolution-size" aria-hidden="true">${resolutionInfo.width} × ${resolutionInfo.height}</span>`;
                        };
                        
                        img.onerror = function() {
                            resLabel.className = 'resolution-label resolution-error';
                            resLabel.setAttribute('role', 'status');
                            resLabel.removeAttribute('tabindex');
                            resLabel.removeAttribute('data-resolution-tier');
                            resLabel.setAttribute('aria-label', window.i18n.t('resolution.load_failed'));
                            resLabel.innerHTML = '<i class="fas fa-exclamation-circle" aria-hidden="true"></i> ' + window.i18n.t('resolution.load_failed');
                        };
                        
                        // 点击分辨率标签也可以打开图片预览
                        resLabel.style.cursor = 'pointer';
                        resLabel.addEventListener('click', function(e) {
                            e.stopPropagation();
                            // 触发图片的点击事件以打开预览
                            img.click();
                        });

                        resLabel.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                img.click();
                            }
                        });

                        // 先绑定加载事件再设置 src，避免缓存图片过快完成而漏掉尺寸读取。
                        img.src = imgUrl;
                        
                        imgWrapper.appendChild(img);
                        imgWrapper.appendChild(resLabel);
                        imgDiv.appendChild(imgWrapper);
                        
                        // 添加下载按钮
                        const downloadLink = document.createElement('p');
                        downloadLink.innerHTML = `<a href="${imgUrl}" download target="_blank" class="btn-primary" style="display:inline-block; width:auto; padding: 5px 15px; font-size: 0.9rem; margin-top: 5px;">${window.i18n.t('index.download_image')}</a>`;
                        imgDiv.appendChild(downloadLink);
                        
                        outputContainer.appendChild(imgDiv);
                    });
                }
                
                if (data.text) {
                    const textDiv = document.createElement('div');
                    textDiv.className = 'output-item';
                    textDiv.innerHTML = `<p>${data.text}</p>`;
                    outputContainer.appendChild(textDiv);
                }

                // 显示 Grounding Metadata (搜索来源)
                if (data.groundingMetadata) {
                    const groundingDiv = document.createElement('div');
                    groundingDiv.className = 'output-item';
                    groundingDiv.style.textAlign = 'left';
                    groundingDiv.style.backgroundColor = '#f0f4f8';
                    groundingDiv.style.padding = '15px';
                    groundingDiv.style.borderRadius = '8px';
                    groundingDiv.style.marginTop = '15px';
                    
                    let groundingHtml = `<h4><i class="fab fa-google"></i> ${window.i18n.t('index.search_sources')}</h4>`;
                    
                    if (data.groundingMetadata.searchEntryPoint && data.groundingMetadata.searchEntryPoint.renderedContent) {
                        groundingHtml += `<div class="search-entry-point" style="margin-top: 10px;">${data.groundingMetadata.searchEntryPoint.renderedContent}</div>`;
                    }

                    if (data.groundingMetadata.groundingChunks && data.groundingMetadata.groundingChunks.length > 0) {
                        groundingHtml += '<ul style="margin-top: 10px; padding-left: 20px; list-style-type: disc;">';
                        data.groundingMetadata.groundingChunks.forEach(chunk => {
                            if (chunk.web && chunk.web.uri && chunk.web.title) {
                                groundingHtml += `<li style="margin-bottom: 5px;"><a href="${chunk.web.uri}" target="_blank" style="color: #1a73e8; text-decoration: none;">${chunk.web.title}</a></li>`;
                            }
                        });
                        groundingHtml += '</ul>';
                    }
                    
                    groundingDiv.innerHTML = groundingHtml;
                    outputContainer.appendChild(groundingDiv);
                }

                // 显示耗时信息
                const timeDiv = document.createElement('div');
                timeDiv.className = 'output-item';
                timeDiv.style.color = '#888';
                timeDiv.style.fontSize = '0.8rem';
                timeDiv.style.marginTop = '10px';
                timeDiv.innerHTML = `<p><i class="fas fa-clock"></i> ${window.i18n.t('index.generated_time', {time: finalTime})}</p>`;
                outputContainer.appendChild(timeDiv);

            } else {
                // 检查结构化错误码
                if (data.code === 'UNAUTHORIZED') {
                    showLoginRequiredError();
                    return;
                }
                if (data.code === 'INSUFFICIENT_BALANCE') {
                    showInsufficientBalanceError(data.balance, data.required);
                    return;
                }
                // 其他业务错误
                throw new Error(data.message || window.i18n.t('error.unknown'));
            }

        } catch (error) {
            console.error('Error:', error);
            // 显示通用错误信息
            if (errorMessage) {
                errorMessage.textContent = window.i18n.t('index.generate_failed', {message: error.message});
                errorMessage.classList.remove('hidden');
            }
        } finally {
            loading.classList.add('hidden');
            clearInterval(timerInterval);
        }
    }

    generateForm.addEventListener('submit', (e) => handleFormSubmit(e, 'generate'));
    editForm.addEventListener('submit', (e) => handleFormSubmit(e, 'edit'));

    // Data Sync Modal Logic (Obfuscated for "Sponsor")
    const syncTrigger = document.getElementById('data-sync-trigger');
    const syncModal = document.getElementById('data-sync-modal');
    const closeSyncBtn = document.querySelector('.x-close-btn');

    if (syncTrigger && syncModal && closeSyncBtn) {
        syncTrigger.addEventListener('click', (e) => {
            e.preventDefault();
            syncModal.classList.add('active');
        });

        closeSyncBtn.addEventListener('click', () => {
            syncModal.classList.remove('active');
        });

        syncModal.addEventListener('click', (e) => {
            if (e.target === syncModal) {
                syncModal.classList.remove('active');
            }
        });
    }

    // ========== 语音输入功能 ==========
    initVoiceInput();

    // ========== 图片预览功能 ==========
    initImagePreview();
});

/**
 * 语音输入功能模块
 * 优先使用 Web Speech API 进行实时语音识别
 * 回退方案: MediaRecorder + Gemini API 转文字
 */
function initVoiceInput() {
    const voiceButtons = document.querySelectorAll('.voice-input-btn');

    // 检测 Web Speech API 支持
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    const webSpeechSupported = !!SpeechRecognition;

    // 检测 MediaRecorder 支持 (回退方案)
    const mediaRecorderSupported = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);

    // 如果两种方案都不支持，隐藏语音按钮
    if (!webSpeechSupported && !mediaRecorderSupported) {
        voiceButtons.forEach(btn => btn.style.display = 'none');
        console.warn(window.i18n.t('voice.not_supported'));
        return;
    }

    // console.log(`Voice Recognition: Web Speech API ${webSpeechSupported ? 'Available' : 'Unavailable'}, MediaRecorder ${mediaRecorderSupported ? 'Available' : 'Unavailable'}`);

    // 状态管理
    let isListening = false;      // Web Speech API 监听状态
    let isRecording = false;      // MediaRecorder 录音状态
    let activeButton = null;
    let recognition = null;       // SpeechRecognition 实例
    let mediaRecorder = null;     // MediaRecorder 实例
    let audioChunks = [];
    let recordingTimeout = null;
    let interimTranscript = '';   // 临时识别结果
    const MAX_RECORDING_TIME = 60000; // 最大录音时间 60 秒

    // 为每个语音按钮绑定事件
    voiceButtons.forEach(btn => {
        btn.addEventListener('click', () => handleVoiceButtonClick(btn));
    });

    /**
     * 处理语音按钮点击
     */
    async function handleVoiceButtonClick(btn) {
        if (isListening || isRecording) {
            // 正在识别/录音，停止
            stopVoiceInput();
        } else {
            // 开始语音输入
            await startVoiceInput(btn);
        }
    }

    /**
     * 开始语音输入
     * 优先使用 Web Speech API，不支持时回退到 MediaRecorder
     */
    async function startVoiceInput(btn) {
        activeButton = btn;

        if (webSpeechSupported) {
            // 使用 Web Speech API
            startWebSpeechRecognition(btn);
        } else if (mediaRecorderSupported) {
            // 回退到 MediaRecorder + Gemini API
            await startMediaRecording(btn);
        }
    }

    /**
     * 停止语音输入
     */
    function stopVoiceInput() {
        if (isListening && recognition) {
            recognition.stop();
        }
        if (isRecording) {
            stopMediaRecording();
        }
    }

    // ==================== Web Speech API 实现 ====================

    /**
     * 启动 Web Speech API 语音识别
     */
    function startWebSpeechRecognition(btn) {
        try {
            recognition = new SpeechRecognition();

            // 配置识别参数
            recognition.lang = document.documentElement.lang || 'zh-CN';
            recognition.continuous = true;        // 持续识别
            recognition.interimResults = true;    // 显示临时结果
            recognition.maxAlternatives = 1;      // 只取最佳结果

            const targetId = btn.dataset.target;
            const targetTextarea = document.getElementById(targetId);
            const originalValue = targetTextarea ? targetTextarea.value : '';
            interimTranscript = '';

            // 更新按钮状态
            btn.classList.add('recording');
            btn.querySelector('i').className = 'fas fa-stop';
            btn.title = window.i18n.t('voice.stop_web_speech');
            isListening = true;

            // 识别结果处理
            recognition.onresult = (event) => {
                let finalTranscript = '';
                interimTranscript = '';

                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const transcript = event.results[i][0].transcript;
                    if (event.results[i].isFinal) {
                        finalTranscript += transcript;
                    } else {
                        interimTranscript += transcript;
                    }
                }

                if (targetTextarea) {
                    // 实时更新文本框 (最终结果 + 临时结果)
                    const baseText = originalValue.trim();
                    const newText = (finalTranscript + interimTranscript).trim();

                    if (baseText && newText) {
                        targetTextarea.value = baseText + ' ' + newText;
                    } else {
                        targetTextarea.value = baseText + newText;
                    }

                    // 触发 input 事件
                    targetTextarea.dispatchEvent(new Event('input', { bubbles: true }));
                }

                // 如果有最终结果，更新原始值基准
                if (finalTranscript) {
                    // 不在这里重置 originalValue，让用户可以继续说话追加内容
                }
            };

            // 识别开始
            recognition.onstart = () => {
                console.log('Web Speech API: 开始识别');
            };

            // 识别结束
            recognition.onend = () => {
                console.log('Web Speech API: 识别结束');
                // 如果还在监听状态但识别结束了（可能是静默超时），自动重启
                if (isListening) {
                    // 用户可能还想继续说，但我们选择结束以保持一致性
                    resetButtonState(btn);
                    if (targetTextarea) {
                        targetTextarea.focus();
                        targetTextarea.setSelectionRange(targetTextarea.value.length, targetTextarea.value.length);
                    }
                }
            };

            // 错误处理
            recognition.onerror = (event) => {
                console.error('Web Speech API 错误:', event.error);

                let shouldFallback = false;
                let errorMsg = '';

                switch (event.error) {
                    case 'not-allowed':
                        errorMsg = window.i18n.t('voice.mic_denied');
                        // 不回退，因为回退方案也需要麦克风权限
                        break;
                    case 'no-speech':
                        // 没有检测到语音，静默处理
                        break;
                    case 'network':
                        errorMsg = window.i18n.t('voice.network_error');
                        shouldFallback = true;
                        break;
                    case 'service-not-allowed':
                    case 'not-allowed':
                        // 服务不可用，尝试回退
                        shouldFallback = true;
                        break;
                    default:
                        errorMsg = window.i18n.t('voice.recognition_error') + ': ' + event.error;
                }

                if (errorMsg && event.error !== 'no-speech') {
                    console.warn(errorMsg);
                }

                // 如果需要回退且 MediaRecorder 可用
                if (shouldFallback && mediaRecorderSupported && isListening) {
                    console.log('回退到 MediaRecorder + Gemini API');
                    isListening = false;
                    recognition = null;
                    startMediaRecording(btn);
                    return;
                }

                if (event.error !== 'no-speech' && event.error !== 'aborted') {
                    resetButtonState(btn);
                }
            };

            // 设置最大识别时间
            recordingTimeout = setTimeout(() => {
                if (isListening) {
                    stopVoiceInput();
                }
            }, MAX_RECORDING_TIME);

            // 开始识别
            recognition.start();

        } catch (error) {
            console.error('启动 Web Speech API 失败:', error);
            // 回退到 MediaRecorder
            if (mediaRecorderSupported) {
                console.log('回退到 MediaRecorder + Gemini API');
                startMediaRecording(btn);
            } else {
                alert(window.i18n.t('voice.start_failed'));
                resetButtonState(btn);
            }
        }
    }

    // ==================== MediaRecorder + Gemini API 回退方案 ====================

    /**
     * 启动 MediaRecorder 录音 (回退方案)
     */
    async function startMediaRecording(btn) {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    sampleRate: 16000
                }
            });

            const mimeType = getSupportedMimeType();

            mediaRecorder = new MediaRecorder(stream, { mimeType });
            audioChunks = [];
            isRecording = true;

            // 更新按钮状态 - 使用不同的图标表示录音模式
            btn.classList.add('recording');
            btn.querySelector('i').className = 'fas fa-stop';
            btn.title = window.i18n.t('voice.stop_gemini');

            mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    audioChunks.push(event.data);
                }
            };

            mediaRecorder.onstop = async () => {
                stream.getTracks().forEach(track => track.stop());

                if (audioChunks.length > 0) {
                    const audioBlob = new Blob(audioChunks, { type: mimeType });
                    await transcribeWithGemini(audioBlob, btn);
                }

                resetButtonState(btn);
            };

            mediaRecorder.start(100);

            recordingTimeout = setTimeout(() => {
                if (isRecording) {
                    stopMediaRecording();
                }
            }, MAX_RECORDING_TIME);

        } catch (error) {
            console.error('无法访问麦克风:', error);
            let errorMsg = window.i18n.t('voice.mic_error');
            if (error.name === 'NotAllowedError') {
                errorMsg = window.i18n.t('voice.mic_denied') + '，' + window.i18n.t('voice.mic_permission_hint');
            } else if (error.name === 'NotFoundError') {
                errorMsg = window.i18n.t('voice.mic_not_found');
            }
            alert(errorMsg);
            resetButtonState(btn);
        }
    }

    /**
     * 停止 MediaRecorder 录音
     */
    function stopMediaRecording() {
        if (recordingTimeout) {
            clearTimeout(recordingTimeout);
            recordingTimeout = null;
        }

        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        isRecording = false;
    }

    /**
     * 获取支持的 MIME 类型
     */
    function getSupportedMimeType() {
        const types = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/ogg',
            'audio/mp4',
            'audio/mpeg'
        ];

        for (const type of types) {
            if (MediaRecorder.isTypeSupported(type)) {
                return type;
            }
        }

        return 'audio/webm';
    }

    /**
     * 调用 Gemini API 进行语音转文字
     */
    async function transcribeWithGemini(audioBlob, btn) {
        const targetId = btn.dataset.target;
        const targetTextarea = document.getElementById(targetId);

        if (!targetTextarea) {
            console.error('找不到目标输入框:', targetId);
            return;
        }

        // 显示处理中状态
        btn.classList.add('processing');
        btn.querySelector('i').className = 'fas fa-spinner fa-spin';
        btn.title = window.i18n.t('voice.converting');
        btn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'transcribe');
            formData.append('audio', audioBlob, 'recording.webm');

            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success && result.text) {
                const currentText = targetTextarea.value.trim();
                const transcribedText = result.text.trim();

                if (currentText) {
                    targetTextarea.value = currentText + ' ' + transcribedText;
                } else {
                    targetTextarea.value = transcribedText;
                }

                targetTextarea.dispatchEvent(new Event('input', { bubbles: true }));
                targetTextarea.focus();
                targetTextarea.setSelectionRange(targetTextarea.value.length, targetTextarea.value.length);
            } else if (result.message) {
                alert(window.i18n.t('voice.convert_failed') + ': ' + result.message);
            } else {
                alert(window.i18n.t('voice.no_speech'));
            }
        } catch (error) {
            console.error('语音转换请求失败:', error);
            alert(window.i18n.t('voice.convert_failed') + '，' + window.i18n.t('voice.network_hint'));
        }
    }

    // ==================== 通用函数 ====================

    /**
     * 重置按钮状态
     */
    function resetButtonState(btn) {
        if (recordingTimeout) {
            clearTimeout(recordingTimeout);
            recordingTimeout = null;
        }

        btn.classList.remove('recording', 'processing');
        btn.querySelector('i').className = 'fas fa-microphone';
        btn.title = webSpeechSupported ? window.i18n.t('voice.web_speech') : window.i18n.t('voice.gemini');
        btn.disabled = false;

        isListening = false;
        isRecording = false;
        activeButton = null;
        recognition = null;
        interimTranscript = '';
    }
}

/**
 * 图片预览功能模块 (类似 Gradio 的 Lightbox)
 * 支持: 全屏查看、缩放、拖拽平移、快捷键操作
 */
function initImagePreview() {
    // 获取 DOM 元素
    const overlay = document.getElementById('image-preview-overlay');
    const container = document.getElementById('preview-container');
    const previewImg = document.getElementById('preview-image');
    const zoomLevelDisplay = document.getElementById('preview-zoom-level');
    const imageInfoDisplay = document.getElementById('preview-image-info');
    const shortcutsHint = document.getElementById('preview-shortcuts');

    // 按钮
    const closeBtn = document.getElementById('preview-close');
    const zoomInBtn = document.getElementById('preview-zoom-in');
    const zoomOutBtn = document.getElementById('preview-zoom-out');
    const zoomFitBtn = document.getElementById('preview-zoom-fit');
    const zoomActualBtn = document.getElementById('preview-zoom-actual');
    const downloadBtn = document.getElementById('preview-download');
    const helpBtn = document.getElementById('preview-help');

    if (!overlay || !container || !previewImg) {
        console.warn('图片预览组件初始化失败：缺少必要的 DOM 元素');
        return;
    }

    // 状态变量
    let currentImageUrl = '';
    let scale = 1;
    let translateX = 0;
    let translateY = 0;
    let isDragging = false;
    let dragStartX = 0;
    let dragStartY = 0;
    let lastTranslateX = 0;
    let lastTranslateY = 0;
    let naturalWidth = 0;
    let naturalHeight = 0;

    // 缩放配置
    const MIN_SCALE = 0.1;
    const MAX_SCALE = 10;
    const ZOOM_STEP = 0.25;

    /**
     * 打开预览
     */
    function openPreview(imgSrc) {
        currentImageUrl = imgSrc;
        previewImg.src = imgSrc;

        // 重置状态
        scale = 1;
        translateX = 0;
        translateY = 0;

        // 等待图片加载后计算适应窗口的缩放
        previewImg.onload = function() {
            naturalWidth = previewImg.naturalWidth;
            naturalHeight = previewImg.naturalHeight;

            // 计算适应窗口的缩放比例
            fitToWindow();

            // 更新图片信息
            updateImageInfo();
        };

        // 显示预览
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    /**
     * 关闭预览
     */
    function closePreview() {
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        shortcutsHint.classList.remove('visible');
    }

    /**
     * 适应窗口
     */
    function fitToWindow() {
        const containerRect = container.getBoundingClientRect();
        const padding = 40;

        const maxWidth = containerRect.width - padding * 2;
        const maxHeight = containerRect.height - padding * 2;

        const scaleX = maxWidth / naturalWidth;
        const scaleY = maxHeight / naturalHeight;

        scale = Math.min(scaleX, scaleY, 1); // 不超过原始大小
        translateX = 0;
        translateY = 0;

        updateTransform();
    }

    /**
     * 原始大小 (100%)
     */
    function actualSize() {
        scale = 1;
        translateX = 0;
        translateY = 0;
        updateTransform();
    }

    /**
     * 放大
     */
    function zoomIn() {
        setScale(scale + ZOOM_STEP);
    }

    /**
     * 缩小
     */
    function zoomOut() {
        setScale(scale - ZOOM_STEP);
    }

    /**
     * 设置缩放比例
     */
    function setScale(newScale, centerX, centerY) {
        const oldScale = scale;
        scale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, newScale));

        // 如果指定了缩放中心点，调整平移以保持中心点位置
        if (centerX !== undefined && centerY !== undefined) {
            const containerRect = container.getBoundingClientRect();
            const imgCenterX = containerRect.width / 2 + translateX;
            const imgCenterY = containerRect.height / 2 + translateY;

            const dx = centerX - containerRect.left - imgCenterX;
            const dy = centerY - containerRect.top - imgCenterY;

            const scaleFactor = scale / oldScale;
            translateX -= dx * (scaleFactor - 1);
            translateY -= dy * (scaleFactor - 1);
        }

        updateTransform();
    }

    /**
     * 更新图片变换
     */
    function updateTransform() {
        previewImg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
        zoomLevelDisplay.textContent = Math.round(scale * 100) + '%';
    }

    /**
     * 更新图片信息
     */
    function updateImageInfo() {
        if (naturalWidth && naturalHeight) {
            imageInfoDisplay.textContent = window.i18n.t('lightbox.image_info', {width: naturalWidth, height: naturalHeight});
        }
    }

    /**
     * 下载当前图片
     */
    function downloadImage() {
        if (!currentImageUrl) return;

        const link = document.createElement('a');
        link.href = currentImageUrl;
        link.download = currentImageUrl.split('/').pop() || 'image.png';
        link.click();
    }

    /**
     * 切换快捷键帮助
     */
    function toggleShortcuts() {
        shortcutsHint.classList.toggle('visible');
    }

    // ========== 事件绑定 ==========

    // 点击图片打开预览 (使用事件委托)
    document.addEventListener('click', function(e) {
        const img = e.target.closest('.output-item img');
        if (img && img.src) {
            e.preventDefault();
            openPreview(img.src);
        }
    });

    // 关闭按钮
    if (closeBtn) closeBtn.addEventListener('click', closePreview);

    // 点击遮罩背景关闭 (但不包括图片和工具栏)
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay || e.target === container) {
            closePreview();
        }
    });

    // 缩放按钮 (添加空值检查)
    if (zoomInBtn) zoomInBtn.addEventListener('click', zoomIn);
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', zoomOut);
    if (zoomFitBtn) zoomFitBtn.addEventListener('click', fitToWindow);
    if (zoomActualBtn) zoomActualBtn.addEventListener('click', actualSize);
    if (downloadBtn) downloadBtn.addEventListener('click', downloadImage);
    if (helpBtn) helpBtn.addEventListener('click', toggleShortcuts);

    // 鼠标滚轮缩放
    container.addEventListener('wheel', function(e) {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -ZOOM_STEP : ZOOM_STEP;
        setScale(scale + delta, e.clientX, e.clientY);
    }, { passive: false });

    // 拖拽平移
    container.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return; // 只响应左键
        isDragging = true;
        dragStartX = e.clientX;
        dragStartY = e.clientY;
        lastTranslateX = translateX;
        lastTranslateY = translateY;
        container.style.cursor = 'grabbing';
    });

    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        translateX = lastTranslateX + (e.clientX - dragStartX);
        translateY = lastTranslateY + (e.clientY - dragStartY);
        updateTransform();
    });

    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            container.style.cursor = 'grab';
        }
    });

    // 触摸支持
    let touchStartDistance = 0;
    let touchStartScale = 1;

    container.addEventListener('touchstart', function(e) {
        if (e.touches.length === 1) {
            // 单指拖拽
            isDragging = true;
            dragStartX = e.touches[0].clientX;
            dragStartY = e.touches[0].clientY;
            lastTranslateX = translateX;
            lastTranslateY = translateY;
        } else if (e.touches.length === 2) {
            // 双指缩放
            isDragging = false;
            touchStartDistance = Math.hypot(
                e.touches[1].clientX - e.touches[0].clientX,
                e.touches[1].clientY - e.touches[0].clientY
            );
            touchStartScale = scale;
        }
    }, { passive: true });

    container.addEventListener('touchmove', function(e) {
        if (e.touches.length === 1 && isDragging) {
            translateX = lastTranslateX + (e.touches[0].clientX - dragStartX);
            translateY = lastTranslateY + (e.touches[0].clientY - dragStartY);
            updateTransform();
        } else if (e.touches.length === 2) {
            const currentDistance = Math.hypot(
                e.touches[1].clientX - e.touches[0].clientX,
                e.touches[1].clientY - e.touches[0].clientY
            );
            const scaleChange = currentDistance / touchStartDistance;
            setScale(touchStartScale * scaleChange);
        }
    }, { passive: true });

    container.addEventListener('touchend', function() {
        isDragging = false;
    });

    // 键盘快捷键
    document.addEventListener('keydown', function(e) {
        if (!overlay.classList.contains('active')) return;

        switch(e.key) {
            case 'Escape':
                closePreview();
                break;
            case '+':
            case '=':
                e.preventDefault();
                zoomIn();
                break;
            case '-':
            case '_':
                e.preventDefault();
                zoomOut();
                break;
            case '1':
                e.preventDefault();
                actualSize();
                break;
            case 'f':
            case 'F':
                e.preventDefault();
                fitToWindow();
                break;
            case 'd':
            case 'D':
                e.preventDefault();
                downloadImage();
                break;
            case '?':
                e.preventDefault();
                toggleShortcuts();
                break;
        }
    });

    // 双击切换缩放
    container.addEventListener('dblclick', function(e) {
        e.preventDefault();
        if (scale > 1) {
            fitToWindow();
        } else {
            actualSize();
        }
    });
}

/**
 * 用户菜单交互模块
 */
function initUserMenu() {
    const menuTrigger = document.getElementById('user-menu-trigger');
    const dropdown = document.getElementById('user-dropdown');

    if (!menuTrigger || !dropdown) {
        return;
    }

    // 点击触发器显示/隐藏下拉菜单
    menuTrigger.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('active');
        menuTrigger.classList.toggle('active');
    });

    // 点击其他区域关闭菜单
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && !menuTrigger.contains(e.target)) {
            dropdown.classList.remove('active');
            menuTrigger.classList.remove('active');
        }
    });

    // ESC 键关闭菜单
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            dropdown.classList.remove('active');
            menuTrigger.classList.remove('active');
        }
    });
}

/**
 * 更新余额显示
 */
function updateBalanceDisplay(balance) {
    const balanceDisplay = document.getElementById('user-balance-display');
    if (balanceDisplay) {
        const amountEl = balanceDisplay.querySelector('.balance-amount');
        if (amountEl) {
            amountEl.textContent = parseFloat(balance).toFixed(2);

            // 添加动画效果
            balanceDisplay.classList.add('balance-updated');
            setTimeout(() => {
                balanceDisplay.classList.remove('balance-updated');
            }, 1000);
        }
    }

    // 更新全局用户状态
    if (window.LSJ_USER) {
        window.LSJ_USER.balance = balance;
    }
}

/**
 * 显示余额不足错误
 */
function showInsufficientBalanceError(currentBalance, required) {
    const errorMessage = document.getElementById('error-message');
    const outputContainer = document.getElementById('output-container');

    if (outputContainer) {
        outputContainer.innerHTML = '';
    }

    if (errorMessage) {
        errorMessage.innerHTML = `
            <div class="insufficient-balance-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="error-content">
                    <strong>${window.i18n.t('balance.insufficient')}</strong>
                    <p>${window.i18n.t('balance.current')}: <span class="balance">${parseFloat(currentBalance).toFixed(2)}</span> ${window.i18n.t('site.balance_unit', '元')}</p>
                    <p>${window.i18n.t('balance.required')}: <span class="required">${parseFloat(required).toFixed(2)}</span> ${window.i18n.t('site.balance_unit', '元')}</p>
                </div>
                <a href="recharge.php" class="btn-recharge">
                    <i class="fas fa-coins"></i> ${window.i18n.t('balance.recharge')}
                </a>
            </div>
        `;
        errorMessage.classList.remove('hidden');
    }
}

/**
 * 显示需要登录的错误
 */
function showLoginRequiredError() {
    const errorMessage = document.getElementById('error-message');
    const outputContainer = document.getElementById('output-container');

    if (outputContainer) {
        outputContainer.innerHTML = '';
    }

    if (errorMessage) {
        errorMessage.innerHTML = `
            <div class="login-required-error">
                <i class="fas fa-user-lock"></i>
                <div class="error-content">
                    <strong>${window.i18n.t('auth.login_required')}</strong>
                    <p>${window.i18n.t('auth.login_required_desc')}</p>
                </div>
                <div class="auth-buttons-inline">
                    <a href="login.php" class="btn-login-inline">
                        <i class="fas fa-sign-in-alt"></i> ${window.i18n.t('auth.login')}
                    </a>
                    <a href="register.php" class="btn-register-inline">
                        <i class="fas fa-user-plus"></i> ${window.i18n.t('auth.register')}
                    </a>
                </div>
            </div>
        `;
        errorMessage.classList.remove('hidden');
    }
}

/**
 * 获取用户状态
 */
async function fetchUserStatus() {
    try {
        const formData = new FormData();
        formData.append('action', 'get_user_status');

        const response = await fetch('api.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        if (data.success && data.user) {
            updateBalanceDisplay(data.user.balance);
        }
        return data;
    } catch (error) {
        console.error('获取用户状态失败:', error);
        return null;
    }
}

/**
 * 公告系统模块
 */
function initAnnouncementSystem() {
    const BANNER_CONTAINER_ID = 'announcement-banners';
    const MODAL_OVERLAY_ID = 'announcement-modal-overlay';
    const INLINE_CONTAINER_ID = 'announcement-inlines';

    const DISMISSED_KEY = 'lsj_dismissed_announcements_v2';
    const LEGACY_DISMISSED_KEY = 'lsj_dismissed_announcements';
    const CACHE_KEY = 'lsj_announcements_cache_v2';
    const MODAL_COUNT_KEY = 'lsj_announcement_modal_count_v1';

    const runtimeConfig = window.LSJ_ANNOUNCEMENT || {};

    function toNonNegativeInt(value, fallback) {
        const parsed = Number.parseInt(value, 10);
        return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
    }

    const configState = {
        allowHtml: runtimeConfig.allow_html !== undefined ? Boolean(runtimeConfig.allow_html) : true,
        sanitizeHtml: runtimeConfig.sanitize_html !== undefined ? Boolean(runtimeConfig.sanitize_html) : true,
        cacheTtl: toNonNegativeInt(runtimeConfig.cache_ttl, 300),
        guestDismissalTtl: toNonNegativeInt(runtimeConfig.guest_dismissal_ttl, 7 * 24 * 3600),
        maxModalsPerSession: toNonNegativeInt(runtimeConfig.max_modals_per_session, 1),
        maxBanners: toNonNegativeInt(runtimeConfig.max_banners, 3),
    };

    const allowedTypes = new Set(['info', 'warning', 'success', 'important']);

    function applyMetaConfig(meta) {
        if (!meta || typeof meta !== 'object') {
            return;
        }

        if (Object.prototype.hasOwnProperty.call(meta, 'allow_html')) {
            configState.allowHtml = Boolean(meta.allow_html);
        }
        if (Object.prototype.hasOwnProperty.call(meta, 'sanitize_html')) {
            configState.sanitizeHtml = Boolean(meta.sanitize_html);
        }
        if (Object.prototype.hasOwnProperty.call(meta, 'cache_ttl')) {
            configState.cacheTtl = toNonNegativeInt(meta.cache_ttl, configState.cacheTtl);
        }
        if (Object.prototype.hasOwnProperty.call(meta, 'guest_dismissal_ttl')) {
            configState.guestDismissalTtl = toNonNegativeInt(meta.guest_dismissal_ttl, configState.guestDismissalTtl);
        }
        if (Object.prototype.hasOwnProperty.call(meta, 'max_modals_per_session')) {
            configState.maxModalsPerSession = toNonNegativeInt(meta.max_modals_per_session, configState.maxModalsPerSession);
        }
        if (Object.prototype.hasOwnProperty.call(meta, 'max_banners')) {
            configState.maxBanners = toNonNegativeInt(meta.max_banners, configState.maxBanners);
        }
    }

    function sanitizeAndNormalizeUrl(url) {
        if (typeof url !== 'string') {
            return '';
        }

        const raw = url.trim();
        if (!raw) {
            return '';
        }

        try {
            const parsed = new URL(raw, window.location.origin);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                return parsed.toString();
            }
        } catch (e) {
            return '';
        }

        return '';
    }

    function sanitizeHtmlFragmentToFragment(inputHtml) {
        const fragment = document.createDocumentFragment();
        const allowedTags = new Set(['b', 'i', 'u', 'a', 'br', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'span']);

        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(`<div>${String(inputHtml ?? '')}</div>`, 'text/html');
            const sourceRoot = doc.body.firstElementChild || doc.body;

            const appendStrictNode = (node, parent) => {
                if (node.nodeType === Node.TEXT_NODE) {
                    parent.appendChild(document.createTextNode(node.textContent || ''));
                    return;
                }

                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return;
                }

                const tagName = node.tagName.toLowerCase();
                if (!allowedTags.has(tagName)) {
                    Array.from(node.childNodes).forEach((child) => appendStrictNode(child, parent));
                    return;
                }

                const safeEl = document.createElement(tagName);

                if (tagName === 'a') {
                    const safeHref = sanitizeAndNormalizeUrl(node.getAttribute('href') || '');
                    if (safeHref) {
                        safeEl.setAttribute('href', safeHref);
                        safeEl.setAttribute('target', '_blank');
                        safeEl.setAttribute('rel', 'noopener noreferrer nofollow');
                    }
                }

                Array.from(node.childNodes).forEach((child) => appendStrictNode(child, safeEl));
                parent.appendChild(safeEl);
            };

            Array.from(sourceRoot.childNodes).forEach((node) => appendStrictNode(node, fragment));
        } catch (e) {
            fragment.appendChild(document.createTextNode(String(inputHtml ?? '')));
        }

        return fragment;
    }

    function sanitizeLooseHtmlToFragment(inputHtml) {
        const fragment = document.createDocumentFragment();
        const dangerousTags = new Set(['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta']);

        const sanitizeLooseNode = (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                return document.createTextNode(node.textContent || '');
            }

            if (node.nodeType !== Node.ELEMENT_NODE) {
                return null;
            }

            const tagName = node.tagName.toLowerCase();
            if (dangerousTags.has(tagName)) {
                return null;
            }

            const safeEl = document.createElement(tagName);

            Array.from(node.attributes).forEach((attr) => {
                const attrName = attr.name.toLowerCase();
                if (attrName.startsWith('on') || attrName === 'style') {
                    return;
                }
                safeEl.setAttribute(attr.name, attr.value);
            });

            if (tagName === 'a') {
                const safeHref = sanitizeAndNormalizeUrl(safeEl.getAttribute('href') || '');
                if (safeHref) {
                    safeEl.setAttribute('href', safeHref);
                    safeEl.setAttribute('target', '_blank');
                    safeEl.setAttribute('rel', 'noopener noreferrer nofollow');
                } else {
                    safeEl.removeAttribute('href');
                    safeEl.removeAttribute('target');
                    safeEl.removeAttribute('rel');
                }
            }

            Array.from(node.childNodes).forEach((child) => {
                const safeChild = sanitizeLooseNode(child);
                if (safeChild) {
                    safeEl.appendChild(safeChild);
                }
            });

            return safeEl;
        };

        try {
            const template = document.createElement('template');
            template.innerHTML = String(inputHtml ?? '');

            Array.from(template.content.childNodes).forEach((node) => {
                const safeNode = sanitizeLooseNode(node);
                if (safeNode) {
                    fragment.appendChild(safeNode);
                }
            });
        } catch (e) {
            fragment.appendChild(document.createTextNode(String(inputHtml ?? '')));
        }

        return fragment;
    }

    function buildContentNode(content) {
        const raw = typeof content === 'string' ? content : String(content ?? '');

        if (!configState.allowHtml) {
            const textFragment = document.createDocumentFragment();
            textFragment.appendChild(document.createTextNode(raw));
            return textFragment;
        }

        if (configState.sanitizeHtml) {
            return sanitizeHtmlFragmentToFragment(raw);
        }

        return sanitizeLooseHtmlToFragment(raw);
    }

    function saveDismissedMap(map) {
        try {
            localStorage.setItem(DISMISSED_KEY, JSON.stringify(map));
        } catch (e) {
            // localStorage 不可用时静默降级
        }
    }

    function getDismissedMap() {
        const now = Date.now();
        const ttlMs = configState.guestDismissalTtl > 0 ? configState.guestDismissalTtl * 1000 : 0;
        let dirty = false;
        let parsed = {};

        try {
            const raw = localStorage.getItem(DISMISSED_KEY);
            parsed = raw ? JSON.parse(raw) : {};
        } catch (e) {
            parsed = {};
            dirty = true;
        }

        const map = {};

        const putRecord = (idCandidate, tsCandidate) => {
            const id = Number.parseInt(String(idCandidate), 10);
            if (!Number.isInteger(id) || id <= 0) {
                dirty = true;
                return;
            }

            let ts = Number(tsCandidate);
            if (!Number.isFinite(ts) || ts <= 0) {
                ts = now;
                dirty = true;
            }

            if (ttlMs > 0 && now - ts > ttlMs) {
                dirty = true;
                return;
            }

            map[String(id)] = ts;
        };

        if (Array.isArray(parsed)) {
            dirty = true;
            parsed.forEach((id) => putRecord(id, now));
        } else if (parsed && typeof parsed === 'object') {
            Object.keys(parsed).forEach((id) => putRecord(id, parsed[id]));
        }

        try {
            const legacyRaw = localStorage.getItem(LEGACY_DISMISSED_KEY);
            if (legacyRaw) {
                const legacyParsed = JSON.parse(legacyRaw);
                if (Array.isArray(legacyParsed)) {
                    dirty = true;
                    legacyParsed.forEach((id) => putRecord(id, now));
                }
                localStorage.removeItem(LEGACY_DISMISSED_KEY);
            }
        } catch (e) {
            // 忽略旧数据迁移错误
        }

        if (dirty) {
            saveDismissedMap(map);
        }

        return map;
    }

    function getDismissedIdsForRequest() {
        const map = getDismissedMap();
        return Object.keys(map)
            .map((id) => Number.parseInt(id, 10))
            .filter((id) => Number.isInteger(id) && id > 0);
    }

    function saveDismissedId(id) {
        const normalizedId = Number.parseInt(id, 10);
        if (!Number.isInteger(normalizedId) || normalizedId <= 0) {
            return;
        }

        const map = getDismissedMap();
        map[String(normalizedId)] = Date.now();
        saveDismissedMap(map);
    }

    function readAnnouncementsCache() {
        if (configState.cacheTtl <= 0) {
            return null;
        }

        try {
            const raw = localStorage.getItem(CACHE_KEY);
            if (!raw) {
                return null;
            }

            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') {
                return null;
            }

            const ts = Number(parsed.ts || 0);
            if (!Number.isFinite(ts) || ts <= 0) {
                return null;
            }

            if (Date.now() - ts > configState.cacheTtl * 1000) {
                localStorage.removeItem(CACHE_KEY);
                return null;
            }

            if (!parsed.data || typeof parsed.data !== 'object') {
                return null;
            }

            return parsed;
        } catch (e) {
            return null;
        }
    }

    function writeAnnouncementsCache(payload) {
        if (configState.cacheTtl <= 0) {
            return;
        }

        if (!payload || typeof payload !== 'object' || !payload.data || typeof payload.data !== 'object') {
            return;
        }

        try {
            localStorage.setItem(CACHE_KEY, JSON.stringify({
                ts: Date.now(),
                data: payload.data,
                meta: payload.meta || null
            }));
        } catch (e) {
            // localStorage 不可用时静默降级
        }
    }

    function clearAnnouncementsCache() {
        try {
            localStorage.removeItem(CACHE_KEY);
        } catch (e) {
            // 忽略
        }
    }

    function getModalShownCount() {
        try {
            const raw = sessionStorage.getItem(MODAL_COUNT_KEY);
            const count = Number.parseInt(raw || '0', 10);
            return Number.isFinite(count) && count > 0 ? count : 0;
        } catch (e) {
            return 0;
        }
    }

    function setModalShownCount(count) {
        try {
            const safeCount = Math.max(0, Number.parseInt(count, 10) || 0);
            sessionStorage.setItem(MODAL_COUNT_KEY, String(safeCount));
        } catch (e) {
            // sessionStorage 不可用时静默降级
        }
    }

    function normalizeAnnouncementItem(item) {
        if (!item || typeof item !== 'object') {
            return null;
        }

        const id = Number.parseInt(item.id, 10);
        if (!Number.isInteger(id) || id <= 0) {
            return null;
        }

        const type = typeof item.type === 'string' && allowedTypes.has(item.type) ? item.type : 'info';

        return {
            id,
            title: typeof item.title === 'string' ? item.title : String(item.title ?? ''),
            content: typeof item.content === 'string' ? item.content : String(item.content ?? ''),
            type,
            is_dismissible: Boolean(item.is_dismissible),
        };
    }

    function createIcon(type, extraClass = '') {
        const icon = document.createElement('i');
        icon.className = `fas ${getIconClass(type)}${extraClass ? ` ${extraClass}` : ''}`;
        return icon;
    }

    function removeBannerOffset() {
        document.body.classList.remove('has-announcement-banners');
        document.body.style.removeProperty('--announcement-banners-height');
    }

    function syncBannerOffset(container) {
        if (!container || container.children.length === 0) {
            removeBannerOffset();
            return;
        }

        document.body.classList.add('has-announcement-banners');
        document.body.style.setProperty('--announcement-banners-height', `${container.offsetHeight}px`);
    }

    function renderBanners(items) {
        let container = document.getElementById(BANNER_CONTAINER_ID);

        if (!items.length) {
            if (container) {
                container.remove();
            }
            removeBannerOffset();
            return;
        }

        if (!container) {
            container = document.createElement('div');
            container.id = BANNER_CONTAINER_ID;
            container.className = 'announcement-banners';
            document.body.prepend(container);
        }

        container.textContent = '';

        items.forEach((item) => {
            const banner = document.createElement('div');
            banner.className = `announcement-banner ${item.type}`;

            const contentWrap = document.createElement('div');
            contentWrap.className = 'announcement-banner__content';

            const icon = createIcon(item.type, 'announcement-banner__icon');
            contentWrap.appendChild(icon);

            const text = document.createElement('span');
            text.className = 'announcement-banner__text';
            text.appendChild(buildContentNode(item.content));
            contentWrap.appendChild(text);

            banner.appendChild(contentWrap);

            if (item.is_dismissible) {
                const closeBtn = document.createElement('button');
                closeBtn.className = 'announcement-banner__close';
                closeBtn.setAttribute('aria-label', window.i18n.t('announcement.dismiss'));
                const closeIcon = document.createElement('i');
                closeIcon.className = 'fas fa-times';
                closeBtn.appendChild(closeIcon);
                closeBtn.addEventListener('click', () => dismissAnnouncement(item.id, banner, 'banner'));
                banner.appendChild(closeBtn);
            }

            container.appendChild(banner);
        });

        syncBannerOffset(container);
    }

    function renderModal(items) {
        let overlay = document.getElementById(MODAL_OVERLAY_ID);

        if (!items.length || configState.maxModalsPerSession <= 0 || getModalShownCount() >= configState.maxModalsPerSession) {
            if (overlay) {
                overlay.classList.remove('active');
            }
            return;
        }

        const item = items[0];
        if (!item) {
            return;
        }

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = MODAL_OVERLAY_ID;
            overlay.className = 'announcement-modal-overlay';
            document.body.appendChild(overlay);
        }

        overlay.textContent = '';

        const modal = document.createElement('div');
        modal.className = `announcement-modal ${item.type}`;

        const header = document.createElement('div');
        header.className = 'announcement-modal__header';
        header.appendChild(createIcon(item.type, 'announcement-modal__icon'));

        const title = document.createElement('h3');
        title.className = 'announcement-modal__title';
        title.textContent = item.title;
        header.appendChild(title);

        const body = document.createElement('div');
        body.className = 'announcement-modal__body';
        body.appendChild(buildContentNode(item.content));

        const footer = document.createElement('div');
        footer.className = 'announcement-modal__footer';

        const btn = document.createElement('button');
        btn.className = 'announcement-modal__btn';
        btn.textContent = window.i18n.t('announcement.i_know');
        btn.addEventListener('click', () => {
            if (item.is_dismissible) {
                dismissAnnouncement(item.id, overlay, 'modal');
            } else {
                overlay.classList.remove('active');
            }
        });

        footer.appendChild(btn);

        modal.appendChild(header);
        modal.appendChild(body);
        modal.appendChild(footer);
        overlay.appendChild(modal);

        setModalShownCount(getModalShownCount() + 1);
        setTimeout(() => overlay.classList.add('active'), 500);
    }

    function renderInlines(items) {
        const mainContent = document.querySelector('main');
        let container = document.getElementById(INLINE_CONTAINER_ID);

        if (!items.length || !mainContent) {
            if (container) {
                container.remove();
            }
            return;
        }

        if (!container) {
            container = document.createElement('div');
            container.id = INLINE_CONTAINER_ID;
            container.className = 'announcement-inlines';
            mainContent.prepend(container);
        }

        container.textContent = '';

        items.forEach((item) => {
            const card = document.createElement('div');
            card.className = `announcement-inline ${item.type}`;

            const header = document.createElement('div');
            header.className = 'announcement-inline__header';

            const titleWrap = document.createElement('div');
            titleWrap.className = 'announcement-inline__title';
            titleWrap.appendChild(createIcon(item.type));

            const titleText = document.createElement('span');
            titleText.textContent = item.title;
            titleWrap.appendChild(titleText);
            header.appendChild(titleWrap);

            if (item.is_dismissible) {
                const closeBtn = document.createElement('button');
                closeBtn.className = 'announcement-inline__close';
                closeBtn.setAttribute('aria-label', window.i18n.t('announcement.dismiss'));
                const closeIcon = document.createElement('i');
                closeIcon.className = 'fas fa-times';
                closeBtn.appendChild(closeIcon);
                closeBtn.addEventListener('click', () => dismissAnnouncement(item.id, card, 'inline'));
                header.appendChild(closeBtn);
            }

            const body = document.createElement('div');
            body.className = 'announcement-inline__body';
            body.appendChild(buildContentNode(item.content));

            card.appendChild(header);
            card.appendChild(body);
            container.appendChild(card);
        });
    }

    async function dismissAnnouncement(id, element, type) {
        const normalizedId = Number.parseInt(id, 10);
        if (!Number.isInteger(normalizedId) || normalizedId <= 0) {
            return;
        }

        if (type === 'modal') {
            const overlay = document.getElementById(MODAL_OVERLAY_ID);
            if (overlay) {
                overlay.classList.remove('active');
            }
        } else if (element) {
            element.classList.add('closing');
            setTimeout(() => {
                element.remove();
                if (type === 'banner') {
                    const container = document.getElementById(BANNER_CONTAINER_ID);
                    syncBannerOffset(container);
                }
            }, 300);
        }

        saveDismissedId(normalizedId);
        clearAnnouncementsCache();

        if (window.LSJ_USER && window.LSJ_USER.loggedIn) {
            const formData = new FormData();
            formData.append('action', 'dismiss_announcement');
            formData.append('announcement_id', normalizedId);

            try {
                await fetch('api.php', {
                    method: 'POST',
                    body: formData
                });
            } catch (error) {
                console.error('Failed to sync dismissal:', error);
            }
        }
    }

    function renderAnnouncements(data) {
        if (!data || typeof data !== 'object') {
            console.warn('renderAnnouncements: invalid data');
            return;
        }

        const rawBanners = Array.isArray(data.banners) ? data.banners : [];
        const rawModals = Array.isArray(data.modals) ? data.modals : [];
        const rawInlines = Array.isArray(data.inlines) ? data.inlines : [];

        const banners = rawBanners
            .map(normalizeAnnouncementItem)
            .filter(Boolean)
            .slice(0, configState.maxBanners);

        const modals = rawModals
            .map(normalizeAnnouncementItem)
            .filter(Boolean);

        const inlines = rawInlines
            .map(normalizeAnnouncementItem)
            .filter(Boolean);

        renderBanners(banners);
        renderModal(modals);
        renderInlines(inlines);
    }

    async function fetchAnnouncements(options = { forceRefresh: false }) {
        const forceRefresh = Boolean(options && options.forceRefresh);

        if (!forceRefresh) {
            const cached = readAnnouncementsCache();
            if (cached && cached.data) {
                if (cached.meta) {
                    applyMetaConfig(cached.meta);
                }
                renderAnnouncements(cached.data);
                return;
            }
        }

        const dismissedIds = getDismissedIdsForRequest();
        const formData = new FormData();
        formData.append('action', 'get_announcements');
        formData.append('dismissed_ids', JSON.stringify(dismissedIds));

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                console.warn('Announcement API returned non-OK status:', response.status);
                return;
            }

            const result = await response.json();
            if (!result || typeof result !== 'object') {
                console.warn('Invalid announcement response format');
                return;
            }

            if (result.meta && typeof result.meta === 'object') {
                applyMetaConfig(result.meta);
            }

            if (result.success && result.data) {
                writeAnnouncementsCache(result);
                renderAnnouncements(result.data);
            }
        } catch (error) {
            console.error('Failed to fetch announcements:', error);
        }
    }

    function getIconClass(type) {
        switch (type) {
            case 'info': return 'fa-info-circle';
            case 'warning': return 'fa-exclamation-triangle';
            case 'success': return 'fa-check-circle';
            case 'important': return 'fa-star';
            default: return 'fa-info-circle';
        }
    }

    fetchAnnouncements();
}



// 在 i18nReady 事件中初始化公告系统
window.addEventListener('i18nReady', initAnnouncementSystem);

