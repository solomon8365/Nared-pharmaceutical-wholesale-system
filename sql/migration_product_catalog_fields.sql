-- Product Catalog Design: add missing pharmaceutical fields
-- Run once. Uses IF NOT EXISTS for safety.

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS pack_size VARCHAR(100) DEFAULT NULL AFTER strength,
  ADD COLUMN IF NOT EXISTS manufacturer VARCHAR(200) DEFAULT NULL AFTER pack_size,
  ADD COLUMN IF NOT EXISTS country_of_origin VARCHAR(100) DEFAULT NULL AFTER manufacturer,
  ADD COLUMN IF NOT EXISTS storage_condition VARCHAR(150) DEFAULT NULL AFTER country_of_origin;
