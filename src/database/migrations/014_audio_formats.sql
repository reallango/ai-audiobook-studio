-- Chapter audio is now an AAC (m4a) file at the project's bitrate; the joined WAV is only an intermediate.
ALTER TABLE chapter_audio ADD COLUMN format TEXT NOT NULL DEFAULT 'wav';
ALTER TABLE chapter_audio ADD COLUMN bitrate_kbps INTEGER NOT NULL DEFAULT 0;

-- Whole-book files built from the chapter audio (a chaptered m4b).
CREATE TABLE IF NOT EXISTS project_exports (
    project_id TEXT NOT NULL,
    kind TEXT NOT NULL,                          -- 'm4b'
    relpath TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    chapter_count INTEGER NOT NULL,              -- chapters found in the finished file
    duration_ms INTEGER NOT NULL DEFAULT 0,
    signature TEXT NOT NULL,                     -- which chapter audio it was built from
    built_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, kind),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
