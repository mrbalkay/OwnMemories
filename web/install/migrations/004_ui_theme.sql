-- 004: kullanıcı başına görünüm teması (sinema, galeri, yerel)
ALTER TABLE users ADD COLUMN ui_theme VARCHAR(16) NOT NULL DEFAULT 'sinema';
