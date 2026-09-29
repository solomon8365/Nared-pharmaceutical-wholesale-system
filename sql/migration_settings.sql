-- ============================================================
-- System Settings (key-value store)
-- ============================================================

CREATE TABLE IF NOT EXISTS settings (
  key_name VARCHAR(100) NOT NULL PRIMARY KEY,
  value TEXT DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings
INSERT IGNORE INTO settings (key_name, value) VALUES
('company_name', 'NARED PHARMACON'),
('company_address', ''),
('company_phone', ''),
('company_email', ''),
('business_reg_no', ''),
('website_url', ''),
('default_payment_terms', ''),
('tax_rate', '0'),
('vat_rate', '0'),
('currency', 'ETB'),
('invoice_prefix', 'INV-'),
('receipt_prefix', 'RCP-'),
('print_logo_on_invoice', '1'),
('low_stock_threshold', '10'),
('expiry_warning_days', '90'),
('timezone', 'Africa/Addis_Ababa'),
('date_format', 'Y-m-d');
