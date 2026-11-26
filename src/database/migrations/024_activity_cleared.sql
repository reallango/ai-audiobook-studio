-- finished work someone has cleared from the Activity list
ALTER TABLE generation_jobs ADD COLUMN cleared INTEGER NOT NULL DEFAULT 0;
ALTER TABLE background_tasks ADD COLUMN cleared INTEGER NOT NULL DEFAULT 0;
