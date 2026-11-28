-- 005: akıllı arama (ownmemories-ml) görüntü vektörleri. vec: float32 little-endian, L2-normalize.
-- Varlık kalıcı silinince satırı PHP siler; model değişince eski modelin satırları yok sayılır ve yenileriyle değiştirilir.
CREATE TABLE IF NOT EXISTS asset_embeddings (
  asset_id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  model VARCHAR(80) NOT NULL,
  vec MEDIUMBLOB NOT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_emb_user (user_id, model),
  KEY ix_emb_model (model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
