ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS hcp_checkout_state VARCHAR(24) NULL AFTER hcp_delivery_verified_at,
    ADD COLUMN IF NOT EXISTS hcp_checkout_report LONGTEXT NULL AFTER hcp_checkout_state,
    ADD COLUMN IF NOT EXISTS hcp_checkout_at DATETIME NULL AFTER hcp_checkout_report,
    ADD COLUMN IF NOT EXISTS hcp_visit_state VARCHAR(32) NULL AFTER hcp_checkout_at,
    ADD COLUMN IF NOT EXISTS hcp_visit_status_checked_at DATETIME NULL AFTER hcp_visit_state;
