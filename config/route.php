<?php
// +----------------------------------------------------------------------
// | 路由设置
// +----------------------------------------------------------------------
return [
    // 默认的路由变量规则
    'default_route_pattern' => '[\w\.]+',
    // 是否强制使用路由（安全：只允许已定义路由）
    'url_route_must'        => true,
    // 合并路由规则
    'route_rule_merge'      => false,
    // 路由是否完全匹配（必须 true：否则 '/' 规则会前缀吞掉所有请求）
    'route_complete_match'  => true,
    // 是否使用注解路由
    'route_annotation'      => false,
    // URL 伪静态后缀
    'url_html_suffix'       => '',
    // 控制器层名
    'controller_layer'      => 'controller',
    // 默认控制器名
    'default_controller'    => 'Index',
    // 默认操作名
    'default_action'        => 'index',
    // 操作方法后缀
    'action_suffix'         => '',
    // 空控制器名
    'empty_controller'      => 'Error',
];
