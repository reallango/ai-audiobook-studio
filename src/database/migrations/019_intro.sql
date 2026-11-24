-- An optional spoken introduction at the start of the audiobook: title, author, how it was made, personal use only.
ALTER TABLE projects ADD COLUMN intro_enabled INTEGER NOT NULL DEFAULT 0;
ALTER TABLE projects ADD COLUMN intro_text TEXT;

-- The introduction's audio, made once for a given text, voice, speed and quality.
CREATE TABLE IF NOT EXISTS project_intro (
    project_id TEXT PRIMARY KEY,
    signature TEXT NOT NULL,
    relpath TEXT NOT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
