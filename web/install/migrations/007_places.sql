-- 007: Yerler — fotoğraf konumlarından çevrimdışı yer adları (GeoNames, CC BY 4.0).
-- geo_places/geo_countries bin/geo-import.php ile doldurulur (server/install-geo.sh indirir); asset_places işlerle dolar.
CREATE TABLE IF NOT EXISTS geo_places (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  name_tr VARCHAR(160) NULL,
  feature_code VARCHAR(10) NOT NULL,
  country_code CHAR(2) NOT NULL,
  admin1_code VARCHAR(20) NOT NULL DEFAULT '',
  admin2_code VARCHAR(80) NOT NULL DEFAULT '',
  admin1 VARCHAR(160) NULL,
  admin2 VARCHAR(160) NULL,
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  population INT UNSIGNED NOT NULL DEFAULT 0,
  cell INT UNSIGNED NOT NULL,
  KEY ix_geo_cell (cell)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS geo_countries (
  code CHAR(2) NOT NULL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  name_tr VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_places (
  asset_id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  place_id INT UNSIGNED NULL,
  city_id INT UNSIGNED NULL,
  name VARCHAR(160) NULL,
  city VARCHAR(160) NULL,
  district VARCHAR(160) NULL,
  admin1 VARCHAR(160) NULL,
  country VARCHAR(120) NULL,
  country_code CHAR(2) NULL,
  region VARCHAR(80) NULL,
  distance_km FLOAT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  search_key VARCHAR(600) NOT NULL DEFAULT '',
  geo_version VARCHAR(16) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL,
  KEY ix_ap_user_city (user_id, city_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
