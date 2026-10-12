-- =============================================================
-- 承台应用管理平台 · 数据库结构（MySQL 5.7+）
-- 库名：authpro_platform
-- =============================================================

-- 平台管理员
CREATE TABLE IF NOT EXISTS `platform_admin` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(64)  NOT NULL,
  `password_hash`   VARCHAR(255) NOT NULL,
  `nickname`        VARCHAR(64)  NOT NULL DEFAULT '',
  `email`           VARCHAR(128) NOT NULL DEFAULT '' COMMENT '管理员邮箱（安装时填写，用于找回密码/通知）',
  `status`          TINYINT      NOT NULL DEFAULT 1 COMMENT '1 启用 0 禁用',
  `is_super`        TINYINT      NOT NULL DEFAULT 0 COMMENT '1 超级管理员（拥有全部权限）',
  `must_change_pwd` TINYINT      NOT NULL DEFAULT 0 COMMENT '1 登录后需改密',
  `last_login_at`   DATETIME     DEFAULT NULL,
  `last_login_ip`   VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 平台会话（登录态，支持强制下线）
CREATE TABLE IF NOT EXISTS `platform_session` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `token_id`   VARCHAR(64)  NOT NULL,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `expire_at`  DATETIME     NOT NULL,
  `revoked_at` DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token_id`),
  KEY `idx_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 应用注册表（承台应用唯一数据源：启用状态 + 排序 + 上传应用的元数据）
CREATE TABLE IF NOT EXISTS `plugin_registry` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plugin_id`     VARCHAR(64)  NOT NULL,
  `name`          VARCHAR(64)  NOT NULL DEFAULT '',
  `version`       VARCHAR(32)  NOT NULL DEFAULT '',
  `enabled`       TINYINT      NOT NULL DEFAULT 0,
  `installed`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1 已安装（可启用）| 0 已上传未安装',
  `installed_at`  DATETIME     DEFAULT NULL COMMENT '安装完成时间',
  `sort`          INT          NOT NULL DEFAULT 100,
  `source`        VARCHAR(16)  NOT NULL DEFAULT 'builtin' COMMENT 'builtin 随承台源码内置 | uploaded 上传安装',
  `install_path`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '上传应用落地目录名（public/apps/<install_path>）',
  `db_prefix`     VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '应用在承台库中的表前缀，约定为 <应用id下划线化>_',
  `manifest_json` TEXT         DEFAULT NULL COMMENT '上传应用的 manifest 原件（内置应用以源码树为准）',
  `config`        JSON         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL,
  `updated_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugin` (`plugin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 登录尝试记录（防爆破限流）
CREATE TABLE IF NOT EXISTS `platform_login_attempt` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
  `username`   VARCHAR(64)  NOT NULL DEFAULT '',
  `success`    TINYINT      NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================
-- P1：角色权限 / 审计 / 会话管理（2026-09-19 新增）
-- =============================================================

-- 平台角色
CREATE TABLE IF NOT EXISTS `platform_role` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(64)  NOT NULL DEFAULT '',
  `code`       VARCHAR(64)  NOT NULL DEFAULT '',
  `remark`     VARCHAR(255) NOT NULL DEFAULT '',
  `status`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1 启用 0 禁用',
  `created_at` DATETIME     NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 平台管理员-角色（多对多）
