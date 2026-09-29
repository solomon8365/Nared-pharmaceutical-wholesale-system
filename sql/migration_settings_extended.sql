-- Extended settings for System tab and enhanced General/Inventory settings

INSERT IGNORE INTO settings (key_name, value) VALUES
-- General: Financial
('default_markup', '20'),
('default_order_status', 'pending'),
('invoice_due_days', '30'),
('rounding_precision', '2'),

-- Invoice
('show_tax_on_invoice', '1'),
('invoice_footer', ''),

-- Inventory: Operational
('allow_negative_stock', '0'),
('require_batch_on_sale', '0'),
('auto_generate_barcode', '0'),
('default_stock_location', 'Main Warehouse'),

-- Localization
('default_country', 'ET'),

-- System
('records_per_page', '50'),
('session_timeout', '120'),
('low_stock_notify_email', ''),
('maintenance_mode', '0');
