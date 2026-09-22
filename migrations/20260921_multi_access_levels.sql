USE visitor_app;

CREATE TABLE IF NOT EXISTS reservation_access_levels (
    reservation_id BIGINT UNSIGNED NOT NULL,
    access_level_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (reservation_id, access_level_id),
    CONSTRAINT fk_ral_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ral_access FOREIGN KEY (access_level_id) REFERENCES access_levels(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO reservation_access_levels (reservation_id, access_level_id)
SELECT id, access_level_id FROM reservations WHERE access_level_id IS NOT NULL;
