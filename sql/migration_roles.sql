-- =============================================================
-- Migration: Dynamic Role & Permission Management System
-- =============================================================

-- 1. Create roles table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `is_system` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create role_permissions table
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT NOT NULL,
    `module` VARCHAR(100) NOT NULL,
    `permission` VARCHAR(50) NOT NULL,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_role_module_perm` (`role_id`, `module`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add role_id column to users (nullable for backward compat)
ALTER TABLE `users`
    ADD COLUMN `role_id` INT DEFAULT NULL AFTER `role`,
    ADD KEY `fk_users_role` (`role_id`);

-- =============================================================
-- Seed default roles (is_system=1 means cannot be deleted)
-- =============================================================

INSERT INTO `roles` (`id`, `name`, `description`, `is_system`) VALUES
(1, 'Admin', 'Full system access to all modules and features', 1),
(2, 'Customer', 'Can browse catalog, place orders, and view own orders/invoices', 1),
(3, 'Delivery Staff', 'Can view assigned deliveries and update delivery status', 1),
(4, 'Finance Officer', 'Can confirm payments, process refunds, and view financial reports', 1),
(6, 'Salesperson', 'Can create and manage sales orders, record sales, and view own sales activity', 0)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- =============================================================
-- Seed all permissions for Admin role (id=1)
-- =============================================================
INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 1, m.module, p.permission
FROM (
    SELECT 'dashboard' AS module UNION SELECT 'products' UNION SELECT 'categories' UNION SELECT 'suppliers'
    UNION SELECT 'purchase_orders' UNION SELECT 'customers' UNION SELECT 'sales' UNION SELECT 'reports'
    UNION SELECT 'stock_movements' UNION SELECT 'price_history' UNION SELECT 'supplier_payments'
    UNION SELECT 'customer_order_queue' UNION SELECT 'expiry_alerts' UNION SELECT 'delivery_staff'
    UNION SELECT 'commissions' UNION SELECT 'finance_dashboard' UNION SELECT 'finance_payments'
    UNION SELECT 'finance_refunds' UNION SELECT 'finance_reports' UNION SELECT 'delivery_dashboard'
    UNION SELECT 'roles' UNION SELECT 'stock_adjustments' UNION SELECT 'stock_transfers' UNION SELECT 'physical_count' UNION SELECT 'expenses' UNION SELECT 'income' UNION SELECT 'credit_management' UNION SELECT 'settings' UNION SELECT 'warehouse_picking' UNION SELECT 'goods_receiving'
    UNION SELECT 'stock' UNION SELECT 'batch_expiry' UNION SELECT 'purchase_returns' UNION SELECT 'sales_returns' UNION SELECT 'salespeople' UNION SELECT 'assign_deliveries' UNION SELECT 'delivery_tracking' UNION SELECT 'delivery_confirmation' UNION SELECT 'users' UNION SELECT 'backup'
) m
CROSS JOIN (
    SELECT 'view' AS permission UNION SELECT 'create' UNION SELECT 'edit' UNION SELECT 'delete'
    UNION SELECT 'approve' UNION SELECT 'export' UNION SELECT 'confirm' UNION SELECT 'process'
    UNION SELECT 'pay' UNION SELECT 'assign_delivery' UNION SELECT 'cancel_refund'
    UNION SELECT 'unconfirm'
) p
WHERE NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 1 AND rp.`module` = m.module AND rp.`permission` = p.permission
);

-- =============================================================
-- Seed permissions for Customer role (id=2)
-- =============================================================
INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 2, m.module, p.permission
FROM (
    SELECT 'catalog' AS module UNION SELECT 'place_order' UNION SELECT 'my_orders' UNION SELECT 'invoice' UNION SELECT 'dashboard'
) m
CROSS JOIN (
    SELECT 'view' AS permission UNION SELECT 'create'
) p
WHERE NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 2 AND rp.`module` = m.module AND rp.`permission` = p.permission
);

-- =============================================================
-- Seed permissions for Delivery Staff role (id=3)
-- =============================================================
INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'delivery_dashboard', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'delivery_dashboard' AND rp.`permission` = 'view');

INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 3, 'invoice', 'view'
WHERE NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 3 AND rp.`module` = 'invoice' AND rp.`permission` = 'view');

-- =============================================================
-- Seed permissions for Finance Officer role (id=4)
-- =============================================================
INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 4, m.module, p.permission
FROM (
    SELECT 'finance_dashboard' AS module UNION SELECT 'finance_payments' UNION SELECT 'finance_refunds'     UNION SELECT 'finance_reports' UNION SELECT 'expenses' UNION SELECT 'income' UNION SELECT 'credit_management'
) m
CROSS JOIN (
    SELECT 'view' AS permission UNION SELECT 'create' UNION SELECT 'delete' UNION SELECT 'confirm' UNION SELECT 'unconfirm' UNION SELECT 'process' UNION SELECT 'export'
) p
WHERE NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 4 AND rp.`module` = m.module AND rp.`permission` = p.permission
);

-- =============================================================
-- Seed permissions for Salesperson role (id=6)
-- =============================================================
INSERT INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 6, m.module, p.permission
FROM (
    SELECT 'sales' AS module UNION SELECT 'customers' UNION SELECT 'products'
) m
CROSS JOIN (
    SELECT 'view' AS permission UNION SELECT 'create'
) p
WHERE NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 6 AND rp.`module` = m.module AND rp.`permission` = p.permission
);

-- =============================================================
-- Migrate existing users: assign role_id based on their role enum
-- =============================================================
UPDATE `users` SET `role_id` = 1 WHERE `role` = 'admin' AND (`role_id` IS NULL OR `role_id` = 0);
UPDATE `users` SET `role_id` = 2 WHERE `role` = 'customer' AND (`role_id` IS NULL OR `role_id` = 0);
UPDATE `users` SET `role_id` = 3 WHERE `role` = 'delivery' AND (`role_id` IS NULL OR `role_id` = 0);
UPDATE `users` SET `role_id` = 4 WHERE `role` = 'finance' AND (`role_id` IS NULL OR `role_id` = 0);
