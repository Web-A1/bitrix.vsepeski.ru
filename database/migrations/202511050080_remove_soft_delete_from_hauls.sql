DELETE FROM hauls WHERE deleted_at IS NOT NULL;

ALTER TABLE hauls
    DROP COLUMN deleted_at;
