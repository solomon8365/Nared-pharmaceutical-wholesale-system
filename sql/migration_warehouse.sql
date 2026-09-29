-- ============================================================
-- Warehouse & Inventory Management
-- ============================================================

CREATE TABLE IF NOT EXISTS warehouses (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  location VARCHAR(255) DEFAULT NULL,
  warehouse_status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (warehouse_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE product_batches
  ADD COLUMN IF NOT EXISTS warehouse_id INT(10) UNSIGNED DEFAULT NULL AFTER product_id,
  ADD INDEX IF NOT EXISTS (warehouse_id);

-- Insert a default warehouse
INSERT IGNORE INTO warehouses (id, name, location) VALUES (1, 'Main Warehouse', 'Head Office');
