-- Background generation. One job per chapter; the worker resumes unfinished jobs after a restart.
CREATE TABLE IF NOT EXISTS generation_jobs (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    logical_section_id TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'QUEUED' CHECK(status IN ('QUEUED','RUNNING','DONE','FAILED','CANCELLED')),
    message TEXT NOT NULL DEFAULT '',
    requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TEXT,
    finished_at TEXT,
    heartbeat_at TEXT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_generation_jobs_status ON generation_jobs(status, requested_at);
