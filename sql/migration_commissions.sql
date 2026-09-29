ALTER TABLE sales
  ADD COLUMN agent_name VARCHAR(150) NULL AFTER total_amount,
  ADD COLUMN commission_percent DECIMAL(5,2) NULL AFTER agent_name,
  ADD COLUMN commission_amount DECIMAL(12,2) NULL AFTER commission_percent,
  ADD COLUMN paid_at DATETIME NULL AFTER commission_amount;

CREATE TABLE IF NOT EXISTS commission_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    paid_at DATETIME NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE
);
