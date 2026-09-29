CREATE TABLE IF NOT EXISTS purchase_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    return_date DATE NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reason TEXT NULL,
    status ENUM('draft','returned') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS purchase_return_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    batch_id INT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE SET NULL
);
