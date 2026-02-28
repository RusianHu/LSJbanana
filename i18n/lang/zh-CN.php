<?php
/**
 * 简体中文语言包 (zh-CN)
 * 
 * 语言包结构：
 * - site: 网站基本信息
 * - nav: 导航菜单
 * - auth: 认证相关（登录、注册、登出等）
 * - user: 用户相关
 * - form: 表单通用
 * - validation: 验证消息
 * - error: 错误消息
 * - success: 成功消息
 * - action: 操作按钮
 * - index: 首页
 * - recharge: 充值页面
 * - password: 密码相关
 * - api: API 响应消息
 * - admin: 管理后台
 * - time: 时间格式
 * - status: 状态文本
 * - payment: 支付相关
 * - js: JavaScript 前端文本
 */

return [
    // ============================================================
    // 网站基本信息
    // ============================================================
    'site' => [
        'title' => '老司机的香蕉',
        'subtitle' => 'LSJbanana',
        'description' => '基于 gemini-3.1-flash-image (Nano Banana 2) 的图片生成与编辑工具',
        'copyright' => '© :year LSJbanana Project. Powered by Gemini.',
        'github_title' => '在 GitHub 上查看源码',
        'github_text' => '源码',
        'sponsor_title' => '为项目充电',
        'sponsor_text' => '投喂',
        'sponsor_desc' => '如果觉得好用，可以请老司机喝杯雪王',
    ],

    // ============================================================
    // 公告系统
    // ============================================================
    'announcement' => [
        'title' => '公告',
        'no_announcements' => '暂无公告',
        'dismiss' => '关闭',
        'read_more' => '查看详情',
        'i_know' => '我知道了',
        'dismiss_success' => '公告已关闭',
        'dismiss_local' => '公告已关闭（本地记录）',
        'type' => [
            'info' => '普通信息',
            'warning' => '警告提醒',
            'success' => '成功通知',
            'important' => '重要公告',
        ],
        'display_mode' => [
            'banner' => '顶部横幅',
            'modal' => '弹窗通知',
            'inline' => '内嵌卡片',
        ],
        'target' => [
            'all' => '所有用户',
            'logged_in' => '仅登录用户',
            'guest' => '仅访客',
        ],
    ],

    // ============================================================
    // 导航菜单
    // ============================================================
    'nav' => [
        'home' => '首页',
        'login' => '登录',
        'register' => '注册',
        'logout' => '退出登录',
        'recharge' => '充值',
        'change_password' => '修改密码',
        'back_home' => '返回首页',
        'admin' => '管理后台',
    ],

    // ============================================================
    // 认证相关
    // ============================================================
    'auth' => [
        'login' => '登录',
        'register' => '注册',
        'logout' => '退出登录',
        'login_title' => '欢迎回来',
        'login_subtitle' => '登录您的账号继续使用',
        'register_title' => '创建账号',
        'register_subtitle' => '加入老司机的香蕉，开始AI创作之旅',
        'already_logged_in' => '已登录',
        'already_logged_in_desc' => '您已登录，无需重复操作。',
        'already_logged_in_register' => '您已登录，无需重复注册。',
        'login_success' => '登录成功',
        'login_success_desc' => '登录已完成，请继续。',
        'register_success' => '注册成功',
        'register_success_desc' => '账号已创建，请使用新账号登录。',
        'logout_success' => '已退出登录',
        'logout_success_desc' => '您已安全退出登录。',
        'require_login' => '需要登录',
        'require_login_desc' => '请先登录后继续访问该页面。',
        'login_now' => '立即登录',
        'register_now' => '立即注册',
        'go_login' => '前往登录',
        'go_register' => '前往充值',
        'continue' => '继续前往',
        'no_account' => '没有账号？',
        'has_account' => '已有账号？',
        'remember_me' => '记住我',
        'forgot_password' => '忘记密码？',
        'quick_login' => '快速登录',
        'quick_login_success' => '快速登录已完成，请继续。',
        'quick_login_invalid' => '无效的快速登录链接',
        'quick_login_failed' => '快速登录失败',
        'registration_closed' => '当前不开放注册，请稍后再试。',
        'session_expired' => '会话已过期，请重新登录',
        'safe_logout' => '已安全登出',
        // 错误消息
        'error' => [
            'username_or_password' => '用户名或密码错误',
            'account_disabled' => '账号已被禁用',
            'captcha_required' => '请输入验证码',
            'captcha_invalid' => '验证码错误或已过期',
            'registration_disabled' => '当前不开放注册',
            'username_exists' => '用户名已被使用',
            'email_exists' => '邮箱已被注册',
            'register_failed' => '注册失败，请稍后重试',
            'password_mismatch' => '两次输入的密码不一致',
        ],
    ],

    // ============================================================
    // 用户相关
    // ============================================================
    'user' => [
        'username' => '用户名',
        'email' => '邮箱',
        'password' => '密码',
        'password_confirm' => '确认密码',
        'balance' => '余额',
        'balance_unit' => '元',
        'status' => '状态',
        'status_active' => '正常',
        'status_disabled' => '禁用',
        'created_at' => '注册时间',
        'last_login' => '最后登录',
        'username_placeholder' => '输入用户名或邮箱',
        'username_hint' => '3-20个字符，支持中文',
        'email_placeholder' => '用于找回密码',
        'password_placeholder' => '输入密码',
        'password_new_placeholder' => '输入新密码',
        'password_confirm_placeholder' => '再次输入密码',
        'password_hint' => '密码至少需要 :min 个字符',
    ],

    // ============================================================
    // 表单通用
    // ============================================================
    'form' => [
        'submit' => '提交',
        'save' => '保存',
        'cancel' => '取消',
        'confirm' => '确认',
        'close' => '关闭',
        'search' => '搜索',
        'reset' => '重置',
        'clear' => '清除',
        'captcha' => '验证码',
        'captcha_placeholder' => '请输入验证码',
        'captcha_refresh' => '换一张',
        'loading' => '加载中...',
        'processing' => '处理中...',
    ],

    // ============================================================
    // 验证消息
    // ============================================================
    'validation' => [
        'required' => ':field 不能为空',
        'email_invalid' => '邮箱格式不正确',
        'username_min_length' => '用户名至少需要 :min 个字符',
        'username_max_length' => '用户名最多 :max 个字符',
        'username_format' => '用户名只能包含字母、数字、下划线和中文',
        'password_min_length' => '密码至少需要 :min 个字符',
        'password_mismatch' => '两次输入的密码不一致',
        'old_password_wrong' => '旧密码错误',
        'amount_min' => '最低金额为 :min 元',
        'amount_max' => '最高金额为 :max 元',
        'max_size' => '文件大小不能超过 :size',
    ],

    // ============================================================
    // 错误消息
    // ============================================================
    'error' => [
        'system' => '系统错误',
        'unknown' => '未知错误',
        'config_missing' => '配置文件不存在：config.php。请复制 config.php.example 并根据环境配置。',
        'config_load_failed' => '配置文件加载失败',
        'db_connection_failed' => '数据库连接失败',
        'init_failed' => '系统初始化失败',
        'init_warning' => '系统初始化警告',
        'data_load_failed' => '数据加载失败',
        'request_failed' => '请求失败',
        'permission_denied' => '权限不足',
        'not_found' => '未找到',
        'user_not_found' => '用户不存在',
        'possible_causes' => '可能的原因',
        'suggested_actions' => '建议操作',
        'cause_config' => '配置文件 (config.php) 不存在或格式错误',
        'cause_db' => '数据库文件损坏或权限不足',
        'cause_extension' => '必需的 PHP 扩展未安装',
        'action_check_config' => '检查 config.php.example 并创建正确的 config.php',
        'action_check_db' => '确认 database 目录存在且具有写入权限',
        'action_check_logs' => '查看服务器错误日志获取详细信息',
        'partial_function' => '部分功能可能无法正常使用。请检查配置文件和数据库设置。',
        'prompt_rejected' => '提示词被拒绝',
        'optimization_interrupted' => '优化过程被中断',
        'optimization_no_result' => '未获取到优化结果',
        'invalid_token_length' => '无效的令牌长度',
        'secure_token_failed' => '无法生成安全令牌',
        'upload_failed' => '上传失败',
        'invalid_upload_source' => '无效的上传来源',
        'file_too_large' => '文件过大',
        'unsupported_image_format' => '不支持的图片格式',
        'invalid_format' => '无效的格式',
        'read_failed' => '读取失败',
    ],

    // ============================================================
    // 成功消息
    // ============================================================
    'success' => [
        'saved' => '保存成功',
        'updated' => '更新成功',
        'deleted' => '删除成功',
        'operation' => '操作成功',
    ],

    // ============================================================
    // 操作按钮
    // ============================================================
    'action' => [
        'view' => '查看',
        'edit' => '编辑',
        'delete' => '删除',
        'enable' => '启用',
        'disable' => '禁用',
        'refresh' => '刷新',
        'download' => '下载',
        'upload' => '上传',
        'generate' => '生成',
        'optimize' => '优化',
        'start' => '开始',
        'stop' => '停止',
        'retry' => '重试',
        'back' => '返回',
        'next' => '下一步',
        'prev' => '上一步',
        'more' => '更多',
    ],

    // ============================================================
    // 首页 (图片生成)
    // ============================================================
    'index' => [
        'tab_generate' => '文生图 (Generate)',
        'tab_edit' => '图生图/编辑 (Edit)',
        // 文生图
        'prompt_label' => '提示词 (Prompt):',
        'prompt_placeholder' => '描述你想要生成的图片...',
        'aspect_ratio' => '宽高比:',
        'resolution' => '分辨率:',
        'aspect_square' => '正方形',
        'aspect_wide' => '宽屏',
        'aspect_tall' => '竖屏',
        'use_google_search' => '使用 Google 搜索增强 (Grounding)',
        'use_google_search_hint' => '基于实时数据生成',
        'btn_generate' => '生成图片',
        // 图生图
        'upload_label' => '上传参考图片 (支持多选/多次添加，最多14张):',
        'upload_hint' => '点击或拖拽上传图片',
        'edit_prompt_label' => '编辑指令 / 提示词:',
        'edit_prompt_placeholder' => '描述如何修改这张图片，或者描述新图片...',
        'aspect_keep' => '保持原样 / 默认',
        'resolution_default' => '默认 (1K)',
        'btn_edit' => '开始编辑',
        // 提示词优化
        'prompt_optimize' => '提示词优化',
        'prompt_optimize_tip' => '调用 gemini-2.5-flash 自动扩写提示词，生成前先润色或加强细节。',
        'optimize_mode_enhance' => '增强模式',
        'optimize_mode_detail' => '细节模式',
        'btn_optimize' => '一键优化',
        'optimize_status_processing' => '优化中，请稍候...',
        'optimize_status_done' => '优化完成，已填入编辑框。',
        'optimize_status_failed' => '优化失败',
        // 结果区域
        'result_title' => '生成结果',
        'generating' => '正在疯狂生成中，请稍候...',
        'elapsed_time' => '已耗时',
        'generated_time' => '生成耗时',
        'save_notice_title' => '请及时保存图片',
        'save_notice_desc' => '生成的图片仅临时存储在服务器上，不会永久保留。建议立即下载保存到本地。',
        'download_image' => '下载图片',
        'search_sources' => '搜索来源信息',
        // 语音输入
        'voice_input' => '语音输入',
        'voice_stop' => '点击停止识别',
        'voice_stop_recording' => '点击停止录音',
        'voice_converting' => '正在转换',
        // Lightbox
        'lightbox_close' => '关闭',
        'lightbox_zoom_in' => '放大',
        'lightbox_zoom_out' => '缩小',
        'lightbox_fit' => '适应窗口',
        'lightbox_actual' => '原始大小',
        'lightbox_download' => '下载图片',
        'lightbox_help' => '快捷键帮助',
        'lightbox_shortcuts' => '关闭 | 缩放 | 原始大小 | 适应窗口 | 下载 | 鼠标滚轮缩放 | 拖拽移动',
        // 思考过程
        'thinking_process' => '思考过程',
        'thinking_time' => '思考耗时 :seconds 秒',
        'thinking_expand' => '点击展开',
        'thinking_collapse' => '点击收起',
        'ai_thinking' => 'AI 思考过程',
        // 价格提示
        'cost_per_task' => '单次消耗 :price 元',
        'available_times' => '可用 :count 次',
    ],

    // ============================================================
    // 余额相关（前端）
    // ============================================================
    'balance' => [
        'recharge' => '立即充值',
        'insufficient' => '余额不足',
        'current' => '当前余额',
        'required' => '本次需要',
    ],

    // ============================================================
    // 充值页面
    // ============================================================
    'recharge' => [
        'title' => '充值',
        'page_title' => '账户充值',
        'current_balance' => '当前余额',
        'can_generate' => '约可生成 :count 次任务',
        'price_info' => '当前价格',
        'price_per_task' => ':price 元/次',
        'recharge_after_use' => '充值后可立即使用',
        'amount_label' => '充值金额',
        'custom_amount' => '自定义金额',
        'custom_amount_placeholder' => '输入充值金额',
        'pay_method' => '选择支付方式',
        'pay_alipay' => '支付宝',
        'pay_wechat' => '微信支付',
        'pay_qq' => 'QQ支付',
        'pay_cashier' => '收银台',
        'btn_recharge' => '立即充值',
        'order_created' => '订单已创建',
        'order_created_desc' => '请点击下方按钮前往支付页面完成充值。',
        'go_pay' => '前往支付',
        'recharge_history' => '充值记录',
        'order_status' => [
            'pending' => '待支付',
            'paid' => '已完成',
            'cancelled' => '已取消',
            'refunded' => '已退款',
            'expired' => '已过期',
        ],
        'source_online' => '在线支付',
        'source_manual' => '系统调整',
        'error' => [
            'min_amount' => '最低充值金额为 :min 元',
            'max_amount' => '最高充值金额为 :max 元',
            'payment_disabled' => '支付功能暂未开放',
            'create_failed' => '创建支付订单失败',
            'payment_url_invalid' => '支付地址异常，请稍后重试',
        ],
        'payment_redirect_title' => '前往支付页面',
        'payment_redirect_desc' => '请点击下方按钮继续完成支付',
        'btn_continue_pay' => '继续前往支付',
        'browser_no_script' => '浏览器未启用脚本，请点击上方链接继续。',
        'error_url_missing' => '支付地址缺失',
        'error_url_missing_desc' => '未获取到支付地址，请返回充值页面重新提交。',
        'error_url_invalid' => '支付地址异常',
        'error_url_invalid_desc' => '支付地址格式不正确，请返回充值页面重试。',
        'return_recharge' => '返回充值',
    ],

    // ============================================================
    // 支付结果页面
    // ============================================================
    'return' => [
        'title' => '支付结果',
        'success_title' => '充值成功',
        'success_msg' => '充值成功！',
        'pending_title' => '支付处理中',
        'pending_msg' => '支付处理中，请稍候刷新页面查看结果...',
        'failed_title' => '支付失败',
        'failed_msg' => '支付验证失败',
        'invalid_callback' => '无效的支付回调',
        'order_not_found' => '订单不存在',
        'order_no' => '订单号',
        'amount' => '充值金额',
        'pay_time' => '支付时间',
        'refresh_status' => '刷新状态',
        'continue_recharge' => '继续充值',
    ],

    // ============================================================
    // 管理员初始化
    // ============================================================
    'setup_admin' => [
        'title' => '管理员初始化',
        'desc' => '本页面用于初始化管理员相关数据表。请在受信任环境下操作。',
        'status_tables' => '管理员表状态',
        'status_complete' => '已完整',
        'status_missing' => '缺失 :count 张表',
        'status_writable' => '数据库可写',
        'writable_yes' => '可写',
        'writable_no' => '不可写',
        'visit_ip' => '访问IP',
        'tables_to_create' => '将创建的表：:tables',
        'admin_key' => '管理员密钥',
        'admin_key_placeholder' => '请输入管理员密钥',
        'force_reindex' => '强制重新初始化索引（表已存在时仍会执行索引创建）',
        'btn_start' => '开始初始化',
        'note_after' => '初始化完成后可前往管理后台登录。',
        'back_login' => '返回管理员登录',
        'init_complete' => '初始化完成',
        'error_disabled' => '初始化引导未启用，请在 config.php 中启用 admin_setup 配置',
        'error_ip' => '当前IP不允许访问初始化引导页面',
        'error_expired' => '请求已过期，请刷新页面后重试',
        'error_key_required' => '请填写管理员密钥',
    ],

    // ============================================================
    // 密码相关
    // ============================================================
    'password' => [
        'change_title' => '修改密码',
        'change_subtitle' => '为了账户安全，请定期更换密码',
        'current_password' => '当前密码',
        'new_password' => '新密码',
        'confirm_password' => '确认新密码',
        'current_placeholder' => '输入当前密码',
        'new_placeholder' => '输入新密码',
        'confirm_placeholder' => '再次输入新密码',
        'btn_save' => '保存修改',
        'changed_success' => '密码已更新',
        'changed_success_desc' => '密码修改成功，请重新登录。',
        'changed_success_msg' => '密码修改成功，请重新登录',
        'reset_success' => '密码重置成功',
        'reset_success_desc' => '密码重置成功，请重新登录',
        'reset_link_sent' => '如果该邮箱已注册，重置链接已发送',
        'reset_link_invalid' => '重置链接无效或已过期',
        'error' => [
            'current_required' => '请输入当前密码',
            'new_required' => '请输入新密码',
            'mismatch' => '两次输入的新密码不一致',
            'old_wrong' => '旧密码错误',
            'reset_failed' => '密码重置失败',
            'change_failed' => '密码修改失败，请稍后重试',
        ],
    ],

    // ============================================================
    // API 响应消息
    // ============================================================
    'api' => [
        'unauthorized' => '请先登录',
        'unauthorized_use' => '请先登录后再使用此功能',
        'account_disabled' => '您的账号已被禁用，请联系管理员',
        'insufficient_balance' => '余额不足，请先充值',
        'balance_low' => '余额不足，可能已被其他请求消耗，请刷新页面重试',
        'deduct_failed' => '扣费失败',
        'refund_success' => '未生成图片，已退还预扣费用',
        'only_post' => '只支持 POST 请求',
        'invalid_action' => '无效的操作类型',
        'unknown_action' => '未知的操作类型',
        'prompt_required' => '提示词不能为空',
        'image_required' => '请上传至少一张图片',
        'image_max_count' => '最多支持 :max 张参考图片',
        'image_invalid' => '未能处理任何有效的图片文件',
        'no_result' => 'API 未返回任何候选结果',
        'request_failed' => '请求失败',
        'gemini_parse_failed' => 'Gemini 返回解析失败',
        'gemini_request_failed' => 'Gemini 请求失败',
        'gemini_request_failed_detail' => '请求 Gemini 失败: :error',
        'gemini_parse_failed_detail' => 'Gemini 返回解析失败: :error',
        'transcribe_failed' => '语音转文字失败',
        'no_speech' => '未能识别到语音内容，请重试',
    ],

    // ============================================================
    // 管理后台
    // ============================================================
    'admin' => [
        'title' => '管理后台',
        'login_title' => '管理员登录',
        'login_subtitle' => '请输入管理员密钥以继续',
        'admin_key' => '管理员密钥',
        'admin_key_placeholder' => '请输入管理员密钥',
        'administrator' => '管理员',
        'warning' => '警告',
        'login_success' => '登录成功',
        'login_success_desc' => '管理员登录已完成，请继续进入后台。',
        'enter_admin' => '进入后台',
        'already_logged_in' => '已登录',
        'already_logged_in_desc' => '您已登录管理员账户，可以继续访问后台。',
        'ip_locked' => 'IP已被锁定，请 :minutes 分钟后重试',
        'quick_login_success' => '管理员快速登录已完成，请继续进入后台。',
        'need_init' => '需要初始化管理员系统',
        'need_init_desc' => '检测到管理员表缺失，请先完成初始化引导。',
        'start_init' => '开始初始化',
        'feature_disabled' => '该功能已禁用',
        'email_updated' => '邮箱修改成功',
        'user_enabled' => '用户已启用',
        'user_disabled' => '用户已禁用',
        'balance_added' => '充值成功',
        'balance_deducted' => '扣款成功',
        'password_reset' => '密码重置成功',
        // 侧边栏
        'sidebar' => [
            'dashboard' => '仪表盘',
            'users' => '用户管理',
            'balance' => '余额管理',
            'orders' => '订单管理',
            'password' => '密码管理',
            'logs' => '日志查看',
            'logout' => '退出登录',
            'toggle_menu' => '切换菜单',
            'announcements' => '公告管理',
        ],
        // 公告管理
        'announcements' => [
            'title' => '公告管理',
            'create' => '新建公告',
            'edit' => '编辑公告',
            'delete_confirm' => '确定要删除这条公告吗？',
            'no_announcements' => '暂无公告数据',
            'preview' => '预览',
            'preview_title' => '公告标题示例',
            'preview_content' => '这里是公告内容预览区域...',
            'save_success' => '公告保存成功',
            'delete_success' => '公告删除成功',
            'enabled' => '公告已启用',
            'disabled' => '公告已禁用',
            'time_range' => '展示时间',
            'time_permanent' => '永久有效',
            'time_now' => '立即开始',
            'time_forever' => '永不结束',
            'search_placeholder' => '搜索标题或内容...',
            'all_status' => '全部状态',
            'all_types' => '全部类型',
            'all_modes' => '全部模式',
            'form' => [
                'title' => '公告标题',
                'content' => '公告内容',
                'content_hint' => '支持 HTML 格式，可使用 <b>粗体</b>、<a href="#">链接</a> 等',
                'type' => '公告类型',
                'display_mode' => '展示模式',
                'target' => '目标用户',
                'priority' => '优先级',
                'priority_hint' => '数值越大越靠前 (0-100)',
                'is_dismissible' => '允许用户关闭',
                'is_active' => '立即启用',
                'start_at' => '开始时间',
                'end_at' => '结束时间',
                'time_hint' => '留空表示立即开始/永不结束',
            ],
            'status' => [
                'active' => '启用中',
                'inactive' => '已禁用',
                'scheduled' => '待生效',
                'expired' => '已过期',
            ],
            'system_disabled' => '公告系统已在配置文件中禁用。要启用公告功能，请将 config.php 中的 $announcementConfig[\'enabled\'] 设置为 true。',
        ],
        // 仪表盘
        'dashboard' => [
            'title' => '仪表盘',
            'total_users' => '总用户数',
            'today_new' => '今日新增 :count 人',
            'total_recharge' => '总充值金额',
            'today_recharge' => '今日充值',
            'total_consumption' => '总消费金额',
            'today_consumption' => '今日消费',
            'total_images' => '生成图片数',
            'today_images' => '今日生成 :count 张',
            'recent_activity' => '最近活动',
            'recent_users' => '最近注册用户',
            'recent_orders' => '最近充值订单',
            'recent_ops' => '最近管理操作',
            'no_data' => '暂无数据',
            'no_records' => '暂无操作记录',
        ],
        // 用户管理
        'users' => [
            'title' => '用户管理',
            'search_placeholder' => '搜索用户名/邮箱/ID...',
            'all_status' => '全部状态',
            'user_id' => '用户ID',
            'no_users' => '暂无用户数据',
            'invalid_id' => '无效的用户ID',
            'view_detail' => '查看详情',
            'user_detail' => '用户详情',
            'basic_info' => '基本信息',
            'login_history' => '登录历史',
            'consumption_detail' => '消费明细',
            'balance_history' => '账户流水',
            'recharge_orders' => '充值订单',
            'statistics' => '统计数据',
            'total_recharge' => '累计充值',
            'total_consumption' => '累计消费',
            'total_images' => '生成图片',
            'quick_actions' => '快捷操作',
            'add_balance' => '充值',
            'deduct_balance' => '扣款',
            'reset_password' => '重置密码',
            'no_login_records' => '暂无登录记录',
            'no_consumption_records' => '暂无消费记录',
            'no_balance_records' => '暂无账户流水记录',
            'no_orders' => '暂无充值订单',
        ],
        // 订单管理（顶层）
        'orders' => [
            'title' => '订单管理',
            'search_placeholder' => '搜索订单号/支付单号/用户ID...',
            'all_pay_types' => '全部支付方式',
            'status_hint' => '提示: 超过30分钟未支付的订单将被标记为过期',
            'no_expire_count' => '发现 :count 个未设置过期时间的旧订单',
            'backfill_hint' => '为旧订单回填过期时间以支持自动清理',
            'backfill_btn' => '回填过期时间',
            'expired_count' => '发现 :count 个过期未支付订单',
            'expired_hint' => '可以批量取消这些订单以释放资源',
            'view_expired' => '查看过期订单',
            'cancel_expired' => '批量取消',
            'no_orders' => '暂无订单记录',
        ],
        // 订单状态
        'order_status' => [
            '0' => '待支付',
            '1' => '已支付',
            '2' => '已取消',
            '3' => '已退款',
            'expired' => '已过期',
        ],
        // 余额操作
        'balance' => [
            'title' => '余额操作',
            'add_title' => '人工充值',
            'deduct_title' => '人工扣款',
            'amount' => '金额 (RMB)',
            'remark' => '备注',
            'manual_recharge' => '人工充值',
            'user_placeholder' => '输入用户名 / 邮箱 / ID',
            'remark_admin' => '管理员备注 (仅后台可见)',
            'remark_placeholder' => '请输入操作备注...',
            'visible_to_user' => '用户可见',
            'visible_hint' => '勾选后，用户可在账户流水记录中看到该备注',
            'user_remark' => '用户备注 (前台显示)',
            'user_remark_placeholder' => '展示给用户的说明信息',
            'user_remark_default' => '留空则使用默认格式',
            'confirm_recharge' => '确认充值',
            'manual_deduct' => '人工扣款',
            'deduct_warning' => '扣款操作将直接减少用户余额，请谨慎操作！',
            'deduct_amount' => '扣除金额',
            'deduct_reason' => '扣款原因',
            'deduct_reason_placeholder' => '请输入扣款原因...',
            'confirm_deduct' => '确认扣款',
            'recharge' => '充值',
            'deduct' => '扣款',
        ],
        // 操作类型翻译
        'op_type' => [
            'user_edit' => '编辑用户',
            'balance_add' => '人工充值',
            'balance_deduct' => '人工扣款',
            'user_disable' => '禁用用户',
            'user_enable' => '启用用户',
            'password_reset' => '重置密码',
        ],
        // 表格列
        'table' => [
            'id' => 'ID',
            'user_id' => '用户ID',
            'username' => '用户名',
            'email' => '邮箱',
            'balance' => '余额',
            'status' => '状态',
            'created_at' => '注册时间',
            'actions' => '操作',
            'order_no' => '订单号',
            'platform_no' => '支付单号',
            'user' => '用户',
            'amount' => '金额',
            'remark' => '备注',
            'time' => '时间',
            'type' => '类型',
            'op_type' => '操作类型',
            'target_user' => '目标用户',
            'details' => '详细信息',
            'ip' => '操作IP',
            'login_time' => '登录时间',
            'ip_address' => 'IP地址',
            'login_method' => '登录方式',
            'result' => '状态',
            'image_count' => '图片数',
            'model' => '模型',
            'prompt_summary' => '提示词摘要',
            'gen_files' => '生成文件',
            'before' => '变动前',
            'after' => '变动后',
            'pay_type' => '支付方式',
            'create_time' => '创建时间',
            'pay_time' => '支付时间',
            'paid_at' => '支付时间',
        ],
        // 分页
        'pagination' => [
            'prev' => '上一页',
            'next' => '下一页',
            'page_info' => '第 :current / :total 页 (共 :count 条)',
        ],
        // 模态框
        'modal' => [
            'edit_email' => '修改邮箱',
            'new_email' => '新邮箱地址',
            'reset_password' => '重置密码',
            'new_password' => '新密码',
            'gen_random' => '生成随机密码',
        ],
        // 登录方式
        'login_type' => [
            'password' => '密码',
            'token' => '令牌',
            'quick_login' => '快速登录',
        ],
        // 消费类型
        'consumption_type' => [
            'generate' => '生成',
            'edit' => '编辑',
        ],
        // 余额类型（账户流水来源）
        'balance_type' => [
            'recharge' => '充值',
            'deduct' => '扣款',
            'online_recharge' => '在线充值',
            'manual_recharge' => '人工充值',
            'consumption' => '消费扣费',
            'manual_deduct' => '人工扣款',
        ],
        // 支付方式
        'pay_type' => [
            'alipay' => '支付宝',
            'wxpay' => '微信支付',
            'qqpay' => 'QQ支付',
        ],
    ],

    // ============================================================
    // 时间格式
    // ============================================================
    'time' => [
        'just_now' => '刚刚',
        'minutes_ago' => ':n 分钟前',
        'hours_ago' => ':n 小时前',
        'days_ago' => ':n 天前',
        'seconds' => '秒',
        'second' => '秒',
    ],

    // ============================================================
    // 状态文本
    // ============================================================
    'status' => [
        'success' => '成功',
        'failed' => '失败',
        'pending' => '待处理',
        'processing' => '处理中',
        'completed' => '已完成',
        'cancelled' => '已取消',
        'unknown' => '未知',
        'files' => ':count 个文件',
    ],

    // ============================================================
    // 支付相关
    // ============================================================
    'payment' => [
        'cashier' => '收银台',
        'alipay' => '支付宝',
        'wxpay' => '微信支付',
        'qqpay' => 'QQ支付',
        'error' => [
            'disabled' => '支付功能未启用',
            'request_failed' => '支付请求失败',
            'response_parse_failed' => '支付响应解析失败',
            'create_failed_default' => '支付创建失败',
            'sign_verify_failed' => '签名验证失败',
            'trade_not_success' => '交易未成功',
            'order_no_required' => '订单号不能为空',
            'query_request_failed' => '查询请求失败',
            'query_parse_failed' => '查询响应解析失败',
            'query_failed_default' => '查询失败',
        ],
    ],

    // ============================================================
    // 适配器错误
    // ============================================================
    'adapter' => [
        'gemini' => [
            'error' => [
                'request_failed' => '请求代理站失败: :error',
                'parse_failed' => '代理站返回解析失败: :error',
                'api_error' => '代理站接口返回异常',
                'request_failed_status' => '代理站请求失败 (:code): :message',
                'request_failed_code' => '代理站请求失败 (:code)',
                'sse_parse_failed' => 'SSE JSON 解析失败: :error - 内容: :content',
            ],
        ],
        'openai' => [
            'error' => [
                'request_failed' => '请求中转站失败: :error',
                'parse_failed' => '中转站返回解析失败: :error',
                'api_error' => '中转站接口返回异常',
                'request_failed_status' => '中转站请求失败 (:code): :message',
            ],
        ],
    ],

    // ============================================================
    // AI 提示词
    // ============================================================
    'ai' => [
        'prompt_optimizer' => [
            'system_prompt' => '你是AI绘画提示词专家，请优化提示词并仅输出优化后的结果。',
        ],
        'speech_to_text' => [
            'system_prompt' => '请将以下音频内容准确转录为文字。只输出转录的文字内容，不要添加任何解释或格式化。如果音频中没有可识别的语音，请返回空字符串。',
        ],
    ],

    // ============================================================
    // 杂项
    // ============================================================
    'misc' => [
        'confirm_action' => '确定要执行此操作吗？',
        'confirm_delete' => '确定要删除吗？此操作不可恢复。',
        'confirm_disable' => '确定要禁用此用户吗？',
        'confirm_enable' => '确定要启用此用户吗？',
        'copied' => '已复制',
        'copy_failed' => '复制失败',
        'no_data' => '暂无数据',
        'unit_images' => '张',
        'unit_times' => '次',
        'unit_yuan' => '元',
        'comma' => '，',
        'list_separator' => '、',
        'category_label' => '类别：',
    ],
];
