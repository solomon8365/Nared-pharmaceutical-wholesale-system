-- ============================================================
-- Stock Transfers
-- ============================================================

CREATE TABLE IF NOT EXISTS stock_transfers (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  from_warehouse_id INT(10) UNSIGNED NOT NULL,
  to_warehouse_id INT(10) UNSIGNED NOT NULL,
  transfer_date DATE NOT NULL,
  status ENUM('draft','completed','cancelled') NOT NULL DEFAULT 'draft',
  notes TEXT DEFAULT NULL,
  created_by INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  INDEX (from_warehouse_id),
  INDEX (to_warehouse_id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  stock_transfer_id INT(10) UNSIGNED NOT NULL,
  product_id INT(10) UNSIGNED NOT NULL,
  batch_id INT(10) UNSIGNED DEFAULT NULL,
  quantity INT(11) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  INDEX (stock_transfer_id),
  INDEX (product_id),
  INDEX (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add a second warehouse for transfers
INSERT IGNORE INTO warehouses (id, name, location) VALUES (2, 'Secondary Warehouse', 'Branch Office');
