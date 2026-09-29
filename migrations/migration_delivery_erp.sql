-- Delivery ERP Migration
-- Adds delivery tracking, driver management, audit logs, returns

-- Extend customer_orders delivery_status for new workflow
ALTER TABLE customer_orders MODIFY COLUMN delivery_status ENUM('assigned','accepted','picked_up','out_for_delivery','pod_pending','delivered','failed','returned') NULL;

-- Add new columns to customer_orders
ALTER TABLE customer_orders ADD COLUMN accepted_at DATETIME NULL AFTER delivery_status;
ALTER TABLE customer_orders ADD COLUMN picked_up_at DATETIME NULL AFTER accepted_at;
ALTER TABLE customer_orders ADD COLUMN dispatch_time DATETIME NULL AFTER picked_up_at;
ALTER TABLE customer_orders ADD COLUMN receiver_name VARCHAR(150) NULL AFTER delivered_at;
ALTER TABLE customer_orders ADD COLUMN receiver_phone VARCHAR(50) NULL AFTER receiver_name;
ALTER TABLE customer_orders ADD COLUMN receiver_signature TEXT NULL AFTER receiver_phone;
ALTER TABLE customer_orders ADD COLUMN delivery_photo VARCHAR(255) NULL AFTER receiver_signature;
ALTER TABLE customer_orders ADD COLUMN delivery_notes TEXT NULL AFTER delivery_photo;
ALTER TABLE customer_orders ADD COLUMN delivery_lat DECIMAL(10,7) NULL AFTER delivery_notes;
ALTER TABLE customer_orders ADD COLUMN delivery_lng DECIMAL(10,7) NULL AFTER delivery_lat;
ALTER TABLE customer_orders ADD COLUMN assigned_at DATETIME NULL AFTER driver_name;
ALTER TABLE customer_orders ADD COLUMN assigned_by INT UNSIGNED NULL AFTER assigned_at;
ALTER TABLE customer_orders ADD COLUMN unassign_reason TEXT NULL AFTER assigned_by;
ALTER TABLE customer_orders ADD COLUMN failed_reason VARCHAR(100) NULL AFTER unassign_reason;
ALTER TABLE customer_orders ADD COLUMN failed_notes TEXT NULL AFTER failed_reason;
ALTER TABLE customer_orders ADD COLUMN rescheduled_date DATE NULL AFTER failed_notes;
ALTER TABLE customer_orders ADD COLUMN return_reason VARCHAR(100) NULL AFTER rescheduled_date;
ALTER TABLE customer_orders ADD COLUMN return_notes TEXT NULL AFTER return_reason;
ALTER TABLE customer_orders ADD COLUMN return_requested_at DATETIME NULL AFTER return_notes;
ALTER TABLE customer_orders ADD COLUMN return_approved_by INT UNSIGNED NULL AFTER return_requested_at;
ALTER TABLE customer_orders ADD COLUMN return_approved_at DATETIME NULL AFTER return_approved_by;

-- Driver profiles (standalone records; no login account required)
CREATE TABLE IF NOT EXISTS driver_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(150) NULL,
    phone VARCHAR(50) NULL,
    vehicle_type VARCHAR(100) NULL,
    vehicle_plate VARCHAR(50) NULL,
    license_no VARCHAR(100) NULL,
    availability ENUM('available','busy','on_delivery','offline','on_leave') DEFAULT 'offline',
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    total_deliveries INT UNSIGNED DEFAULT 0,
    successful_deliveries INT UNSIGNED DEFAULT 0,
    failed_deliveries INT UNSIGNED DEFAULT 0,
    avg_delivery_time_min INT UNSIGNED DEFAULT 0,
    rating DECIMAL(3,2) DEFAULT 5.00,
    current_lat DECIMAL(10,7) NULL,
    current_lng DECIMAL(10,7) NULL,
    last_location_update DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Delivery audit log
CREATE TABLE IF NOT EXISTS delivery_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    performed_by INT UNSIGNED NULL,
    notes TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Delivery returns
CREATE TABLE IF NOT EXISTS delivery_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    return_type ENUM('full','partial') DEFAULT 'full',
    reason VARCHAR(100) NOT NULL,
    notes TEXT NULL,
    status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
    requested_by INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    warehouse_received_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    completed_at DATETIME NULL,
    FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Delivery notifications
CREATE TABLE IF NOT EXISTS delivery_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','warning','success','error') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
