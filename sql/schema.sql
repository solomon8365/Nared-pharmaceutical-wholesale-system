-- Create database schema for Pharma Distribution System
-- Order: customers before users (users.customer_id FK).

CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NULL,
    role ENUM('admin', 'customer', 'delivery', 'finance') NOT NULL DEFAULT 'admin',
    customer_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    image_path VARCHAR(255) NULL,
    barcode VARCHAR(100) NULL UNIQUE,
    price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    stock_quantity INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    order_date DATE NOT NULL,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    sale_date DATE NOT NULL,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    agent_name VARCHAR(150) NULL,
    commission_percent DECIMAL(5,2) NULL,
    commission_amount DECIMAL(12,2) NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS commission_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    paid_at DATETIME NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS customer_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    placed_by_user_id INT UNSIGNED NOT NULL,
    order_date DATE NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('bank', 'telebirr') NOT NULL DEFAULT 'bank',
    payment_detail VARCHAR(255) NOT NULL,
    payment_screenshot VARCHAR(255) NULL,
    delivery_address TEXT NULL,
    admin_note TEXT NULL,
    sale_id INT UNSIGNED NULL,
    delivery_staff_id INT UNSIGNED NULL,
    delivery_status ENUM('assigned', 'out_for_delivery', 'delivered') NULL,
    payment_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    payment_confirmed_at DATETIME NULL,
    payment_confirmed_by INT UNSIGNED NULL,
    payment_notes TEXT NULL,
    delivery_otp_hash VARCHAR(255) NULL,
    delivery_confirmed_at DATETIME NULL,
    confirmation_method VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    FOREIGN KEY (placed_by_user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE SET NULL,
    FOREIGN KEY (delivery_staff_id) REFERENCES users (id) ON DELETE SET NULL,
    FOREIGN KEY (payment_confirmed_by) REFERENCES users (id) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS product_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    batch_number VARCHAR(100) NOT NULL,
    expiry_date DATE NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    purchase_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS supplier_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50) NULL,
    reference VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(50) NOT NULL UNIQUE,
    customer_order_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    delivery_address TEXT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_order_id) REFERENCES customer_orders (id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address, attempted_at)
);

INSERT INTO users (username, password, full_name, role, customer_id)
VALUES ('admin', '$2y$10$wdP/d1DWLmpDUWIxRpc7GuOiOF6KHTh8EYiyGW6Q.2e9dwkx6qpt.', 'Administrator', 'admin', NULL);

-- Add refunds table at the end
CREATE TABLE IF NOT EXISTS refunds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_order_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    reason TEXT NOT NULL,
    refunded_at DATETIME NOT NULL,
    refunded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_order_id) REFERENCES customer_orders (id) ON DELETE CASCADE,
    FOREIGN KEY (refunded_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
