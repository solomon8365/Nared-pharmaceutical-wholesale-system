-- Migration: Customer Order & Payment Workflow
-- Adds payment methods, payment log, notifications, credit tracking, order timeline

-- 1. Expand payment_method to support all methods
ALTER TABLE customer_orders MODIFY COLUMN payment_method ENUM('cash','bank','cbe_birr','telebirr','credit') NOT NULL DEFAULT 'cash';

-- 2. Add transaction reference to customer_orders
ALTER TABLE customer_orders ADD COLUMN transaction_ref VARCHAR(100) NULL AFTER payment_detail;
ALTER TABLE customer_orders ADD COLUMN bank_name VARCHAR(100) NULL AFTER transaction_ref;
ALTER TABLE customer_orders ADD COLUMN account_name VARCHAR(100) NULL AFTER bank_name;
ALTER TABLE customer_orders ADD COLUMN account_number VARCHAR(100) NULL AFTER account_name;
ALTER TABLE customer_orders ADD COLUMN merchant_name VARCHAR(100) NULL AFTER account_number;
ALTER TABLE customer_orders ADD COLUMN merchant_id VARCHAR(100) NULL AFTER merchant_name;

-- 3. Add payment verification details
ALTER TABLE customer_orders ADD COLUMN verified_amount DECIMAL(12,2) NULL AFTER payment_notes;
ALTER TABLE customer_orders ADD COLUMN verified_by INT UNSIGNED NULL AFTER verified_amount;
ALTER TABLE customer_orders ADD COLUMN verified_at DATETIME NULL AFTER verified_by;
ALTER TABLE customer_orders ADD COLUMN rejection_reason TEXT NULL AFTER verified_at;
ALTER TABLE customer_orders ADD COLUMN payment_rejection_reason TEXT NULL AFTER rejection_reason;

-- 4. Add credit tracking to customers
ALTER TABLE customers ADD COLUMN credit_terms_days INT NOT NULL DEFAULT 30 AFTER credit_used;
ALTER TABLE customers ADD COLUMN is_credit_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_terms_days;

-- 5. Add order timeline tracking
ALTER TABLE customer_orders ADD COLUMN timeline_data JSON NULL AFTER confirmation_method;

-- 6. Add delivery details
ALTER TABLE customer_orders ADD COLUMN driver_name VARCHAR(150) NULL AFTER delivery_staff_id;
ALTER TABLE customer_orders ADD COLUMN vehicle_info VARCHAR(150) NULL AFTER driver_name;
ALTER TABLE customer_orders ADD COLUMN scheduled_delivery_date DATE NULL AFTER vehicle_info;
ALTER TABLE customer_orders ADD COLUMN delivered_at DATETIME NULL AFTER delivery_confirmed_at;

-- 7. Payment log table for audit trail
CREATE TABLE IF NOT EXISTS payment_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Order notifications table
CREATE TABLE IF NOT EXISTS order_notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Add discount and VAT to customer_orders summary
ALTER TABLE customer_orders ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount;
ALTER TABLE customer_orders ADD COLUMN vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER discount_amount;
