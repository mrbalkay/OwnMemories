-- 002: içe aktarma girdileri tablosu (bellek dostu tarama), desteklenmeyen dosya sonucu,
-- zaman çizelgesi alt sorguları için indeksler.
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
ALTER TABLE imports ADD COLUMN sources_json TEXT NULL;
ALTER TABLE import_items MODIFY result ENUM('imported','duplicate','no_metadata','skipped_trashed','failed','metadata_only','unsupported') NOT NULL;
ALTER TABLE assets ADD KEY ix_assets_live (live_pair_id);
ALTER TABLE assets ADD KEY ix_assets_edited (edited_of_id);
