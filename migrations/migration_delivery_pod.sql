-- Delivery POD Confirmation Migration
-- Orders move to 'pod_pending' after delivery; staff verifies POD before 'delivered'.
-- Invoice fulfillment status marks 'completed' only after verification.

-- Extend delivery_status ENUM with pod_pending
ALTER TABLE customer_orders MODIFY COLUMN delivery_status ENUM('assigned','accepted','picked_up','out_for_delivery','pod_pending','delivered','failed','returned') NULL;

-- POD recording / verification audit columns
ALTER TABLE customer_orders ADD COLUMN pod_recorded_by INT UNSIGNED NULL AFTER confirmation_method;
ALTER TABLE customer_orders ADD COLUMN pod_recorded_at DATETIME NULL AFTER pod_recorded_by;
ALTER TABLE customer_orders ADD COLUMN pod_verified_by INT UNSIGNED NULL AFTER pod_recorded_at;
ALTER TABLE customer_orders ADD COLUMN pod_verified_at DATETIME NULL AFTER pod_verified_by;

-- Invoice fulfillment lifecycle (payment_status remains separate)
ALTER TABLE invoices ADD COLUMN status ENUM('pending','completed') NOT NULL DEFAULT 'pending' AFTER delivery_address;
ALTER TABLE invoices ADD COLUMN completed_at DATETIME NULL AFTER status;
ALTER TABLE invoices ADD COLUMN completed_by INT UNSIGNED NULL AFTER completed_at;
