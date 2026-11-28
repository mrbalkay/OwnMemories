-- 006: Kişiler — elle etiketleme ve yüz tanıma (ownmemories-ml yüzleri yazar ve kümeler).
-- people.name NULL = adlandırılmamış yüz kümesi. asset_person "bu fotoğrafta kim var" ilişkisinin tek kaynağıdır.
-- faces: kutu 0..1, ekranda görünen (EXIF yönü uygulanmış) görüntüye göre; embedding float32 LE, 512, L2-normalize.
CREATE TABLE IF NOT EXISTS people (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NULL,
  cover_face_id BIGINT UNSIGNED NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_people_user (user_id),
  KEY ix_people_name (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_person (
  asset_id CHAR(36) NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  source ENUM('manual','takeout','face') NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (asset_id, person_id),
  KEY ix_ap_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faces (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  asset_id CHAR(36) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NULL,
  rejected_person_id INT UNSIGNED NULL,
  x1 FLOAT NULL,
  y1 FLOAT NULL,
  x2 FLOAT NULL,
  y2 FLOAT NULL,
  score FLOAT NOT NULL,
  embedding BLOB NOT NULL,
  model VARCHAR(80) NOT NULL,
  assigned_by ENUM('auto','user') NULL,
  created_at DATETIME NOT NULL,
  KEY ix_faces_asset (asset_id),
  KEY ix_faces_user_person (user_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS face_scans (
  asset_id CHAR(36) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  model VARCHAR(80) NOT NULL,
  faces INT NOT NULL,
  scanned_at DATETIME NOT NULL,
  KEY ix_fs_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Takeout kişi adları (asset_people) → people + asset_person (kullanıcı ve ad başına bir kişi). Tekrar çalıştırılabilir.
-- asset_people tablosu yerinde kalır ama artık okunmaz/yazılmaz.
INSERT INTO people (user_id, name, cover_face_id, hidden, created_at, updated_at)
SELECT x.user_id, MIN(x.name), NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM (SELECT a.user_id, ap.name FROM asset_people ap JOIN assets a ON a.id = ap.asset_id) x
WHERE NOT EXISTS (SELECT 1 FROM people p WHERE p.user_id = x.user_id AND p.name = x.name)
GROUP BY x.user_id, x.name;

INSERT IGNORE INTO asset_person (asset_id, person_id, source, created_at)
SELECT ap.asset_id, (SELECT MIN(p.id) FROM people p WHERE p.user_id = a.user_id AND p.name = ap.name), 'takeout', UTC_TIMESTAMP()
FROM asset_people ap JOIN assets a ON a.id = ap.asset_id;
