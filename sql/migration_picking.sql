-- Warehouse Picking workflow
ALTER TABLE customer_orders
  ADD COLUMN IF NOT EXISTS picking_status VARCHAR(20) DEFAULT NULL AFTER payment_confirmed_by,
  ADD COLUMN IF NOT EXISTS picked_at DATETIME DEFAULT NULL AFTER picking_status,
  ADD COLUMN IF NOT EXISTS picker_id INT UNSIGNED DEFAULT NULL AFTER picked_at;
