-- 010: Bağlantıyla paylaşım — tailnet dışındaki kişilere (Tailscale Funnel) bir albüm veya seçim.
-- share_links: belirteç (token) yalnızca SHA-256 özeti olarak saklanır (token_hash); düz belirteç
--   yalnızca oluşturulurken (ve yenilenirken) sahibine bir kez gösterilir.
--   kind=album: albümün güncel içeriği (album_id); kind=selection: share_link_assets'teki öğeler.
--   show_location=0: sunulan dosyalarda GPS yok (türev görüntüler zaten metadatasız; orijinal/video
--   indirmede GPS anında silinir). revoked_at dolu veya expires_at geçmişse bağlantı çalışmaz.
CREATE TABLE IF NOT EXISTS share_links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,
  album_id CHAR(36) NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  password_hash VARCHAR(255) NULL,
  allow_download TINYINT(1) NOT NULL DEFAULT 0,
  show_location TINYINT(1) NOT NULL DEFAULT 0,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  last_viewed_at DATETIME NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_share_token (token_hash),
  KEY ix_share_user (user_id, created_at),
  KEY ix_share_album (album_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS share_link_assets (
  link_id INT UNSIGNED NOT NULL,
  asset_id CHAR(36) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (link_id, asset_id),
  KEY ix_sla_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
