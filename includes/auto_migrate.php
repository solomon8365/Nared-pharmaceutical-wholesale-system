<?php
/**
 * Auto-run pending migrations for Enhanced Invoice features.
 */
function ensureInvoiceFeatureMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        // invoices: payment_status
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='payment_status');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN payment_status ENUM(\\'paid\\',\\'partial\\',\\'unpaid\\') NOT NULL DEFAULT \\'unpaid\\' AFTER total_amount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // invoices: amount_paid
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='amount_paid');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER payment_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // invoices: credit_due_date
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='credit_due_date');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN credit_due_date DATE NULL AFTER amount_paid','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // invoices: outstanding_balance
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='outstanding_balance');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN outstanding_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER credit_due_date','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // invoices: digital_signature
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='digital_signature');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN digital_signature TEXT NULL AFTER outstanding_balance','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // invoices: return_reference_no
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='return_reference_no');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN return_reference_no VARCHAR(100) NULL AFTER digital_signature','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // customer_order_items: batch_no
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_order_items' AND COLUMN_NAME='batch_no');
         SET @s=IF(@e=0,'ALTER TABLE customer_order_items ADD COLUMN batch_no VARCHAR(100) NULL AFTER unit_price','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // customer_order_items: expiry_date
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_order_items' AND COLUMN_NAME='expiry_date');
         SET @s=IF(@e=0,'ALTER TABLE customer_order_items ADD COLUMN expiry_date DATE NULL AFTER batch_no','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Auto-run pending migrations for Sales Rep and OOS features.
 * Safe to call multiple times — uses IF NOT EXISTS / safe column checks.
 */
function ensureSalesRepMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        // Territories table
        "CREATE TABLE IF NOT EXISTS territories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            region VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Salespeople columns
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND COLUMN_NAME='territory_id');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD COLUMN territory_id INT UNSIGNED NULL','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND COLUMN_NAME='commission_rate');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD COLUMN commission_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND COLUMN_NAME='new_customer_bonus');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD COLUMN new_customer_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND COLUMN_NAME='monthly_target');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD COLUMN monthly_target INT NOT NULL DEFAULT 0','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND COLUMN_NAME='monthly_target_bonus');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD COLUMN monthly_target_bonus DECIMAL(10,2) NOT NULL DEFAULT 0.00','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",

        // FK for territory
        "SET @e=(SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND CONSTRAINT_NAME='fk_salespeople_territory');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD CONSTRAINT fk_salespeople_territory FOREIGN KEY (territory_id) REFERENCES territories(id) ON DELETE SET NULL','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",

        // Visits table — create if missing, add columns safely
        "CREATE TABLE IF NOT EXISTS visits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            salesperson_id INT UNSIGNED NOT NULL,
            visit_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='customer_id');
         SET @s=IF(@e=0,'ALTER TABLE visits ADD COLUMN customer_id INT UNSIGNED NULL AFTER salesperson_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='customer_name');
         SET @s=IF(@e=0,'ALTER TABLE visits ADD COLUMN customer_name VARCHAR(200) NULL AFTER customer_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='visit_type');
         SET @s=IF(@e=0,'ALTER TABLE visits ADD COLUMN visit_type VARCHAR(20) NOT NULL DEFAULT \'initial\' AFTER visit_date','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='notes');
         SET @s=IF(@e=0,'ALTER TABLE visits ADD COLUMN notes TEXT NULL AFTER visit_type','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='location');
         SET @s=IF(@e=0,'ALTER TABLE visits ADD COLUMN location VARCHAR(255) NULL AFTER notes','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='follow_up_date');
         SET @s=IF(@e=0,'ALTER TABLE visits ADD COLUMN follow_up_date DATE NULL AFTER location','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='visits' AND COLUMN_NAME='status');
         SET @s=IF(@e=0,'ALTER TABLE visits ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT \'completed\' AFTER follow_up_date','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",

        // Commissions table — create if missing, then add any missing columns
        "CREATE TABLE IF NOT EXISTS commissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            salesperson_id INT UNSIGNED NOT NULL,
            commission_type VARCHAR(20) NOT NULL DEFAULT 'sale',
            commission_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Commissions: add missing columns safely
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='reference_type');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN reference_type VARCHAR(50) NULL AFTER commission_type','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='reference_id');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN reference_id INT UNSIGNED NULL AFTER reference_type','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='sale_amount');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN sale_amount DECIMAL(12,2) NULL AFTER reference_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='commission_rate');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN commission_rate DECIMAL(5,2) NULL AFTER sale_amount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='approved_by');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN approved_by INT UNSIGNED NULL AFTER status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='approved_at');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN approved_at DATETIME NULL AFTER approved_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='paid_by');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN paid_by INT UNSIGNED NULL AFTER approved_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='paid_at');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN paid_at DATETIME NULL AFTER paid_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='rejection_reason');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN rejection_reason TEXT NULL AFTER paid_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='commission_month');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN commission_month INT NULL AFTER rejection_reason','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='commission_year');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN commission_year INT NULL AFTER commission_month','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='notes');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN notes TEXT NULL AFTER commission_year','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND COLUMN_NAME='updated_at');
         SET @s=IF(@e=0,'ALTER TABLE commissions ADD COLUMN updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER notes','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",

        // Customer registered_by column
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='registered_by');
         SET @s=IF(@e=0,'ALTER TABLE customers ADD COLUMN registered_by INT UNSIGNED NULL AFTER created_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",

        // Customer orders salesperson_id column
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='salesperson_id');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN salesperson_id INT UNSIGNED NULL AFTER placed_by_user_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",

        // Out of stock queue table — create if missing, add columns safely
        "CREATE TABLE IF NOT EXISTS out_of_stock_queue (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            quantity_needed INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='customer_order_id');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN customer_order_id INT UNSIGNED NULL AFTER id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='quantity_fulfilled');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN quantity_fulfilled INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity_needed','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='purchase_order_id');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN purchase_order_id INT UNSIGNED NULL AFTER status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='salesperson_id');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN salesperson_id INT UNSIGNED NULL AFTER purchase_order_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='notified_admin');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN notified_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER salesperson_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='notified_salesperson');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN notified_salesperson TINYINT(1) NOT NULL DEFAULT 0 AFTER notified_admin','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='customer_notified');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN customer_notified TINYINT(1) NOT NULL DEFAULT 0 AFTER notified_salesperson','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='notes');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN notes TEXT NULL AFTER customer_notified','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='fulfilled_at');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN fulfilled_at DATETIME NULL AFTER notes','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='out_of_stock_queue' AND COLUMN_NAME='updated_at');
         SET @s=IF(@e=0,'ALTER TABLE out_of_stock_queue ADD COLUMN updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER fulfilled_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",

        // Seed default territories
        "INSERT IGNORE INTO territories (id, name, description, region) VALUES
         (1,'Addis Ababa Central','Central business district area','Addis Ababa'),
         (2,'Addis Ababa North','Northern districts','Addis Ababa'),
         (3,'Addis Ababa South','Southern districts','Addis Ababa'),
         (4,'Oromia Region','Oromia coverage area','Oromia'),
         (5,'Amhara Region','Amhara coverage area','Amhara')",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Auto-run pending migrations for Professional Pharmaceutical Invoice features.
 */
function ensureProfessionalInvoiceMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='reference_no');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN reference_no VARCHAR(50) NULL AFTER invoice_no','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='sales_type');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN sales_type ENUM(\\'cash\\',\\'credit\\') NOT NULL DEFAULT \\'cash\\' AFTER payment_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='warehouse_id');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER delivery_address','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='prepared_by');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN prepared_by INT UNSIGNED NULL AFTER warehouse_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approved_by');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN approved_by INT UNSIGNED NULL AFTER prepared_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_order_items' AND COLUMN_NAME='discount');
         SET @s=IF(@e=0,'ALTER TABLE customer_order_items ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_price','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_order_items' AND COLUMN_NAME='tax_amount');
         SET @s=IF(@e=0,'ALTER TABLE customer_order_items ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='customer_code');
         SET @s=IF(@e=0,'ALTER TABLE customers ADD COLUMN customer_code VARCHAR(50) NULL AFTER id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "INSERT IGNORE INTO settings (key_name, value) VALUES ('company_tin', '')",
        // invoices: customer_signature
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='customer_signature');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN customer_signature TEXT NULL AFTER digital_signature','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Auto-run pending migrations for Order & Payment Workflow.
 */
function ensureOrderWorkflowMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='transaction_ref');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN transaction_ref VARCHAR(100) NULL AFTER payment_detail','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='bank_name');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN bank_name VARCHAR(100) NULL AFTER transaction_ref','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='account_name');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN account_name VARCHAR(100) NULL AFTER bank_name','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='account_number');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN account_number VARCHAR(100) NULL AFTER account_name','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='merchant_name');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN merchant_name VARCHAR(100) NULL AFTER account_number','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='merchant_id');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN merchant_id VARCHAR(100) NULL AFTER merchant_name','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='verified_amount');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN verified_amount DECIMAL(12,2) NULL AFTER payment_notes','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='verified_by');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN verified_by INT UNSIGNED NULL AFTER verified_amount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='verified_at');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN verified_at DATETIME NULL AFTER verified_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='rejection_reason');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN rejection_reason TEXT NULL AFTER verified_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='payment_rejection_reason');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN payment_rejection_reason TEXT NULL AFTER rejection_reason','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='driver_name');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN driver_name VARCHAR(150) NULL AFTER delivery_staff_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='vehicle_info');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN vehicle_info VARCHAR(150) NULL AFTER driver_name','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='scheduled_delivery_date');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN scheduled_delivery_date DATE NULL AFTER vehicle_info','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='delivered_at');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN delivered_at DATETIME NULL AFTER delivery_confirmed_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='discount_amount');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='vat_amount');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_amount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='credit_terms_days');
         SET @s=IF(@e=0,'ALTER TABLE customers ADD COLUMN credit_terms_days INT NOT NULL DEFAULT 30 AFTER credit_used','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='is_credit_approved');
         SET @s=IF(@e=0,'ALTER TABLE customers ADD COLUMN is_credit_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_terms_days','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // customers.credit_approval_status must include 'over_limit' (enum currently lacks it)
        "SET @t=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='credit_approval_status');
         SET @s=IF(LOCATE('over_limit',@t)=0,\"ALTER TABLE customers MODIFY COLUMN credit_approval_status ENUM('pending','approved','suspended','rejected','over_limit') NOT NULL DEFAULT 'pending'\",'SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // Repair any rows that previously stored an invalid '' (enum rejected 'over_limit')
        "UPDATE customers SET credit_approval_status = 'pending' WHERE credit_approval_status = ''",
        // invoices: penalty_amount (one-time late fee applied when past grace period)
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='penalty_amount');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN penalty_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER outstanding_balance','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // invoices: penalty_applied_at
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='penalty_applied_at');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN penalty_applied_at DATE NULL AFTER penalty_amount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // invoices: interest_last_applied_at (monthly interest accrual tracker)
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='interest_last_applied_at');
         SET @s=IF(@e=0,'ALTER TABLE invoices ADD COLUMN interest_last_applied_at DATE NULL AFTER penalty_applied_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // customers: auto_suspended_at (set when auto_suspend_on_overdue suspends the account)
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='auto_suspended_at');
         SET @s=IF(@e=0,'ALTER TABLE customers ADD COLUMN auto_suspended_at DATETIME NULL AFTER credit_approval_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // customers: credit approval audit trail (who approved credit and when)
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='credit_approved_by');
         SET @s=IF(@e=0,'ALTER TABLE customers ADD COLUMN credit_approved_by INT UNSIGNED NULL AFTER credit_approval_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customers' AND COLUMN_NAME='credit_approved_at');
         SET @s=IF(@e=0,'ALTER TABLE customers ADD COLUMN credit_approved_at DATETIME NULL AFTER credit_approved_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_log' AND COLUMN_NAME='customer_order_id' AND IS_NULLABLE='NO');
         SET @s=IF(@e>0,'ALTER TABLE payment_log MODIFY COLUMN customer_order_id INT UNSIGNED NULL','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "CREATE TABLE IF NOT EXISTS payment_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_order_id INT UNSIGNED NULL,
            customer_id INT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            amount DECIMAL(12,2) NULL,
            payment_method VARCHAR(50) NULL,
            transaction_ref VARCHAR(100) NULL,
            performed_by INT UNSIGNED NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS order_notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_order_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Auto-run migration for the Order Fulfillment workflow (store manager).
 * Adds the fulfillment tracking columns on customer_orders used by the
 * Stock Check -> Ready to Prepare -> Reserved -> Preparing -> Prepared
 * (Waiting Payment) -> Paid / Credit Approved -> Waiting Customer Pickup /
 * Ready for Delivery -> Completed pipeline.
 */
function ensureFulfillmentMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='fulfillment_status');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN fulfillment_status VARCHAR(30) NULL AFTER picking_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='stock_verified_by');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN stock_verified_by INT UNSIGNED NULL AFTER fulfillment_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='stock_verified_at');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN stock_verified_at DATETIME NULL AFTER stock_verified_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='prepared_by');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN prepared_by INT UNSIGNED NULL AFTER stock_verified_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='prepared_at');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN prepared_at DATETIME NULL AFTER prepared_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='reserved_quantity');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN reserved_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER prepared_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='reserved_status');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN reserved_status VARCHAR(20) NULL AFTER reserved_quantity','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // stock_movements: record which batch (and warehouse) a movement belongs to,
        // so FEFO batch usage for fulfillment is auditable per-batch.
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME='batch_id');
         SET @s=IF(@e=0,'ALTER TABLE stock_movements ADD COLUMN batch_id INT UNSIGNED NULL AFTER reference_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_movements' AND COLUMN_NAME='warehouse_id');
         SET @s=IF(@e=0,'ALTER TABLE stock_movements ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER batch_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }

    ensureWholesaleFulfillmentMigration($pdo);
    ensureSalesFulfillmentMigration($pdo);
}

