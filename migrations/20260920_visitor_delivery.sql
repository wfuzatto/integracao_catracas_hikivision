USE visitor_app;
ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS hcp_registration_id VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS hcp_assigned_level_id VARCHAR(190) NULL,
    ADD COLUMN IF NOT EXISTS hcp_delivery_state VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS hcp_delivery_report LONGTEXT NULL,
    ADD COLUMN IF NOT EXISTS hcp_delivery_verified_at DATETIME NULL;

