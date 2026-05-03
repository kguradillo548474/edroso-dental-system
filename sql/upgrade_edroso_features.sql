-- Edroso Dental — consolidated schema upgrades (run once on existing DB)
-- Order-safe: uses IF NOT EXISTS / checks where possible

USE edroso_dental;

-- Feature 1: fast slot conflict lookups
ALTER TABLE appointments
    ADD INDEX idx_slot (dentist_id, appointment_date, appointment_time);

-- Feature 2: procedure → required dentist specialization
ALTER TABLE services
    ADD COLUMN required_specialization VARCHAR(100) NULL DEFAULT NULL AFTER name;

UPDATE services SET required_specialization = 'Orthodontist'
  WHERE required_specialization IS NULL AND (
    LOWER(name) LIKE '%brace%' OR LOWER(name) LIKE '%alignment%' OR LOWER(name) LIKE '%orthodont%'
  );
UPDATE services SET required_specialization = 'General Dentist'
  WHERE required_specialization IS NULL AND (
    LOWER(name) LIKE '%filling%' OR LOWER(name) LIKE '%extraction%' OR LOWER(name) LIKE '%prophylaxis%'
    OR LOWER(name) LIKE '%clean%' OR LOWER(name) LIKE '%pasta%'
  );
UPDATE services SET required_specialization = 'Endodontist'
  WHERE required_specialization IS NULL AND (
    LOWER(name) LIKE '%root canal%' OR LOWER(name) LIKE '%rootcanal%' OR LOWER(name) LIKE '%endodont%'
  );
UPDATE services SET required_specialization = 'Periodontist'
  WHERE required_specialization IS NULL AND (
    LOWER(name) LIKE '%gum%' OR LOWER(name) LIKE '%periodont%'
  );
UPDATE services SET required_specialization = 'Pediatric Dentist'
  WHERE required_specialization IS NULL AND (
    LOWER(name) LIKE '%pediatric%' OR LOWER(name) LIKE '%child%'
  );
UPDATE services SET required_specialization = 'General Dentist'
  WHERE required_specialization IS NULL;

-- Example: align seed dentists to canonical specialization labels (adjust IDs if needed)
UPDATE dentists SET specialization = 'General Dentist'
  WHERE specialization IS NULL OR TRIM(specialization) = '' OR LOWER(specialization) LIKE '%general%';
UPDATE dentists SET specialization = 'Orthodontist'
  WHERE LOWER(COALESCE(specialization, '')) LIKE '%orthodont%';
UPDATE dentists SET specialization = 'General Dentist'
  WHERE specialization NOT IN (
    'General Dentist','Orthodontist','Pediatric Dentist','Endodontist','Periodontist'
  );

-- Feature 5: payment status for completed visits
ALTER TABLE payments
    MODIFY COLUMN status ENUM('Pending','Due','Paid','Partial','Refunded') NOT NULL DEFAULT 'Pending';

-- Feature 3C: password reset tokens
CREATE TABLE IF NOT EXISTS reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    portal_user_id INT NOT NULL,
    token VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_user (portal_user_id),
    INDEX idx_reset_token (token),
    FOREIGN KEY (portal_user_id) REFERENCES portal_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Feature 6: per-dentist weekly templates (source for availability slots)
CREATE TABLE IF NOT EXISTS dentist_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dentist_id INT NOT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_duration_minutes INT NOT NULL DEFAULT 30,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE CASCADE,
    UNIQUE KEY unique_dentist_day (dentist_id, day_of_week),
    INDEX idx_dentist_sched (dentist_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portal bookings: speed conflict SELECT FOR UPDATE
ALTER TABLE patient_appointments
    ADD INDEX idx_portal_slot (dentist_id, preferred_date, preferred_time);
