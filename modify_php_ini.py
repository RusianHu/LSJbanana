import os

php_ini_path = 'php/php.ini'
# 获取绝对路径并确保格式正确
cacert_path = os.path.abspath('php/cacert.pem').replace('\\', '/')

try:
    with open(php_ini_path, 'r', encoding='utf-8') as f:
        content = f.read()

    # 替换配置
    replacements = [
        (';extension=curl', 'extension=curl'),
        (';extension=mbstring', 'extension=mbstring'),
        (';extension=openssl', 'extension=openssl'),
        (';extension_dir = "ext"', 'extension_dir = "ext"'),
        (';curl.cainfo =', f'curl.cainfo = "{cacert_path}"'),
        (';openssl.cafile =', f'openssl.cafile = "{cacert_path}"')
    ]

    for old, new in replacements:
        content = content.replace(old, new)

    with open(php_ini_path, 'w', encoding='utf-8') as f:
        f.write(content)

    print("php.ini updated successfully.")

except Exception as e:
    print(f"Error updating php.ini: {e}")