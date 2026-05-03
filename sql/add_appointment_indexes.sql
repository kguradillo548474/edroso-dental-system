-- Migration: indexes for appointments and patient_appointments
-- Run once against database edroso_dental (phpMyAdmin or mysql client).
-- If an index already exists, skip that line or remove the duplicate from this file.

USE edroso_dental;

-- Admin/staff appointments
ALTER TABLE appointments ADD INDEX idx_appointment_date (appointment_date);
ALTER TABLE appointments ADD INDEX idx_status (status);
ALTER TABLE appointments ADD INDEX idx_date_status (appointment_date, status);

-- Portal bookings (date column = preferred_date; same access pattern as appointments)
ALTER TABLE patient_appointments ADD INDEX idx_preferred_date (preferred_date);
ALTER TABLE patient_appointments ADD INDEX idx_status (status);
ALTER TABLE patient_appointments ADD INDEX idx_date_status (preferred_date, status);
