PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS projects (
    id TEXT PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'NEW',
    active_provider_profile_id TEXT,
    active_voice_profile_id TEXT,
    audio_bitrate_kbps INTEGER NOT NULL DEFAULT 96,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT
);

CREATE TABLE IF NOT EXISTS jobs (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    job_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'QUEUED',
    progress_current INTEGER NOT NULL DEFAULT 0,
    progress_total INTEGER,
    stage_label TEXT,
    payload_json TEXT NOT NULL DEFAULT '{}',
    result_json TEXT,
    error_code TEXT,
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TEXT,
    finished_at TEXT,
    heartbeat_at TEXT,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at);
INSERT OR IGNORE INTO schema_migrations(version) VALUES ('001_initial');
