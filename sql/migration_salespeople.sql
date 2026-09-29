CREATE TABLE IF NOT EXISTS salespeople (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE sales
  ADD COLUMN salesperson_id INT UNSIGNED NULL AFTER customer_id,
  ADD FOREIGN KEY (salesperson_id) REFERENCES salespeople(id) ON DELETE SET NULL;
