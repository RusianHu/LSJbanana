// 等待 i18n 就绪后再初始化
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
                        img.src = imgUrl;
                        img.alt = `Generated Image ${index + 1}`;
                        
                        // 创建分辨率标签（初始加载状态）
                        const resLabel = document.createElement('div');
                        resLabel.className = 'resolution-label resolution-loading';
                        resLabel.setAttribute('aria-label', window.i18n.t('resolution.loading'));
                        resLabel.setAttribute('role', 'status');
                        resLabel.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">' + window.i18n.t('resolution.loading') + '</span>';
                        
                        // 分辨率阈值常量
                        const RESOLUTION_THRESHOLD_2K = 2000;
                        const RESOLUTION_THRESHOLD_1K = 1000;
                        
                        // 图片加载完成后读取尺寸
                        img.onload = function() {
                            const w = img.naturalWidth;
                            const h = img.naturalHeight;
                            
                            // 边界检查：确保尺寸有效
                            if (!w || !h || w <= 0 || h <= 0) {
                                resLabel.className = 'resolution-label resolution-error';
                                resLabel.setAttribute('aria-label', window.i18n.t('resolution.unknown'));
                                resLabel.innerHTML = '<i class="fas fa-question-circle" aria-hidden="true"></i> ' + window.i18n.t('resolution.unknown');
                                return;
                            }
                            
                            const maxDim = Math.max(w, h);
                            
                            // 判断分辨率档位
                            let tierClass = 'resolution-low';
                            let tierLabel = '';
                            let tierDescription = '';
                            
                            if (maxDim >= RESOLUTION_THRESHOLD_2K) {
                                tierClass = 'resolution-2k';
                                tierLabel = '2K';
                                tierDescription = window.i18n.t('resolution.2k');
                            } else if (maxDim >= RESOLUTION_THRESHOLD_1K) {
                                tierClass = 'resolution-1k';
                                tierLabel = '1K';
                                tierDescription = window.i18n.t('resolution.1k');
                            } else {
                                tierClass = 'resolution-low';
                                tierLabel = 'SD';
                                tierDescription = window.i18n.t('resolution.sd');
                            }
                            
                            const ariaText = window.i18n.t('lightbox.image_info', {width: w, height: h});
                            resLabel.className = `resolution-label ${tierClass}`;
                            resLabel.setAttribute('aria-label', ariaText);
                            resLabel.setAttribute('title', `${tierLabel} ${w}×${h}`);
                            resLabel.innerHTML = `<span class="resolution-tier" aria-hidden="true">${tierLabel}</span><span class="resolution-size" aria-hidden="true">${w} × ${h}</span>`;
                        };
                        
                        img.onerror = function() {
                            resLabel.className = 'resolution-label resolution-error';
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
            recognition.lang = 'zh-CN';           // 默认中文，会自动识别其他语言
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
    
    // 从 localStorage 获取已关闭的公告 ID
    function getDismissedIds() {
        try {
            const stored = localStorage.getItem('lsj_dismissed_announcements');
            return stored ? JSON.parse(stored) : [];
        } catch (e) {
            return [];
        }
    }
    
    // 保存已关闭的公告 ID 到 localStorage
    function saveDismissedId(id) {
        const ids = getDismissedIds();
        if (!ids.includes(id)) {
            ids.push(id);
            localStorage.setItem('lsj_dismissed_announcements', JSON.stringify(ids));
        }
    }
    
    // 获取公告数据
    async function fetchAnnouncements() {
        const dismissedIds = getDismissedIds();
        const formData = new FormData();
        formData.append('action', 'get_announcements');
        formData.append('dismissed_ids', JSON.stringify(dismissedIds));
        
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                body: formData
            });
            
            // 检查 HTTP 响应状态
            if (!response.ok) {
                console.warn('Announcement API returned non-OK status:', response.status);
                return;
            }
            
            const result = await response.json();
            
            // 验证响应结构
            if (!result || typeof result !== 'object') {
                console.warn('Invalid announcement response format');
                return;
            }
            
            if (result.success && result.data) {
                renderAnnouncements(result.data);
            }
            // 如果 success 为 false 或没有 data，静默处理（不显示公告）
        } catch (error) {
            // 网络错误或 JSON 解析错误，静默处理
            console.error('Failed to fetch announcements:', error);
        }
    }
    
    // 关闭公告
    async function dismissAnnouncement(id, element, type) {
        // 视觉移除
        if (type === 'modal') {
            const overlay = document.getElementById(MODAL_OVERLAY_ID);
            if (overlay) overlay.classList.remove('active');
        } else {
            element.classList.add('closing');
            setTimeout(() => {
                element.remove();
                // 检查是否还有 banner，如果没有则移除 body 的 class
                if (type === 'banner') {
                    const container = document.getElementById(BANNER_CONTAINER_ID);
                    if (!container || container.children.length === 0) {
                        document.body.classList.remove('has-announcement-banners');
                        document.body.style.removeProperty('--announcement-banners-height');
                    } else {
                        // 更新剩余 banner 的高度
                        const height = container.offsetHeight;
                        document.body.style.setProperty('--announcement-banners-height', height + 'px');
                    }
                }
            }, 300);
        }
        
        // 本地存储
        saveDismissedId(id);
        
        // 如果已登录，同步到服务器
        if (window.LSJ_USER && window.LSJ_USER.loggedIn) {
            const formData = new FormData();
            formData.append('action', 'dismiss_announcement');
            formData.append('announcement_id', id);
            
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
    
    // 渲染公告
    function renderAnnouncements(data) {
        // 数据验证：确保 data 对象有效
        if (!data || typeof data !== 'object') {
            console.warn('renderAnnouncements: invalid data');
            return;
        }
        
        // 初始化默认空数组
        const banners = Array.isArray(data.banners) ? data.banners : [];
        const modals = Array.isArray(data.modals) ? data.modals : [];
        const inlines = Array.isArray(data.inlines) ? data.inlines : [];
        
        // 渲染 Banners
        if (banners.length > 0) {
            let container = document.getElementById(BANNER_CONTAINER_ID);
            if (!container) {
                container = document.createElement('div');
                container.id = BANNER_CONTAINER_ID;
                container.className = 'announcement-banners';
                document.body.prepend(container);
            }
            
            container.innerHTML = '';
            banners.forEach(item => {
                // 验证必要字段
                if (!item || typeof item.id === 'undefined' || !item.content) {
                    return;
                }
                const banner = document.createElement('div');
                banner.className = `announcement-banner ${item.type}`;
                banner.innerHTML = `
                    <div class="announcement-banner__content">
                        <i class="fas ${getIconClass(item.type)} announcement-banner__icon"></i>
                        <span class="announcement-banner__text">${item.content}</span>
                    </div>
                    ${item.is_dismissible ? `
                    <button class="announcement-banner__close" aria-label="${window.i18n.t('announcement.dismiss')}">
                        <i class="fas fa-times"></i>
                    </button>` : ''}
                `;
                
                if (item.is_dismissible) {
                    const closeBtn = banner.querySelector('.announcement-banner__close');
                    closeBtn.addEventListener('click', () => dismissAnnouncement(item.id, banner, 'banner'));
                }
                
                container.appendChild(banner);
            });
            
            // 设置 body class 和高度变量，用于调整顶部固定元素位置
            document.body.classList.add('has-announcement-banners');
            const height = container.offsetHeight;
            document.body.style.setProperty('--announcement-banners-height', height + 'px');
        }
        
        // 渲染 Modals (一次只显示一个)
        if (modals.length > 0) {
            const item = modals[0]; // 优先级最高的
            
            // 验证必要字段
            if (!item || typeof item.id === 'undefined' || !item.title || !item.content) {
                return;
            }
            let overlay = document.getElementById(MODAL_OVERLAY_ID);
            
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = MODAL_OVERLAY_ID;
                overlay.className = 'announcement-modal-overlay';
                document.body.appendChild(overlay);
            }
            
            overlay.innerHTML = `
                <div class="announcement-modal ${item.type}">
                    <div class="announcement-modal__header">
                        <i class="fas ${getIconClass(item.type)} announcement-modal__icon"></i>
                        <h3 class="announcement-modal__title">${item.title}</h3>
                    </div>
                    <div class="announcement-modal__body">
                        ${item.content}
                    </div>
                    <div class="announcement-modal__footer">
                        <button class="announcement-modal__btn">${window.i18n.t('announcement.i_know')}</button>
                    </div>
                </div>
            `;
            
            const btn = overlay.querySelector('.announcement-modal__btn');
            btn.addEventListener('click', () => dismissAnnouncement(item.id, overlay, 'modal'));
            
            // 显示模态框
            setTimeout(() => overlay.classList.add('active'), 500);
        }
        
        // 渲染 Inlines (插入到主内容区域顶部)
        if (inlines.length > 0) {
            const mainContent = document.querySelector('main');
            if (mainContent) {
                let container = document.getElementById(INLINE_CONTAINER_ID);
                if (!container) {
                    container = document.createElement('div');
                    container.id = INLINE_CONTAINER_ID;
                    container.className = 'announcement-inlines';
                    mainContent.prepend(container);
                }
                
                container.innerHTML = '';
                inlines.forEach(item => {
                    // 验证必要字段
                    if (!item || typeof item.id === 'undefined' || !item.title || !item.content) {
                        return;
                    }
                    const card = document.createElement('div');
                    card.className = `announcement-inline ${item.type}`;
                    card.innerHTML = `
                        <div class="announcement-inline__header">
                            <div class="announcement-inline__title">
                                <i class="fas ${getIconClass(item.type)}"></i>
                                <span>${item.title}</span>
                            </div>
                            ${item.is_dismissible ? `
                            <button class="announcement-inline__close" aria-label="${window.i18n.t('announcement.dismiss')}">
                                <i class="fas fa-times"></i>
                            </button>` : ''}
                        </div>
                        <div class="announcement-inline__body">
                            ${item.content}
                        </div>
                    `;
                    
                    if (item.is_dismissible) {
                        const closeBtn = card.querySelector('.announcement-inline__close');
                        closeBtn.addEventListener('click', () => dismissAnnouncement(item.id, card, 'inline'));
                    }
                    
                    container.appendChild(card);
                });
            }
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
    
    // 初始化
    fetchAnnouncements();
}

// 在 i18nReady 事件中初始化公告系统
window.addEventListener('i18nReady', initAnnouncementSystem);
