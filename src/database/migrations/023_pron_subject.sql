-- What the book is about ("Star Trek"), searched first for names, before same-spelled words in other languages.
ALTER TABLE projects ADD COLUMN pron_subject TEXT NOT NULL DEFAULT '';
