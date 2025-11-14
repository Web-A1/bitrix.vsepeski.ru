ALTER TABLE hauls
    DROP FOREIGN KEY hauls_material_fk,
    DROP INDEX hauls_material_id_index,
    MODIFY material_id VARCHAR(64) NOT NULL;

DROP TABLE IF EXISTS materials;
