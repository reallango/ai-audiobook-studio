-- Generation segments of a chapter, and the chapter audio built from them.
-- A segment belongs to the exact text, voice and speed it was prepared with, so unchanged segments are reused.
CREATE TABLE IF NOT EXISTS segments (
    id TEXT PRIMARY KEY,
    logical_section_id TEXT NOT NULL,
    position INTEGER NOT NULL,
    text TEXT NOT NULL,
    text_sha256 TEXT NOT NULL,
    gap_ms INTEGER NOT NULL DEFAULT 250,         -- pause after this segment
    voice TEXT NOT NULL,
    speed REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING','GENERATING','DONE','FAILED')),
    attempts INTEGER NOT NULL DEFAULT 0,
    claimed_at TEXT,
    audio_relpath TEXT,                          -- relative to storage/projects
    duration_ms INTEGER,
    error TEXT,
    generated_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (logical_section_id, position),
    FOREIGN KEY (logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_segments_chapter_status ON segments(logical_section_id, status);

CREATE TABLE IF NOT EXISTS chapter_audio (
    logical_section_id TEXT PRIMARY KEY,
    audio_relpath TEXT NOT NULL,
    duration_ms INTEGER NOT NULL,
    byte_size INTEGER NOT NULL,
    segment_signature TEXT NOT NULL,             -- changes when any segment, voice, speed or pause changes
    built_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE
);
