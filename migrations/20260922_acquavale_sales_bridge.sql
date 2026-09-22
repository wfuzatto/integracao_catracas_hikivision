USE visitor_app;

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS source_system VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS source_order_code VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS source_ticket_code VARCHAR(64) NULL;

SET @has_source_ticket_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservations' AND INDEX_NAME='uq_res_source_ticket'
);
SET @sql := IF(@has_source_ticket_idx=0,
    'ALTER TABLE reservations ADD UNIQUE KEY uq_res_source_ticket (source_system,source_ticket_code)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS acquavale_import_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(64) NOT NULL UNIQUE,
    remote_order_id VARCHAR(64) NULL,
    claim_token CHAR(64) NOT NULL,
    consumer VARCHAR(100) NOT NULL DEFAULT 'vale-visitor',
    buyer_email VARCHAR(190) NULL,
    buyer_phone VARCHAR(40) NULL,
    total DECIMAL(12,2) NULL,
    paid_at DATETIME NULL,
    reservation_code VARCHAR(100) NULL,
    payload_json LONGTEXT NOT NULL,
    state ENUM('received','processing','confirmed','failed','acked') NOT NULL DEFAULT 'received',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    last_error TEXT NULL,
    received_at DATETIME NOT NULL,
    acked_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_aqv_import_state(state,last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acquavale_import_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_order_id BIGINT UNSIGNED NOT NULL,
    ticket_code VARCHAR(64) NOT NULL UNIQUE,
    source_visitor_id VARCHAR(64) NULL,
    product_name VARCHAR(190) NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NOT NULL,
    photo_url TEXT NULL,
    photo_path VARCHAR(255) NULL,
    photo_sha256 CHAR(64) NULL,
    photo_mime_type VARCHAR(80) NULL,
    photo_size INT UNSIGNED NULL,
    local_reservation_id BIGINT UNSIGNED NULL,
    state ENUM('received','processing','photo_saved','local_created','syncing','confirmed','failed','acked') NOT NULL DEFAULT 'received',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NULL,
    last_error TEXT NULL,
    hcp_delivery_report LONGTEXT NULL,
    confirmed_at DATETIME NULL,
    acked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_aqv_import_ticket_order FOREIGN KEY(import_order_id) REFERENCES acquavale_import_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_aqv_import_ticket_reservation FOREIGN KEY(local_reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
    INDEX idx_aqv_ticket_state(state,last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acquavale_webhook_deliveries (
    delivery_id VARCHAR(128) PRIMARY KEY,
    import_order_id BIGINT UNSIGNED NOT NULL,
    received_at DATETIME NOT NULL,
    CONSTRAINT fk_aqv_delivery_order FOREIGN KEY(import_order_id) REFERENCES acquavale_import_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
