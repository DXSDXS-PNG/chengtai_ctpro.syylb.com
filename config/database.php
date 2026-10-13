<?php
// +----------------------------------------------------------------------
// | 数据库设置（MySQL 5.7+）
// +----------------------------------------------------------------------
return [
    // 默认使用的数据库连接配置
    'default'     => env('database.driver', 'mysql'),
    // 自定义时间查询规则
    'time_query_rule' => [],
    // 自动写入时间戳字段
    'auto_timestamp'  => false,
    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',
    // 数据库连接配置信息
    'connections' => [
        'mysql' => [
            // 数据库类型
            'type'            => 'mysql',
            // 服务器地址
            'hostname'        => env('database.hostname', '127.0.0.1'),
            // 数据库名
            'database'        => env('database.database', 'authpro_platform'),
            // 用户名
            'username'        => env('database.username', 'root'),
            // 密码
            'password'        => env('database.password', ''),
            // 端口
            'hostport'        => env('database.hostport', '3306'),
            // 数据库连接参数
            'params'          => [],
            // 数据库编码默认采用 utf8mb4
            'charset'         => env('database.charset', 'utf8mb4'),
            // 数据库表前缀
            'prefix'          => env('database.prefix', ''),
            // 数据库部署方式:0 集中式(单一服务器)
            'deploy'          => 0,
            // 数据库读写是否分离
            'rw_separate'     => false,
            // 读写分离后 主服务器序号
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
            // 是否需要断线重连
            'break_reconnect' => true,
            // 监听 SQL（仅调试期打开）
            'trigger_sql'     => (bool) env('app.debug', false),
            // 开启字段缓存
            'fields_cache'    => false,
        ],
    ],
];
