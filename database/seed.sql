-- =============================================================
-- 统一管理平台 · 种子数据
-- 默认账号：admin / admin123（首次登录后请修改密码）
-- =============================================================

-- 平台管理员（密码 admin123 的 bcrypt 哈希；is_super=1 超级管理员拥有全部权限）
INSERT INTO `platform_admin` (`username`, `password_hash`, `nickname`, `status`, `is_super`, `must_change_pwd`, `created_at`, `updated_at`)
VALUES ('admin', '$2y$10$eoxkwO/NSPV9t7cSX5XYC.jwmXM4nqrFMeid5dXNN3oY91J5ECNwS', '平台管理员', 1, 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `is_super` = 1, `updated_at` = NOW();

-- 演示角色（可选绑定：将角色分配给某个非超管管理员后即可测试受限权限）
INSERT INTO `platform_role` (`name`, `code`, `remark`, `status`, `created_at`, `updated_at`)
VALUES ('运营专员', 'operator', '演示角色：仅可查看审计日志与会话', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `updated_at` = NOW();
