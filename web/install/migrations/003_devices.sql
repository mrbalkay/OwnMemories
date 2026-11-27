-- 003: iOS cihazlarının yedekleme durumu
CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  device_id VARCHAR(100) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  model VARCHAR(60) NULL,
  os_version VARCHAR(30) NULL,
  app_version VARCHAR(30) NULL,
  pending INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_total INT UNSIGNED NOT NULL DEFAULT 0,
  last_backup_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  last_seen_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_devices_user_device (user_id, device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
