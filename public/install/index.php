<?php
declare(strict_types=1);

/**
 * 承台应用管理平台 · 在线安装程序
 *
 * 用法：把承台源码上传到站点目录，浏览器访问 https://你的域名/install 即可在线安装。
 * 特性：
 *   1. 安装前检测运行环境（区分「必备依赖」与「建议依赖」），必备不满足时不允许继续；
 *   2. 表单填写数据库信息 + 承台管理员（邮箱 / 账号 / 密码）；
 *   3. 自动建库建表、创建超级管理员、生成 .env（含随机 JWT 密钥）、部署前端静态资源；
 *   4. 安装完成后写入 install.lock，防止被重复安装。
 *
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');   // 错误自行捕获并以页面形式展示，避免污染 HTML 输出
@ini_set('log_errors', '1');

@session_start();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$INSTALL_DIR = __DIR__;                       // .../public/install
$PUBLIC_DIR  = dirname(__DIR__);              // .../public
$ROOT_DIR    = dirname(__DIR__, 2);           // .../platform-server（承台后端根目录）
$LOCK_FILE   = $INSTALL_DIR . DIRECTORY_SEPARATOR . 'install.lock';

// =====================================================================
// 工具函数
// =====================================================================

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iniBytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '') {
        return 0;
    }
    if ($v === '-1') {
        return -1; // 不限制
    }
    $num  = (float) $v;
    $unit = strtolower(substr($v, -1));
    switch ($unit) {
        case 'g':
            $num *= 1024 * 1024 * 1024;
            break;
        case 'm':
            $num *= 1024 * 1024;
            break;
        case 'k':
            $num *= 1024;
            break;
    }
    return (int) $num;
}

function iniShow(string $key): string
{
    $v = ini_get($key);
    return $v === false || $v === '' ? '(未设置)' : (string) $v;
}

function item(string $name, string $status, string $current, string $expected, string $hint = ''): array
{
    return compact('name', 'status', 'current', 'expected', 'hint');
}

/** 当前协议 */
function isHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** 站点根 URL（用于展示登录地址） */
function baseUrl(): string
{
    $scheme = isHttps() ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
    $dir    = rtrim(dirname($script), '/');                 // /install
    $base   = preg_replace('#/install$#', '', $dir);        // ''
    return $scheme . '://' . $host . $base;
}

/** 递归复制目录 */
function copyDir(string $src, string $dst): void
{
    if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
        throw new RuntimeException('无法创建目录：' . $dst);
    }
    $items = @scandir($src);
    if ($items === false) {
        throw new RuntimeException('无法读取目录：' . $src);
    }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $s = $src . DIRECTORY_SEPARATOR . $it;
        $d = $dst . DIRECTORY_SEPARATOR . $it;
        if (is_dir($s)) {
            copyDir($s, $d);
        } elseif (!@copy($s, $d)) {
            throw new RuntimeException('无法复制文件：' . $s . ' → ' . $d);
        }
    }
}

