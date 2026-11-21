PRAGMA foreign_keys = ON;
CREATE TABLE IF NOT EXISTS source_metadata (
 project_id TEXT PRIMARY KEY, title TEXT, creator TEXT, language TEXT, identifier TEXT,
 package_path TEXT NOT NULL, package_version TEXT, analyzed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS source_sections (
 id TEXT PRIMARY KEY, project_id TEXT NOT NULL, source_asset_id TEXT NOT NULL,
 spine_index INTEGER NOT NULL, manifest_id TEXT NOT NULL, href TEXT NOT NULL,
 media_type TEXT, linear INTEGER NOT NULL DEFAULT 1, title_hint TEXT,
 raw_sha256 TEXT NOT NULL, word_count INTEGER NOT NULL DEFAULT 0,
 block_count INTEGER NOT NULL DEFAULT 0, warning_count INTEGER NOT NULL DEFAULT 0,
 initial_recommendation TEXT NOT NULL DEFAULT 'KEEP' CHECK(initial_recommendation IN ('KEEP','REVIEW')),
 UNIQUE(project_id, spine_index),
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 FOREIGN KEY(source_asset_id) REFERENCES source_assets(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS source_blocks (
 id TEXT PRIMARY KEY, project_id TEXT NOT NULL, source_section_id TEXT NOT NULL,
 block_index INTEGER NOT NULL, block_type TEXT NOT NULL, source_text TEXT NOT NULL,
 source_sha256 TEXT NOT NULL, dom_path TEXT NOT NULL,
 UNIQUE(source_section_id, block_index),
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 FOREIGN KEY(source_section_id) REFERENCES source_sections(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS import_warnings (
 id INTEGER PRIMARY KEY AUTOINCREMENT, project_id TEXT NOT NULL, source_section_id TEXT,
 warning_code TEXT NOT NULL, message TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 FOREIGN KEY(source_section_id) REFERENCES source_sections(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_source_sections_project_spine ON source_sections(project_id, spine_index);
CREATE INDEX IF NOT EXISTS idx_source_blocks_section_order ON source_blocks(source_section_id, block_index);
CREATE INDEX IF NOT EXISTS idx_import_warnings_project ON import_warnings(project_id, id);
INSERT OR IGNORE INTO schema_migrations(version) VALUES ('004_epub_analysis');
