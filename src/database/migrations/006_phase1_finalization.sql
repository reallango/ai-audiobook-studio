PRAGMA foreign_keys=ON;
ALTER TABLE logical_section_sources ADD COLUMN start_block_index INTEGER;
ALTER TABLE logical_section_sources ADD COLUMN end_block_index INTEGER;
CREATE TABLE IF NOT EXISTS project_section_settings(
 project_id TEXT PRIMARY KEY,
 number_style TEXT NOT NULL DEFAULT 'preserve' CHECK(number_style IN('preserve','digits','words')),
 capitalization TEXT NOT NULL DEFAULT 'preserve' CHECK(capitalization IN('preserve','title','upper')),
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS logical_structure_revisions(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 project_id TEXT NOT NULL,
 operation TEXT NOT NULL,
 description TEXT NOT NULL,
 snapshot_json TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 undone_at TEXT,
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_structure_revisions_project ON logical_structure_revisions(project_id,id DESC);
INSERT OR IGNORE INTO schema_migrations(version) VALUES('006_phase1_finalization');
