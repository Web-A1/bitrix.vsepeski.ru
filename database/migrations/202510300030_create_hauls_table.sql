CREATE TABLE IF NOT EXISTS hauls (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    deal_id BIGINT NOT NULL,
    responsible_id BIGINT NULL,
    truck_id CHAR(36) NOT NULL,
    material_id CHAR(36) NOT NULL,
    sequence INT NOT NULL DEFAULT 1,

    load_address_text TEXT NOT NULL,
    load_address_url TEXT NULL,
    load_from_company_id BIGINT NULL,
    load_to_company_id BIGINT NULL,
    load_volume DECIMAL(12, 2) NULL,
    load_documents JSON NOT NULL DEFAULT (JSON_ARRAY()),

    unload_address_text TEXT NOT NULL,
    unload_address_url TEXT NULL,
    unload_from_company_id BIGINT NULL,
    unload_to_company_id BIGINT NULL,
    unload_contact_name VARCHAR(160) NULL,
    unload_contact_phone VARCHAR(40) NULL,
    unload_documents JSON NOT NULL DEFAULT (JSON_ARRAY()),

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    CONSTRAINT hauls_truck_fk FOREIGN KEY (truck_id) REFERENCES trucks (id) ON DELETE RESTRICT,
    CONSTRAINT hauls_material_fk FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX hauls_deal_id_index ON hauls (deal_id);
CREATE INDEX hauls_truck_id_index ON hauls (truck_id);
CREATE INDEX hauls_material_id_index ON hauls (material_id);
CREATE UNIQUE INDEX hauls_deal_sequence_unique ON hauls (deal_id, sequence);

