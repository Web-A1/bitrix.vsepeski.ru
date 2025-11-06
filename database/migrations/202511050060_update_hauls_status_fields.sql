ALTER TABLE hauls
    ADD COLUMN status TINYINT NOT NULL DEFAULT 0 AFTER sequence,
    ADD COLUMN general_notes TEXT NULL AFTER material_id,
    ADD COLUMN load_actual_volume DECIMAL(12, 2) NULL AFTER load_volume,
    ADD COLUMN unload_acceptance_time VARCHAR(160) NULL AFTER unload_contact_phone;

CREATE TABLE IF NOT EXISTS haul_status_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    haul_id CHAR(36) NOT NULL,
    status TINYINT NOT NULL,
    changed_by BIGINT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT haul_status_events_haul_fk FOREIGN KEY (haul_id) REFERENCES hauls (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX haul_status_events_haul_index ON haul_status_events (haul_id);
CREATE INDEX haul_status_events_status_index ON haul_status_events (status);
