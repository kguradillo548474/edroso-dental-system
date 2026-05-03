-- Run in phpMyAdmin (database: edroso_dental) before using the patient portal
CREATE TABLE IF NOT EXISTS portal_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  phone VARCHAR(20) NOT NULL,
  dob DATE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  recovery_question VARCHAR(255) NULL,
  recovery_answer_hash VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
