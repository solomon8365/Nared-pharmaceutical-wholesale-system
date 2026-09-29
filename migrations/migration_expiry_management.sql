-- ============================================================
-- Expiry Management Module — Database Migration
-- Run this once to add expiry management tables and columns
-- ============================================================

-- 1. Add status tracking columns to product_batches
SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='status');
SET @s=IF(@e=0,
  "ALTER TABLE product_batches ADD COLUMN status ENUM('active','expired','quarantined','disposed') NOT NULL DEFAULT 'active' AFTER quantity",
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='quarantined_at');
SET @s=IF(@e=0,
  'ALTER TABLE product_batches ADD COLUMN quarantined_at DATETIME NULL AFTER status',
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='disposed_at');
SET @s=IF(@e=0,
  'ALTER TABLE product_batches ADD COLUMN disposed_at DATETIME NULL AFTER quarantined_at',
  'SELECT 1');
PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;

-- 2. Create product_disposals audit table
CREATE TABLE IF NOT EXISTS product_disposals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    disposal_reason VARCHAR(200) NOT NULL,
    disposal_date DATE NOT NULL,
    quantity INT NOT NULL,
    batch_number VARCHAR(100) NOT NULL,
    approved_by INT UNSIGNED NULL,
    remarks TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Auto-mark all currently expired batches
UPDATE product_batches SET status = 'expired' WHERE status = 'active' AND expiry_date < CURDATE() AND quantity > 0;

-- 4. Add expiry_management permissions for admin role (role_id=1) and finance officer (role_id=4)
-- Admin gets all permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 1, 'expiry_management', p.permission
FROM (
    SELECT 'view' AS permission UNION SELECT 'create' UNION SELECT 'edit' UNION SELECT 'delete'
) p
WHERE NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 1 AND rp.`module` = 'expiry_management' AND rp.`permission` = p.permission
);

-- Finance Officer gets view only
INSERT IGNORE INTO `role_permissions` (`role_id`, `module`, `permission`)
SELECT 4, 'expiry_management', 'view'
WHERE NOT EXISTS (
    SELECT 1 FROM `role_permissions` rp WHERE rp.`role_id` = 4 AND rp.`module` = 'expiry_management' AND rp.`permission` = 'view'
);
