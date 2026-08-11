ALTER TABLE visit_types
    ADD COLUMN show_company TINYINT(1) NOT NULL DEFAULT 1 AFTER show_position,
    ADD COLUMN show_contact TINYINT(1) NOT NULL DEFAULT 1 AFTER show_company;

ALTER TABLE declarations
    MODIFY COLUMN contact VARCHAR(255) NULL;
