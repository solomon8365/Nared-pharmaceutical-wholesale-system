-- Add `finance` role to users
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'customer', 'delivery', 'finance') NOT NULL DEFAULT 'admin';

-- Add payment confirmation columns to customer_orders
ALTER TABLE customer_orders
    ADD COLUMN payment_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_status,
    ADD COLUMN payment_confirmed_at DATETIME NULL AFTER payment_confirmed,
    ADD COLUMN payment_confirmed_by INT UNSIGNED NULL AFTER payment_confirmed_at,
    ADD COLUMN payment_notes TEXT NULL AFTER payment_confirmed_by,
    ADD FOREIGN KEY (payment_confirmed_by) REFERENCES users (id) ON DELETE SET NULL;

-- Refunds table
CREATE TABLE IF NOT EXISTS refunds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_order_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    reason TEXT NOT NULL,
    refunded_at DATETIME NOT NULL,
    refunded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_order_id) REFERENCES customer_orders (id) ON DELETE CASCADE,
    FOREIGN KEY (refunded_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
