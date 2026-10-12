<?php
// +----------------------------------------------------------------------
// | 路由定义
// | 安全约定：url_route_must = true，仅已定义路由可访问
// +----------------------------------------------------------------------
use app\middleware\LoginRateLimit;
use app\middleware\PlatformAuth;
use app\middleware\PlatformOrPluginAuth;
use app\middleware\PluginTokenAuth;
use think\facade\Route;

// ---------------------------------------------------------------
// 基础 / 健康检查（免登录）
// ---------------------------------------------------------------
Route::get('/', '\app\controller\HealthController@root');
Route::get('api/platform/health', '\app\controller\HealthController@index');

// ---------------------------------------------------------------
// 平台认证
// ---------------------------------------------------------------
Route::post('api/platform/auth/login', '\app\controller\AuthController@login')
    ->middleware(LoginRateLimit::class);
Route::post('api/platform/auth/logout', '\app\controller\AuthController@logout')
    ->middleware(PlatformAuth::class);
// 当前登录管理员的身份与权限：前端进后台时用它刷新本地缓存的权限点
Route::get('api/platform/auth/me', '\app\controller\AuthController@me')
    ->middleware(PlatformAuth::class);
Route::post('api/platform/auth/change-password', '\app\controller\AuthController@changePassword')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 应用注册表（需登录）
// ---------------------------------------------------------------
Route::get('api/platform/plugin/list', '\app\controller\PluginController@list')
    ->middleware(PlatformAuth::class);
// 应用详情：基本信息 / 权限点 / 绑定域名 / 最近审计 / 数据表
Route::get('api/platform/plugin/detail', '\app\controller\PluginController@detail')
    ->middleware(PlatformAuth::class);
// 一键诊断：逐项检查应用当前能否正常工作（内部还需 plugin:manage）
Route::get('api/platform/plugin/diagnose', '\app\controller\PluginController@diagnose')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/plugin/status', '\app\controller\PluginController@status')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/plugin/sort', '\app\controller\PluginController@sort')
    ->middleware(PlatformAuth::class);
// 上传应用包（multipart/form-data，字段名 file）——仅落地
Route::post('api/platform/plugin/install', '\app\controller\PluginController@install')
    ->middleware(PlatformAuth::class);
// 执行安装（运行应用包内的 server/setup.php 钩子）
Route::post('api/platform/plugin/setup', '\app\controller\PluginController@setup')
    ->middleware(PlatformAuth::class);
// 卸载上传安装的应用
Route::delete('api/platform/plugin/uninstall', '\app\controller\PluginController@uninstall')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 应用免登握手（ticket / exchange / 应用上报审计）
// ---------------------------------------------------------------
// 申请一次性 ticket：需平台登录态
Route::get('api/platform/plugin/ticket', '\app\controller\PluginAuthController@ticket')
    ->middleware(PlatformAuth::class);
// 兑换登录态：免登录（凭一次性 ticket），供应用后台调用
Route::get('api/platform/plugin/exchange', '\app\controller\PluginAuthController@exchange');
Route::get('api/platform/plugin/session', '\app\controller\PluginAuthController@session')
    ->middleware(PluginTokenAuth::class);
// 应用上报操作到平台审计：需应用域 token（scope=plugin:<id>）
Route::post('api/platform/plugin/audit', '\app\controller\PluginAuthController@audit')
    ->middleware(PluginTokenAuth::class);

// ---------------------------------------------------------------
// 平台概览（需登录）
// ---------------------------------------------------------------
Route::get('api/platform/dashboard/stats', '\app\controller\DashboardController@stats')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 角色 / 权限（需登录 + role:manage）
// ---------------------------------------------------------------
Route::get('api/platform/role/list', '\app\controller\RoleController@list')
    ->middleware(PlatformAuth::class);
Route::post('api/platform/role/create', '\app\controller\RoleController@create')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/role/update', '\app\controller\RoleController@update')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/role/toggle', '\app\controller\RoleController@toggle')
    ->middleware(PlatformAuth::class);
