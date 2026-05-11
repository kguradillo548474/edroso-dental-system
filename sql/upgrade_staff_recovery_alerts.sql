-- Staff credential recovery OTP challenges + in-app admin notifications
-- Safe to run multiple times (CREATE IF NOT EXISTS).

USE edroso_dental;

CREATE TABLE IF NOT EXISTS credential_recovery_challenges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_credential_recovery_user (user_id),
    CONSTRAINT fk_credential_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_credential_recovery_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_staff_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_staff_alerts_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demo staff (password: password — same bcrypt as seed admin)
INSERT INTO users (username, password, full_name, role)
SELECT 'staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Clinic Reception', 'staff'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'staff' LIMIT 1);
