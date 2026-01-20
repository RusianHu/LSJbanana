<?php
/**
 * 公告管理页面
 */

require_once __DIR__ . '/../admin_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../i18n/I18n.php';

$adminAuth = getAdminAuth();

// 页面权限验证
if (!$adminAuth->requireAuth()) {
    exit;
}

// 获取配置
$config = require __DIR__ . '/../config.php';
$announcementConfig = $config['announcement'] ?? [];
$announcementSystemEnabled = $announcementConfig['enabled'] ?? true;

$db = Database::getInstance();

// 确保公告表存在
$db->initAnnouncementTables();

// 获取分页参数
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// 获取筛选参数
$filters = [];
if (!empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (!empty($_GET['type'])) {
    $filters['type'] = $_GET['type'];
}
if (!empty($_GET['display_mode'])) {
    $filters['display_mode'] = $_GET['display_mode'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = trim($_GET['search']);
}

// 获取公告列表
$announcements = $db->getAnnouncements($perPage, $offset, $filters);
$totalCount = $db->getAnnouncementCount($filters);
$totalPages = ceil($totalCount / $perPage);
?>
<!DOCTYPE html>
<html lang="<?php echo i18n()->getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('admin.announcements.title'); ?> - <?php _e('admin.title'); ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* 公告管理专用样式 */
        .announcement-type {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .announcement-type.info {
            background: #e3f2fd;
            color: #1565c0;
        }
        .announcement-type.warning {
            background: #fff3e0;
            color: #e65100;
        }
        .announcement-type.success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .announcement-type.important {
            background: #fffde7;
            color: #6b4c00;
        }
        .display-mode-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            background: #f0f0f0;
            color: #666;
        }
        .target-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            background: #e8e8e8;
            color: #555;
        }
        .time-range {
            font-size: 12px;
            color: #888;
        }
        .priority-badge {
            display: inline-block;
            min-width: 24px;
            text-align: center;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background: #f5f5f5;
            color: #666;
        }
        .priority-badge.high {
            background: #ffecb3;
            color: #ff8f00;
        }
        .content-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 13px;
            color: #666;
        }
        .btn-create {
            background: linear-gradient(135deg, #ffd54f, #ffca28);
            color: #333;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-create:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 202, 40, 0.4);
        }
        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
        }
        .filters-bar select,
        .filters-bar input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .filters-bar .btn-search {
            padding: 8px 16px;
            background: #2196f3;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .filters-bar .btn-reset {
            padding: 8px 16px;
            background: #757575;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        
        /* 模态框样式 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.show {
            display: flex;
        }
        .modal-content {
            background: #fff;
            border-radius: 12px;
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        /* 表单样式 */
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #333;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="datetime-local"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        .form-group .hint {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        
        /* 预览区域 */
        .preview-section {
            margin-top: 20px;
            padding: 16px;
            background: #f9f9f9;
            border-radius: 8px;
            border: 1px dashed #ddd;
        }
        .preview-section h4 {
            margin: 0 0 12px 0;
            font-size: 14px;
            color: #666;
        }
        .preview-announcement {
            padding: 12px 16px;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .preview-announcement.info {
            background: linear-gradient(135deg, #e3f2fd 0%, #fff 100%);
            border-color: #1976d2;
        }
        .preview-announcement.warning {
            background: linear-gradient(135deg, #fff3e0 0%, #fff 100%);
            border-color: #ff9800;
        }
        .preview-announcement.success {
            background: linear-gradient(135deg, #e8f5e9 0%, #fff 100%);
            border-color: #4caf50;
        }
        .preview-announcement.important {
            background: linear-gradient(135deg, #fffde7 0%, #fff8e1 100%);
            border-color: #ffc107;
        }
        .preview-title {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .preview-content {
            font-size: 14px;
            color: #555;
        }
        
        /* 响应式 */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .filters-bar {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1><i class="fas fa-bullhorn"></i> <?php _e('admin.announcements.title'); ?></h1>
            <?php if ($announcementSystemEnabled): ?>
            <button class="btn-create" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> <?php _e('admin.announcements.create'); ?>
            </button>
            <?php endif; ?>
        </div>

        <?php if (!$announcementSystemEnabled): ?>
        <!-- 公告系统禁用提示 -->
        <div class="system-disabled-notice" style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 24px; color: #ff9800;"></i>
            <div>
                <h4 style="margin: 0 0 5px 0; color: #856404;"><?php _e('admin.warning'); ?></h4>
                <p style="margin: 0; color: #856404;"><?php _e('admin.announcements.system_disabled'); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- 筛选栏 -->
        <form class="filters-bar" method="get" action="">
            <select name="status">
                <option value=""><?php _e('admin.announcements.all_status'); ?></option>
                <option value="active" <?php echo ($_GET['status'] ?? '') === 'active' ? 'selected' : ''; ?>><?php _e('admin.announcements.status.active'); ?></option>
                <option value="inactive" <?php echo ($_GET['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php _e('admin.announcements.status.inactive'); ?></option>
            </select>
            <select name="type">
                <option value=""><?php _e('admin.announcements.all_types'); ?></option>
                <option value="info" <?php echo ($_GET['type'] ?? '') === 'info' ? 'selected' : ''; ?>><?php _e('announcement.type.info'); ?></option>
                <option value="warning" <?php echo ($_GET['type'] ?? '') === 'warning' ? 'selected' : ''; ?>><?php _e('announcement.type.warning'); ?></option>
                <option value="success" <?php echo ($_GET['type'] ?? '') === 'success' ? 'selected' : ''; ?>><?php _e('announcement.type.success'); ?></option>
                <option value="important" <?php echo ($_GET['type'] ?? '') === 'important' ? 'selected' : ''; ?>><?php _e('announcement.type.important'); ?></option>
            </select>
            <select name="display_mode">
                <option value=""><?php _e('admin.announcements.all_modes'); ?></option>
                <option value="banner" <?php echo ($_GET['display_mode'] ?? '') === 'banner' ? 'selected' : ''; ?>><?php _e('announcement.display_mode.banner'); ?></option>
                <option value="modal" <?php echo ($_GET['display_mode'] ?? '') === 'modal' ? 'selected' : ''; ?>><?php _e('announcement.display_mode.modal'); ?></option>
                <option value="inline" <?php echo ($_GET['display_mode'] ?? '') === 'inline' ? 'selected' : ''; ?>><?php _e('announcement.display_mode.inline'); ?></option>
            </select>
            <input type="text" name="search" placeholder="<?php _e('admin.announcements.search_placeholder'); ?>" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            <button type="submit" class="btn-search"><i class="fas fa-search"></i> <?php _e('form.search'); ?></button>
            <a href="<?php echo url('/admin/announcements.php'); ?>" class="btn-reset"><?php _e('form.reset'); ?></a>
        </form>

        <!-- 公告列表 -->
        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php _e('admin.announcements.form.title'); ?></th>
                        <th><?php _e('admin.announcements.form.type'); ?></th>
                        <th><?php _e('admin.announcements.form.display_mode'); ?></th>
                        <th><?php _e('admin.announcements.form.target'); ?></th>
                        <th><?php _e('admin.announcements.form.priority'); ?></th>
                        <th><?php _e('admin.table.status'); ?></th>
                        <th><?php _e('admin.announcements.time_range'); ?></th>
                        <th><?php _e('admin.table.actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($announcements)): ?>
                        <tr>
                            <td colspan="9" class="text-center"><?php _e('admin.announcements.no_announcements'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <?php
                            $now = date('Y-m-d H:i:s');
                            $isExpired = !empty($announcement['end_at']) && $announcement['end_at'] < $now;
                            $isScheduled = !empty($announcement['start_at']) && $announcement['start_at'] > $now;
                            ?>
                            <tr>
                                <td><?php echo $announcement['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                    <div class="content-preview"><?php echo htmlspecialchars(strip_tags($announcement['content'])); ?></div>
                                </td>
                                <td>
                                    <span class="announcement-type <?php echo $announcement['type']; ?>">
                                        <?php
                                        $icons = ['info' => 'fa-info-circle', 'warning' => 'fa-exclamation-triangle', 'success' => 'fa-check-circle', 'important' => 'fa-star'];
                                        echo '<i class="fas ' . ($icons[$announcement['type']] ?? 'fa-info-circle') . '"></i> ';
                                        _e('announcement.type.' . $announcement['type']);
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="display-mode-badge"><?php _e('announcement.display_mode.' . $announcement['display_mode']); ?></span>
                                </td>
                                <td>
                                    <span class="target-badge"><?php _e('announcement.target.' . $announcement['target']); ?></span>
                                </td>
                                <td>
                                    <span class="priority-badge <?php echo $announcement['priority'] >= 10 ? 'high' : ''; ?>"><?php echo $announcement['priority']; ?></span>
                                </td>
                                <td>
                                    <?php if ($isExpired): ?>
                                        <span class="status-badge inactive"><?php _e('admin.announcements.status.expired'); ?></span>
                                    <?php elseif ($isScheduled): ?>
                                        <span class="status-badge pending"><?php _e('admin.announcements.status.scheduled'); ?></span>
                                    <?php elseif ($announcement['is_active']): ?>
                                        <span class="status-badge active"><?php _e('admin.announcements.status.active'); ?></span>
                                    <?php else: ?>
                                        <span class="status-badge inactive"><?php _e('admin.announcements.status.inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="time-range">
                                    <?php
                                    if (empty($announcement['start_at']) && empty($announcement['end_at'])) {
                                        _e('admin.announcements.time_permanent');
                                    } else {
                                        $start = $announcement['start_at'] ? date('m/d H:i', strtotime($announcement['start_at'])) : __('admin.announcements.time_now');
                                        $end = $announcement['end_at'] ? date('m/d H:i', strtotime($announcement['end_at'])) : __('admin.announcements.time_forever');
                                        echo $start . ' - ' . $end;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-icon btn-edit" onclick="openEditModal(<?php echo $announcement['id']; ?>)" title="<?php _e('action.edit'); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-icon btn-toggle" onclick="toggleStatus(<?php echo $announcement['id']; ?>)" title="<?php echo $announcement['is_active'] ? __('action.disable') : __('action.enable'); ?>">
                                            <i class="fas <?php echo $announcement['is_active'] ? 'fa-toggle-on' : 'fa-toggle-off'; ?>"></i>
                                        </button>
                                        <button class="btn-icon btn-delete" onclick="deleteAnnouncement(<?php echo $announcement['id']; ?>)" title="<?php _e('action.delete'); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="page-btn">
                        <i class="fas fa-chevron-left"></i> <?php _e('admin.pagination.prev'); ?>
                    </a>
                <?php endif; ?>
                
                <span class="page-info">
                    <?php _e('admin.pagination.page_info', ['current' => $page, 'total' => $totalPages, 'count' => $totalCount]); ?>
                </span>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="page-btn">
                        <?php _e('admin.pagination.next'); ?> <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 创建/编辑公告模态框 -->
    <div class="modal-overlay" id="announcementModal" data-modal="announcement" style="display: none;">
        <div class="modal-content" data-modal-content>
            <div class="modal-header">
                <h3 id="modalTitle"><?php _e('admin.announcements.create'); ?></h3>
                <button type="button" class="modal-close" data-modal-close aria-label="<?php _e('form.close'); ?>">&times;</button>
            </div>
            <div class="modal-body">
                <form id="announcementForm">
                    <input type="hidden" name="id" id="formId">
                    
                    <div class="form-group">
                        <label for="formTitle"><?php _e('admin.announcements.form.title'); ?> *</label>
                        <input type="text" id="formTitle" name="title" required maxlength="200">
                    </div>
                    
                    <div class="form-group">
                        <label for="formContent"><?php _e('admin.announcements.form.content'); ?> *</label>
                        <textarea id="formContent" name="content" required></textarea>
                        <div class="hint"><?php _e('admin.announcements.form.content_hint'); ?></div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="formType"><?php _e('admin.announcements.form.type'); ?></label>
                            <select id="formType" name="type">
                                <option value="info"><?php _e('announcement.type.info'); ?></option>
                                <option value="warning"><?php _e('announcement.type.warning'); ?></option>
                                <option value="success"><?php _e('announcement.type.success'); ?></option>
                                <option value="important"><?php _e('announcement.type.important'); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="formDisplayMode"><?php _e('admin.announcements.form.display_mode'); ?></label>
                            <select id="formDisplayMode" name="display_mode">
                                <option value="banner"><?php _e('announcement.display_mode.banner'); ?></option>
                                <option value="modal"><?php _e('announcement.display_mode.modal'); ?></option>
                                <option value="inline"><?php _e('announcement.display_mode.inline'); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="formTarget"><?php _e('admin.announcements.form.target'); ?></label>
                            <select id="formTarget" name="target">
                                <option value="all"><?php _e('announcement.target.all'); ?></option>
                                <option value="logged_in"><?php _e('announcement.target.logged_in'); ?></option>
                                <option value="guest"><?php _e('announcement.target.guest'); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="formPriority"><?php _e('admin.announcements.form.priority'); ?></label>
                            <input type="number" id="formPriority" name="priority" value="0" min="0" max="100">
                            <div class="hint"><?php _e('admin.announcements.form.priority_hint'); ?></div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="formStartAt"><?php _e('admin.announcements.form.start_at'); ?></label>
                            <input type="datetime-local" id="formStartAt" name="start_at">
                        </div>
                        <div class="form-group">
                            <label for="formEndAt"><?php _e('admin.announcements.form.end_at'); ?></label>
                            <input type="datetime-local" id="formEndAt" name="end_at">
                        </div>
                    </div>
                    <div class="hint"><?php _e('admin.announcements.form.time_hint'); ?></div>
                    
                    <div class="form-row" style="margin-top: 16px;">
                        <div class="form-group">
                            <label class="form-checkbox">
                                <input type="checkbox" id="formDismissible" name="is_dismissible" checked>
                                <?php _e('admin.announcements.form.is_dismissible'); ?>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="form-checkbox">
                                <input type="checkbox" id="formActive" name="is_active" checked>
                                <?php _e('admin.announcements.form.is_active'); ?>
                            </label>
                        </div>
                    </div>
                    
                    <!-- 预览区域 -->
                    <div class="preview-section">
                        <h4><i class="fas fa-eye"></i> <?php _e('admin.announcements.preview'); ?></h4>
                        <div class="preview-announcement info" id="previewAnnouncement">
                            <div class="preview-title" id="previewTitle"><?php _e('admin.announcements.preview_title'); ?></div>
                            <div class="preview-content" id="previewContent"><?php _e('admin.announcements.preview_content'); ?></div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" data-modal-close><?php _e('form.cancel'); ?></button>
                <button type="button" class="btn-primary" id="saveAnnouncementBtn"><?php _e('form.save'); ?></button>
            </div>
        </div>
    </div>

    <script>
        // ========== 模态框管理器 ==========
        const AnnouncementModal = {
            modal: null,
            form: null,
            isOpen: false,
            
            init() {
                this.modal = document.getElementById('announcementModal');
                this.form = document.getElementById('announcementForm');
                if (!this.modal) return;
                
                this.bindEvents();
            },
            
            bindEvents() {
                // 使用事件委托处理所有关闭按钮
                this.modal.addEventListener('click', (e) => {
                    // 点击遮罩背景关闭
                    if (e.target === this.modal) {
                        this.close();
                        return;
                    }
                    // 点击带有 data-modal-close 属性的元素关闭
                    if (e.target.closest('[data-modal-close]')) {
                        e.preventDefault();
                        e.stopPropagation();
                        this.close();
                        return;
                    }
                });
                
                // ESC 键关闭
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.isOpen) {
                        this.close();
                    }
                });
                
                // 保存按钮
                const saveBtn = document.getElementById('saveAnnouncementBtn');
                if (saveBtn) {
                    saveBtn.addEventListener('click', () => this.save());
                }
            },
            
            open(mode = 'create', id = null) {
                if (!this.modal) return;
                
                if (mode === 'create') {
                    document.getElementById('modalTitle').textContent = '<?php _e('admin.announcements.create'); ?>';
                    this.form.reset();
                    document.getElementById('formId').value = '';
                    document.getElementById('formDismissible').checked = true;
                    document.getElementById('formActive').checked = true;
                    updatePreview();
                    this.show();
                } else if (mode === 'edit' && id) {
                    document.getElementById('modalTitle').textContent = '<?php _e('admin.announcements.edit'); ?>';
                    this.loadAndEdit(id);
                }
            },
            
            loadAndEdit(id) {
                fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=get_announcement&id=' + id
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const a = data.data;
                        document.getElementById('formId').value = a.id;
                        document.getElementById('formTitle').value = a.title;
                        document.getElementById('formContent').value = a.content;
                        document.getElementById('formType').value = a.type;
                        document.getElementById('formDisplayMode').value = a.display_mode;
                        document.getElementById('formTarget').value = a.target;
                        document.getElementById('formPriority').value = a.priority;
                        document.getElementById('formDismissible').checked = a.is_dismissible == 1;
                        document.getElementById('formActive').checked = a.is_active == 1;
                        
                        // 处理日期时间
                        if (a.start_at) {
                            document.getElementById('formStartAt').value = a.start_at.replace(' ', 'T').slice(0, 16);
                        } else {
                            document.getElementById('formStartAt').value = '';
                        }
                        if (a.end_at) {
                            document.getElementById('formEndAt').value = a.end_at.replace(' ', 'T').slice(0, 16);
                        } else {
                            document.getElementById('formEndAt').value = '';
                        }
                        
                        updatePreview();
                        this.show();
                    } else {
                        alert(data.message || '<?php _e('error.data_load_failed'); ?>');
                    }
                })
                .catch(e => {
                    console.error(e);
                    alert('<?php _e('error.request_failed'); ?>');
                });
            },
            
            show() {
                if (!this.modal) return;
                this.modal.style.display = 'flex'; // 强制显示
                // 稍微延迟添加 show 类以触发动画（如果有）
                requestAnimationFrame(() => {
                    this.modal.classList.add('show');
                });
                this.isOpen = true;
                document.body.style.overflow = 'hidden';
                
                // 焦点管理：聚焦到第一个输入框
                setTimeout(() => {
                    const firstInput = this.modal.querySelector('input:not([type="hidden"]), textarea');
                    if (firstInput) firstInput.focus();
                }, 100);
            },
            
            close() {
                if (!this.modal) return;
                this.modal.classList.remove('show');
                // 等待动画结束后隐藏
                setTimeout(() => {
                    if (!this.modal.classList.contains('show')) {
                        this.modal.style.display = 'none';
                    }
                }, 300);
                this.isOpen = false;
                document.body.style.overflow = '';
            },
            
            save() {
                const formData = new FormData(this.form);
                const id = formData.get('id');
                
                formData.append('action', id ? 'update_announcement' : 'create_announcement');
                formData.set('is_dismissible', document.getElementById('formDismissible').checked ? '1' : '0');
                formData.set('is_active', document.getElementById('formActive').checked ? '1' : '0');
                
                // 转换日期时间格式
                const startAt = formData.get('start_at');
                const endAt = formData.get('end_at');
                if (startAt) formData.set('start_at', startAt.replace('T', ' ') + ':00');
                if (endAt) formData.set('end_at', endAt.replace('T', ' ') + ':00');
                
                fetch('api.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        this.close();
                        location.reload();
                    } else {
                        alert(data.message || '<?php _e('error.unknown'); ?>');
                    }
                })
                .catch(e => {
                    console.error(e);
                    alert('<?php _e('error.request_failed'); ?>');
                });
            }
        };
        
        // 兼容旧的全局函数调用
        function openCreateModal() {
            AnnouncementModal.open('create');
        }
        
        function openEditModal(id) {
            AnnouncementModal.open('edit', id);
        }
        
        function closeModal() {
            AnnouncementModal.close();
        }
        
        function toggleStatus(id) {
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=toggle_announcement&id=' + id
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '<?php _e('error.unknown'); ?>');
                }
            })
            .catch(e => {
                console.error(e);
                alert('<?php _e('error.request_failed'); ?>');
            });
        }
        
        function deleteAnnouncement(id) {
            if (!confirm('<?php _e('admin.announcements.delete_confirm'); ?>')) {
                return;
            }
            
            fetch('api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_announcement&id=' + id
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '<?php _e('error.unknown'); ?>');
                }
            })
            .catch(e => {
                console.error(e);
                alert('<?php _e('error.request_failed'); ?>');
            });
        }
        
        // 实时预览
        function updatePreview() {
            const title = document.getElementById('formTitle').value || '<?php _e('admin.announcements.preview_title'); ?>';
            const content = document.getElementById('formContent').value || '<?php _e('admin.announcements.preview_content'); ?>';
            const type = document.getElementById('formType').value;
            
            const previewEl = document.getElementById('previewAnnouncement');
            previewEl.className = 'preview-announcement ' + type;
            document.getElementById('previewTitle').textContent = title;
            document.getElementById('previewContent').innerHTML = content;
        }
        
        // 监听表单变化
        document.getElementById('formTitle').addEventListener('input', updatePreview);
        document.getElementById('formContent').addEventListener('input', updatePreview);
        document.getElementById('formType').addEventListener('change', updatePreview);
        
        // 初始化模态框管理器
        document.addEventListener('DOMContentLoaded', function() {
            AnnouncementModal.init();
        });
    </script>
</body>
</html>