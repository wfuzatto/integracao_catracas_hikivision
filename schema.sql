CREATE DATABASE IF NOT EXISTS visitor_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE visitor_app;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO app_settings (setting_key,setting_value) VALUES ('auto_checkout_enabled','0');

CREATE TABLE IF NOT EXISTS guest_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS access_levels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    group_id INT UNSIGNED NULL,
    access_level_id INT UNSIGNED NULL,
    entry_at DATETIME NOT NULL,
    exit_at DATETIME NOT NULL,
    document_type ENUM('CPF','RG','CNH','OUTRO') NULL,
    document_number VARCHAR(80) NULL,
    gender ENUM('F','M','N') NULL,
    photo_path VARCHAR(255) NULL,
    qr_token CHAR(48) NOT NULL UNIQUE,
    qr_payload VARCHAR(255) NOT NULL,
    status ENUM('PENDING','SENT','ACTIVE','ERROR','CANCELLED') NOT NULL DEFAULT 'PENDING',
    visitor_flow_status ENUM('REGISTERED','CHECKED_IN') NOT NULL DEFAULT 'REGISTERED',
    hcp_reference VARCHAR(190) NULL,
    hcp_visitor_id VARCHAR(190) NULL,
    hcp_appoint_code VARCHAR(190) NULL,
    hcp_qr_image_path VARCHAR(255) NULL,
    hcp_last_error TEXT NULL,
    hcp_registration_id VARCHAR(190) NULL,
    hcp_assigned_level_id VARCHAR(190) NULL,
    hcp_delivery_state VARCHAR(24) NULL,
    hcp_delivery_report LONGTEXT NULL,
    hcp_delivery_verified_at DATETIME NULL,
    hcp_checkout_state VARCHAR(24) NULL,
    hcp_checkout_report LONGTEXT NULL,
    hcp_checkout_at DATETIME NULL,
    hcp_visit_state VARCHAR(32) NULL,
    hcp_visit_status_checked_at DATETIME NULL,
    source_system VARCHAR(60) NULL,
    source_order_code VARCHAR(64) NULL,
    source_ticket_code VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_res_group FOREIGN KEY (group_id) REFERENCES guest_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_res_access FOREIGN KEY (access_level_id) REFERENCES access_levels(id) ON DELETE SET NULL,
    INDEX idx_res_dates (entry_at, exit_at),
    INDEX idx_res_status (status),
    INDEX idx_res_name (last_name, first_name),
    UNIQUE KEY uq_res_source_ticket (source_system, source_ticket_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_access_levels (
    reservation_id BIGINT UNSIGNED NOT NULL,
    access_level_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (reservation_id, access_level_id),
    CONSTRAINT fk_ral_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ral_access FOREIGN KEY (access_level_id) REFERENCES access_levels(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO guest_groups (name) VALUES
    ('Hospedes Vale'), ('Hóspedes Serra'), ('Day use');

INSERT IGNORE INTO access_levels (name) VALUES
    ('Day use'), ('Hóspede Vale'), ('Hóspede Serra'),
    ('ENTRADA ACQUAVALE'), ('SAIDA ACQUAVALE');


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
