-- Edroso Dental Clinic Database Schema
-- Run this in MySQL/phpMyAdmin to set up the database

CREATE DATABASE IF NOT EXISTS edroso_dental CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE edroso_dental;

-- Users / Admin table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('admin','staff') DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Dentists table
CREATE TABLE IF NOT EXISTS dentists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    specialization VARCHAR(255),
    email VARCHAR(150),
    phone VARCHAR(30),
    status ENUM('active','inactive') DEFAULT 'active',
    satisfaction_rate INT DEFAULT 95,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Patients table
CREATE TABLE IF NOT EXISTS patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_number VARCHAR(20) UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150),
    phone VARCHAR(30),
    date_of_birth DATE,
    gender ENUM('Male','Female','Other'),
    address TEXT,
    medical_notes TEXT,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Appointments table
CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    dentist_id INT NOT NULL,
    procedure_name VARCHAR(150) NOT NULL,
    procedure_type ENUM('cleaning','rootcanal','extraction','filling','crown','whitening','other') DEFAULT 'other',
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    duration_minutes INT DEFAULT 30,
    room VARCHAR(50),
    status ENUM('Scheduled','Confirmed','In Progress','Completed','Cancelled') DEFAULT 'Scheduled',
    notes TEXT,
    internal_change_reason VARCHAR(64) NULL DEFAULT NULL,
    slot_modified_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_appointment_date (appointment_date),
    INDEX idx_status (status),
    INDEX idx_date_status (appointment_date, status),
    INDEX idx_slot (dentist_id, appointment_date, appointment_time),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE CASCADE
);

-- Global key/value settings (clinic profile, preferences, etc.)
CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(191) NOT NULL PRIMARY KEY,
    `value` TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Treatment / service catalog (pricing)
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    required_specialization VARCHAR(100) NULL DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT,
    patient_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash',
    status ENUM('Pending','Due','Paid','Partial','Refunded') DEFAULT 'Pending',
    description TEXT,
    payment_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

-- Weekly working hours per dentist (day_of_week: 0 = Sunday … 6 = Saturday)
CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dentist_id INT NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '0=Sun … 6=Sat',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE CASCADE,
    INDEX idx_schedules_dentist_day (dentist_id, day_of_week),
    INDEX idx_schedules_active (dentist_id, is_active)
);

-- Clinic-wide (dentist_id NULL) or per-dentist unavailable dates / time ranges
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
);

CREATE TABLE IF NOT EXISTS blocked_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dentist_id INT NULL DEFAULT NULL,
    blocked_date DATE NOT NULL,
    start_time TIME NULL DEFAULT NULL,
    end_time TIME NULL DEFAULT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dentist_id) REFERENCES dentists(id) ON DELETE CASCADE,
    INDEX idx_blocked_date (blocked_date),
    INDEX idx_blocked_dentist_date (dentist_id, blocked_date)
);

-- Outbound patient communication log
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    appointment_id INT NULL DEFAULT NULL,
    type ENUM('confirmation','reminder','cancellation') NOT NULL,
    channel ENUM('email','sms') NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    sent_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    INDEX idx_notifications_patient (patient_id),
    INDEX idx_notifications_appointment (appointment_id),
    INDEX idx_notifications_status_created (status, created_at)
);

-- =====================
-- SEED DATA
-- =====================

