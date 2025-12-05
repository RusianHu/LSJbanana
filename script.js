document.addEventListener('DOMContentLoaded', () => {
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
	            filePreview.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> 点击或拖拽上传图片';
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
	                alert(`最多支持 ${MAX_EDIT_IMAGES} 张参考图片`);
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
    function setupPromptOptimizer({ textareaId, buttonId, statusId, modeGroup }) {
        const promptInput = document.getElementById(textareaId);
        const actionBtn = document.getElementById(buttonId);
        const statusEl = document.getElementById(statusId);
        const modeButtons = document.querySelectorAll(`[data-optimize-mode][data-optimize-group="${modeGroup}"]`);
        let optimizeMode = 'basic';

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
                setStatus('请先输入提示词，再试试优化。', true);
                promptInput.focus();
                return;
            }

            const originalHtml = actionBtn.innerHTML;
            setStatus('优化中，请稍候...');
            actionBtn.disabled = true;
            actionBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> 优化中...';

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
                    throw new Error(data.message || '优化失败，请稍后重试');
                }

                if (data.optimized_prompt) {
                    promptInput.value = data.optimized_prompt;
                    setStatus('优化完成，已填入编辑框。');
                } else {
                    throw new Error('未获取到优化结果');
                }
            } catch (err) {
                setStatus(`优化失败：${err.message}`, true);
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
        modeGroup: 'generate'
    });

    setupPromptOptimizer({
        textareaId: 'edit-prompt',
        buttonId: 'optimize-prompt-btn',
        statusId: 'optimize-status',
        modeGroup: 'edit'
    });

    // 表单提交处理
    const generateForm = document.getElementById('generate-form');
    const editForm = document.getElementById('edit-form');
    const resultArea = document.getElementById('result-area');
    const loading = document.getElementById('loading');
    const errorMessage = document.getElementById('error-message');
    const outputContainer = document.getElementById('output-container');
    const timerDisplay = document.getElementById('timer');

	    // 通用提交函数
	    async function handleFormSubmit(event, type) {
	        event.preventDefault();

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
	                errorMessage.textContent = '请先选择至少一张参考图片（可多次从不同文件夹添加）。';
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
	            timerDisplay.textContent = "已耗时: 0.00 s";
	        }
	        let startTime = Date.now();
	        let timerInterval = setInterval(() => {
	            const elapsedTime = (Date.now() - startTime) / 1000;
	            if (timerDisplay) {
	                timerDisplay.textContent = `已耗时: ${elapsedTime.toFixed(2)} s`;
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

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                // 计算最终耗时
                const finalTime = ((Date.now() - startTime) / 1000).toFixed(2);

                // 显示结果
                if (data.images && data.images.length > 0) {
                    data.images.forEach(imgUrl => {
                        const imgDiv = document.createElement('div');
                        imgDiv.className = 'output-item';
                        imgDiv.innerHTML = `
                            <img src="${imgUrl}" alt="Generated Image">
                            <p><a href="${imgUrl}" download target="_blank" class="btn-primary" style="display:inline-block; width:auto; padding: 5px 15px; font-size: 0.9rem; margin-top: 5px;">下载图片</a></p>
                        `;
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
                    
                    let groundingHtml = '<h4><i class="fab fa-google"></i> 搜索来源信息</h4>';
                    
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
                timeDiv.innerHTML = `<p><i class="fas fa-clock"></i> 生成耗时: ${finalTime} 秒</p>`;
                outputContainer.appendChild(timeDiv);

            } else {
                throw new Error(data.message || '未知错误');
            }

        } catch (error) {
            console.error('Error:', error);
            errorMessage.textContent = `发生错误: ${error.message}`;
            errorMessage.classList.remove('hidden');
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
        console.warn('当前浏览器不支持语音录入功能');
        return;
    }

    console.log(`语音识别: Web Speech API ${webSpeechSupported ? '可用' : '不可用'}, MediaRecorder ${mediaRecorderSupported ? '可用' : '不可用'}`);

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
            btn.title = '点击停止识别 (Web Speech)';
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
                        errorMsg = '麦克风权限被拒绝';
                        // 不回退，因为回退方案也需要麦克风权限
                        break;
                    case 'no-speech':
                        // 没有检测到语音，静默处理
                        break;
                    case 'network':
                        errorMsg = '网络错误，切换到离线模式';
                        shouldFallback = true;
                        break;
                    case 'service-not-allowed':
                    case 'not-allowed':
                        // 服务不可用，尝试回退
                        shouldFallback = true;
                        break;
                    default:
                        errorMsg = '语音识别出错: ' + event.error;
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
                alert('无法启动语音识别');
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
            btn.title = '点击停止录音 (Gemini API)';

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
            let errorMsg = '无法访问麦克风';
            if (error.name === 'NotAllowedError') {
                errorMsg = '麦克风权限被拒绝，请在浏览器设置中允许访问麦克风';
            } else if (error.name === 'NotFoundError') {
                errorMsg = '未检测到麦克风设备';
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
        btn.title = '正在转换 (Gemini API)...';
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
                alert('语音转换失败: ' + result.message);
            } else {
                alert('未能识别到语音内容，请重试');
            }
        } catch (error) {
            console.error('语音转换请求失败:', error);
            alert('语音转换失败，请检查网络连接后重试');
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
        btn.title = webSpeechSupported ? '语音输入 (Web Speech)' : '语音输入 (Gemini API)';
        btn.disabled = false;

        isListening = false;
        isRecording = false;
        activeButton = null;
        recognition = null;
        interimTranscript = '';
    }
}
