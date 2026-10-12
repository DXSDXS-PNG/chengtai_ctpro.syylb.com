<?php
declare(strict_types=1);

/**
 * 承台 · 安装信息上报钩子（个人版）
 *
 * 用途：安装完成后向承台官方运营版上报一条「安装记录」，用于
 *   1. 统计承台个人版的正版安装量；
 *   2. 在运营版后台「系统管理 → 承台安装详情」中查看已安装的站点。
 *
 * 上报内容（已在安装向导中明示，并提供开关，可在安装时关闭）：
 *   站点域名、站点地址、安装时填写的管理员邮箱、承台版本、PHP 版本、上报时间。
 *   服务器公网 IP 由接收端按请求来源自动记录。
 *   不采集任何业务数据、用户数据、数据库内容或站点访问数据。
 *
 * 加密说明：本文件为独立模块，可整体交由 Xend / XLoad 加密保护。
 *   仅使用标准 PHP 能力（curl / stream / json / hash_hmac），不含 eval、
 *   变量函数、动态类名等加密后语义会改变的写法。
 */

if (!defined('CT_INSTALL_REPORT_ENDPOINT')) {
    // 承台官方运营版上报接收地址
    define('CT_INSTALL_REPORT_ENDPOINT', 'https://ctpro.syylb.com/api/platform/install/report');
}
if (!defined('CT_INSTALL_REPORT_SECRET')) {
    // 与运营版接收端约定的共享密钥，仅用于校验上报来源（防止伪造记录）
    define('CT_INSTALL_REPORT_SECRET', 'ct_install_2026_9f3a7c1e5b2d8460912af37c6e5d4b81c8e7a6f5d4c3b2a1');
}
if (!defined('CT_INSTALL_REPORT_VERSION')) {
    // 个人版版本号（用于在运营版区分安装的版本）
    define('CT_INSTALL_REPORT_VERSION', '1.0.0');
}

/**
 * 执行一次安装信息上报。
 *
 * @param array $cfg 安装配置，需包含 siteUrl（站点地址）与 email（管理员邮箱）
 * @return array{status:string,msg:string} status 只会是 ok 或 skip；上报失败绝不影响安装流程
 */
function ct_install_report(array $cfg): array
{
    $siteUrl = trim((string) ($cfg['siteUrl'] ?? ''));
    $email   = trim((string) ($cfg['email'] ?? ''));
    $host    = (string) parse_url($siteUrl, PHP_URL_HOST);
    if ($host === '') {
        return ['status' => 'skip', 'msg' => '未能解析站点域名，已跳过上报'];
    }

    $payload = [
        'product'      => 'chengtai',
        'edition'      => 'personal',
        'report_id'    => bin2hex(random_bytes(8)),
        'site_url'     => $siteUrl,
        'domain'       => $host,
        'admin_email'  => $email,
        'version'      => CT_INSTALL_REPORT_VERSION,
        'php_version'  => PHP_VERSION,
        'installed_at' => date('Y-m-d H:i:s'),
    ];

    $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sign = hash_hmac('sha256', $json, CT_INSTALL_REPORT_SECRET);

    $result = ct_install_report_http([
        'payload' => $json,
        'sign'    => $sign,
    ]);

    if ($result['ok']) {
        return ['status' => 'ok', 'msg' => '已上报安装记录（域名 ' . $host . '）'];
    }
    return ['status' => 'skip', 'msg' => '上报未成功（不影响安装）：' . $result['msg']];
}

/**
 * 发送上报请求：优先 curl，不可用时退化为 stream。
 *
 * @param array<string,string> $fields
 * @return array{ok:bool,msg:string}
 */
function ct_install_report_http(array $fields): array
{
    $url  = CT_INSTALL_REPORT_ENDPOINT;
    $body = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            // 客户服务器环境差异较大（系统 CA 可能缺失/过期），
            // 且上报内容不含敏感信息，这里关闭证书校验以保证兼容性。
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'msg' => $err !== '' ? $err : '网络请求失败'];
        }
        if ($code !== 200) {
            return ['ok' => false, 'msg' => 'HTTP ' . $code];
        }
        return ct_install_report_parse((string) $resp);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $body,
            'timeout'       => 8,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return ['ok' => false, 'msg' => '网络请求失败'];
    }
    return ct_install_report_parse((string) $resp);
}

/**
 * 解析上报接口返回。
 *
 * @return array{ok:bool,msg:string}
 */
function ct_install_report_parse(string $resp): array
{
    $data = json_decode($resp, true);
    if (is_array($data) && (int) ($data['code'] ?? 0) === 200) {
        return ['ok' => true, 'msg' => 'success'];
    }
    $msg = is_array($data) ? (string) ($data['msg'] ?? '返回异常') : '返回异常';
    return ['ok' => false, 'msg' => $msg];
}
