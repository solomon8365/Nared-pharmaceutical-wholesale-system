-- Migration: Enhanced Invoice Features
-- Adds payment_status, credit terms, outstanding balance, digital signature, return reference, batch/expiry on items

-- New columns on invoices
ALTER TABLE invoices ADD COLUMN payment_status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid' AFTER total_amount;
ALTER TABLE invoices ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER payment_status;
ALTER TABLE invoices ADD COLUMN credit_due_date DATE NULL AFTER amount_paid;
ALTER TABLE invoices ADD COLUMN outstanding_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER credit_due_date;
ALTER TABLE invoices ADD COLUMN digital_signature TEXT NULL AFTER outstanding_balance;
ALTER TABLE invoices ADD COLUMN return_reference_no VARCHAR(100) NULL AFTER digital_signature;

-- Batch & Expiry on order items
ALTER TABLE customer_order_items ADD COLUMN batch_no VARCHAR(100) NULL AFTER unit_price;
ALTER TABLE customer_order_items ADD COLUMN expiry_date DATE NULL AFTER batch_no;
