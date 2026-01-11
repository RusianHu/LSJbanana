<?php
/**
 * 支付接口封装类
 * 
 * 对接老司机聚合支付平台
 * 文档参考: 老司机聚合支付V1.md
 */

require_once __DIR__ . '/security_utils.php';

class Payment {
    private array $config;
    private string $gatewayUrl;
    private int $pid;
    private string $key;

    public function __construct(?array $config = null) {
        require_once __DIR__ . '/i18n/I18n.php'; // 确保 I18n 已加载
        if ($config === null) {
            $fullConfig = require __DIR__ . '/config.php';
            $config = $fullConfig['payment'] ?? [];
        }
        $this->config = $config;
        $this->gatewayUrl = rtrim($config['gateway_url'] ?? 'https://pay.yanshanlaosiji.top', '/');
        $this->pid = (int) ($config['pid'] ?? 1000);
        $this->key = $config['key'] ?? '';
    }

    /**
     * 检查支付是否启用
     */
    public function isEnabled(): bool {
        return ($this->config['enabled'] ?? false) && !empty($this->key);
    }

    /**
     * 生成商户订单号
     */
    public function generateOutTradeNo(): string {
        return date('YmdHis') . mt_rand(100000, 999999);
    }

    /**
     * 生成签名
     * 
     * 签名规则:
     * 1. 将参数按ASCII码从小到大排序
     * 2. sign、sign_type 和空值不参与签名
     * 3. 拼接成 a=b&c=d&e=f 格式
     * 4. 末尾拼接商户密钥，进行 MD5 加密
     */
    public function generateSign(array $params): string {
        // 过滤空值和签名相关参数
        $signParams = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $key === 'sign_type') {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $signParams[$key] = $value;
        }

        // 按 ASCII 码排序
        ksort($signParams);

        // 拼接字符串
        $signStr = '';
        foreach ($signParams as $key => $value) {
            $signStr .= $key . '=' . $value . '&';
        }
        $signStr = rtrim($signStr, '&');

