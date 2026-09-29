-- Migration: Customer Dashboard Enhancement Tables
-- Creates tables for promotions, wallet, rewards, reviews, recently viewed, search history, product comparison

-- 1. Promotions / Special Offers
CREATE TABLE IF NOT EXISTS `promotions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `promo_type` ENUM('percentage','fixed','buy_x_get_y','free_delivery','bundle') NOT NULL DEFAULT 'percentage',
    `discount_value` DECIMAL(10,2) DEFAULT 0,
    `min_order_amount` DECIMAL(10,2) DEFAULT 0,
    `promo_code` VARCHAR(50) DEFAULT NULL,
    `banner_color` VARCHAR(20) DEFAULT '#2563eb',
    `icon` VARCHAR(50) DEFAULT 'fa-tag',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `target_audience` ENUM('all','specific_customers','new_customers','vip') DEFAULT 'all',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Customer Wallet
CREATE TABLE IF NOT EXISTS `customer_wallets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `balance` DECIMAL(12,2) DEFAULT 0.00,
    `currency` VARCHAR(10) DEFAULT 'ETB',
    `status` ENUM('active','frozen','closed') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `uk_wallet_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Wallet Transactions
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `wallet_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `type` ENUM('credit','debit','refund','recharge','payment','adjustment') NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `balance_after` DECIMAL(12,2) NOT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `performed_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_wt_wallet` (`wallet_id`),
    KEY `idx_wt_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Reward Points
CREATE TABLE IF NOT EXISTS `customer_rewards` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `points` INT DEFAULT 0,
    `tier` ENUM('bronze','silver','gold','platinum') DEFAULT 'bronze',
    `total_earned` INT DEFAULT 0,
    `total_redeemed` INT DEFAULT 0,
    `expires_at` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL,
    UNIQUE KEY `uk_rewards_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Reward Points History
CREATE TABLE IF NOT EXISTS `reward_points_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `points` INT NOT NULL,
    `type` ENUM('earn','redeem','expire','adjust') NOT NULL,
    `source` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `expires_at` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rpl_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Product Reviews
CREATE TABLE IF NOT EXISTS `product_reviews` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `rating` TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    `title` VARCHAR(200) DEFAULT NULL,
    `review_text` TEXT,
    `is_verified` TINYINT(1) DEFAULT 0,
    `is_approved` TINYINT(1) DEFAULT 1,
    `helpful_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL,
    KEY `idx_pr_product` (`product_id`),
    KEY `idx_pr_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Recently Viewed Products
CREATE TABLE IF NOT EXISTS `recently_viewed_products` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `viewed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_rvp_unique` (`customer_id`, `product_id`),
    KEY `idx_rvp_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Search History
CREATE TABLE IF NOT EXISTS `search_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `search_query` VARCHAR(200) NOT NULL,
    `results_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sh_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Product Comparison Lists
CREATE TABLE IF NOT EXISTS `product_comparisons` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NOT NULL,
    `session_id` VARCHAR(100) DEFAULT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pc_customer` (`customer_id`),
    KEY `idx_pc_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Add wallet/reward columns to customers table
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `wallet_balance` DECIMAL(12,2) DEFAULT 0.00 AFTER `credit_used`,
    ADD COLUMN IF NOT EXISTS `reward_points` INT DEFAULT 0 AFTER `wallet_balance`,
    ADD COLUMN IF NOT EXISTS `reward_tier` ENUM('bronze','silver','gold','platinum') DEFAULT 'bronze' AFTER `reward_points`;

-- 11. Seed some sample promotions
INSERT INTO `promotions` (`title`, `description`, `promo_type`, `discount_value`, `min_order_amount`, `banner_color`, `icon`, `start_date`, `end_date`, `is_active`) VALUES
('New Customer Welcome', 'Get 10% off your first order above 5,000 ETB', 'percentage', 10.00, 5000.00, '#16a34a', 'fa-gift', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1),
('Bulk Order Discount', 'Order 20+ items and get 5% off', 'percentage', 5.00, 0, '#2563eb', 'fa-boxes', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 1),
('Free Delivery', 'Free delivery on orders above 10,000 ETB', 'free_delivery', 0, 10000.00, '#7c3aed', 'fa-truck', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1),
('Weekend Special', '15% off all orders placed on Friday & Saturday', 'percentage', 15.00, 2000.00, '#ea580c', 'fa-sun', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 90 DAY), 1),
('Buy 10 Get 1 Free', 'Buy any 10 units of the same product, get 1 extra free', 'buy_x_get_y', 1, 0, '#dc2626', 'fa-fire', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45 DAY), 1);
