SET NAMES utf8mb4;

CREATE TABLE visit_types (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_hu            VARCHAR(255) NOT NULL,
    name_en            VARCHAR(255) NOT NULL DEFAULT '',
    video_path         VARCHAR(255) NULL,
    doc_title_hu       VARCHAR(255) NOT NULL DEFAULT '',
    doc_title_en       VARCHAR(255) NOT NULL DEFAULT '',
    doc_content_hu     LONGTEXT NULL,
    doc_content_en     LONGTEXT NULL,
    quiz_pass_percent  TINYINT UNSIGNED NOT NULL DEFAULT 80,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    sort_order         INT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_questions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_type_id  INT UNSIGNED NOT NULL,
    question_hu    TEXT NOT NULL,
    question_en    TEXT NOT NULL,
    sort_order     INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_quiz_questions_visit_type (visit_type_id),
    CONSTRAINT fk_quiz_questions_visit_type
        FOREIGN KEY (visit_type_id) REFERENCES visit_types(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_options (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id   INT UNSIGNED NOT NULL,
    option_hu     VARCHAR(500) NOT NULL,
    option_en     VARCHAR(500) NOT NULL,
    is_correct    TINYINT(1) NOT NULL DEFAULT 0,
    sort_order    INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_quiz_options_question (question_id),
    CONSTRAINT fk_quiz_options_question
        FOREIGN KEY (question_id) REFERENCES quiz_questions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE declarations
    ADD COLUMN visit_type_id INT UNSIGNED NULL AFTER contact,
    ADD COLUMN quiz_score    INT UNSIGNED NULL AFTER visit_type_id,
    ADD COLUMN quiz_total    INT UNSIGNED NULL AFTER quiz_score,
    ADD COLUMN quiz_passed   TINYINT(1) NULL AFTER quiz_total,
    ADD KEY idx_declarations_visit_type (visit_type_id),
    ADD CONSTRAINT fk_declarations_visit_type
        FOREIGN KEY (visit_type_id) REFERENCES visit_types(id)
        ON DELETE SET NULL;
