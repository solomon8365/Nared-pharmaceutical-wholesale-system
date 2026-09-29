-- Create invoices table
CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(50) NOT NULL UNIQUE,
    customer_order_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    delivery_address TEXT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_order_id) REFERENCES customer_orders (id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add delivery columns to customer_orders
ALTER TABLE customer_orders
  ADD COLUMN delivery_address TEXT NULL AFTER payment_screenshot,
  ADD COLUMN delivery_otp_hash VARCHAR(255) NULL AFTER delivery_status,
  ADD COLUMN delivery_confirmed_at DATETIME NULL AFTER delivery_otp_hash,
  ADD COLUMN confirmation_method VARCHAR(50) NULL AFTER delivery_confirmed_at;