/** 把一个 SQL 文件拆成一条条可执行语句（识别引号/注释，忽略分号在字符串中的情况） */
function splitSql(string $sql): array
{
    $statements = [];
    $buffer     = '';
    $len        = strlen($sql);
    $inSingle   = $inDouble = $inBacktick = false;
    $inLineComment = $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
                $buffer .= "\n";
            }
            continue;
        }
        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }
        if ($inSingle) {
            $buffer .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inSingle = false;
            }
            continue;
        }
        if ($inDouble) {
            $buffer .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($ch === '"') {
                $inDouble = false;
            }
            continue;
        }
        if ($inBacktick) {
            $buffer .= $ch;
            if ($ch === '`') {
                $inBacktick = false;
            }
            continue;
        }

        // 不在任何引号内
        if ($ch === '-' && $next === '-') {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($ch === '#') {
            $inLineComment = true;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $buffer .= $ch;
            continue;
        }
        if ($ch === '"') {
            $inDouble = true;
            $buffer .= $ch;
            continue;
        }
        if ($ch === '`') {
            $inBacktick = true;
            $buffer .= $ch;
            continue;
        }
        if ($ch === ';') {
            $t = trim($buffer);
            if ($t !== '') {
                $statements[] = $t;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }

    $t = trim($buffer);
    if ($t !== '') {
        $statements[] = $t;
    }
    return $statements;
}

/**
 * 把值安全地写进 .env。
 * ThinkPHP 用 parse_ini_file(..., INI_SCANNER_RAW) 解析：该模式不会处理反斜杠转义，
 * 但会把「以双引号开头且结尾」的值剥离首尾引号、内部原样保留。
 * 因此统一用双引号包裹（不转义）即可原样还原任意值，包括含 ; # " ' \ 空格 的密码。
 */
function envVal(string $v): string
{
    if ($v === '') {
        return '';
    }
    return '"' . $v . '"';
}

/** 查找前端构建产物目录（dist），找到返回绝对路径，否则返回 null */
function findFrontDist(string $rootDir): ?string
{
    $candidates = [
        dirname($rootDir) . DIRECTORY_SEPARATOR . 'platform' . DIRECTORY_SEPARATOR . 'dist',
        dirname($rootDir) . DIRECTORY_SEPARATOR . 'platform-web' . DIRECTORY_SEPARATOR . 'dist',
        $rootDir . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'dist',
        $rootDir . DIRECTORY_SEPARATOR . 'dist',
        $rootDir . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'dist',
    ];
    foreach ($candidates as $c) {
        if (is_file($c . DIRECTORY_SEPARATOR . 'index.html')) {
            return $c;
        }
    }
    return null;
}

// =====================================================================
// 依赖检测
// =====================================================================

/**
 * 检测运行环境。
 * 返回 ['groups' => [...], 'error' => int, 'warn' => int]
 * status: ok | warn | error（error 属于「必备依赖」，warn 属于「建议依赖」）
 */
function detect(string $rootDir, string $publicDir, ?string $frontDist): array
{
    $groups = [];

    // ---------- 必备：PHP 运行环境 ----------
    $required = [];
    $required[] = item(
        'PHP 版本',
        PHP_VERSION_ID >= 80200 ? 'ok' : 'error',
        PHP_VERSION,
        '≥ 8.2',
        '承台使用了 PHP 8.2 以上才有的语法与特性，请升级 PHP 后重新部署。'
    );
    $exts = [
        'pdo_mysql' => '连接 MySQL 数据库',
        'openssl'   => '生成与校验签名、加密支付密钥',
        'zip'       => '解压上传的应用包',
        'curl'      => '调用支付网关、探测域名与回调应用',
        'mbstring'  => '处理中文与字符串长度限制',
    ];
    foreach ($exts as $ext => $why) {
        $has = extension_loaded($ext);
        $required[] = item(
            "扩展 {$ext}",
            $has ? 'ok' : 'error',
            $has ? '已启用' : '未启用',
            '已启用',
            $has ? '' : "该扩展用于{$why}，请在 PHP 配置中启用后重启 PHP-FPM。"
        );
    }
    $required[] = item(
        '函数 proc_open',
        function_exists('proc_open') ? 'ok' : 'error',
        function_exists('proc_open') ? '可用' : '已被禁用',
        '可用',
        function_exists('proc_open')
            ? ''
            : '承台需要用它执行应用自带的初始化脚本。请从 php.ini 的 disable_functions 中移除 proc_open 后重启 PHP-FPM。'
    );

    // 承台源码关键文件
    $autoload = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $required[] = item(
        'Composer 依赖 vendor/autoload.php',
        is_file($autoload) ? 'ok' : 'error',
        is_file($autoload) ? '已就绪' : '缺失',
        '存在',
        is_file($autoload)
            ? ''
            : '源码缺少 vendor 目录。请在服务器上进入 platform-server 目录执行 composer install（或上传完整的 vendor 目录）。'
    );

    $schema = $rootDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
    $required[] = item(
        '数据库脚本 database/schema.sql',
        is_file($schema) ? 'ok' : 'error',
        is_file($schema) ? '已就绪' : '缺失',
        '存在',
        is_file($schema) ? '' : '源码缺少建表脚本，无法完成安装，请核对源码是否完整。'
    );

    // 目录可写
    $appsDir     = $publicDir . DIRECTORY_SEPARATOR . 'apps';
    $appsExists  = is_dir($appsDir);
    $appsWritable = $appsExists ? is_writable($appsDir) : is_writable($publicDir);
    $required[] = item(
        '应用落地目录可写',
        $appsWritable ? 'ok' : 'error',
        $appsExists
            ? ('public/apps' . (is_writable($appsDir) ? '（可写）' : '（不可写）'))
            : 'public/apps 不存在，且 public 不可写',
        '可写',
        $appsWritable
            ? ''
            : '安装应用时承台要在 public/apps/ 下落文件。请把该目录属主改为 PHP-FPM 的运行用户（如 www）；若 apps 目录还没创建，则需要 public 目录本身可写。'
    );

    $runtimeDir = $rootDir . DIRECTORY_SEPARATOR . 'runtime';
    $runtimeExists = is_dir($runtimeDir);
    $runtimeWritable = $runtimeExists ? is_writable($runtimeDir) : is_writable($rootDir);
    $required[] = item(
        'runtime 目录可写',
        $runtimeWritable ? 'ok' : 'error',
        $runtimeExists
            ? ('runtime' . (is_writable($runtimeDir) ? '（可写）' : '（不可写）'))
            : 'runtime 不存在，且根目录不可写',
        '可写',
        $runtimeWritable ? '' : '用于存放缓存与安装暂存区。请把 runtime 目录属主改为 PHP-FPM 的运行用户（如 www）。'
    );

    $required[] = item(
        '根目录可写（生成 .env）',
        is_writable($rootDir) ? 'ok' : 'error',
        is_writable($rootDir) ? '可写' : '不可写',
        '可写',
        is_writable($rootDir)
            ? ''
            : '安装程序需要在 platform-server 根目录生成 .env 配置文件，请赋予该目录写权限。'
    );

    $groups[] = ['name' => '必备依赖 · PHP 与源码', 'required' => true, 'items' => $required];

    // ---------- 建议：PHP 配置 ----------
    $advice = [];
    $fileinfo = extension_loaded('fileinfo');
    $advice[] = item(
        '扩展 fileinfo',
        $fileinfo ? 'ok' : 'warn',
        $fileinfo ? '已启用' : '未启用',
        '建议启用',
        $fileinfo ? '' : '上传应用包时用于识别文件类型，未启用一般不影响使用，但建议开启。'
    );

    $upload = iniBytes('upload_max_filesize');
    $advice[] = item(
        'upload_max_filesize',
        $upload >= 67108864 ? 'ok' : 'warn',
        iniShow('upload_max_filesize'),
        '≥ 64M',
        $upload >= 67108864 ? '' : '应用包上限为 50MB，该值过小会导致上传失败，建议设为 64M。'
    );

    $post = iniBytes('post_max_size');
    $advice[] = item(
        'post_max_size',
        $post >= 67108864 ? 'ok' : 'warn',
        iniShow('post_max_size'),
        '≥ 64M',
        $post >= 67108864 ? '' : '该值必须不小于 upload_max_filesize，否则大文件上传会被丢弃。建议设为 64M。'
    );

    $memory = iniBytes('memory_limit');
    $advice[] = item(
        'memory_limit',
        ($memory < 0 || $memory >= 134217728) ? 'ok' : 'warn',
        iniShow('memory_limit'),
        '≥ 128M',
        ($memory < 0 || $memory >= 134217728) ? '' : '解压与校验应用包时需要一定内存，过小可能失败。'
    );

    $userIni = (string) ini_get('user_ini.filename');
    $advice[] = item(
        'user_ini.filename',
        $userIni !== '' ? 'ok' : 'warn',
        $userIni !== '' ? $userIni : '(空)',
        '.user.ini',
        $userIni !== ''
            ? ''
            : '承台靠 .user.ini 关闭 display_errors（防止 PHP 警告污染 JSON 响应、并避免暴露服务器路径），设为空会让这层保护失效。'
    );

    $displayErrors = (string) ini_get('display_errors');
    $off = in_array(strtolower($displayErrors), ['0', 'off', ''], true);
    $advice[] = item(
        'display_errors',
        $off ? 'ok' : 'warn',
        $displayErrors !== '' ? $displayErrors : 'Off',
        'Off',
        $off ? '' : '建议在 php.ini 关闭。承台已用 .user.ini 兜底，但关闭全局配置更稳妥。'
    );

    $sapi = PHP_SAPI;
    $sapiOk = in_array($sapi, ['fpm-fcgi', 'cgi-fcgi', 'apache2handler'], true);
    $advice[] = item(
        'PHP 运行方式',
        $sapiOk ? 'ok' : 'warn',
        $sapi,
        'fpm-fcgi（推荐）',
        $sapiOk ? '' : '目录级 .user.ini 只在 CGI / FastCGI / FPM 模式下生效，当前模式下该保护不生效。'
    );

    $groups[] = ['name' => '建议依赖 · PHP 配置', 'required' => false, 'items' => $advice];

    // ---------- 建议：部署形态 ----------
    $deploy = [];
    $deploy[] = item(
        'HTTPS',
        isHttps() ? 'ok' : 'warn',
        isHttps() ? '已启用' : '未启用',
        '生产环境启用',
        isHttps() ? '' : '生产环境建议配置证书：应用生成的对外分享链接会按当前协议拼接。'
    );

    $hasFront = $frontDist !== null;
    $publicIndex = $publicDir . DIRECTORY_SEPARATOR . 'index.html';
    $deploy[] = item(
        '前端静态资源',
        is_file($publicIndex) ? 'ok' : ($hasFront ? 'warn' : 'warn'),
        is_file($publicIndex) ? 'public 已存在 index.html' : ($hasFront ? '源码中已找到 dist，将自动部署' : '未找到 dist'),
        '存在',
        is_file($publicIndex)
            ? ''
            : ($hasFront
                ? '安装程序会自动把前端构建产物复制到 public 目录，安装后即可正常访问。'
                : '未在源码中找到前端构建产物（platform/dist）。请自行把 platform/dist/* 复制到 public 目录，否则访问站点只能看到空页面。')
    );

    $server = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
    $deploy[] = item(
        'Web 服务器',
        'ok',
        $server !== '' ? $server : '（未知）',
        '—',
        '以下两项无法从 PHP 读取，请自行确认：① docroot 指向 platform-server/public；'
            . '② Nginx 建议配置 client_max_body_size ≥ 64m，否则大包上传会被拦在 PHP 之前。'
    );

    $groups[] = ['name' => '建议依赖 · 部署形态', 'required' => false, 'items' => $deploy];

    $error = $warn = 0;
    foreach ($groups as $g) {
        foreach ($g['items'] as $it) {
            if ($it['status'] === 'error') {
                $error++;
            } elseif ($it['status'] === 'warn') {
                $warn++;
            }
        }
    }
    return ['groups' => $groups, 'error' => $error, 'warn' => $warn];
}

// =====================================================================
// 表单校验
// =====================================================================

function validateInput(array $in): array
{
    $errs = [];

    $host = trim((string) ($in['db_host'] ?? ''));
    $port = trim((string) ($in['db_port'] ?? ''));
    $name = trim((string) ($in['db_name'] ?? ''));
    $user = trim((string) ($in['db_user'] ?? ''));
    $pass = (string) ($in['db_pass'] ?? '');

    if ($host === '') {
        $errs['db_host'] = '请填写数据库地址。';
    }
    if ($port === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $errs['db_port'] = '端口需为 1-65535 的数字。';
    }
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        $errs['db_name'] = '库名只能包含字母、数字与下划线。';
    }
    if ($user === '') {
        $errs['db_user'] = '请填写数据库账号。';
    }

    $siteUrl = trim((string) ($in['site_url'] ?? ''));
    if ($siteUrl === '') {
        $siteUrl = baseUrl(); // 表单留空时按当前访问地址自动推断
    }
    $siteUrl = rtrim($siteUrl, '/');
    if (!preg_match('#^https?://#i', $siteUrl) || !filter_var($siteUrl, FILTER_VALIDATE_URL)) {
        $errs['site_url'] = '请填写完整站点地址，如 https://example.com。';
    } else {
        $hostPart = (string) parse_url($siteUrl, PHP_URL_HOST);
        if ($hostPart === '') {
            $errs['site_url'] = '站点地址缺少主机名。';
        }
    }

    $email = trim((string) ($in['admin_email'] ?? ''));
    $username = trim((string) ($in['admin_user'] ?? ''));
    $pwd = (string) ($in['admin_pass'] ?? '');
    $pwd2 = (string) ($in['admin_pass2'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errs['admin_email'] = '请填写有效的邮箱地址。';
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        $errs['admin_user'] = '账号需为 3-32 位字母、数字或下划线。';
    }
    if (strlen($pwd) < 8 || !preg_match('/[A-Za-z]/', $pwd) || !preg_match('/\d/', $pwd)) {
        $errs['admin_pass'] = '密码至少 8 位，且必须同时包含字母和数字。';
    }
    if ($pwd !== $pwd2) {
        $errs['admin_pass2'] = '两次输入的密码不一致。';
    }

    // 安装统计上报为安装前置条件：必须勾选后才能完成安装
    if (empty($in['report_enabled'])) {
        $errs['report_enabled'] = '请勾选后安装。';
    }
    $reportEnabled = !empty($in['report_enabled']);

    return [$errs, compact('host', 'port', 'name', 'user', 'pass', 'siteUrl', 'email', 'username', 'pwd', 'reportEnabled')];
}

// =====================================================================
// 执行安装
// =====================================================================

function runInstall(array $cfg, string $rootDir, string $publicDir, ?string $frontDist, string $lockFile): array
{
    $logs = [];
    $fail = static function (string $name, string $msg) use (&$logs): array {
        $logs[] = ['name' => $name, 'status' => 'fail', 'msg' => $msg];
        return $logs;
    };

    // 1) 连接数据库（库不存在时自动创建）
    $dsnBase = "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        try {
            $pdo = new PDO($dsnBase . ";dbname={$cfg['name']}", $cfg['user'], $cfg['pass'], $opts);
        } catch (PDOException $e) {
            // 可能是库不存在：连服务器后创建
            $pdoSrv = new PDO($dsnBase, $cfg['user'], $cfg['pass'], $opts);
            $pdoSrv->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $pdo = new PDO($dsnBase . ";dbname={$cfg['name']}", $cfg['user'], $cfg['pass'], $opts);
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Access denied') !== false || stripos($msg, 'CREATE') !== false) {
            $msg = '无法连接或创建数据库。若数据库不存在，请先用该账号在 MySQL 中手动创建数据库 '
                . "`{$cfg['name']}`（账号需具备建库权限），或在面板中创建同名库后重试。原始错误：" . $msg;
        } else {
            $msg = '无法连接数据库：' . $msg . '（请检查地址、端口、账号与密码）';
        }
        return $fail('连接数据库', $msg);
    }

    try {
        $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable $e) {
        $ver = '';
    }
    $logs[] = ['name' => '连接数据库', 'status' => 'ok', 'msg' => '连接成功' . ($ver !== '' ? "（MySQL {$ver}）" : '')];

    // 2) 执行建表脚本
    $schemaFile = $rootDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
    try {
        $sql = (string) file_get_contents($schemaFile);
        $stmts = splitSql($sql);
        foreach ($stmts as $stmt) {
            $pdo->exec($stmt);
        }
        $logs[] = ['name' => '导入数据库结构', 'status' => 'ok', 'msg' => '共执行 ' . count($stmts) . ' 条语句'];
    } catch (Throwable $e) {
        return $fail('导入数据库结构', '执行 schema.sql 失败：' . $e->getMessage());
    }

    // 3) 兼容旧库：确保 platform_admin.email 列存在
    try {
        $has = $pdo->query("SHOW COLUMNS FROM `platform_admin` LIKE 'email'")->fetchAll();
        if (!$has) {
            $pdo->exec("ALTER TABLE `platform_admin` ADD COLUMN `email` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '管理员邮箱（安装时填写，用于找回密码/通知）' AFTER `nickname`");
            $logs[] = ['name' => '校对管理员邮箱字段', 'status' => 'ok', 'msg' => '已补充 email 字段'];
        } else {
            $logs[] = ['name' => '校对管理员邮箱字段', 'status' => 'ok', 'msg' => 'email 字段已存在'];
        }
    } catch (Throwable $e) {
        return $fail('校对管理员邮箱字段', '检查/新增 email 字段失败：' . $e->getMessage());
    }

    // 4) 创建超级管理员
    try {
        $exists = $pdo->prepare('SELECT `id` FROM `platform_admin` WHERE `username` = ? LIMIT 1');
        $exists->execute([$cfg['username']]);
        if ($exists->fetchColumn()) {
            return $fail('创建超级管理员', '账号 ' . $cfg['username'] . ' 已存在，请更换账号名后重试。');
        }
        $hash = password_hash($cfg['pwd'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO `platform_admin` (`username`, `password_hash`, `nickname`, `email`, `status`, `is_super`, `must_change_pwd`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, 1, 1, 0, NOW(), NOW())'
        );
        $stmt->execute([$cfg['username'], $hash, '超级管理员', $cfg['email']]);
        $logs[] = ['name' => '创建超级管理员', 'status' => 'ok', 'msg' => '账号 ' . $cfg['username'] . '（' . $cfg['email'] . '）'];
    } catch (Throwable $e) {
        return $fail('创建超级管理员', '创建管理员失败：' . $e->getMessage());
    }

    // 5) 生成 .env
    try {
        $secret = bin2hex(random_bytes(32)); // 64 位十六进制，满足 ≥32 位要求
        $paySecret = bin2hex(random_bytes(32)); // 支付/插件签名密钥
        $env = "APP_DEBUG = false\n"
            . "APP_TRACE = false\n\n"
            . "[APP]\n"
            . "DEFAULT_TIMEZONE = Asia/Shanghai\n"
            . "CORS_ORIGINS =\n"
            . 'URL = ' . envVal($cfg['siteUrl']) . "\n\n"
            . "[DATABASE]\n"
            . "TYPE = mysql\n"
            . 'HOSTNAME = ' . envVal($cfg['host']) . "\n"
            . 'DATABASE = ' . envVal($cfg['name']) . "\n"
            . 'USERNAME = ' . envVal($cfg['user']) . "\n"
            . 'PASSWORD = ' . envVal($cfg['pass']) . "\n"
            . 'HOSTPORT = ' . envVal($cfg['port']) . "\n"
            . "CHARSET = utf8mb4\n"
            . "PREFIX =\n"
            . "DEBUG = false\n\n"
            . "[JWT]\n"
            . 'SECRET = ' . $secret . "\n"
            . "TTL = 7200\n"
            . "ISSUER = authpro-platform\n\n"
            . "[PLUGIN]\n"
            . 'BASE_URL = ' . envVal($cfg['siteUrl']) . "\n"
            . 'PAY_SECRET = ' . $paySecret . "\n";

        $envFile = $rootDir . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            @copy($envFile, $envFile . '.bak.' . date('YmdHis'));
        }
        if (@file_put_contents($envFile, $env) === false) {
            return $fail('生成 .env 配置', '写入失败，请检查 platform-server 根目录写权限。');
        }
        $logs[] = ['name' => '生成 .env 配置', 'status' => 'ok', 'msg' => '已写入站点、数据库、随机 JWT 密钥与插件签名密钥'];
    } catch (Throwable $e) {
        return $fail('生成 .env 配置', '生成配置失败：' . $e->getMessage());
    }

    // 6) 部署前端静态资源
    $publicIndex = $publicDir . DIRECTORY_SEPARATOR . 'index.html';
    if (is_file($publicIndex)) {
        $logs[] = ['name' => '部署前端静态资源', 'status' => 'skip', 'msg' => 'public/index.html 已存在，跳过（不覆盖已有前端）'];
    } elseif ($frontDist !== null) {
        try {
            copyDir($frontDist, $publicDir);
            $logs[] = ['name' => '部署前端静态资源', 'status' => 'ok', 'msg' => '已从源码 dist 复制到 public'];
        } catch (Throwable $e) {
            return $fail('部署前端静态资源', '复制失败：' . $e->getMessage());
        }
    } else {
        $logs[] = ['name' => '部署前端静态资源', 'status' => 'skip', 'msg' => '未找到 dist，请手动把 platform/dist/* 复制到 public'];
    }

    // 7) 确保应用目录与 runtime 目录存在
    try {
        $appsDir = $publicDir . DIRECTORY_SEPARATOR . 'apps';
        if (!is_dir($appsDir)) {
            @mkdir($appsDir, 0755, true);
        }
        $runtimeDir = $rootDir . DIRECTORY_SEPARATOR . 'runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
        $logs[] = ['name' => '准备运行目录', 'status' => 'ok', 'msg' => 'public/apps 与 runtime 已就绪'];
    } catch (Throwable $e) {
        $logs[] = ['name' => '准备运行目录', 'status' => 'skip', 'msg' => '目录准备出现异常：' . $e->getMessage()];
    }

    // 8) 写入安装锁
    try {
        $lock = "installed_at=" . date('Y-m-d H:i:s') . "\nadmin={$cfg['username']}\n";
        @file_put_contents($lockFile, $lock);
        $logs[] = ['name' => '锁定安装程序', 'status' => 'ok', 'msg' => '已写入 install.lock，防止重复安装'];
    } catch (Throwable $e) {
        $logs[] = ['name' => '锁定安装程序', 'status' => 'skip', 'msg' => '未能写入锁文件，请手动删除 install 目录'];
    }

    // 9) 安装信息上报（可在安装时关闭；失败仅提示，绝不影响安装结果）
    if (empty($cfg['reportEnabled'])) {
        $logs[] = ['name' => '安装信息上报', 'status' => 'skip', 'msg' => '已按你的选择关闭安装统计上报'];
    } else {
        $reportFile = dirname($lockFile) . DIRECTORY_SEPARATOR . 'ct_report.php';
        if (!is_file($reportFile)) {
            $logs[] = ['name' => '安装信息上报', 'status' => 'skip', 'msg' => '未找到上报模块，已跳过'];
        } else {
            try {
                require_once $reportFile;
                $r = ct_install_report($cfg);
                $logs[] = ['name' => '安装信息上报', 'status' => $r['status'], 'msg' => $r['msg']];
            } catch (Throwable $e) {
                $logs[] = ['name' => '安装信息上报', 'status' => 'skip', 'msg' => '上报出现异常，已忽略：' . $e->getMessage()];
            }
        }
    }

    return $logs;
}