        // 拼接密钥并 MD5
        return md5($signStr . $this->key);
    }

    /**
     * 验证签名
     */
    public function verifySign(array $params): bool {
        $receivedSign = $params['sign'] ?? '';
        if (empty($receivedSign)) {
            return false;
        }

        $expectedSign = $this->generateSign($params);
        return strtolower($receivedSign) === strtolower($expectedSign);
    }

    /**
     * 获取通知地址
     */
    public function getNotifyUrl(): string {
        if (!empty($this->config['notify_url'])) {
            return $this->config['notify_url'];
        }
        return $this->getBaseUrl() . '/notify.php';
    }

    /**
     * 获取跳转地址
     */
    public function getReturnUrl(): string {
        if (!empty($this->config['return_url'])) {
            return $this->config['return_url'];
        }
        return $this->getBaseUrl() . '/return.php';
    }

    /**
     * 获取当前站点基础 URL
     */
    private function getBaseUrl(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        return rtrim($protocol . '://' . $host . $path, '/');
    }

    /**
     * 创建支付订单（页面跳转方式）
     * 
     * @param string $outTradeNo 商户订单号
     * @param float $money 金额
     * @param string $name 商品名称
     * @param string|null $payType 支付方式 (alipay/wxpay/qqpay，空则跳转收银台)
     * @param string|null $param 业务扩展参数
     * @return array 包含跳转URL或表单HTML
     */
    public function createOrder(
        string $outTradeNo,
        float $money,
        string $name,
        ?string $payType = null,
        ?string $param = null
    ): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => __('payment.error.disabled')];
        }

        $params = [
            'pid' => $this->pid,
            'out_trade_no' => $outTradeNo,
            'notify_url' => $this->getNotifyUrl(),
            'return_url' => $this->getReturnUrl(),
            'name' => mb_substr($name, 0, 127, 'UTF-8'),
            'money' => number_format($money, 2, '.', ''),
            'sign_type' => 'MD5',
        ];

        if (!empty($payType)) {
            $params['type'] = $payType;
        }

        if (!empty($param)) {
            $params['param'] = $param;
        }

        // 生成签名
        $params['sign'] = $this->generateSign($params);

        // 生成跳转URL
        $submitUrl = $this->gatewayUrl . '/submit.php';
        $queryString = http_build_query($params);

        return [
            'success' => true,
            'url' => $submitUrl . '?' . $queryString,
            'form_html' => $this->buildFormHtml($submitUrl, $params),
            'params' => $params,
        ];
    }

    /**
     * 创建支付订单（API 方式）
     * 
     * @param string $outTradeNo 商户订单号
     * @param float $money 金额
     * @param string $name 商品名称
     * @param string $payType 支付方式 (alipay/wxpay/qqpay)
     * @param string|null $clientIp 用户IP
     * @param string $device 设备类型 (pc/mobile/qq/wechat/alipay/jump)
     * @param string|null $param 业务扩展参数
     * @return array API 返回结果
     */
    public function createOrderApi(
        string $outTradeNo,
        float $money,
        string $name,
        string $payType,
        ?string $clientIp = null,
        string $device = 'pc',
        ?string $param = null
    ): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => __('payment.error.disabled')];
        }

        $params = [
            'pid' => $this->pid,
            'type' => $payType,
            'out_trade_no' => $outTradeNo,
            'notify_url' => $this->getNotifyUrl(),
            'return_url' => $this->getReturnUrl(),
            'name' => mb_substr($name, 0, 127, 'UTF-8'),
            'money' => number_format($money, 2, '.', ''),
            'clientip' => $clientIp ?? $this->getClientIp(),
            'device' => $device,
            'sign_type' => 'MD5',
        ];

        if (!empty($param)) {
            $params['param'] = $param;
        }

        // 生成签名
        $params['sign'] = $this->generateSign($params);

        // 发送请求
        $apiUrl = $this->gatewayUrl . '/mapi.php';
        $response = $this->httpPost($apiUrl, $params);

        if ($response === false) {
            return ['success' => false, 'message' => __('payment.error.request_failed')];
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => __('payment.error.response_parse_failed')];
        }

        if (($result['code'] ?? 0) !== 1) {
            return ['success' => false, 'message' => $result['msg'] ?? __('payment.error.create_failed_default')];
        }

        return [
            'success' => true,
            'trade_no' => $result['trade_no'] ?? '',
            'payurl' => $result['payurl'] ?? null,
            'qrcode' => $result['qrcode'] ?? null,
            'urlscheme' => $result['urlscheme'] ?? null,
        ];
    }

    /**
     * 处理支付通知
     * 
     * @param array $params 通知参数 (通常是 $_GET)
     * @return array 处理结果
     */
    public function handleNotify(array $params): array {
        // 验证签名
        if (!$this->verifySign($params)) {
            return ['success' => false, 'message' => __('payment.error.sign_verify_failed')];
        }

        // 检查交易状态
        $tradeStatus = $params['trade_status'] ?? '';
        if ($tradeStatus !== 'TRADE_SUCCESS') {
            return ['success' => false, 'message' => __('payment.error.trade_not_success')];
        }

        return [
            'success' => true,
            'pid' => (int) ($params['pid'] ?? 0),
            'trade_no' => $params['trade_no'] ?? '',
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'type' => $params['type'] ?? '',
            'name' => $params['name'] ?? '',
            'money' => (float) ($params['money'] ?? 0),
            'param' => $params['param'] ?? '',
        ];
    }

    /**
     * 查询订单状态
     */
    public function queryOrder(string $outTradeNo = '', string $tradeNo = ''): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => __('payment.error.disabled')];
        }

        $apiUrl = $this->gatewayUrl . '/api.php';
        $params = [
            'act' => 'order',
            'pid' => $this->pid,
            'key' => $this->key,
        ];

        if (!empty($tradeNo)) {
            $params['trade_no'] = $tradeNo;
        } elseif (!empty($outTradeNo)) {
            $params['out_trade_no'] = $outTradeNo;
        } else {
            return ['success' => false, 'message' => __('payment.error.order_no_required')];
        }

        $response = $this->httpGet($apiUrl . '?' . http_build_query($params));
        if ($response === false) {
            return ['success' => false, 'message' => __('payment.error.query_request_failed')];
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => __('payment.error.query_parse_failed')];
        }

        if (($result['code'] ?? 0) !== 1) {
            return ['success' => false, 'message' => $result['msg'] ?? __('payment.error.query_failed_default')];
        }

        return [
            'success' => true,
            'order' => $result,
        ];
    }

    /**
     * 查询商户信息
     */
    public function queryMerchant(): array {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => __('payment.error.disabled')];
        }

        $apiUrl = $this->gatewayUrl . '/api.php';
        $params = [
            'act' => 'query',
            'pid' => $this->pid,
            'key' => $this->key,
        ];

        $response = $this->httpGet($apiUrl . '?' . http_build_query($params));
        if ($response === false) {
            return ['success' => false, 'message' => __('payment.error.query_request_failed')];
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => __('payment.error.query_parse_failed')];
        }

        if (($result['code'] ?? 0) !== 1) {
            return ['success' => false, 'message' => $result['msg'] ?? __('payment.error.query_failed_default')];
        }

        return [
            'success' => true,
            'merchant' => $result,
        ];
    }

    /**
     * 生成表单 HTML
     */
    private function buildFormHtml(string $action, array $params): string {
        $html = '<form id="pay-form" method="POST" action="' . htmlspecialchars($action) . '">';
        foreach ($params as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
        }
        $html .= '</form>';
        $html .= '<script>document.getElementById("pay-form").submit();</script>';
        return $html;
    }

    /**
     * 获取客户端IP
     */
    private function getClientIp(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * HTTP GET 请求
     */
    private function httpGet(string $url): string|false {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Payment HTTP GET error: ' . $error);
            return false;
        }

        return $response;
    }

    /**
     * HTTP POST 请求
     */
    private function httpPost(string $url, array $data): string|false {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('Payment HTTP POST error: ' . $error);
            return false;
        }

        return $response;
    }
}

/**
 * 获取全局 Payment 实例
 */
function getPayment(): Payment {
    static $payment = null;
    if ($payment === null) {
        $payment = new Payment();
    }
    return $payment;
}