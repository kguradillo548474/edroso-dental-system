-- Legacy migration only — NOT needed after a fresh import of:
--   sql/database.sql (appointments already has idx_appointment_date, idx_status, idx_date_status)
--   sql/patient_appointments.sql (same index names on preferred_date / status)
-- Run against OLD databases that predate those definitions; duplicate-index errors mean you can skip.
--
-- Database: edroso_dental (phpMyAdmin or mysql client).

USE edroso_dental;

-- Admin/staff appointments
ALTER TABLE appointments ADD INDEX idx_appointment_date (appointment_date);
ALTER TABLE appointments ADD INDEX idx_status (status);
ALTER TABLE appointments ADD INDEX idx_date_status (appointment_date, status);

-- Portal bookings (date column = preferred_date; same access pattern as appointments)
ALTER TABLE patient_appointments ADD INDEX idx_preferred_date (preferred_date);
ALTER TABLE patient_appointments ADD INDEX idx_status (status);
ALTER TABLE patient_appointments ADD INDEX idx_date_status (preferred_date, status);
