-- Centralized Workflow Engine
-- Creates status_history table for audit logging of all status transitions

CREATE TABLE IF NOT EXISTS status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(50) DEFAULT NULL,
    to_status VARCHAR(50) NOT NULL,
    changed_by INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wh_entity (workflow, entity_id),
    INDEX idx_wh_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
