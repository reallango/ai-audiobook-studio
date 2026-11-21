PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS projects (
    id TEXT PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'NEW' CHECK(status IN ('NEW','IMPORTED','SECTION_REVIEW','TEXT_REVIEW','READY_FOR_AUDIO','AUDIO_PARTIAL','AUDIO_COMPLETE','ARCHIVED','ERROR')),
    active_provider_profile_id TEXT,
    active_voice_profile_id TEXT,
    audio_bitrate_kbps INTEGER NOT NULL DEFAULT 96 CHECK(audio_bitrate_kbps BETWEEN 32 AND 320),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_projects_status_updated ON projects(status, updated_at DESC);
INSERT OR IGNORE INTO schema_migrations(version) VALUES ('002_project_management');
