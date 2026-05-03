-- Run once in phpMyAdmin if you prefer manual migration (api/patient_auth.php also adds these columns automatically).
ALTER TABLE portal_users ADD COLUMN recovery_question VARCHAR(255) NULL;
ALTER TABLE portal_users ADD COLUMN recovery_answer_hash VARCHAR(255) NULL;
