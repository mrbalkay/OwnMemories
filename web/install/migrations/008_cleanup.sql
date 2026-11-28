-- 008: Temizlik — benzer/yinelenen fotoğraflar, bulanık/karanlık kareler, ekran görüntüleri.
-- asset_quality: ownmemories-ml dizinleyicisinin türetilmiş görüntüden hesapladığı ucuz ölçümler.
--   phash: 64 bit DCT algısal özet (aynı görüntünün yeniden kodlanmış/küçültülmüş kopyalarında Hamming ≤ ~6).
--   sharpness: Laplace varyansı (1024 px uzun kenara ölçeklenmiş gri görüntü, 4×4 karoların en netlerinden ikincisi).
--   brightness: ortalama parlaklık 0..255. color: 4×4 ortalama RGB (48 bayt; aynı düzenli ama farklı renkli
--   kareleri ayırır). is_screenshot: dosya adı/biçim/ekran boyutu kuralı. algo: ölçüm sürümü.
CREATE TABLE IF NOT EXISTS asset_quality (
  asset_id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  phash BIGINT UNSIGNED NULL,
  sharpness FLOAT NULL,
  brightness FLOAT NULL,
  color VARBINARY(48) NULL,
  is_screenshot TINYINT(1) NOT NULL DEFAULT 0,
  algo SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  computed_at DATETIME NOT NULL,
  KEY ix_aq_user (user_id),
  KEY ix_aq_phash (user_id, phash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Bunlar farklı" / "Bu iyi": kullanıcının gizlediği öneriler bir daha gösterilmez.
--   kind=group: item_key = sıralı üye id'lerinin SHA-1'i, asset_ids = virgülle üyeler (alt küme grupları da gizlenir).
--   kind=blurry|dark|screenshot|video: item_key = asset id.
CREATE TABLE IF NOT EXISTS cleanup_dismissed (
  user_id INT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,
  item_key CHAR(40) NOT NULL,
  asset_ids TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, kind, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
