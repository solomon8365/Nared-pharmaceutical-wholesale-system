-- ============================================================
-- Purchase Payment Approval Workflow
-- Extends purchase_orders with payment approval columns
-- ============================================================

ALTER TABLE purchase_orders
  MODIFY COLUMN status ENUM('pending','approved','payment_pending','payment_approved','payment_rejected','received','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS finance_notes TEXT DEFAULT NULL AFTER payment_status,
  ADD COLUMN IF NOT EXISTS payment_approved_by INT UNSIGNED DEFAULT NULL AFTER finance_notes,
  ADD COLUMN IF NOT EXISTS payment_approved_at DATETIME DEFAULT NULL AFTER payment_approved_by,
  ADD COLUMN IF NOT EXISTS payment_receipt VARCHAR(255) DEFAULT NULL AFTER payment_approved_at,
  ADD COLUMN IF NOT EXISTS payment_voucher_no VARCHAR(100) DEFAULT NULL AFTER payment_receipt,
  ADD COLUMN IF NOT EXISTS payment_date DATE DEFAULT NULL AFTER payment_voucher_no,
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT NULL AFTER payment_date,
  ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) DEFAULT NULL AFTER payment_method;
