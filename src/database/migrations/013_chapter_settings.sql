-- Optional per-chapter voice and speed. Empty means the project's setting applies.
CREATE TABLE IF NOT EXISTS chapter_settings (
    logical_section_id TEXT PRIMARY KEY,
    voice TEXT,
    speed REAL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE
);