Route::delete('api/platform/role/delete', '\app\controller\RoleController@delete')
    ->middleware(PlatformAuth::class);
Route::get('api/platform/role/permissions', '\app\controller\RoleController@permissions')
    ->middleware(PlatformAuth::class);
Route::get('api/platform/role/permission-list', '\app\controller\RoleController@permissionList')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/role/assign-permissions', '\app\controller\RoleController@assignPermissions')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 管理员管理（需登录 + admin:manage）
// ---------------------------------------------------------------
Route::get('api/platform/admin/list', '\app\controller\AdminController@list')
    ->middleware(PlatformAuth::class);
Route::post('api/platform/admin/create', '\app\controller\AdminController@create')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/admin/enable', '\app\controller\AdminController@enable')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/admin/assign-roles', '\app\controller\AdminController@assignRoles')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 审计日志（需登录 + audit:view）
// ---------------------------------------------------------------
Route::get('api/platform/audit/list', '\app\controller\AuditController@list')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 会话管理（需登录 + session:manage）
// ---------------------------------------------------------------
Route::get('api/platform/session/list', '\app\controller\SessionController@list')
    ->middleware(PlatformAuth::class);
Route::post('api/platform/session/revoke', '\app\controller\SessionController@revoke')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 支付：配置（需登录 + pay:manage）
// ---------------------------------------------------------------
Route::get('api/platform/pay/config', '\app\controller\PayController@config')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/pay/config', '\app\controller\PayController@saveConfig')
    ->middleware(PlatformAuth::class);
Route::post('api/platform/pay/config/test', '\app\controller\PayController@testConfig')
    ->middleware(PlatformAuth::class);
Route::get('api/platform/pay/order/list', '\app\controller\PayController@orderList')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 支付：下单（承台自身用平台登录态；应用用应用域 token）
// ---------------------------------------------------------------
Route::post('api/platform/pay/create', '\app\controller\PayController@create')
    ->middleware(PlatformOrPluginAuth::class);

// ---------------------------------------------------------------
// 支付：易支付回调（免登录，签名验真）
// ---------------------------------------------------------------
Route::get('api/platform/pay/notify', '\app\controller\PayController@notify');
Route::get('api/platform/pay/return', '\app\controller\PayController@payReturn');
Route::post('api/platform/pay/notify', '\app\controller\PayController@notify');
Route::post('api/platform/pay/return', '\app\controller\PayController@payReturn');

// ---------------------------------------------------------------
// 域名库（需登录 + domain:manage）
// 承台只记录「域名 → 应用」的归属；域名生效靠宝塔把域名加到承台站点
// ---------------------------------------------------------------
// 按访问域名解析应用入口：免登录（分享出去的域名没有承台登录态）
Route::get('api/platform/domain/resolve', '\app\controller\DomainController@resolve');
Route::get('api/platform/domain/list', '\app\controller\DomainController@list')
    ->middleware(PlatformAuth::class);
Route::post('api/platform/domain/create', '\app\controller\DomainController@create')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/domain/update', '\app\controller\DomainController@update')
    ->middleware(PlatformAuth::class);
Route::delete('api/platform/domain/delete', '\app\controller\DomainController@delete')
    ->middleware(PlatformAuth::class);
Route::put('api/platform/domain/assign', '\app\controller\DomainController@assign')
    ->middleware(PlatformAuth::class);
// 显式检测域名是否已生效（列表接口只读，探测与回写都在这里）
Route::post('api/platform/domain/check', '\app\controller\DomainController@check')
    ->middleware(PlatformAuth::class);

// ---------------------------------------------------------------
// 系统自检（需登录 + admin:manage）
// 把部署时必须满足的条件（PHP 扩展、proc_open、上传上限、目录权限……）做成可查清单
// ---------------------------------------------------------------
Route::get('api/platform/system/env', '\app\controller\SystemController@env')
    ->middleware(PlatformAuth::class);
