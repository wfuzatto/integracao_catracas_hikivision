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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_res_group FOREIGN KEY (group_id) REFERENCES guest_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_res_access FOREIGN KEY (access_level_id) REFERENCES access_levels(id) ON DELETE SET NULL,
    INDEX idx_res_dates (entry_at, exit_at),
    INDEX idx_res_status (status),
    INDEX idx_res_name (last_name, first_name)
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
