<?php
// +----------------------------------------------------------------------
// | 统一管理平台 - PHP 内置开发服务器路由脚本（php think run 使用）
// +----------------------------------------------------------------------
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/' . ltrim($path, '/');

if ($path !== '/' && is_file($file)) {
    return false;
}

if (!is_file(__DIR__ . '/index.php')) {
    return false;
}

require __DIR__ . '/index.php';
