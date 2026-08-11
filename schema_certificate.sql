ALTER TABLE visit_types
    ADD COLUMN trainer_name          VARCHAR(255) NULL AFTER quiz_pass_percent,
    ADD COLUMN trainer_qualification VARCHAR(255) NULL AFTER trainer_name,
    ADD COLUMN validity_days         INT UNSIGNED NULL AFTER trainer_qualification,
    ADD COLUMN show_position         TINYINT(1) NOT NULL DEFAULT 0 AFTER validity_days;

ALTER TABLE declarations
    ADD COLUMN position             VARCHAR(255) NULL AFTER company,
    ADD COLUMN training_valid_until DATE NULL AFTER quiz_passed;
