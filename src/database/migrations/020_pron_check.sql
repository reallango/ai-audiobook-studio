-- Pronunciations looked up online: answers are cached so a word is fetched once (found or not), and each book keeps
-- what was found for its words until a rule is made or the word is dismissed.
CREATE TABLE IF NOT EXISTS pron_lookup_cache (
    cache_key TEXT PRIMARY KEY,
    result_json TEXT NOT NULL,
    fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pron_checks (
    project_id TEXT NOT NULL,
    word TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL CHECK(status IN ('found','none','error')),
    ipa TEXT NOT NULL DEFAULT '',
    sounds TEXT NOT NULL DEFAULT '',          -- what Kokoro reads (its sound set)
    respelling TEXT NOT NULL DEFAULT '',
    lang TEXT NOT NULL DEFAULT '',
    confidence TEXT NOT NULL DEFAULT '',      -- high | medium | low
    source_title TEXT NOT NULL DEFAULT '',
    source_url TEXT NOT NULL DEFAULT '',
    note TEXT NOT NULL DEFAULT '',
    dismissed INTEGER NOT NULL DEFAULT 0,
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, word),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);

-- background tasks can carry settings (which kinds of words to check)
ALTER TABLE background_tasks ADD COLUMN params TEXT NOT NULL DEFAULT '';