CREATE TABLE IF NOT EXISTS `platform_admin_role` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL,
  `role_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_role` (`admin_id`, `role_id`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 角色-权限点（permission 为权限点字符串，见 PermissionService::PERMISSIONS）
CREATE TABLE IF NOT EXISTS `platform_role_permission` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`    INT UNSIGNED NOT NULL,
  `permission` VARCHAR(64)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_perm` (`role_id`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 权限点清单（承台是唯一的权限来源：平台内置权限点 + 应用声明的业务权限点）
CREATE TABLE IF NOT EXISTS `platform_permission` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(64)  NOT NULL COMMENT '权限点标识；应用权限点为 <应用id>:<应用内code>',
  `name`       VARCHAR(64)  NOT NULL DEFAULT '',
  `plugin_id`  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '空=平台内置；非空=该应用声明的业务权限点',
  `sort`       INT          NOT NULL DEFAULT 100,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_perm_code` (`code`),
  KEY `idx_perm_plugin` (`plugin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 平台内置权限点（应用权限点由安装应用时自动注册，不要写在这里）
INSERT INTO `platform_permission` (`code`, `name`, `plugin_id`, `sort`, `created_at`) VALUES
  ('plugin:manage',  '应用管理',     '', 10, NOW()),
  ('role:manage',    '角色管理',     '', 20, NOW()),
  ('admin:manage',   '管理员管理',   '', 30, NOW()),
  ('audit:view',     '审计日志查看', '', 40, NOW()),
  ('session:manage', '会话管理',     '', 50, NOW()),
  ('pay:manage',     '支付配置',     '', 60, NOW()),
  ('domain:manage',  '域名库管理',   '', 70, NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort` = VALUES(`sort`);

-- 域名库：登记域名并分配给应用。域名生效靠宝塔把域名加到承台站点（承台只记录归属、提供对外链接与生效检测）
CREATE TABLE IF NOT EXISTS `platform_domain` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `domain`      VARCHAR(191) NOT NULL COMMENT '站点域名=主机名（不含协议与路径）；对象存储域名=托管落地页的完整 URL',
  `domain_type` TINYINT      NOT NULL DEFAULT 0 COMMENT '0=站点域名（指向承台站点，可分配给应用）| 5=对象存储域名（落地页托管在对象存储，不参与分配与探测）',
  `plugin_id`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '绑定的应用 id；空=尚未分配给应用',
  `is_platform` TINYINT      NOT NULL DEFAULT 0 COMMENT '1=承台自身正在使用的域名（系统内置，不可删除）',
  `enabled`     TINYINT      NOT NULL DEFAULT 1 COMMENT '1 启用（参与分享链接与入口分流）| 0 停用',
  `remark`      VARCHAR(128) NOT NULL DEFAULT '',
  `apply_state` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '生效检测结果：ok=该域名已指向承台站点，否则为未生效原因',
  `applied_at`  DATETIME     DEFAULT NULL COMMENT '最近一次检测时间',
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_domain_plugin` (`plugin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 平台审计日志
CREATE TABLE IF NOT EXISTS `platform_audit_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `admin_name`  VARCHAR(64)  NOT NULL DEFAULT '',
  `action`      VARCHAR(64)  NOT NULL DEFAULT '',
  `target_type` VARCHAR(64)  NOT NULL DEFAULT '',
  `target_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `before_json` TEXT         DEFAULT NULL,
  `after_json`  TEXT         DEFAULT NULL,
  `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_time` (`admin_id`, `created_at`),
  KEY `idx_action_time` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================
-- P0：应用免登 ticket 握手（2026-09-19 新增）
-- =============================================================

-- 应用一次性登录票据：iframe 型应用凭 ticket 兑换平台身份（一次性、短有效期）
CREATE TABLE IF NOT EXISTS `platform_plugin_ticket` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  VARCHAR(64)  NOT NULL COMMENT 'ticket 的 jti，唯一',
  `plugin_id`  VARCHAR(64)  NOT NULL COMMENT '仅允许该应用兑换',
  `admin_id`   INT UNSIGNED NOT NULL,
  `expire_at`  DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL COMMENT '非空表示已兑换（一次性）',
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ticket` (`ticket_id`),
  KEY `idx_plugin_time` (`plugin_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 旧库升级：为已存在的 platform_admin 表补充 is_super 字段（重复执行无副作用需手动确认，MySQL 8.0+ 可用 ADD COLUMN IF NOT EXISTS）
-- ALTER TABLE `platform_admin` ADD COLUMN `is_super` TINYINT NOT NULL DEFAULT 0 COMMENT '1 超级管理员（拥有全部权限）';

-- =============================================================
-- 应用上传安装（2026-09-19 新增）：plugin_registry 扩展
-- =============================================================
-- 旧库需执行以下三条迁移（新库由上面的 CREATE 直接建好）：
-- ALTER TABLE `plugin_registry` ADD COLUMN `source` VARCHAR(16) NOT NULL DEFAULT 'builtin' COMMENT 'builtin 随承台源码内置 | uploaded 上传安装';
-- ALTER TABLE `plugin_registry` ADD COLUMN `install_path` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '上传应用落地目录名（public/apps/<install_path>）';
-- ALTER TABLE `plugin_registry` ADD COLUMN `manifest_json` TEXT DEFAULT NULL COMMENT '上传应用的 manifest 原件（内置应用以源码树为准）';

-- =============================================================
-- 应用安装钩子与安装状态（2026-09-19 新增）：plugin_registry 再扩展
-- =============================================================
-- 旧库需执行以下两条迁移（新库由上面的 CREATE 直接建好）：
-- ALTER TABLE `plugin_registry` ADD COLUMN `installed` TINYINT NOT NULL DEFAULT 1 COMMENT '1 已安装（可启用）| 0 已上传未安装';
-- ALTER TABLE `plugin_registry` ADD COLUMN `installed_at` DATETIME DEFAULT NULL COMMENT '安装完成时间';

-- 应用表前缀（与承台同库，靠表名前缀隔离，避免同类应用表名撞车）
-- ALTER TABLE `plugin_registry` ADD COLUMN `db_prefix` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '应用在承台库中的表前缀，约定为 <应用id下划线化>_';

-- =============================================================
-- 支付（2026-09-20 新增）
-- 承台统一收单：应用不接触商户密钥，也不直接对接易支付。
-- 应用调承台下单接口 → 承台签名并跳转易支付 → 易支付异步回调承台 →
-- 承台验签 + 幂等落库 → 承台通知应用 → 应用开通业务。
-- =============================================================

-- 支付配置（单例，id 恒为 1）
CREATE TABLE IF NOT EXISTS `platform_pay_config` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `enabled`             TINYINT      NOT NULL DEFAULT 0 COMMENT '1 启用支付 0 停用',
  `gateway`             VARCHAR(32)  NOT NULL DEFAULT 'epay' COMMENT '网关类型，当前仅 epay（易支付）',
  `sign_type`           VARCHAR(16)  NOT NULL DEFAULT 'RSA' COMMENT 'RSA=RSA-SHA256（商户私钥签名/平台公钥验签）| MD5=传统彩虹易支付',
  `apiurl`              VARCHAR(255) NOT NULL DEFAULT '' COMMENT '易支付网关地址，需自带结尾斜杠，如 https://pay.example.com/',
  `pid`                 VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '易支付商户ID',
  `platform_public_key` TEXT         DEFAULT NULL COMMENT 'sign_type=RSA 时的平台公钥（验签用）',
  `merchant_private_key` TEXT        DEFAULT NULL COMMENT 'sign_type=RSA 时的商户私钥（签名用）',
  `key`                 VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'sign_type=MD5 时的商户密钥',
  `default_type`        VARCHAR(16)  NOT NULL DEFAULT 'wxpay' COMMENT '默认支付方式：wxpay / alipay',
  `notify_url`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT '异步回调地址；留空则按当前域名自动推导',
  `return_url`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT '同步跳转地址；留空则按当前域名自动推导',
  `updated_at`          DATETIME     NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 承台统一支付订单（应用下单都落这里；应用侧订单通过 plugin_order_no 关联）
CREATE TABLE IF NOT EXISTS `platform_pay_order` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `out_trade_no`     VARCHAR(64)  NOT NULL COMMENT '承台订单号（提交给易支付）',
  `plugin_id`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '发起下单的应用 id；空=承台自身',
  `plugin_order_no`  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '应用侧订单号，回调时原样带回',
  `subject`          VARCHAR(128) NOT NULL DEFAULT '' COMMENT '商品名称',
  `money`            DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '金额（元），以下单时的服务端金额为准',
  `type`             VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '支付方式 wxpay / alipay',
  `status`           VARCHAR(16)  NOT NULL DEFAULT 'pending' COMMENT 'pending 待支付 | success 已支付 | closed 已关闭',
  `trade_no`         VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '易支付平台交易号',
  `buyer`            VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '下单人（承台管理员用户名）',
  `admin_id`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下单的承台管理员 id',
  `ip`               VARCHAR(45)  NOT NULL DEFAULT '',
  `notify_count`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '收到回调次数（幂等护栏：仅首次业务生效）',
  `paid_at`          DATETIME     DEFAULT NULL COMMENT '支付成功时间',
  `notified_at`      DATETIME     DEFAULT NULL COMMENT '通知应用成功时间',
  `notify_result`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '通知应用的返回摘要',
  `raw_notify`       TEXT         DEFAULT NULL COMMENT '回调原始参数（排障用）',
  `created_at`       DATETIME     NOT NULL,
  `updated_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_out_trade_no` (`out_trade_no`),
  KEY `idx_plugin_order` (`plugin_id`, `plugin_order_no`),
  KEY `idx_status_time` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
