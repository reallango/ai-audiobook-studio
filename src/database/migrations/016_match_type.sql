-- How a rule's written form is matched: 'word' (whole word, possessive included), 'anywhere' (also inside words) or 'regex'.
-- whole_word stays in step (1 only for 'word') so older code keeps working.
ALTER TABLE pronunciation_rules ADD COLUMN match_type TEXT NOT NULL DEFAULT 'word';
UPDATE pronunciation_rules SET match_type = CASE WHEN whole_word = 1 THEN 'word' ELSE 'anywhere' END;
