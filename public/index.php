<?php
// +----------------------------------------------------------------------
// | 统一管理平台 - HTTP 入口
// +----------------------------------------------------------------------
namespace think;

require __DIR__ . '/../vendor/autoload.php';

// 执行 HTTP 应用并响应
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);
