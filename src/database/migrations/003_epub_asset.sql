PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS source_assets (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    stored_relpath TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    media_type TEXT NOT NULL DEFAULT 'application/epub+zip',
    zip_entries INTEGER NOT NULL DEFAULT 0,
    has_mimetype_entry INTEGER NOT NULL DEFAULT 0,
    has_container_entry INTEGER NOT NULL DEFAULT 0,
    imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_source_assets_project
ON source_assets(project_id);

INSERT OR IGNORE INTO schema_migrations(version)
VALUES ('003_epub_asset');
