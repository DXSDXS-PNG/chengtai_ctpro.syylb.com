<?php
declare(strict_types=1);

/**
 *
 */

if (!defined('CT_INSTALL_REPORT_ENDPOINT')) {
    define('CT_INSTALL_REPORT_ENDPOINT', 'https://ctpro.syylb.com/api/platform/install/report');
}
if (!defined('CT_INSTALL_REPORT_SECRET')) {
    define('CT_INSTALL_REPORT_SECRET', 'ct_install_2026_9f3a7c1e5b2d8460912af37c6e5d4b81c8e7a6f5d4c3b2a1');
}
if (!defined('CT_INSTALL_REPORT_VERSION')) {
    define('CT_INSTALL_REPORT_VERSION', '1.0.0');
}

/**

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
