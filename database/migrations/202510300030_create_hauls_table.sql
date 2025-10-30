CREATE TABLE IF NOT EXISTS hauls (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    deal_id BIGINT NOT NULL,
    responsible_id BIGINT,
    truck_id UUID NOT NULL REFERENCES trucks (id),
    material_id UUID NOT NULL REFERENCES materials (id),
    sequence INTEGER NOT NULL DEFAULT 1,

    load_address_text TEXT NOT NULL,
    load_address_url TEXT,
    load_from_company_id BIGINT,
    load_to_company_id BIGINT,
    load_volume NUMERIC(12, 2),
    load_documents JSONB NOT NULL DEFAULT '[]'::jsonb,

    unload_address_text TEXT NOT NULL,
    unload_address_url TEXT,
    unload_from_company_id BIGINT,
    unload_to_company_id BIGINT,
    unload_contact_name VARCHAR(160),
    unload_contact_phone VARCHAR(40),
    unload_documents JSONB NOT NULL DEFAULT '[]'::jsonb,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS hauls_deal_id_index ON hauls (deal_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS hauls_truck_id_index ON hauls (truck_id) WHERE deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS hauls_material_id_index ON hauls (material_id) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS hauls_deal_sequence_unique ON hauls (deal_id, sequence) WHERE deleted_at IS NULL;

