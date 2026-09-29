-- ============================================================
-- Phase 1: Products, Customers & Suppliers enhancements
-- ============================================================

-- Products: new fields
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS brand VARCHAR(150) DEFAULT NULL AFTER name,
  ADD COLUMN IF NOT EXISTS generic_name VARCHAR(200) DEFAULT NULL AFTER brand,
  ADD COLUMN IF NOT EXISTS dosage_form VARCHAR(100) DEFAULT NULL AFTER generic_name,
  ADD COLUMN IF NOT EXISTS strength VARCHAR(100) DEFAULT NULL AFTER dosage_form,
  ADD COLUMN IF NOT EXISTS unit_of_measure VARCHAR(50) DEFAULT NULL AFTER strength,
  ADD COLUMN IF NOT EXISTS manufacturing_date DATE DEFAULT NULL AFTER unit_of_measure,
  ADD COLUMN IF NOT EXISTS product_status ENUM('active','inactive','discontinued') NOT NULL DEFAULT 'active' AFTER stock_quantity,
  ADD COLUMN IF NOT EXISTS purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER product_status;

-- Customers: new fields
ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS customer_type ENUM('pharmacy','drug_store','hospital','clinic','wholesaler','other_licensed') DEFAULT NULL AFTER name,
  ADD COLUMN IF NOT EXISTS license_number VARCHAR(100) DEFAULT NULL AFTER customer_type,
  ADD COLUMN IF NOT EXISTS tin_number VARCHAR(100) DEFAULT NULL AFTER license_number,
  ADD COLUMN IF NOT EXISTS email VARCHAR(150) DEFAULT NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS physical_address TEXT DEFAULT NULL AFTER email,
  ADD COLUMN IF NOT EXISTS credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER physical_address,
  ADD COLUMN IF NOT EXISTS payment_terms VARCHAR(100) DEFAULT NULL AFTER credit_limit,
  ADD COLUMN IF NOT EXISTS customer_status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active' AFTER payment_terms;

-- Suppliers: new fields
ALTER TABLE suppliers
  ADD COLUMN IF NOT EXISTS email VARCHAR(150) DEFAULT NULL AFTER phone,
  ADD COLUMN IF NOT EXISTS physical_address TEXT DEFAULT NULL AFTER email,
  ADD COLUMN IF NOT EXISTS supplier_status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER physical_address;

-- Fix: allow admin to NOT access finance pages (match requireFinanceOfficer change)
-- Purchase orders table
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT(10) UNSIGNED NOT NULL,
  order_date DATE NOT NULL,
  status ENUM('pending','approved','received','cancelled') NOT NULL DEFAULT 'pending',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes TEXT DEFAULT NULL,
  created_by INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_at DATETIME DEFAULT NULL,
  INDEX (supplier_id),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id INT(10) UNSIGNED NOT NULL,
  product_id INT(10) UNSIGNED NOT NULL,
  quantity INT(11) NOT NULL DEFAULT 0,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  received_qty INT(11) NOT NULL DEFAULT 0,
  INDEX (purchase_order_id),
  INDEX (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
