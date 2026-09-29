-- Add payment fields to customer orders (run if upgrading).
ALTER TABLE customer_orders
    ADD COLUMN payment_method ENUM('bank', 'telebirr') NOT NULL DEFAULT 'bank' AFTER total_amount,
    ADD COLUMN payment_detail VARCHAR(255) NOT NULL DEFAULT '' AFTER payment_method;

-- If columns already exist, skip or run manually. For empty DB with old schema without NOT NULL, you may need:
-- UPDATE customer_orders SET payment_detail = '' WHERE payment_detail IS NULL;