-- Admin user (password: password — bcrypt below)
INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User', 'admin'),
('edroso', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Alex Edroso', 'admin'),
('staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Clinic Reception', 'staff');

-- Dentists
INSERT INTO dentists (name, specialization, email, phone, satisfaction_rate) VALUES
('Dr. Alex Edroso', 'General Dentist', 'alex@edrosoclinic.com', '09171234567', 95),
('Dr. Maria Edroso', 'Orthodontist', 'maria@edrosoclinic.com', '09179876543', 92);


-- Appointments (using today's date and nearby dates)
INSERT INTO appointments (patient_id, dentist_id, procedure_name, procedure_type, appointment_date, appointment_time, duration_minutes, room, status, notes) VALUES
(1, 1, 'Teeth Cleaning', 'cleaning', CURDATE(), '09:00:00', 30, 'Room 1', 'In Progress', 'Regular check-up and cleaning.'),
(2, 2, 'Root Canal', 'rootcanal', CURDATE(), '10:00:00', 60, 'Room 2', 'Confirmed', 'Patient reports severe tooth pain.'),
(3, 1, 'Teeth Cleaning', 'cleaning', CURDATE(), '11:15:00', 30, 'Room 3', 'Confirmed', 'Routine cleaning.'),
(4, 2, 'Tooth Extraction', 'extraction', CURDATE(), '13:30:00', 45, 'Room 1', 'Scheduled', 'Wisdom tooth extraction.'),
(5, 1, 'Dental Filling', 'filling', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:30:00', 45, 'Room 2', 'Confirmed', 'Cavity filling on lower right molar.'),
(6, 2, 'Root Canal', 'rootcanal', DATE_ADD(CURDATE(), INTERVAL 1 DAY), '11:00:00', 60, 'Room 3', 'Confirmed', 'Follow-up root canal.'),
(7, 1, 'Teeth Cleaning', 'cleaning', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '10:00:00', 30, 'Room 1', 'Scheduled', 'New patient, first visit.'),
(1, 2, 'Dental Crown', 'crown', DATE_ADD(CURDATE(), INTERVAL -3 DAY), '14:00:00', 90, 'Room 2', 'Completed', 'Crown placement completed.'),
(2, 1, 'Teeth Cleaning', 'cleaning', DATE_ADD(CURDATE(), INTERVAL -5 DAY), '09:00:00', 30, 'Room 3', 'Completed', 'Routine cleaning.'),
(8, 2, 'Tooth Extraction', 'extraction', DATE_ADD(CURDATE(), INTERVAL -2 DAY), '11:00:00', 45, 'Room 1', 'Completed', 'Lower molar extracted successfully.');

-- Payments
INSERT INTO payments (appointment_id, patient_id, amount, payment_method, status, description, payment_date) VALUES
(8, 1, 8500.00, 'GCash', 'Paid', 'Dental Crown', DATE_ADD(CURDATE(), INTERVAL -3 DAY)),
(9, 2, 1000.00, 'Cash', 'Paid', 'Teeth Cleaning', DATE_ADD(CURDATE(), INTERVAL -5 DAY)),
(10, 8, 3500.00, 'Credit Card', 'Paid', 'Tooth Extraction', DATE_ADD(CURDATE(), INTERVAL -2 DAY)),
(1, 1, 1000.00, 'Cash', 'Pending', 'Teeth Cleaning', NULL),
(2, 2, 12500.00, 'PhilHealth', 'Pending', 'Root Canal Treatment', NULL),
(3, 3, 1000.00, 'Cash', 'Pending', 'Teeth Cleaning', NULL),
(4, 4, 3500.00, 'GCash', 'Pending', 'Tooth Extraction', NULL);

-- Default settings (INSERT IGNORE keeps re-runs safe)
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('clinic_name', 'Edroso Dental Clinic'),
('dentist_name', 'Dr. Alex Edroso'),
('prc_license', ''),
('clinic_contact', '09171234567'),
('clinic_address', ''),
('clinic_hours', 'Mon–Sat 9:00 AM – 5:00 PM'),
('time_per_patient', '30'),
('walkin_limit', '10'),
('appointment_system_enabled', '1'),
('patient_record_preferences', '{"required_fields":["name","contact"],"notes_format":"free_text"}'),
('billing_preferences', '{"payment_types":["Cash","GCash","Bank Transfer","PhilHealth","HMO"],"down_payment_enabled":false,"down_payment_percent":0,"receipt_format":"simple"}'),
('portal_referral_sources', '["Facebook","Instagram","TikTok","Referral","Walk-in","Other"]'),
('auto_logout_minutes', '30');

INSERT IGNORE INTO services (id, name, required_specialization, price, active) VALUES
(1, 'Oral Prophylaxis', 'General Dentist', 800.00, 1),
(2, 'Extraction', 'General Dentist', 3500.00, 1),
(3, 'Filling (Pasta)', 'General Dentist', 1500.00, 1),
(4, 'Braces Adjustment', 'Orthodontist', 2500.00, 1),
(5, 'Whitening', 'General Dentist', 5000.00, 1);

-- Default weekly availability (Mon–Sat 9–17, 30 min) for seeded dentists
INSERT IGNORE INTO dentist_schedules (dentist_id, day_of_week, start_time, end_time, slot_duration_minutes, is_active) VALUES
(1, 'Monday', '09:00:00', '17:00:00', 30, 1),
(1, 'Tuesday', '09:00:00', '17:00:00', 30, 1),
(1, 'Wednesday', '09:00:00', '17:00:00', 30, 1),
(1, 'Thursday', '09:00:00', '17:00:00', 30, 1),
(1, 'Friday', '09:00:00', '17:00:00', 30, 1),
(1, 'Saturday', '09:00:00', '17:00:00', 30, 1),
(2, 'Monday', '09:00:00', '17:00:00', 30, 1),
(2, 'Tuesday', '09:00:00', '17:00:00', 30, 1),
(2, 'Wednesday', '09:00:00', '17:00:00', 30, 1),
(2, 'Thursday', '09:00:00', '17:00:00', 30, 1),
(2, 'Friday', '09:00:00', '17:00:00', 30, 1),
(2, 'Saturday', '09:00:00', '17:00:00', 30, 1);

-- ---------------------------------------------------------------------------
-- Upgrading an existing database (run manually in phpMyAdmin if needed):
-- ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'Cash';
-- Then create `settings` and `services` tables as above (or open Settings once; APIs create missing tables).
-- ---------------------------------------------------------------------------
