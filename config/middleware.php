<?php
// +----------------------------------------------------------------------
// | 中间件别名配置
// +----------------------------------------------------------------------
return [
    'alias'    => [
        'platform_auth' => \app\middleware\PlatformAuth::class,
        'login_rate'    => \app\middleware\LoginRateLimit::class,
    ],
    'priority' => [],
];
