-- MariaDB / MySQL. Select the database first. Never drop or replace existing data.
CREATE TABLE IF NOT EXISTS sl_admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(12) NOT NULL DEFAULT 'admin',
  enabled TINYINT NOT NULL DEFAULT 1,
  created_at VARCHAR(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_code VARCHAR(40) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL DEFAULT '',
  enabled TINYINT NOT NULL DEFAULT 1,
  notes TEXT NOT NULL,
  created_at VARCHAR(25) NOT NULL,
  updated_at VARCHAR(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_locks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  public_key CHAR(130) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  bt_address CHAR(17) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  created_at VARCHAR(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lock_id BIGINT UNSIGNED NOT NULL,
  device_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  reported_status TINYINT NULL,
  reported_at VARCHAR(25) NULL,
  UNIQUE KEY sl_device_identity (lock_id, device_id),
  FOREIGN KEY (lock_id) REFERENCES sl_locks(id),
  FOREIGN KEY (user_id) REFERENCES sl_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lock_id BIGINT UNSIGNED NOT NULL,
  device_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  boot_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uptime_ms BIGINT UNSIGNED NOT NULL,
  event VARCHAR(48) CHARACTER SET ascii NOT NULL,
  reason VARCHAR(96) CHARACTER SET ascii NOT NULL,
  source_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  imported_by BIGINT UNSIGNED NOT NULL,
  imported_at VARCHAR(25) NOT NULL,
  UNIQUE KEY sl_event_source (lock_id, source_hash),
  KEY sl_event_lock_time (lock_id, id),
  FOREIGN KEY (lock_id) REFERENCES sl_locks(id),
  FOREIGN KEY (imported_by) REFERENCES sl_admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(40) NOT NULL,
  target VARCHAR(100) NOT NULL,
  details TEXT NOT NULL,
  created_at VARCHAR(25) NOT NULL,
  FOREIGN KEY (actor_id) REFERENCES sl_admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_login_limits (
  bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  window_start BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live Android observations; kept separate from ESP32-signed SD imports.
CREATE TABLE IF NOT EXISTS sl_app_clients (
  client_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  public_key CHAR(130) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  lock_id BIGINT UNSIGNED NOT NULL,
  device_row_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  enabled TINYINT NOT NULL DEFAULT 1,
  snapshot_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
  phase VARCHAR(16) NOT NULL DEFAULT 'IDLE',
  bt_connected TINYINT NOT NULL DEFAULT 0,
  last_seen VARCHAR(25) NOT NULL,
  created_at VARCHAR(25) NOT NULL,
  FOREIGN KEY (lock_id) REFERENCES sl_locks(id),
  FOREIGN KEY (device_row_id) REFERENCES sl_devices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_app_codes (
  code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  lock_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  issued_by BIGINT UNSIGNED NOT NULL,
  used_by CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  used_at BIGINT UNSIGNED NULL,
  FOREIGN KEY (lock_id) REFERENCES sl_locks(id),
  FOREIGN KEY (user_id) REFERENCES sl_users(id),
  FOREIGN KEY (issued_by) REFERENCES sl_admins(id),
  FOREIGN KEY (used_by) REFERENCES sl_app_clients(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_app_nonces (
  client_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (client_id,nonce),
  FOREIGN KEY (client_id) REFERENCES sl_app_clients(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sl_app_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type VARCHAR(32) CHARACTER SET ascii NOT NULL,
  observed_at VARCHAR(25) NOT NULL,
  received_at VARCHAR(25) NOT NULL,
  UNIQUE KEY sl_app_event_identity (client_id,event_id),
  FOREIGN KEY (client_id) REFERENCES sl_app_clients(client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent PHP sessions for stateless Vercel Functions.
CREATE TABLE IF NOT EXISTS sl_web_sessions (
  session_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  payload MEDIUMBLOB NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  KEY sl_web_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;