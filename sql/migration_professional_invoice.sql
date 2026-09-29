-- Migration: Professional Pharmaceutical Invoice
-- Adds missing columns for professional invoice layout

-- invoices: reference_no
ALTER TABLE invoices ADD COLUMN reference_no VARCHAR(50) NULL AFTER invoice_no;

-- invoices: sales_type (cash/credit)
ALTER TABLE invoices ADD COLUMN sales_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER payment_status;

-- invoices: warehouse_id
ALTER TABLE invoices ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER delivery_address;

-- invoices: prepared_by (user id)
ALTER TABLE invoices ADD COLUMN prepared_by INT UNSIGNED NULL AFTER warehouse_id;

-- invoices: approved_by (user id)
ALTER TABLE invoices ADD COLUMN approved_by INT UNSIGNED NULL AFTER prepared_by;

-- customer_order_items: discount per line
ALTER TABLE customer_order_items ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unit_price;

-- customer_order_items: tax per line
ALTER TABLE customer_order_items ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount;

-- customers: customer_code
ALTER TABLE customers ADD COLUMN customer_code VARCHAR(50) NULL AFTER id;

-- Seed company_tin into settings if not present
INSERT IGNORE INTO settings (key_name, value) VALUES ('company_tin', '');
