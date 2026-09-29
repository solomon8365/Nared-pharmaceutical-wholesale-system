-- ============================================================
-- Finance Management: Expenses, Income, Credit Payments
-- ============================================================

CREATE TABLE IF NOT EXISTS expenses (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  expense_category VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  description TEXT DEFAULT NULL,
  expense_date DATE NOT NULL,
  payment_method VARCHAR(50) DEFAULT NULL,
  receipt_path VARCHAR(255) DEFAULT NULL,
  created_by INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX (expense_category),
  INDEX (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS income (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  income_category VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  description TEXT DEFAULT NULL,
  income_date DATE NOT NULL,
  payment_method VARCHAR(50) DEFAULT NULL,
  created_by INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX (income_category),
  INDEX (income_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_credit_payments (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id INT(10) UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_date DATE NOT NULL,
  payment_method VARCHAR(50) DEFAULT NULL,
  reference_no VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_by INT(10) UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (customer_id),
  INDEX (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
