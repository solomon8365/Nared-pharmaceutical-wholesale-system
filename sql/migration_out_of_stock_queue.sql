-- Out of Stock Queue Migration
-- Tracks products that went out of stock during customer order placement
-- Auto-links to POs, notifies admins, and auto-fulfills when goods arrive

CREATE TABLE IF NOT EXISTS out_of_stock_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_order_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity_needed INT UNSIGNED NOT NULL DEFAULT 0,
    quantity_fulfilled INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending','purchase_ordered','receiving','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
    purchase_order_id INT UNSIGNED NULL,
    salesperson_id INT UNSIGNED NULL,
    notified_admin TINYINT(1) NOT NULL DEFAULT 0,
    notified_salesperson TINYINT(1) NOT NULL DEFAULT 0,
    customer_notified TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    fulfilled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_order_id) REFERENCES customer_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (salesperson_id) REFERENCES salespeople(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
