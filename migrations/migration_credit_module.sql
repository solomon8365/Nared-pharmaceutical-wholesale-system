-- Credit Management Module Migration
-- Safe to run multiple times (IF NOT EXISTS / ALTER IGNORE)

-- 1. Add credit control settings
CREATE TABLE IF NOT EXISTS credit_control_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO credit_control_settings (setting_key, setting_value) VALUES
('allow_credit_sales', '1'),
('require_credit_approval', '1'),
('max_credit_limit', '500000'),
('default_credit_days', '30'),
('grace_period_days', '7'),
('penalty_percentage', '2'),
('interest_percentage', '1.5'),
('auto_suspend_on_overdue', '0'),
('block_new_orders_on_overdue', '0'),
('allow_override', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 2. Add overdue_date to invoices (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'overdue_date');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE invoices ADD COLUMN overdue_date DATE NULL AFTER credit_due_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add overdue_at to invoices
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'days_overdue');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE invoices ADD COLUMN days_overdue INT DEFAULT 0 AFTER overdue_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Add sales_type to customer_orders if missing
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customer_orders' AND COLUMN_NAME = 'sales_type');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customer_orders ADD COLUMN sales_type ENUM("cash","credit") DEFAULT "cash" AFTER payment_method', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Add credit columns to customer_orders if missing
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customer_orders' AND COLUMN_NAME = 'credit_check_passed');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customer_orders ADD COLUMN credit_check_passed TINYINT(1) DEFAULT 0 AFTER sales_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Add credit_approval_status to customers if missing
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'credit_approval_status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN credit_approval_status ENUM("pending","approved","suspended","rejected") DEFAULT "pending" AFTER is_credit_approved', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add last_payment_date and last_payment_amount to customers
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'last_payment_date');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN last_payment_date DATETIME NULL AFTER credit_approval_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'last_payment_amount');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN last_payment_amount DECIMAL(12,2) DEFAULT 0 AFTER last_payment_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Add credit_score to customers
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'credit_score');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN credit_score INT DEFAULT 500 AFTER last_payment_amount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. Credit audit log table
CREATE TABLE IF NOT EXISTS credit_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Credit notifications table
CREATE TABLE IF NOT EXISTS credit_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_unread (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. Ensure credit_limit has proper default and credit_used syncs
UPDATE customers c SET credit_used = COALESCE(
    (SELECT SUM(i.outstanding_balance) FROM invoices i WHERE i.customer_id = c.id AND i.payment_status IN ('unpaid','partial')),
    0
);

-- 11b. Late-charge tracking columns on invoices (grace/penalty/interest module)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'penalty_amount');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE invoices ADD COLUMN penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER outstanding_balance', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'penalty_applied_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE invoices ADD COLUMN penalty_applied_at DATE NULL AFTER penalty_amount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'interest_last_applied_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE invoices ADD COLUMN interest_last_applied_at DATE NULL AFTER penalty_applied_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'auto_suspended_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN auto_suspended_at DATETIME NULL AFTER credit_approval_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 11c. Credit approval audit columns on customers
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'credit_approved_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN credit_approved_by INT UNSIGNED NULL AFTER credit_approval_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'pharma' AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'credit_approved_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN credit_approved_at DATETIME NULL AFTER credit_approved_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 12. Sync overdue status on existing invoices
UPDATE invoices SET days_overdue = DATEDIFF(CURDATE(), credit_due_date), overdue_date = credit_due_date
WHERE credit_due_date IS NOT NULL AND credit_due_date < CURDATE() AND payment_status IN ('unpaid','partial');