/**
 * Auto-run migration for the Fulfillment Method on direct over-the-counter
 * sales. Adds the pickup/delivery lane column on the sales table so the
 * Sales page prices pickup sales at wholesale and delivery sales at the
 * selling price, consistent with customer orders.
 */
function ensureSalesFulfillmentMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='fulfillment_method');
         SET @s=IF(@e=0,'ALTER TABLE sales ADD COLUMN fulfillment_method VARCHAR(20) NULL AFTER sale_date','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='payment_method');
         SET @s=IF(@e=0,'ALTER TABLE sales ADD COLUMN payment_method VARCHAR(50) NULL AFTER total_amount','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='payment_detail');
         SET @s=IF(@e=0,'ALTER TABLE sales ADD COLUMN payment_detail VARCHAR(255) NULL AFTER payment_method','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='payment_confirmed');
         SET @s=IF(@e=0,'ALTER TABLE sales ADD COLUMN payment_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_detail','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Auto-run migration for the Wholesale Order Fulfillment workflow
 * (Phase 6). Adds the pickup-vs-delivery lane columns on customer_orders:
 * fulfillment_method (pickup / delivery), prepared_quantity, and the pickup
 * hand-over tracking fields. Runs on top of ensureFulfillmentMigration.
 */
function ensureWholesaleFulfillmentMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='fulfillment_method');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN fulfillment_method VARCHAR(20) NULL AFTER fulfillment_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='prepared_quantity');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN prepared_quantity INT UNSIGNED NULL AFTER reserved_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='pickup_time');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN pickup_time DATETIME NULL AFTER prepared_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='pickup_by');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN pickup_by INT UNSIGNED NULL AFTER pickup_time','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='received_by');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN received_by VARCHAR(100) NULL AFTER pickup_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='pickup_signature');
         SET @s=IF(@e=0,'ALTER TABLE customer_orders ADD COLUMN pickup_signature VARCHAR(255) NULL AFTER received_by','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

function ensureMessagesMigration($pdo)
{    static $done = false;
    if ($done) return;
    $done = true;

    $sql = "CREATE TABLE IF NOT EXISTS messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_id INT UNSIGNED NOT NULL,
        receiver_id INT UNSIGNED NULL,
        subject VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        replied_by INT UNSIGNED NULL,
        reply TEXT NULL,
        replied_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
}

function ensureExpiryManagementMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [];

    $sqls[] = "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='status');
    SET @s=IF(@e=0,'ALTER TABLE product_batches ADD COLUMN status ENUM(\'active\',\'expired\',\'quarantined\',\'disposed\') NOT NULL DEFAULT \'active\' AFTER quantity','SELECT 1');
    PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p";

    $sqls[] = "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='quarantined_at');
    SET @s=IF(@e=0,'ALTER TABLE product_batches ADD COLUMN quarantined_at DATETIME NULL AFTER status','SELECT 1');
    PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p";

    $sqls[] = "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='disposed_at');
    SET @s=IF(@e=0,'ALTER TABLE product_batches ADD COLUMN disposed_at DATETIME NULL AFTER quarantined_at','SELECT 1');
    PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p";

    $sqls[] = "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='expiry_date');
    SET @s=IF(@e=0,'SELECT 1','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p";

    $sqls[] = "SET @e=(SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='expiry_date');
    SET @s=IF(@e='NO','ALTER TABLE product_batches MODIFY COLUMN expiry_date DATE NULL','SELECT 1');
    PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p";

    $sqls[] = "CREATE TABLE IF NOT EXISTS product_disposals (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }

    // Auto-mark expired batches
    try {
        $pdo->exec("UPDATE product_batches SET status = 'expired' WHERE status = 'active' AND expiry_date < CURDATE() AND quantity > 0");
    } catch (Exception $e) { /* ignore */ }

    // Keep products.stock_quantity in sync with available (active) batches only.
    // Products with no batch rows keep their manual stock_quantity.
    try {
        $pdo->exec("
            UPDATE products p
            LEFT JOIN (
                SELECT product_id, COALESCE(SUM(quantity), 0) AS batch_qty
                FROM product_batches
                WHERE status = 'active'
                GROUP BY product_id
            ) b ON b.product_id = p.id
            SET p.stock_quantity = COALESCE(b.batch_qty, p.stock_quantity)
            WHERE EXISTS (SELECT 1 FROM product_batches pb WHERE pb.product_id = p.id)
        ");
    } catch (Exception $e) { /* ignore */ }
}

/**
 * Auto-run migration for the Inventory workflow (store operations).
 * Adds batch workflow tracking columns used by the Purchase Order ->
 * Goods Receiving -> Batch Verification -> Expiry Verification ->
 * Available for Sales pipeline, and backfills legacy batches.
 */
function ensureInventoryWorkflowMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='workflow_status');
         SET @s=IF(@e=0,'ALTER TABLE product_batches ADD COLUMN workflow_status VARCHAR(50) NULL AFTER status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='workflow_updated_at');
         SET @s=IF(@e=0,'ALTER TABLE product_batches ADD COLUMN workflow_updated_at DATETIME NULL AFTER workflow_status','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_batches' AND COLUMN_NAME='workflow_assigned_to');
         SET @s=IF(@e=0,'ALTER TABLE product_batches ADD COLUMN workflow_assigned_to INT UNSIGNED NULL AFTER workflow_updated_at','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];
    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }

    // Backfill legacy batches: stock already in hand is treated as verified.
    try {
        $pdo->exec("UPDATE product_batches SET workflow_status = 'available_for_sales' WHERE workflow_status IS NULL AND status = 'active'");
        $pdo->exec("UPDATE product_batches SET workflow_status = 'expired' WHERE workflow_status IS NULL AND status = 'expired'");
        $pdo->exec("UPDATE product_batches SET workflow_status = 'quarantined' WHERE workflow_status IS NULL AND status = 'quarantined'");
        $pdo->exec("UPDATE product_batches SET workflow_status = 'disposed' WHERE workflow_status IS NULL AND status = 'disposed'");
        $pdo->exec("UPDATE product_batches SET workflow_updated_at = COALESCE(quarantined_at, disposed_at, created_at) WHERE workflow_updated_at IS NULL");
    } catch (Exception $e) { /* ignore */ }
}

/**
 * Auto-run migration for invoice_payments ledger table.
 * Creates the table and backfills from existing payment_log data.
 */
function ensureInvoicePaymentsMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    // payment_log must exist and allow NULL customer_order_id for invoice-based payments
    ensureOrderWorkflowMigration($pdo);

    $sqls = [
        "CREATE TABLE IF NOT EXISTS invoice_payments (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id      INT UNSIGNED NOT NULL,
            customer_id     INT UNSIGNED NOT NULL,
            amount          DECIMAL(12,2) NOT NULL,
            payment_method  VARCHAR(50) NULL,
            reference_no    VARCHAR(100) NULL,
            receipt_path    VARCHAR(255) NULL,
            notes           TEXT NULL,
            recorded_by     INT UNSIGNED NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            INDEX idx_ip_invoice (invoice_id),
            INDEX idx_ip_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }

    // Backfill: convert existing confirmed customer payments from payment_log into invoice_payments
    try {
        $exists = $pdo->query("SELECT COUNT(*) FROM invoice_payments")->fetchColumn();
        if ((int)$exists === 0) {
            $rows = $pdo->query("
                SELECT pl.customer_order_id, pl.customer_id, pl.amount, pl.payment_method,
                       pl.transaction_ref, pl.performed_by, pl.created_at, i.id AS invoice_id
                FROM payment_log pl
                JOIN invoices i ON i.customer_order_id = pl.customer_order_id
                WHERE pl.action = 'customer_payment' AND pl.amount IS NOT NULL AND pl.amount > 0
                ORDER BY pl.created_at ASC
            ")->fetchAll();
            if (!empty($rows)) {
                $ins = $pdo->prepare("INSERT IGNORE INTO invoice_payments (invoice_id, customer_id, amount, payment_method, reference_no, recorded_by, created_at) VALUES (:iid, :cid, :amt, :meth, :ref, :uid, :dt)");
                foreach ($rows as $r) {
                    $ins->execute([
                        'iid' => $r['invoice_id'], 'cid' => $r['customer_id'], 'amt' => $r['amount'],
                        'meth' => $r['payment_method'], 'ref' => $r['transaction_ref'],
                        'uid' => $r['performed_by'], 'dt' => $r['created_at']
                    ]);
                }
                // Sync invoice totals after backfill
                $pdo->exec("
                    UPDATE invoices i
                    SET i.amount_paid = COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = i.id), 0),
                        i.outstanding_balance = GREATEST(i.total_amount - COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = i.id), 0), 0),
                        i.payment_status = CASE
                            WHEN COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = i.id), 0) >= i.total_amount THEN 'paid'
                            WHEN COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip WHERE ip.invoice_id = i.id), 0) > 0 THEN 'partial'
                            ELSE 'unpaid'
                        END
                ");
            }
        }
    } catch (Exception $e) { /* ignore */ }
}