// =====================================================================
// 页面渲染
// =====================================================================

function renderHead(string $title): void
{
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' · 承台安装</title><style>'
        . '*{box-sizing:border-box}'
        . 'body{margin:0;background:#f5f6f8;color:#1f2329;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
        . '.wrap{max-width:860px;margin:0 auto;padding:32px 16px 64px}'
        . '.brand{display:flex;align-items:center;gap:12px;margin-bottom:8px}'
        . '.logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#2b2f36,#4a5058);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px}'
        . 'h1{font-size:20px;margin:0}'
        . '.sub{color:#8a9099;font-size:13px;margin:2px 0 24px}'
        . '.card{background:#fff;border:1px solid #e6e8eb;border-radius:12px;padding:20px 22px;margin-bottom:16px;box-shadow:0 1px 2px rgba(16,24,40,.04)}'
        . '.card h2{font-size:15px;margin:0 0 4px;display:flex;align-items:center;gap:8px}'
        . '.tag{font-size:12px;padding:1px 8px;border-radius:10px;font-weight:600}'
        . '.tag.req{background:#fdecec;color:#d4380d}.tag.adv{background:#eef4ff;color:#2f54eb}'
        . 'table{width:100%;border-collapse:collapse;margin-top:10px}'
        . 'td{padding:9px 8px;border-top:1px solid #f0f1f3;vertical-align:top;font-size:13px}'
        . 'tr:first-child td{border-top:none}'
        . '.st{white-space:nowrap;font-weight:600}'
        . '.st.ok{color:#0d9f6e}.st.warn{color:#d48806}.st.error{color:#d4380d}'
        . '.st.skip{color:#8a9099}'
        . '.cur{color:#4e5969}.exp{color:#8a9099}'
        . '.hint{color:#a0a5ad;font-size:12px;margin-top:3px}'
        . 'label{display:block;font-weight:600;margin:14px 0 6px;font-size:13px}'
        . 'input{width:100%;padding:9px 11px;border:1px solid #dcdfe4;border-radius:8px;font-size:14px;outline:none}'
        . 'input:focus{border-color:#4a5058;box-shadow:0 0 0 3px rgba(74,80,88,.1)}'
        . '.err{color:#d4380d;font-size:12px;margin-top:5px}'
        . '.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}'
        . '@media(max-width:600px){.grid{grid-template-columns:1fr}}'
        . '.btn{display:inline-block;padding:10px 22px;border-radius:8px;border:none;background:#2b2f36;color:#fff;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none}'
        . '.btn:hover{background:#1c1f24}'
        . '.btn[disabled]{background:#c9cdd4;cursor:not-allowed}'
        . '.bar{display:flex;justify-content:space-between;align-items:center;margin-top:22px;gap:12px;flex-wrap:wrap}'
        . '.note{background:#fff8e6;border:1px solid #ffe1a8;border-radius:8px;padding:12px 14px;font-size:13px;color:#7a5b00;margin-top:14px}'
        . '.ok-box{background:#eaf7f1;border:1px solid #b7e3ce;border-radius:8px;padding:12px 14px;font-size:13px;color:#0d6e4c}'
        . '.kv{margin:8px 0}.kv b{display:inline-block;min-width:88px;color:#4e5969}'
        . 'code{background:#f2f3f5;padding:1px 6px;border-radius:4px;font-size:12px}'
        . 'ul{margin:6px 0 0;padding-left:20px}li{margin:4px 0;font-size:13px}'
        . '</style></head><body><div class="wrap">'
        . '<div class="brand"><div class="logo">承</div><div><h1>承台应用管理平台</h1></div></div>'
        . '<div class="sub">在线安装程序 · ' . h($title) . '</div>';
}

