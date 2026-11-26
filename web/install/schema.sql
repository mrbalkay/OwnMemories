-- OwnMemories veritabanı şeması (SCHEMA_VERSION = 2; migrasyonlar dahil güncel hali)
-- InnoDB, utf8mb4_unicode_ci, tüm zamanlar UTC. MySQL 5.7+ / MariaDB 10.4+ uyumlu.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  locale VARCHAR(5) NOT NULL DEFAULT 'tr',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Istanbul',
  quota_bytes BIGINT UNSIGNED NULL,
  used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sync_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  platform VARCHAR(16) NOT NULL DEFAULT 'ios',
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  UNIQUE KEY uq_api_tokens_hash (token_hash),
  KEY ix_api_tokens_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blobs (
  sha1 CHAR(40) NOT NULL PRIMARY KEY,
  size BIGINT UNSIGNED NOT NULL,
  mime VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
  ext VARCHAR(10) NOT NULL DEFAULT '',
  path VARCHAR(255) NOT NULL,
  ref_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  blob_sha1 CHAR(40) NOT NULL,
  type ENUM('photo','video') NOT NULL,
  original_filename VARCHAR(255) NOT NULL DEFAULT '',
  mime VARCHAR(100) NOT NULL DEFAULT '',
  size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  duration_ms INT UNSIGNED NULL,
  taken_at DATETIME NOT NULL,
  taken_tz_offset SMALLINT NULL,
  date_source ENUM('takeout','exif','filename','file','user') NOT NULL DEFAULT 'file',
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  altitude DECIMAL(9,2) NULL,
  camera_make VARCHAR(80) NULL,
  camera_model VARCHAR(120) NULL,
  lens VARCHAR(120) NULL,
  description TEXT NULL,
  is_favorite TINYINT(1) NOT NULL DEFAULT 0,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  shared_from_others TINYINT(1) NOT NULL DEFAULT 0,
  trashed_at DATETIME NULL,
  source ENUM('takeout','ios','web') NOT NULL,
  source_ref VARCHAR(512) NULL,
  live_pair_id CHAR(36) NULL,
  edited_of_id CHAR(36) NULL,
  thumb_state ENUM('pending','ready','placeholder','failed') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_assets_user_blob (user_id, blob_sha1),
  KEY ix_assets_timeline (user_id, trashed_at, taken_at),
  KEY ix_assets_version (user_id, version),
  KEY ix_assets_dupe (user_id, taken_at, width, height),
  KEY ix_assets_source_ref (user_id, source_ref(191)),
  KEY ix_assets_live (live_pair_id),
  KEY ix_assets_edited (edited_of_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deleted_checksums (
  user_id INT UNSIGNED NOT NULL,
  sha1 CHAR(40) NOT NULL,
  deleted_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, sha1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS albums (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  cover_asset_id CHAR(36) NULL,
  source ENUM('takeout','user') NOT NULL DEFAULT 'user',
  source_ref VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_albums_user (user_id),
  KEY ix_albums_source (user_id, source, source_ref(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS album_assets (
  album_id CHAR(36) NOT NULL,
  asset_id CHAR(36) NOT NULL,
  added_by INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  added_at DATETIME NOT NULL,
  PRIMARY KEY (album_id, asset_id),
  KEY ix_album_assets_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS album_members (
  album_id CHAR(36) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('viewer','contributor') NOT NULL DEFAULT 'viewer',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (album_id, user_id),
  KEY ix_album_members_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_people (
  asset_id CHAR(36) NOT NULL,
  name VARCHAR(190) NOT NULL,
  PRIMARY KEY (asset_id, name),
  KEY ix_asset_people_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(40) NOT NULL,
  payload TEXT NOT NULL,
  status ENUM('queued','running','done','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  run_after DATETIME NOT NULL,
  locked_by VARCHAR(64) NULL,
  locked_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_jobs_claim (status, priority, run_after),
  KEY ix_jobs_locked (locked_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('queued','scanning','importing','linking','done','failed','paused') NOT NULL DEFAULT 'queued',
  source_dir VARCHAR(1024) NOT NULL,
  files_total INT UNSIGNED NOT NULL DEFAULT 0,
  files_done INT UNSIGNED NOT NULL DEFAULT 0,
  counts_json TEXT NULL,
  current_zip VARCHAR(255) NULL,
  current_entry INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  last_error TEXT NULL,
  sources_json TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_imports_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_id INT UNSIGNED NOT NULL,
  zip_name VARCHAR(255) NOT NULL,
  entry_path VARCHAR(1024) NOT NULL,
  entry_hash CHAR(40) NOT NULL,
  result ENUM('imported','duplicate','no_metadata','skipped_trashed','failed','metadata_only','unsupported') NOT NULL,
  asset_id CHAR(36) NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_import_items (import_id, entry_hash),
  KEY ix_import_items_result (import_id, result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_id INT UNSIGNED NOT NULL,
  kind ENUM('media','sidecar','album_meta') NOT NULL,
  source_idx SMALLINT UNSIGNED NOT NULL,
  path VARCHAR(1024) NOT NULL,
  dir_key VARCHAR(768) NOT NULL,
  name_key VARCHAR(255) NOT NULL,
  mtime INT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_import_entries_kind (import_id, kind, id),
  KEY ix_import_entries_dir (import_id, kind, dir_key(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploads (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  sha1 CHAR(40) NULL,
  received BIGINT UNSIGNED NOT NULL DEFAULT 0,
  source ENUM('ios','web') NOT NULL DEFAULT 'web',
  meta_json TEXT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY ix_uploads_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
  `key` VARCHAR(64) NOT NULL PRIMARY KEY,
  value MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  `key` CHAR(64) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
