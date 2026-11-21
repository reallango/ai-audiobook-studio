-- ALL-CAPS words are spelled out by Kokoro. By default they are turned into normal words (STARBASE -> Starbase);
-- a pronunciation rule for the word always wins. This switches the behaviour off for a project.
ALTER TABLE projects ADD COLUMN normalize_caps INTEGER NOT NULL DEFAULT 1;
