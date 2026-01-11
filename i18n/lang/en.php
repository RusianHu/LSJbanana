<?php
/**
 * English Language Pack (en)
 * 
 * Language pack structure:
 * - site: Basic site information
 * - nav: Navigation menu
 * - auth: Authentication (login, register, logout, etc.)
 * - user: User related
 * - form: Common form elements
 * - validation: Validation messages
 * - error: Error messages
 * - success: Success messages
 * - action: Action buttons
 * - index: Homepage
 * - recharge: Recharge page
 * - password: Password related
 * - api: API response messages
 * - admin: Admin backend
 * - time: Time formats
 * - status: Status texts
 * - payment: Payment related
 * - js: JavaScript frontend texts
 */

return [
    // ============================================================
    // Basic Site Information
    // ============================================================
    'site' => [
        'title' => 'LSJbanana',
        'subtitle' => 'LSJbanana',
        'description' => 'AI Image Generation & Editing Tool powered by gemini-3-pro-image (Nano Banana)',
        'copyright' => '© :year LSJbanana Project. Powered by Gemini.',
        'github_title' => 'View Source on GitHub',
        'github_text' => 'Source',
        'sponsor_title' => 'Support This Project',
        'sponsor_text' => 'Donate',
        'sponsor_desc' => 'If you find this useful, consider buying us a coffee',
    ],

    // ============================================================
    // Navigation Menu
    // ============================================================
    'nav' => [
        'home' => 'Home',
        'login' => 'Login',
        'register' => 'Register',
        'logout' => 'Logout',
        'recharge' => 'Recharge',
        'change_password' => 'Change Password',
        'back_home' => 'Back to Home',
        'admin' => 'Admin Panel',
    ],

    // ============================================================
    // Authentication
    // ============================================================
    'auth' => [
        'login' => 'Login',
        'register' => 'Register',
        'logout' => 'Logout',
        'login_title' => 'Welcome Back',
        'login_subtitle' => 'Sign in to your account to continue',
        'register_title' => 'Create Account',
        'register_subtitle' => 'Join LSJbanana and start your AI creative journey',
        'already_logged_in' => 'Already Logged In',
        'already_logged_in_desc' => 'You are already logged in.',
        'already_logged_in_register' => 'You are already logged in, no need to register.',
        'login_success' => 'Login Successful',
        'login_success_desc' => 'Login completed, please continue.',
        'register_success' => 'Registration Successful',
        'register_success_desc' => 'Account created, please login with your new account.',
        'logout_success' => 'Logged Out',
        'logout_success_desc' => 'You have been safely logged out.',
        'require_login' => 'Login Required',
        'require_login_desc' => 'Please login first to access this page.',
        'login_now' => 'Login Now',
        'register_now' => 'Register Now',
        'go_login' => 'Go to Login',
        'go_register' => 'Go to Recharge',
        'continue' => 'Continue',
        'no_account' => "Don't have an account?",
        'has_account' => 'Already have an account?',
        'remember_me' => 'Remember me',
        'forgot_password' => 'Forgot password?',
        'quick_login' => 'Quick Login',
        'quick_login_success' => 'Quick login completed, please continue.',
        'quick_login_invalid' => 'Invalid quick login link',
        'quick_login_failed' => 'Quick login failed',
        'registration_closed' => 'Registration is currently closed, please try again later.',
        'session_expired' => 'Session expired, please login again',
        'safe_logout' => 'Safely logged out',
        // Error messages
        'error' => [
            'username_or_password' => 'Invalid username or password',
            'account_disabled' => 'Account has been disabled',
            'captcha_required' => 'Please enter the captcha',
            'captcha_invalid' => 'Invalid or expired captcha',
            'registration_disabled' => 'Registration is currently disabled',
            'username_exists' => 'Username already taken',
            'email_exists' => 'Email already registered',
            'register_failed' => 'Registration failed, please try again later',
            'password_mismatch' => 'Passwords do not match',
        ],
    ],

    // ============================================================
    // User Related
    // ============================================================
    'user' => [
        'username' => 'Username',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirm' => 'Confirm Password',
        'balance' => 'Balance',
        'balance_unit' => 'CNY',
        'status' => 'Status',
        'status_active' => 'Active',
        'status_disabled' => 'Disabled',
        'created_at' => 'Registered',
        'last_login' => 'Last Login',
        'username_placeholder' => 'Enter username or email',
        'username_hint' => '3-20 characters, supports Chinese',
        'email_placeholder' => 'For password recovery',
        'password_placeholder' => 'Enter password',
        'password_new_placeholder' => 'Enter new password',
        'password_confirm_placeholder' => 'Enter password again',
        'password_hint' => 'Password must be at least :min characters',
    ],

    // ============================================================
    // Common Form Elements
    // ============================================================
    'form' => [
        'submit' => 'Submit',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'confirm' => 'Confirm',
        'close' => 'Close',
        'search' => 'Search',
        'reset' => 'Reset',
        'clear' => 'Clear',
        'captcha' => 'Captcha',
        'captcha_placeholder' => 'Enter captcha',
        'captcha_refresh' => 'Refresh',
        'loading' => 'Loading...',
        'processing' => 'Processing...',
    ],

    // ============================================================
    // Validation Messages
    // ============================================================
    'validation' => [
        'required' => ':field is required',
        'email_invalid' => 'Invalid email format',
        'username_min_length' => 'Username must be at least :min characters',
        'username_max_length' => 'Username must not exceed :max characters',
        'username_format' => 'Username can only contain letters, numbers, underscores and Chinese characters',
        'password_min_length' => 'Password must be at least :min characters',
        'password_mismatch' => 'Passwords do not match',
        'old_password_wrong' => 'Current password is incorrect',
        'amount_min' => 'Minimum amount is :min CNY',
        'amount_max' => 'Maximum amount is :max CNY',
    ],

    // ============================================================
    // Error Messages
    // ============================================================
    'error' => [
        'system' => 'System Error',
        'unknown' => 'Unknown Error',
        'config_missing' => 'Configuration file missing: config.php. Please copy config.php.example and configure accordingly.',
        'config_load_failed' => 'Failed to load configuration file',
        'db_connection_failed' => 'Database connection failed',
        'init_failed' => 'System initialization failed',
        'init_warning' => 'System Initialization Warning',
        'data_load_failed' => 'Failed to load data',
        'request_failed' => 'Request failed',
        'permission_denied' => 'Permission denied',
        'not_found' => 'Not found',
        'user_not_found' => 'User not found',
        'possible_causes' => 'Possible causes',
        'suggested_actions' => 'Suggested actions',
        'cause_config' => 'Configuration file (config.php) is missing or malformed',
        'cause_db' => 'Database file is corrupted or lacks permissions',
        'cause_extension' => 'Required PHP extensions are not installed',
        'action_check_config' => 'Check config.php.example and create a proper config.php',
        'action_check_db' => 'Ensure database directory exists and has write permissions',
        'action_check_logs' => 'Check server error logs for more details',
        'partial_function' => 'Some features may not work properly. Please check configuration and database settings.',
    ],

    // ============================================================
    // Success Messages
    // ============================================================
    'success' => [
        'saved' => 'Saved successfully',
        'updated' => 'Updated successfully',
        'deleted' => 'Deleted successfully',
        'operation' => 'Operation successful',
    ],

    // ============================================================
    // Action Buttons
    // ============================================================
    'action' => [
        'view' => 'View',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'refresh' => 'Refresh',
        'download' => 'Download',
        'upload' => 'Upload',
        'generate' => 'Generate',
        'optimize' => 'Optimize',
        'start' => 'Start',
        'stop' => 'Stop',
        'retry' => 'Retry',
        'back' => 'Back',
        'next' => 'Next',
        'prev' => 'Previous',
        'more' => 'More',
    ],

    // ============================================================
    // Homepage (Image Generation)
    // ============================================================
    'index' => [
        'tab_generate' => 'Text to Image (Generate)',
        'tab_edit' => 'Image to Image (Edit)',
        // Text to Image
        'prompt_label' => 'Prompt:',
        'prompt_placeholder' => 'Describe the image you want to generate...',
        'aspect_ratio' => 'Aspect Ratio:',
        'resolution' => 'Resolution:',
        'aspect_square' => 'Square',
        'aspect_wide' => 'Landscape',
        'aspect_tall' => 'Portrait',
        'use_google_search' => 'Use Google Search Grounding',
        'use_google_search_hint' => 'Generate based on real-time data',
        'btn_generate' => 'Generate Image',
        // Image to Image
        'upload_label' => 'Upload reference images (multiple selection, up to 14):',
        'upload_hint' => 'Click or drag to upload images',
        'edit_prompt_label' => 'Edit Instructions / Prompt:',
        'edit_prompt_placeholder' => 'Describe how to modify this image, or describe a new image...',
        'aspect_keep' => 'Keep Original / Default',
        'resolution_default' => 'Default (1K)',
        'btn_edit' => 'Start Editing',
        // Prompt Optimization
        'prompt_optimize' => 'Prompt Optimization',
        'prompt_optimize_tip' => 'Use gemini-2.5-flash to automatically enhance prompts, polish or add details before generation.',
        'optimize_mode_enhance' => 'Enhance Mode',
        'optimize_mode_detail' => 'Detail Mode',
        'btn_optimize' => 'Optimize',
        'optimize_status_processing' => 'Optimizing, please wait...',
        'optimize_status_done' => 'Optimization complete, filled in the text box.',
        'optimize_status_failed' => 'Optimization failed',
        // Result Area
        'result_title' => 'Generation Result',
        'generating' => 'Generating, please wait...',
        'elapsed_time' => 'Elapsed',
        'generated_time' => 'Generation time',
        'save_notice_title' => 'Please save images promptly',
        'save_notice_desc' => 'Generated images are temporarily stored on the server and will not be kept permanently. Please download and save locally immediately.',
        'download_image' => 'Download Image',
        'search_sources' => 'Search Sources',
        // Voice Input
        'voice_input' => 'Voice Input',
        'voice_stop' => 'Click to stop recognition',
        'voice_stop_recording' => 'Click to stop recording',
        'voice_converting' => 'Converting',
        // Lightbox
        'lightbox_close' => 'Close',
        'lightbox_zoom_in' => 'Zoom In',
        'lightbox_zoom_out' => 'Zoom Out',
        'lightbox_fit' => 'Fit to Window',
        'lightbox_actual' => 'Actual Size',
        'lightbox_download' => 'Download Image',
        'lightbox_help' => 'Keyboard Shortcuts',
        'lightbox_shortcuts' => 'Close | Zoom | Actual Size | Fit | Download | Mouse Wheel Zoom | Drag to Pan',
        // Thinking Process
        'thinking_process' => 'Thinking Process',
        'thinking_time' => 'Thinking time: :seconds seconds',
        'thinking_expand' => 'Click to expand',
        'thinking_collapse' => 'Click to collapse',
        'ai_thinking' => 'AI Thinking Process',
    ],

    // ============================================================
    // Recharge Page
    // ============================================================
    'recharge' => [
        'title' => 'Recharge',
        'page_title' => 'Account Recharge',
        'current_balance' => 'Current Balance',
        'can_generate' => 'Approximately :count tasks available',
        'price_info' => 'Current Price',
        'price_per_task' => ':price CNY/task',
        'recharge_after_use' => 'Available immediately after recharge',
        'amount_label' => 'Recharge Amount',
        'custom_amount' => 'Custom Amount',
        'custom_amount_placeholder' => 'Enter amount',
        'pay_method' => 'Select Payment Method',
        'btn_recharge' => 'Recharge Now',
        'order_created' => 'Order Created',
        'order_created_desc' => 'Please click the button below to proceed to payment.',
        'go_pay' => 'Go to Payment',
        'recharge_history' => 'Recharge History',
        'order_status' => [
            'pending' => 'Pending',
            'paid' => 'Completed',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
        ],
        'source_online' => 'Online Payment',
        'source_manual' => 'System Adjustment',
        'error' => [
            'min_amount' => 'Minimum recharge amount is :min CNY',
            'max_amount' => 'Maximum recharge amount is :max CNY',
            'payment_disabled' => 'Payment is currently unavailable',
            'create_failed' => 'Failed to create payment order',
            'payment_url_invalid' => 'Invalid payment URL, please try again later',
        ],
        'payment_redirect_title' => 'Go to Payment',
        'payment_redirect_desc' => 'Please click the button below to continue',
        'btn_continue_pay' => 'Continue to Pay',
        'browser_no_script' => 'Your browser does not support scripts, please click the link above to continue.',
        'error_url_missing' => 'Payment URL Missing',
        'error_url_missing_desc' => 'Failed to get payment URL, please return to recharge page and try again.',
        'error_url_invalid' => 'Invalid Payment URL',
        'error_url_invalid_desc' => 'Payment URL format is incorrect, please return to recharge page and retry.',
        'return_recharge' => 'Return to Recharge',
    ],

    // ============================================================
    // Payment Return Page
    // ============================================================
    'return' => [
        'title' => 'Payment Result',
        'success_title' => 'Recharge Successful',
        'success_msg' => 'Recharge Successful!',
        'pending_title' => 'Payment Processing',
        'pending_msg' => 'Payment is processing, please wait and refresh later...',
        'failed_title' => 'Payment Failed',
        'failed_msg' => 'Payment validation failed',
        'invalid_callback' => 'Invalid payment callback',
        'order_not_found' => 'Order not found',
        'order_no' => 'Order No',
        'amount' => 'Amount',
        'pay_time' => 'Time',
        'refresh_status' => 'Refresh Status',
        'continue_recharge' => 'Continue Recharge',
    ],

    // ============================================================
    // Admin Initialization
    // ============================================================
    'setup_admin' => [
        'title' => 'Admin Initialization',
        'desc' => 'This page is for initializing admin system tables. Please operate in a trusted environment.',
        'status_tables' => 'Tables Status',
        'status_complete' => 'Complete',
        'status_missing' => 'Missing :count tables',
        'status_writable' => 'DB Writable',
        'writable_yes' => 'Yes',
        'writable_no' => 'No',
        'visit_ip' => 'Your IP',
        'tables_to_create' => 'Tables to create: :tables',
        'admin_key' => 'Admin Key',
        'admin_key_placeholder' => 'Enter admin key',
        'force_reindex' => 'Force re-initialize indexes (even if tables exist)',
        'btn_start' => 'Start Initialization',
        'note_after' => 'You can proceed to admin login after initialization.',
        'back_login' => 'Back to Admin Login',
        'init_complete' => 'Initialization Completed',
        'error_disabled' => 'Setup is disabled, please enable admin_setup in config.php',
        'error_ip' => 'Your IP is not allowed to access setup page',
        'error_expired' => 'Request expired, please refresh and try again',
        'error_key_required' => 'Admin key is required',
    ],

    // ============================================================
    // Password Related
    // ============================================================
    'password' => [
        'change_title' => 'Change Password',
        'change_subtitle' => 'For account security, please change your password regularly',
        'current_password' => 'Current Password',
        'new_password' => 'New Password',
        'confirm_password' => 'Confirm New Password',
        'current_placeholder' => 'Enter current password',
        'new_placeholder' => 'Enter new password',
        'confirm_placeholder' => 'Enter new password again',
        'btn_save' => 'Save Changes',
        'changed_success' => 'Password Updated',
        'changed_success_desc' => 'Password changed successfully, please login again.',
        'changed_success_msg' => 'Password changed successfully, please login again',
        'reset_success' => 'Password Reset Successful',
        'reset_success_desc' => 'Password reset successful, please login again',
        'reset_link_sent' => 'If this email is registered, a reset link has been sent',
        'reset_link_invalid' => 'Reset link is invalid or expired',
        'error' => [
            'current_required' => 'Please enter current password',
            'new_required' => 'Please enter new password',
            'mismatch' => 'New passwords do not match',
            'old_wrong' => 'Current password is incorrect',
            'reset_failed' => 'Password reset failed',
            'change_failed' => 'Password change failed, please try again later',
        ],
    ],

    // ============================================================
    // API Response Messages
    // ============================================================
    'api' => [
        'unauthorized' => 'Please login first',
        'unauthorized_use' => 'Please login first to use this feature',
        'account_disabled' => 'Your account has been disabled, please contact administrator',
        'insufficient_balance' => 'Insufficient balance, please recharge first',
        'balance_low' => 'Insufficient balance, may have been consumed by other requests, please refresh the page',
        'deduct_failed' => 'Deduction failed',
        'refund_success' => 'No images generated, pre-deducted amount has been refunded',
        'only_post' => 'Only POST requests are supported',
        'invalid_action' => 'Invalid action type',
        'unknown_action' => 'Unknown action type',
        'prompt_required' => 'Prompt cannot be empty',
        'image_required' => 'Please upload at least one image',
        'image_max_count' => 'Maximum :max reference images allowed',
        'image_invalid' => 'Could not process any valid image files',
        'no_result' => 'API returned no candidate results',
        'request_failed' => 'Request failed',
        'gemini_parse_failed' => 'Failed to parse Gemini response',
        'gemini_request_failed' => 'Gemini request failed',
        'gemini_request_failed_detail' => 'Gemini request failed: :error',
        'gemini_parse_failed_detail' => 'Gemini response parse failed: :error',
        'transcribe_failed' => 'Speech to text failed',
        'no_speech' => 'No speech recognized, please try again',
    ],

    // ============================================================
    // Admin Backend
    // ============================================================
    'admin' => [
        'title' => 'Admin Panel',
        'login_title' => 'Admin Login',
        'login_subtitle' => 'Please enter the admin key to continue',
        'admin_key' => 'Admin Key',
        'admin_key_placeholder' => 'Enter admin key',
        'administrator' => 'Administrator',
        'login_success' => 'Login Successful',
        'login_success_desc' => 'Admin login completed, please continue to the backend.',
        'enter_admin' => 'Enter Admin',
        'already_logged_in' => 'Already Logged In',
        'already_logged_in_desc' => 'You are already logged in as admin, you can continue to access the backend.',
        'ip_locked' => 'IP has been locked, please try again in :minutes minutes',
        'quick_login_success' => 'Admin quick login completed, please continue to the backend.',
        'need_init' => 'Admin System Initialization Required',
        'need_init_desc' => 'Admin tables are missing, please complete the initialization wizard first.',
        'start_init' => 'Start Initialization',
        // Sidebar
        'sidebar' => [
            'dashboard' => 'Dashboard',
            'users' => 'User Management',
            'balance' => 'Balance Management',
            'orders' => 'Order Management',
            'password' => 'Password Management',
            'logs' => 'View Logs',
            'logout' => 'Logout',
        ],
        // Dashboard
        'dashboard' => [
            'title' => 'Dashboard',
            'total_users' => 'Total Users',
            'today_new' => 'New today: :count',
            'total_recharge' => 'Total Recharge',
            'today_recharge' => 'Today\'s Recharge',
            'total_consumption' => 'Total Consumption',
            'today_consumption' => 'Today\'s Consumption',
            'total_images' => 'Images Generated',
            'today_images' => 'Generated today: :count',
            'recent_activity' => 'Recent Activity',
            'recent_users' => 'Recent Registrations',
            'recent_orders' => 'Recent Orders',
            'recent_ops' => 'Recent Operations',
            'no_data' => 'No data',
            'no_records' => 'No operation records',
        ],
        // User Management
        'users' => [
            'title' => 'User Management',
            'search_placeholder' => 'Search username/email/ID...',
            'all_status' => 'All Status',
            'user_id' => 'User ID',
            'no_users' => 'No user data',
            'view_detail' => 'View Details',
            'user_detail' => 'User Details',
            'basic_info' => 'Basic Info',
            'login_history' => 'Login History',
            'consumption_detail' => 'Consumption Details',
            'balance_history' => 'Balance History',
            'recharge_orders' => 'Recharge Orders',
            'statistics' => 'Statistics',
            'total_recharge' => 'Total Recharge',
            'total_consumption' => 'Total Consumption',
            'total_images' => 'Images Generated',
            'quick_actions' => 'Quick Actions',
            'add_balance' => 'Add Balance',
            'deduct_balance' => 'Deduct Balance',
            'reset_password' => 'Reset Password',
            'no_login_records' => 'No login records',
            'no_consumption_records' => 'No consumption records',
            'no_balance_records' => 'No balance records',
            'no_orders' => 'No recharge orders',
        ],
        // Balance Operations
        'balance' => [
            'title' => 'Balance Operation',
            'add_title' => 'Manual Recharge',
            'deduct_title' => 'Manual Deduction',
            'amount' => 'Amount (CNY)',
            'remark' => 'Remark',
        ],
        // Operation Type Translations
        'op_type' => [
            'user_edit' => 'Edit User',
            'balance_add' => 'Manual Recharge',
            'balance_deduct' => 'Manual Deduction',
            'user_disable' => 'Disable User',
            'user_enable' => 'Enable User',
            'password_reset' => 'Reset Password',
        ],
        // Table Columns
        'table' => [
            'id' => 'ID',
            'username' => 'Username',
            'email' => 'Email',
            'balance' => 'Balance',
            'status' => 'Status',
            'created_at' => 'Registered',
            'actions' => 'Actions',
            'order_no' => 'Order No.',
            'user' => 'User',
            'amount' => 'Amount',
            'time' => 'Time',
            'type' => 'Type',
            'op_type' => 'Operation Type',
            'target_user' => 'Target User',
            'details' => 'Details',
            'ip' => 'IP Address',
            'login_time' => 'Login Time',
            'ip_address' => 'IP Address',
            'login_method' => 'Login Method',
            'result' => 'Result',
            'image_count' => 'Images',
            'model' => 'Model',
            'prompt_summary' => 'Prompt Summary',
            'gen_files' => 'Generated Files',
            'before' => 'Before',
            'after' => 'After',
            'pay_type' => 'Payment Type',
            'create_time' => 'Created',
            'pay_time' => 'Paid',
        ],
        // Pagination
        'pagination' => [
            'prev' => 'Previous',
            'next' => 'Next',
            'page_info' => 'Page :current / :total (Total :count items)',
        ],
        // Modals
        'modal' => [
            'edit_email' => 'Edit Email',
            'new_email' => 'New Email Address',
            'reset_password' => 'Reset Password',
            'new_password' => 'New Password',
            'gen_random' => 'Generate Random Password',
        ],
        // Login Types
        'login_type' => [
            'password' => 'Password',
            'token' => 'Token',
            'quick_login' => 'Quick Login',
        ],
        // Consumption Types
        'consumption_type' => [
            'generate' => 'Generate',
            'edit' => 'Edit',
        ],
        // Balance Types
        'balance_type' => [
            'recharge' => 'Recharge',
            'deduct' => 'Deduction',
        ],
        // Payment Types
        'pay_type' => [
            'alipay' => 'Alipay',
            'wxpay' => 'WeChat Pay',
            'qqpay' => 'QQ Pay',
        ],
    ],

    // ============================================================
    // Time Formats
    // ============================================================
    'time' => [
        'just_now' => 'Just now',
        'minutes_ago' => ':n minutes ago',
        'hours_ago' => ':n hours ago',
        'days_ago' => ':n days ago',
        'seconds' => 'seconds',
        'second' => 'second',
    ],

    // ============================================================
    // Status Texts
    // ============================================================
    'status' => [
        'success' => 'Success',
        'failed' => 'Failed',
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'unknown' => 'Unknown',
        'files' => ':count files',
    ],

    // ============================================================
    // Payment Related
    // ============================================================
    'payment' => [
        'cashier' => 'Cashier',
        'alipay' => 'Alipay',
        'wxpay' => 'WeChat Pay',
        'qqpay' => 'QQ Pay',
        'error' => [
            'disabled' => 'Payment function is disabled',
            'request_failed' => 'Payment request failed',
            'response_parse_failed' => 'Payment response parse failed',
            'create_failed_default' => 'Payment creation failed',
            'sign_verify_failed' => 'Signature verification failed',
            'trade_not_success' => 'Trade not successful',
            'order_no_required' => 'Order No is required',
            'query_request_failed' => 'Query request failed',
            'query_parse_failed' => 'Query response parse failed',
            'query_failed_default' => 'Query failed',
        ],
    ],

    // ============================================================
    // Adapter Errors
    // ============================================================
    'adapter' => [
        'gemini' => [
            'error' => [
                'request_failed' => 'Proxy request failed: :error',
                'parse_failed' => 'Proxy response parse failed: :error',
                'api_error' => 'Proxy API returned error',
                'request_failed_status' => 'Proxy request failed (:code): :message',
                'request_failed_code' => 'Proxy request failed (:code)',
                'sse_parse_failed' => 'SSE JSON parse failed: :error - Content: :content',
            ],
        ],
        'openai' => [
            'error' => [
                'request_failed' => 'Relay request failed: :error',
                'parse_failed' => 'Relay response parse failed: :error',
                'api_error' => 'Relay API returned error',
                'request_failed_status' => 'Relay request failed (:code): :message',
            ],
        ],
    ],

    // ============================================================
    // AI Prompts
    // ============================================================
    'ai' => [
        'prompt_optimizer' => [
            'system_prompt' => 'You are an AI image generation prompt expert. Please optimize the prompt and output only the optimized result.',
        ],
        'speech_to_text' => [
            'system_prompt' => 'Please transcribe the following audio content accurately into text. Output only the transcribed text without any explanation or formatting. If there is no recognizable speech in the audio, return an empty string.',
        ],
    ],

    // ============================================================
    // Miscellaneous
    // ============================================================
    'misc' => [
        'confirm_action' => 'Are you sure you want to perform this action?',
        'confirm_delete' => 'Are you sure you want to delete? This action cannot be undone.',
        'confirm_disable' => 'Are you sure you want to disable this user?',
        'confirm_enable' => 'Are you sure you want to enable this user?',
        'copied' => 'Copied',
        'copy_failed' => 'Copy failed',
        'no_data' => 'No data',
        'unit_images' => 'images',
        'unit_times' => 'times',
        'unit_yuan' => 'CNY',
    ],
];