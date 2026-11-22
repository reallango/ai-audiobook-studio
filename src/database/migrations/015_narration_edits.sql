-- Hand edits to a paragraph's narration, saved as changes to specific words of the SOURCE text (not as a frozen copy of
-- the result). Other pronunciation rules keep applying around them, so an edit survives later rule changes.
CREATE TABLE IF NOT EXISTS narration_edits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    logical_section_id TEXT NOT NULL,
    source_block_id TEXT NOT NULL,
    src_start INTEGER NOT NULL,                  -- byte offset in the cleaned source paragraph
    src_len INTEGER NOT NULL,                    -- 0 for an insertion
    src TEXT NOT NULL,                           -- the source words replaced (checked before applying)
    dst TEXT NOT NULL,                           -- what is narrated instead
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_narration_edits_block ON narration_edits(logical_section_id, source_block_id, src_start);
