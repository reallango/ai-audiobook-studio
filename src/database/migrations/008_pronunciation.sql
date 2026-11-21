-- Layered pronunciation rules. Precedence, lowest to highest: default < voice < series set < project.
CREATE TABLE IF NOT EXISTS pronunciation_sets (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pronunciation_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope TEXT NOT NULL CHECK(scope IN ('default','voice','set','project')),
    scope_ref TEXT NOT NULL DEFAULT '',          -- voice name, set id or project id; empty for default
    pattern TEXT NOT NULL,
    replacement TEXT NOT NULL,
    case_sensitive INTEGER NOT NULL DEFAULT 0,
    whole_word INTEGER NOT NULL DEFAULT 1,
    enabled INTEGER NOT NULL DEFAULT 1,
    note TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(scope, scope_ref, pattern)
);
CREATE INDEX IF NOT EXISTS idx_pron_rules_layer ON pronunciation_rules(scope, scope_ref);

-- A series set is linked to a project, not copied: editing the set changes every book that uses it.
CREATE TABLE IF NOT EXISTS project_pronunciation_sets (
    project_id TEXT NOT NULL,
    set_id TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,        -- a later set overrides an earlier one
    PRIMARY KEY (project_id, set_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (set_id) REFERENCES pronunciation_sets(id) ON DELETE CASCADE
);
