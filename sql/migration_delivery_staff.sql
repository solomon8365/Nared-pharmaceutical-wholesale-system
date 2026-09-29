-- Extend users role to include 'delivery'
ALTER TABLE users
  MODIFY COLUMN role ENUM('admin', 'customer', 'delivery') NOT NULL DEFAULT 'admin';

-- Add delivery columns to customer_orders
ALTER TABLE customer_orders
  ADD COLUMN delivery_staff_id INT UNSIGNED NULL AFTER sale_id,
  ADD COLUMN delivery_status ENUM('assigned', 'out_for_delivery', 'delivered') NULL AFTER delivery_staff_id,
  ADD FOREIGN KEY (delivery_staff_id) REFERENCES users (id) ON DELETE SET NULL;
