-- Background work other than chapter audio (building the chaptered M4B). The worker runs these after the chapter jobs.
CREATE TABLE IF NOT EXISTS background_tasks (
    id TEXT PRIMARY KEY,
    project_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'QUEUED' CHECK(status IN ('QUEUED','RUNNING','DONE','FAILED')),
    message TEXT NOT NULL DEFAULT '',
    requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TEXT,
    finished_at TEXT,
    heartbeat_at TEXT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_background_tasks_status ON background_tasks(status, requested_at);
CREATE INDEX IF NOT EXISTS idx_background_tasks_project ON background_tasks(project_id, requested_at DESC);
