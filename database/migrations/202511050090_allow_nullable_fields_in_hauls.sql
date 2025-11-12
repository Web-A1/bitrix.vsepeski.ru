ALTER TABLE hauls
    MODIFY truck_id CHAR(36) NULL,
    MODIFY material_id CHAR(36) NULL,
    MODIFY load_address_text TEXT NULL,
    MODIFY unload_address_text TEXT NULL;
