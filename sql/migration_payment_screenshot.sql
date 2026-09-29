ALTER TABLE customer_orders
  ADD COLUMN payment_screenshot VARCHAR(255) NULL AFTER payment_detail;
