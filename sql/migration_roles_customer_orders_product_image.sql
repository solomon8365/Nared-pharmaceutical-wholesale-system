-- Upgrade existing pharma DB (run once). Adjust if columns already exist.

ALTER TABLE users
    ADD COLUMN role ENUM('admin', 'customer') NOT NULL DEFAULT 'admin' AFTER full_name;

ALTER TABLE users
    ADD COLUMN customer_id INT UNSIGNED NULL AFTER role;

ALTER TABLE users
    ADD CONSTRAINT fk_users_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL;

ALTER TABLE products
    ADD COLUMN image_path VARCHAR(255) NULL AFTER description;

CREATE TABLE IF NOT EXISTS customer_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    placed_by_user_id INT UNSIGNED NOT NULL,
    order_date DATE NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('bank', 'telebirr') NOT NULL DEFAULT 'bank',
    payment_detail VARCHAR(255) NOT NULL DEFAULT '',
    admin_note TEXT NULL,
    sale_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    FOREIGN KEY (placed_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS customer_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (customer_order_id) REFERENCES customer_orders (id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
);