/**
 * Auto-run migration for supplier codes (mirrors customers.customer_code).
 */
function ensureSupplierCodeMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sql = "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='suppliers' AND COLUMN_NAME='supplier_code');
     SET @s=IF(@e=0,'ALTER TABLE suppliers ADD COLUMN supplier_code VARCHAR(50) NULL AFTER name','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p";

    try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
}

/**
 * Auto-run migration for the CMS activity log. Extends cms_activity_log with
 * generic entity columns so AuditService::cms() can record page actions and
 * non-CMS entity actions (products, customers, etc.) in the same table.
 */
function ensureCmsActivityMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        // page_id must be nullable (non-CMS entity actions leave it null)
        "SET @e=(SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cms_activity_log' AND COLUMN_NAME='page_id');
         SET @s=IF(@e='NO','ALTER TABLE cms_activity_log MODIFY COLUMN page_id INT UNSIGNED NULL','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cms_activity_log' AND COLUMN_NAME='entity_type');
         SET @s=IF(@e=0,'ALTER TABLE cms_activity_log ADD COLUMN entity_type VARCHAR(50) NULL AFTER page_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cms_activity_log' AND COLUMN_NAME='entity_id');
         SET @s=IF(@e=0,'ALTER TABLE cms_activity_log ADD COLUMN entity_id INT UNSIGNED NULL AFTER entity_type','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cms_activity_log' AND COLUMN_NAME='summary');
         SET @s=IF(@e=0,'ALTER TABLE cms_activity_log ADD COLUMN summary TEXT NULL AFTER entity_id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cms_activity_log' AND COLUMN_NAME='ip_address');
         SET @s=IF(@e=0,'ALTER TABLE cms_activity_log ADD COLUMN ip_address VARCHAR(45) NULL AFTER summary','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}

/**
 * Auto-run migration for the Salesperson staff role. Adds the 'salesperson'
 * value to the users.role enum and a user_id link column on salespeople so a
 * salesperson login resolves to their salespeople record. Idempotent.
 */
function ensureSalespersonRoleMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        // users.role enum must include 'salesperson'
        "SET @t=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role');
         SET @s=IF(LOCATE('salesperson',@t)=0,\"ALTER TABLE users MODIFY COLUMN role ENUM('admin','customer','delivery','finance','salesperson') NOT NULL DEFAULT 'admin'\",'SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // salespeople.user_id links a salesperson login to their salespeople record
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND COLUMN_NAME='user_id');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD COLUMN user_id INT UNSIGNED NULL AFTER id','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        "SET @e=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='salespeople' AND INDEX_NAME='uq_salespeople_user_id');
         SET @s=IF(@e=0,'ALTER TABLE salespeople ADD UNIQUE KEY uq_salespeople_user_id (user_id)','SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }

    // Backfill: link salespeople to salesperson-role users whose full_name
    // matches an existing salespeople record, so they can log in immediately.
    try {
        $rows = $pdo->query("SELECT u.id AS uid, sp.id AS spid FROM users u JOIN salespeople sp ON LOWER(TRIM(sp.name)) = LOWER(TRIM(u.full_name)) WHERE u.role = 'salesperson' AND (sp.user_id IS NULL OR sp.user_id = 0)")->fetchAll();
        $upd = $pdo->prepare('UPDATE salespeople SET user_id = :uid WHERE id = :spid AND (user_id IS NULL OR user_id = 0)');
        foreach ($rows as $r) {
            $upd->execute(['uid' => $r['uid'], 'spid' => $r['spid']]);
        }
    } catch (Exception $e) { /* ignore */ }
}

/**
 * Auto-run migration for the Store Manager role. Adds the 'store_manager'
 * value to the users.role enum so a store-manager login resolves via
 * isStoreManager() and the correct role_id-based permissions. Idempotent.
 */
function ensureStoreManagerRoleMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $sqls = [
            "SET @t=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role');
             SET @s=IF(LOCATE('store_manager',@t)=0,\"ALTER TABLE users MODIFY COLUMN role ENUM('admin','customer','delivery','finance','salesperson','store_manager') NOT NULL DEFAULT 'admin'\",'SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        ];
        foreach ($sqls as $sql) {
            try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
        }
    } catch (Exception $e) { /* ignore */ }

    // Idempotently grant the store-manager role warehouse-report access:
    // reports view (already seeded) + reports export so CSV/XLS/Print work.
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, module, permission) VALUES (5, 'reports', 'export')");
        $ins->execute();
    } catch (Exception $e) { /* ignore */ }
}

/**
 * Staged approval for salesperson-created orders.
 *
 * Salesperson orders start with approval_stage='admin' and advance through
 * 'store' then 'finance' before they are finalized (sale + invoice + stock
 * deduction). Non-salesperson orders keep approval_stage = NULL and use the
 * existing single-step admin approval. Idempotent.
 */
function ensureSalespersonOrderApprovalMigration($pdo)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $sqls = [
        "SET @e=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='approval_stage');
         SET @s=IF(@e=0,\"ALTER TABLE customer_orders ADD COLUMN approval_stage ENUM('admin','store','finance') NULL DEFAULT NULL AFTER salesperson_id\",'SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
        // Sales page payment methods (bank_transfer / mobile_money) must fit the order enum
        "SET @t=(SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_orders' AND COLUMN_NAME='payment_method');
         SET @s=IF(LOCATE('bank_transfer',@t)=0,\"ALTER TABLE customer_orders MODIFY COLUMN payment_method ENUM('cash','bank','cbe_birr','telebirr','credit','bank_transfer','mobile_money') NOT NULL DEFAULT 'cash'\",'SELECT 1'); PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p",
    ];

    foreach ($sqls as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* ignore */ }
    }
}
