# LSJbanana

基于 Google Gemini 3 Pro Image 模型的 AI 图像生成工具。

## 功能

- 文生图：通过文本描述生成图像
- 图生图：上传参考图并通过文本编辑
- 提示词优化：使用 Gemini 2.5 Flash 自动增强提示词
- 语音输入：语音转文字输入提示词
- Google 搜索接地：基于实时搜索数据生成图像

## 技术栈

- PHP 8.x
- HTML / CSS / JavaScript
- Google Gemini API / OpenAI 兼容 API

## 安装

1. 复制配置文件并填写 API 密钥：

```bash
cp config.php.example config.php
```

2. 启动服务：

```bash
php -S 127.0.0.1:8000
```

3. 访问 `http://127.0.0.1:8000`

## 配置

编辑 `config.php` 设置以下选项：

| 配置项 | 说明 |
|--------|------|
| `api_provider` | `native` (直连) 或 `openai_compatible` (中转) |
| `api_key` | Gemini API 密钥 |
| `model_name` | 图像生成模型 |
| `generation_config` | 图像参数 (比例、分辨率等) |

## 生产环境部署建议

PHP 配置：

```ini
max_execution_time = 300
max_input_time = 300
memory_limit = 768M
post_max_size = 120M
upload_max_filesize = 10M
max_file_uploads = 20
```

PHP-FPM：

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 10
pm.max_spare_servers = 20
pm.max_requests = 500
```

Nginx：

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/tmp/php-cgi-84.sock;
    fastcgi_read_timeout 300;
    fastcgi_send_timeout 300;
    fastcgi_connect_timeout 60;
    fastcgi_buffers 16 16k;
    fastcgi_buffer_size 32k;
}
```

## 许可证

[Apache License 2.0](LICENSE)

