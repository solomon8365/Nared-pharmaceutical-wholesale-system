-- Sales Representative Features Migration
-- Territories, Visits, Commissions, enhanced salespeople, customer_orders link

-- 1. Territories table
CREATE TABLE IF NOT EXISTS territories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    region VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Enhance salespeople table
ALTER TABLE salespeople
    ADD COLUMN territory_id INT UNSIGNED NULL AFTER email,
    ADD COLUMN commission_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER territory_id,
    ADD COLUMN new_customer_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER commission_rate,
    ADD COLUMN monthly_target INT NOT NULL DEFAULT 0 AFTER new_customer_bonus,
    ADD COLUMN monthly_target_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_target;

-- Add FK for territory (safe to run even if column exists)
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'salespeople' AND CONSTRAINT_NAME = 'fk_salespeople_territory');
SET @sql = IF(@exists = 0, 'ALTER TABLE salespeople ADD CONSTRAINT fk_salespeople_territory FOREIGN KEY (territory_id) REFERENCES territories(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Visits table
CREATE TABLE IF NOT EXISTS visits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salesperson_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    customer_name VARCHAR(200) NULL,
    visit_date DATE NOT NULL,
    visit_type ENUM('initial','follow_up','closing','delivery') NOT NULL DEFAULT 'initial',
    notes TEXT NULL,
    location VARCHAR(255) NULL,
    follow_up_date DATE NULL,
    status ENUM('completed','pending_follow_up','cancelled') NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (salesperson_id) REFERENCES salespeople(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Commissions table (new comprehensive tracking)
CREATE TABLE IF NOT EXISTS commissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    salesperson_id INT UNSIGNED NOT NULL,
    commission_type ENUM('sale','new_customer','monthly_target') NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT UNSIGNED NULL,
    sale_amount DECIMAL(12,2) NULL,
    commission_rate DECIMAL(5,2) NULL,
    commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    paid_by INT UNSIGNED NULL,
    paid_at DATETIME NULL,
    rejection_reason TEXT NULL,
    commission_month INT NULL,
    commission_year INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (salesperson_id) REFERENCES salespeople(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Link customers to salespeople who registered them
SET @rbExists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'registered_by');
SET @sqlRB = IF(@rbExists = 0, 'ALTER TABLE customers ADD COLUMN registered_by INT UNSIGNED NULL AFTER created_at', 'SELECT 1');
PREPARE stmtRB FROM @sqlRB;
EXECUTE stmtRB;
DEALLOCATE PREPARE stmtRB;

SET @rbFkExists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND CONSTRAINT_NAME = 'fk_customers_registered_by');
SET @sqlRB2 = IF(@rbFkExists = 0, 'ALTER TABLE customers ADD CONSTRAINT fk_customers_registered_by FOREIGN KEY (registered_by) REFERENCES salespeople(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmtRB2 FROM @sqlRB2;
EXECUTE stmtRB2;
DEALLOCATE PREPARE stmtRB2;

-- 6. Link customer_orders to salespeople
SET @colExists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_orders' AND COLUMN_NAME = 'salesperson_id');
SET @sql2 = IF(@colExists = 0, 'ALTER TABLE customer_orders ADD COLUMN salesperson_id INT UNSIGNED NULL AFTER placed_by_user_id', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SET @fkExists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_orders' AND CONSTRAINT_NAME = 'fk_co_salesperson');
SET @sql3 = IF(@fkExists = 0, 'ALTER TABLE customer_orders ADD CONSTRAINT fk_co_salesperson FOREIGN KEY (salesperson_id) REFERENCES salespeople(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- 7. Seed default territories
INSERT IGNORE INTO territories (id, name, description, region) VALUES
(1, 'Addis Ababa Central', 'Central business district area', 'Addis Ababa'),
(2, 'Addis Ababa North', 'Northern districts', 'Addis Ababa'),
(3, 'Addis Ababa South', 'Southern districts', 'Addis Ababa'),
(4, 'Oromia Region', 'Oromia coverage area', 'Oromia'),
(5, 'Amhara Region', 'Amhara coverage area', 'Amhara');
