-- ============================================================
-- Physical Count
-- ============================================================

CREATE TABLE IF NOT EXISTS physical_counts (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  warehouse_id INT(10) UNSIGNED DEFAULT NULL,
  count_date DATE NOT NULL,
  status ENUM('draft','completed','cancelled') NOT NULL DEFAULT 'draft',
  notes TEXT DEFAULT NULL,
  created_by INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  INDEX (warehouse_id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS physical_count_items (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  physical_count_id INT(10) UNSIGNED NOT NULL,
  product_id INT(10) UNSIGNED NOT NULL,
  batch_id INT(10) UNSIGNED DEFAULT NULL,
  expected_qty INT(11) NOT NULL DEFAULT 0,
  actual_qty INT(11) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  notes TEXT DEFAULT NULL,
  INDEX (physical_count_id),
  INDEX (product_id),
  INDEX (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