function renderFoot(): void
{
    echo '</div></body></html>';
}

/** 依赖检测结果表格 */
function renderDetect(array $result): void
{
    foreach ($result['groups'] as $g) {
        $req = (bool) ($g['required'] ?? false);
        echo '<div class="card"><h2>' . h($g['name'])
            . '<span class="tag ' . ($req ? 'req' : 'adv') . '">' . ($req ? '必备依赖' : '建议依赖') . '</span></h2>';
        echo '<table>';
        foreach ($g['items'] as $it) {
            $label = $it['status'] === 'ok' ? '正常' : ($it['status'] === 'warn' ? '建议处理' : '不满足');
            echo '<tr><td class="st ' . h($it['status']) . '">' . $label . '</td>'
                . '<td><div class="cur">' . h($it['name']) . '：' . h($it['current']) . '</div>'
                . '<div class="exp">期望：' . h($it['expected']) . '</div>'
                . ($it['hint'] !== '' ? '<div class="hint">' . h($it['hint']) . '</div>' : '')
                . '</td></tr>';
        }
        echo '</table></div>';
    }
}

// =====================================================================
// 路由
// =====================================================================

$frontDist = findFrontDist($ROOT_DIR);
$detection = detect($ROOT_DIR, $PUBLIC_DIR, $frontDist);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

