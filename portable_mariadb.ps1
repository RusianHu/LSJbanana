param(
    [ValidateSet('install', 'start', 'stop', 'status')]
    [string]$Action = 'status',
    [int]$Port = 3307,
    [string]$Database = 'lsjbanana',
    [string]$AppUser = 'lsjbanana',
    [string]$AppPassword = 'lsjbanana_dev_only',
    [string]$RootPassword = 'lsjbanana_root_dev_only',
    [string]$Proxy = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$MariaDbVersion = '11.8.8'
$ProjectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RuntimeRoot = Join-Path $ProjectDir 'database\.mariadb'
$DownloadDir = Join-Path $ProjectDir 'database\.mariadb-download'
$ServerDir = Join-Path $RuntimeRoot "mariadb-$MariaDbVersion-winx64"
$DataDir = Join-Path $RuntimeRoot 'data'
$ConfigFile = Join-Path $DataDir 'my.ini'
$ClientConfigFile = Join-Path $RuntimeRoot 'client.ini'
$ZipFile = Join-Path $DownloadDir "mariadb-$MariaDbVersion-winx64.zip"
$ChecksumsFile = Join-Path $DownloadDir 'sha256sums.txt'
$ArchiveBase = "https://archive.mariadb.org/mariadb-$MariaDbVersion/winx64-packages"

function Assert-SafeRuntimePath([string]$Path) {
    $runtimeFull = [System.IO.Path]::GetFullPath($RuntimeRoot).TrimEnd('\') + '\'
    $pathFull = [System.IO.Path]::GetFullPath($Path)
    if (-not $pathFull.StartsWith($runtimeFull, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "拒绝操作运行目录之外的路径：$pathFull"
    }
}

function Assert-Identifier([string]$Value, [string]$Label) {
    if ($Value -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
        throw "$Label 只能包含字母、数字和下划线，且不能以数字开头"
    }
}

function Convert-ToIniPath([string]$Path) {
    return ([System.IO.Path]::GetFullPath($Path) -replace '\\', '/')
}

function Escape-SqlLiteral([string]$Value) {
    return $Value.Replace('\', '\\').Replace("'", "''")
}

function Invoke-Download([string]$Uri, [string]$OutFile) {
    $params = @{
        Uri = $Uri
        OutFile = $OutFile
    }
    $effectiveProxy = if ($Proxy) { $Proxy } elseif ($env:LSJBANANA_DOWNLOAD_PROXY) { $env:LSJBANANA_DOWNLOAD_PROXY } else { '' }
    if ($effectiveProxy) {
        $params.Proxy = $effectiveProxy
    }
    Invoke-WebRequest @params
}

function Get-Executable([string]$Name) {
    $path = Join-Path $ServerDir "bin\$Name"
    if (-not (Test-Path -LiteralPath $path)) {
        throw "MariaDB 可执行文件不存在：$path，请先运行 install"
    }
    return $path
}

function Write-ServerConfig {
    Assert-SafeRuntimePath $DataDir
    New-Item -ItemType Directory -Path $DataDir -Force | Out-Null
    $basedir = Convert-ToIniPath $ServerDir
    $datadir = Convert-ToIniPath $DataDir
    $pidFile = Convert-ToIniPath (Join-Path $DataDir 'mariadb.pid')
    $errorLog = Convert-ToIniPath (Join-Path $DataDir 'mariadb.err')
    $pluginDir = Convert-ToIniPath (Join-Path $ServerDir 'lib\plugin')

    $serverConfig = @"
[mysqld]
basedir=$basedir
datadir=$datadir
port=$Port
bind-address=127.0.0.1
skip-name-resolve
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
innodb-buffer-pool-size=128M
max-connections=30
performance-schema=OFF
skip-log-bin
pid-file=$pidFile
log-error=$errorLog

[client]
host=127.0.0.1
port=$Port
default-character-set=utf8mb4
plugin-dir=$pluginDir
"@
    Set-Content -LiteralPath $ConfigFile -Value $serverConfig -Encoding UTF8

    $escapedRootPassword = $RootPassword.Replace('\', '\\').Replace('"', '\"')
    $clientConfig = @"
[client]
host=127.0.0.1
port=$Port
user=root
password="$escapedRootPassword"
default-character-set=utf8mb4
plugin-dir=$pluginDir
"@
    Set-Content -LiteralPath $ClientConfigFile -Value $clientConfig -Encoding UTF8
}

function Test-ServerRunning {
    if (-not (Test-Path -LiteralPath $ServerDir)) {
        return $false
    }
    $adminExe = Get-Executable 'mariadb-admin.exe'
    & $adminExe "--defaults-file=$ClientConfigFile" --protocol=tcp ping *> $null
    return $LASTEXITCODE -eq 0
}

function Install-PortableMariaDb {
    Assert-Identifier $Database '数据库名'
    Assert-Identifier $AppUser '应用用户名'
    New-Item -ItemType Directory -Path $DownloadDir -Force | Out-Null
    New-Item -ItemType Directory -Path $RuntimeRoot -Force | Out-Null

    if (-not (Test-Path -LiteralPath $ZipFile)) {
        Write-Host "下载 MariaDB $MariaDbVersion ..."
        Invoke-Download "$ArchiveBase/mariadb-$MariaDbVersion-winx64.zip" $ZipFile
    }
    Invoke-Download "$ArchiveBase/sha256sums.txt" $ChecksumsFile

    $checksumLine = Get-Content -LiteralPath $ChecksumsFile |
        Where-Object { $_ -match "mariadb-$([regex]::Escape($MariaDbVersion))-winx64\.zip$" } |
        Select-Object -First 1
    if (-not $checksumLine) {
        throw '官方 sha256sums.txt 中未找到 ZIP 校验值'
    }
    $expected = (($checksumLine.Trim() -split '\s+')[0]).ToLowerInvariant()
    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $ZipFile).Hash.ToLowerInvariant()
    if ($expected -ne $actual) {
        throw "MariaDB ZIP SHA-256 校验失败：expected=$expected actual=$actual"
    }
    Write-Host "SHA-256 校验通过：$actual"

    if (-not (Test-Path -LiteralPath $ServerDir)) {
        Expand-Archive -LiteralPath $ZipFile -DestinationPath $RuntimeRoot -Force
    }

    Assert-SafeRuntimePath $DataDir
    $needsInitialize = -not (Test-Path -LiteralPath (Join-Path $DataDir 'mysql'))
    if ($needsInitialize) {
        New-Item -ItemType Directory -Path $DataDir -Force | Out-Null
        $installExe = Get-Executable 'mariadb-install-db.exe'
        & $installExe "--datadir=$DataDir" "--password=$RootPassword" "--port=$Port" --default-user
        if ($LASTEXITCODE -ne 0) {
            throw "MariaDB 数据目录初始化失败，退出码：$LASTEXITCODE"
        }
    }

    Write-ServerConfig
    Start-PortableMariaDb
    Write-Host '便携式 MariaDB 安装完成。'
}

function Start-PortableMariaDb {
    if (-not (Test-Path -LiteralPath (Join-Path $DataDir 'mysql'))) {
        throw 'MariaDB 尚未初始化，请先运行 install'
    }
    Write-ServerConfig
    if (-not (Test-ServerRunning)) {
        $serverExe = Get-Executable 'mariadbd.exe'
        $arguments = "--defaults-file=`"$ConfigFile`" --console"
        Start-Process -FilePath $serverExe -ArgumentList $arguments -WindowStyle Hidden | Out-Null

        $ready = $false
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Milliseconds 500
            if (Test-ServerRunning) {
                $ready = $true
                break
            }
        }
        if (-not $ready) {
            $logTail = if (Test-Path -LiteralPath (Join-Path $DataDir 'mariadb.err')) {
                (Get-Content -LiteralPath (Join-Path $DataDir 'mariadb.err') -Tail 20) -join [Environment]::NewLine
            } else {
                '未生成错误日志'
            }
            throw "MariaDB 启动超时。日志：`n$logTail"
        }
    }

    $clientExe = Get-Executable 'mariadb.exe'
    $dbName = $Database
    $user = Escape-SqlLiteral $AppUser
    $password = Escape-SqlLiteral $AppPassword
    $sql = @"
CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$user'@'127.0.0.1' IDENTIFIED BY '$password';
ALTER USER '$user'@'127.0.0.1' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON ``$dbName``.* TO '$user'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
    & $clientExe "--defaults-file=$ClientConfigFile" --protocol=tcp --execute=$sql
    if ($LASTEXITCODE -ne 0) {
        throw "创建调试数据库或应用用户失败，退出码：$LASTEXITCODE"
    }
    Write-Host "MariaDB 正在运行：127.0.0.1:$Port / database=$Database / user=$AppUser"
}

function Stop-PortableMariaDb {
    if (-not (Test-Path -LiteralPath $ClientConfigFile)) {
        Write-Host 'MariaDB 未安装或客户端配置不存在。'
        return
    }
    if (-not (Test-ServerRunning)) {
        Write-Host 'MariaDB 当前未运行。'
        return
    }
    $adminExe = Get-Executable 'mariadb-admin.exe'
    & $adminExe "--defaults-file=$ClientConfigFile" --protocol=tcp shutdown
    if ($LASTEXITCODE -ne 0) {
        throw "MariaDB 停止失败，退出码：$LASTEXITCODE"
    }
    Write-Host 'MariaDB 已停止。'
}

function Show-PortableMariaDbStatus {
    if (-not (Test-Path -LiteralPath $ServerDir)) {
        Write-Host 'MariaDB 未安装。运行：.\portable_mariadb.ps1 install'
        return
    }
    Write-ServerConfig
    if (Test-ServerRunning) {
        $clientExe = Get-Executable 'mariadb.exe'
        $version = & $clientExe "--defaults-file=$ClientConfigFile" --protocol=tcp --skip-column-names --execute='SELECT VERSION()'
        Write-Host "MariaDB 正在运行：127.0.0.1:$Port，版本 $version"
    } else {
        Write-Host 'MariaDB 已安装但未运行。'
    }
}

switch ($Action) {
    'install' { Install-PortableMariaDb }
    'start' { Start-PortableMariaDb }
    'stop' { Stop-PortableMariaDb }
    'status' { Show-PortableMariaDbStatus }
}
