-- Which accent a book's online pronunciation check prefers: 'americas' (US English, Latin American Spanish, Quebec
-- French...) or 'europe' (British English, Spain, France...).
ALTER TABLE projects ADD COLUMN pron_region TEXT NOT NULL DEFAULT 'americas';

-- what a check found also records the accent it chose, and whether the word is a dictionary word rather than a name
ALTER TABLE pron_checks ADD COLUMN accent TEXT NOT NULL DEFAULT '';
ALTER TABLE pron_checks ADD COLUMN common INTEGER NOT NULL DEFAULT 0;
