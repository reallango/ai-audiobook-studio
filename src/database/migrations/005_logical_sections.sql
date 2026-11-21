PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS logical_sections(
 id TEXT PRIMARY KEY,project_id TEXT NOT NULL,position INTEGER NOT NULL,title TEXT NOT NULL,
 included INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(project_id,position),
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS logical_section_sources(
 logical_section_id TEXT NOT NULL,source_section_id TEXT NOT NULL,source_order INTEGER NOT NULL,
 PRIMARY KEY(logical_section_id,source_section_id),UNIQUE(logical_section_id,source_order),
 FOREIGN KEY(logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE,
 FOREIGN KEY(source_section_id) REFERENCES source_sections(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_logical_sections_project_position ON logical_sections(project_id,position);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('005_logical_sections');
