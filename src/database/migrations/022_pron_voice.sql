-- What the pronunciation checker says about each word: how the voice reads it now and how close that is to the
-- pronunciation found online (0-100), and a plain spelling the voice reads closer, with its score.
ALTER TABLE pron_checks ADD COLUMN voice_reads TEXT NOT NULL DEFAULT '';
ALTER TABLE pron_checks ADD COLUMN voice_score REAL;
ALTER TABLE pron_checks ADD COLUMN alt_spelling TEXT NOT NULL DEFAULT '';
ALTER TABLE pron_checks ADD COLUMN alt_reads TEXT NOT NULL DEFAULT '';
ALTER TABLE pron_checks ADD COLUMN alt_score REAL;

-- the score a book counts as "the voice already says it"
ALTER TABLE projects ADD COLUMN pron_minimum INTEGER NOT NULL DEFAULT 85;