// CSRF 令牌
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string) $_SESSION['install_csrf'];

// ---------- 执行安装 ----------
if ($method === 'POST' && $action === 'install') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        renderHead('安装失败');
        echo '<div class="card"><h2>安全校验失败</h2><p>页面已过期，请返回重新填写。</p>'
            . '<div class="bar"><a class="btn" href="?step=form">返回填写</a></div></div>';
        renderFoot();
        exit;
    }

    [$errs, $cfg] = validateInput($_POST);
    if ($errs !== []) {
        renderHead('填写有误');
        echo '<div class="card"><h2>请修正以下问题</h2><ul>';
        foreach ($errs as $e) {
            echo '<li>' . h($e) . '</li>';
        }
        echo '</ul><div class="bar"><a class="btn" href="?step=form">返回修改</a></div></div>';
        renderFoot();
        exit;
    }

    $logs = runInstall($cfg, $ROOT_DIR, $PUBLIC_DIR, $frontDist, $LOCK_FILE);
    $failed = false;
    foreach ($logs as $l) {
        if ($l['status'] === 'fail') {
            $failed = true;
            break;
        }
    }

    if ($failed) {
        renderHead('安装失败');
        echo '<div class="card"><h2>安装未完成</h2><table>';
        foreach ($logs as $l) {
            echo '<tr><td class="st ' . h($l['status']) . '">'
                . ($l['status'] === 'ok' ? '完成' : ($l['status'] === 'skip' ? '跳过' : '失败')) . '</td>'
                . '<td><div class="cur">' . h($l['name']) . '</div><div class="hint">' . h($l['msg']) . '</div></td></tr>';
        }
        echo '</table><div class="note">请根据上面的错误信息处理后，刷新本页重试。</div>'
            . '<div class="bar"><a class="btn" href="?step=form">重新填写</a></div></div>';
        renderFoot();
        exit;
    }

    $base = baseUrl();
    renderHead('安装完成');
    echo '<div class="card"><div class="ok-box">承台已安装完成，可以登录后台了。</div>';
    echo '<div class="kv" style="margin-top:16px"><b>后台地址</b> <a href="' . h($base) . '/">' . h($base) . '/</a>　（打开后即为登录页）</div>';
    echo '<div class="kv"><b>登录账号</b> ' . h($cfg['username']) . '</div>';
    echo '<div class="kv"><b>管理员邮箱</b> ' . h($cfg['email']) . '</div>';
    echo '</div>';

    echo '<div class="card"><h2>安装明细</h2><table>';
    foreach ($logs as $l) {
        $label = $l['status'] === 'ok' ? '完成' : ($l['status'] === 'skip' ? '跳过' : '失败');
        echo '<tr><td class="st ' . h($l['status']) . '">' . h($label) . '</td>'
            . '<td><div class="cur">' . h($l['name']) . '</div><div class="hint">' . h($l['msg']) . '</div></td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>安全提示</h2><ul>'
        . '<li>安装程序已自动锁定（写入 install.lock），重复访问只会显示已安装提示；如需重装，请先删除服务器上的 <code>public/install/install.lock</code>。</li>'
        . '<li>强烈建议删除或重命名 <code>public/install</code> 目录，避免安装程序长期暴露在公网。</li>'
        . '<li>请确认 <code>.env</code> 已被 Web 服务器禁止访问（Nginx 示例：<code>location ~ /\\.(env|git) { deny all; }</code>）。</li>'
        . '<li>建议开启 HTTPS，并确保 <code>platform-server/public</code> 为站点根目录（docroot）。</li>'
        . '</ul></div>';

    renderFoot();
    exit;
}

