ALTER TABLE hauls
    ADD COLUMN leg_distance_km DECIMAL(8, 2) NULL AFTER load_actual_volume;

CREATE TABLE IF NOT EXISTS haul_change_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    haul_id CHAR(36) NOT NULL,
    field VARCHAR(120) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    changed_by_id BIGINT NULL,
    changed_by_name VARCHAR(190) NULL,
    actor_role VARCHAR(32) NOT NULL DEFAULT 'manager',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT haul_change_events_haul_fk FOREIGN KEY (haul_id) REFERENCES hauls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX haul_change_events_haul_index ON haul_change_events (haul_id);
CREATE INDEX haul_change_events_field_index ON haul_change_events (field);
