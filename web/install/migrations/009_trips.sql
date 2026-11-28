-- 009: Geziler ve etkinlikler — fotoğraf konumu ve zamanından otomatik gezi/etkinlik albümleri.
-- trips: 'trips' işi (web/includes/trips.php) yeniden hesaplar; kimlikler kararlıdır (ortak öğeyle eşleşir).
--   kind: trip (evden uzakta günler) | event (yoğun çekim / günübirlik yakın çıkış). label: day_trip | birthday | wedding | …
--   title_auto=0: kullanıcı adlandırdı (korunur). cover_auto=0: kullanıcı kapak seçti. dismissed=1: gizlendi (geri gelmez).
--   album_id: "Albüme dönüştür" ile oluşan normal albüm (bağ korunur). place_summary: JSON {places, countries, home_cc}.
--   fingerprint: tür + sıralı öğe kimliklerinin SHA-1'i (değişmeyen gezi yeniden yazılmaz). label_checked: ML'e soruldu.
CREATE TABLE IF NOT EXISTS trips (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('trip','event') NOT NULL DEFAULT 'trip',
  label VARCHAR(24) NULL,
  label_checked TINYINT(1) NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  title_auto TINYINT(1) NOT NULL DEFAULT 1,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  start_day DATE NOT NULL,
  end_day DATE NOT NULL,
  days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  place_summary TEXT NULL,
  cover_asset_id CHAR(36) NULL,
  cover_auto TINYINT(1) NOT NULL DEFAULT 1,
  asset_count INT UNSIGNED NOT NULL DEFAULT 0,
  photo_count INT UNSIGNED NOT NULL DEFAULT 0,
  video_count INT UNSIGNED NOT NULL DEFAULT 0,
  distance_km DECIMAL(8,1) NULL,
  dismissed TINYINT(1) NOT NULL DEFAULT 0,
  album_id CHAR(36) NULL,
  fingerprint CHAR(40) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_trips_user (user_id, dismissed, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_assets (
  trip_id INT UNSIGNED NOT NULL,
  asset_id CHAR(36) NOT NULL,
  PRIMARY KEY (trip_id, asset_id),
  KEY ix_trip_assets_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
