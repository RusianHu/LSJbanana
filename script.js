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

    // 文件上传预览
    const fileInput = document.getElementById('edit-image');
    const filePreview = document.getElementById('image-preview');

    fileInput.addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            filePreview.innerHTML = ''; // Clear previous content
            filePreview.style.padding = '10px';
            filePreview.style.display = 'flex';
            filePreview.style.flexWrap = 'wrap';
            filePreview.style.gap = '10px';
            filePreview.style.justifyContent = 'center';

            Array.from(this.files).forEach(file => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.maxHeight = '100px';
                    img.style.maxWidth = '100px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '4px';
                    filePreview.appendChild(img);
                }
                reader.readAsDataURL(file);
            });
        } else {
            filePreview.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> 点击或拖拽上传图片';
            filePreview.style.padding = '30px';
            filePreview.style.display = 'block';
        }
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
        
        // UI 状态更新
        resultArea.classList.remove('hidden');
        loading.classList.remove('hidden');
        errorMessage.classList.add('hidden');
        outputContainer.innerHTML = '';
        
        // 重置并启动计时器
        if (timerDisplay) timerDisplay.textContent = "已耗时: 0.00 s";
        let startTime = Date.now();
        let timerInterval = setInterval(() => {
            const elapsedTime = (Date.now() - startTime) / 1000;
            if (timerDisplay) timerDisplay.textContent = `已耗时: ${elapsedTime.toFixed(2)} s`;
        }, 10);
        
        // 滚动到结果区域
        resultArea.scrollIntoView({ behavior: 'smooth' });

        const formData = new FormData(event.target);
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
});