// ---------- 已安装 ----------
if (is_file($LOCK_FILE)) {
    renderHead('已安装');
    echo '<div class="card"><div class="ok-box">检测到 <code>install.lock</code>，承台已经安装过了。</div>'
        . '<div class="bar"><a class="btn" href="' . h(baseUrl()) . '/">前往后台</a></div>'
        . '<div class="note">如需重新安装，请先删除服务器上的 <code>public/install/install.lock</code> 后刷新本页。</div></div>';
    renderFoot();
    exit;
}

// ---------- 填写信息 ----------
if (($action === 'form') || (($_GET['step'] ?? '') === 'form')) {
    renderHead('填写安装信息');
    if ($detection['error'] > 0) {
        echo '<div class="note">当前仍有 ' . (int) $detection['error'] . ' 项必备依赖不满足，请先处理后再填写。</div>';
    }
    $old = static function (string $k, string $d = ''): string {
        return h((string) ($_POST[$k] ?? $d));
    };
    echo '<form id="install-form" method="post" action="?action=install">'
        . '<input type="hidden" name="action" value="install">'
        . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'

        . '<div class="card"><h2>站点信息</h2>'
        . '<div class="grid">'
        . '<div><label>站点地址</label><input name="site_url" value="' . $old('site_url', baseUrl()) . '" placeholder="https://example.com"><div class="hint">承台对外访问地址，用于插件回调与支付签名</div></div>'
        . '</div></div>'

        . '<div class="card"><h2>数据库</h2>'
        . '<div class="grid">'
        . '<div><label>数据库地址</label><input name="db_host" value="' . $old('db_host', '127.0.0.1') . '" placeholder="127.0.0.1"></div>'
        . '<div><label>端口</label><input name="db_port" value="' . $old('db_port', '3306') . '" placeholder="3306"></div>'
        . '<div><label>数据库名</label><input name="db_name" value="' . $old('db_name', 'authpro_platform') . '" placeholder="authpro_platform"><div class="hint">不存在时会自动创建（需账号有建库权限）</div></div>'
        . '<div><label>数据库账号</label><input name="db_user" value="' . $old('db_user', 'root') . '"></div>'
        . '<div><label>数据库密码</label><input name="db_pass" type="password" value=""></div>'
        . '</div></div>'

        . '<div class="card"><h2>承台管理员</h2>'
        . '<div class="grid">'
        . '<div><label>管理员邮箱</label><input name="admin_email" type="email" value="' . $old('admin_email') . '" placeholder="you@example.com"></div>'
        . '<div><label>登录账号</label><input name="admin_user" value="' . $old('admin_user') . '" placeholder="3-32 位字母/数字/下划线"></div>'
        . '<div><label>登录密码</label><input name="admin_pass" type="password" placeholder="至少 8 位，含字母与数字"></div>'
        . '<div><label>确认密码</label><input name="admin_pass2" type="password"></div>'
        . '</div></div>';

    $reportChecked = empty($_POST['action']) ? true : !empty($_POST['report_enabled']);
    echo '<div class="card"><h2>系统介绍</h2>'
        . '<div class="hint" style="font-size:13px;color:#4e5969;margin-top:2px">'
        . '本系统为承台应用管理平台，是青岛次世代网络科技有限公司开发的一款多应用聚合可接入式平台。'
        . '</div>'
        . '<label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-weight:400;font-size:13px">'
        . '<input type="checkbox" name="report_enabled" value="1" style="width:auto;margin:0"'
        . ($reportChecked ? ' checked' : '') . '> 确认安装'
        . '</label></div>';

    echo '<div class="bar"><a class="btn" href="?" style="background:#eceef1;color:#33363b">上一步</a>'
        . '<button class="btn" type="submit"' . ($detection['error'] > 0 ? ' disabled' : '') . '>开始安装</button></div>'
        . '</form>';

    // 安装统计必须勾选：未勾选时拦截提交并提示
    echo '<script>(function(){var f=document.getElementById("install-form");if(!f)return;'
        . 'f.addEventListener("submit",function(e){var c=f.querySelector("input[name=report_enabled]");'
        . 'if(!c||!c.checked){e.preventDefault();alert("请勾选后安装");}});})();</script>';
    renderFoot();
    exit;
}

// ---------- 依赖检测（默认） ----------
renderHead('环境检测');
$summaryColor = $detection['error'] > 0 ? '#d4380d' : ($detection['warn'] > 0 ? '#d48806' : '#0d9f6e');
echo '<div class="card"><h2>环境检测结果</h2>'
    . '<div style="margin-top:6px;color:' . $summaryColor . '">'
    . '必备依赖不满足 ' . (int) $detection['error'] . ' 项；建议依赖待优化 ' . (int) $detection['warn'] . ' 项。'
    . '</div>'
    . '<div class="hint">「必备依赖」不满足时将无法继续安装；「建议依赖」不满足一般不影响安装，但建议一并处理。</div></div>';
renderDetect($detection);

echo '<div class="bar">';
if ($detection['error'] > 0) {
    echo '<a class="btn" href="?" style="background:#eceef1;color:#33363b">重新检测</a>'
        . '<a class="btn" href="?step=form" style="background:#eceef1;color:#33363b">已了解，仍要继续</a>';
} else {
    echo '<a class="btn" href="?step=form">下一步：填写安装信息</a>';
}
echo '</div>';
renderFoot();
