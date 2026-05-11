-- Optional: create authentication audit table manually (otherwise created on first staff/portal login).
CREATE TABLE IF NOT EXISTS auth_login_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  realm ENUM('staff','portal') NOT NULL,
  identifier VARCHAR(191) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  ip_address VARCHAR(45) NULL DEFAULT NULL,
  user_agent VARCHAR(512) NULL DEFAULT NULL,
  detail VARCHAR(191) NULL DEFAULT NULL,
  INDEX idx_auth_login_created (created_at),
  INDEX idx_auth_login_realm_created (realm, created_at),
  INDEX idx_auth_login_success_created (success, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
