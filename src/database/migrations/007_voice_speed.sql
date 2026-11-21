-- Narration speed is a per-project setting (voice already lives in projects.active_voice_profile_id).
ALTER TABLE projects ADD COLUMN tts_speed REAL NOT NULL DEFAULT 1.0;
