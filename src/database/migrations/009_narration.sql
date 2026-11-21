-- Narration text is computed from the immutable source blocks: cleaned, pronunciation rules applied, then these per-chapter edits.
CREATE TABLE IF NOT EXISTS narration_blocks (
    logical_section_id TEXT NOT NULL,
    source_block_id TEXT NOT NULL,
    excluded INTEGER NOT NULL DEFAULT 0,        -- 1 = this block is not narrated
    override_text TEXT,                          -- when set, narrated instead of the computed text
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (logical_section_id, source_block_id),
    FOREIGN KEY (logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE
);

-- Approval is tied to the exact narration text, so a dictionary or text change after approval is noticed.
CREATE TABLE IF NOT EXISTS chapter_approvals (
    logical_section_id TEXT PRIMARY KEY,
    narration_sha256 TEXT NOT NULL,
    approved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (logical_section_id) REFERENCES logical_sections(id) ON DELETE CASCADE
);
