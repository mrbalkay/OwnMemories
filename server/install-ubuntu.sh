#!/usr/bin/env bash
# OwnMemories — Ubuntu Server 24.04 kurulum betiği (root olarak çalıştırın).
#   sudo bash server/install-ubuntu.sh
# Yapar: paketleri kurar, /var/www/ownmemories'e kopyalar, Apache sitesini, veritabanını,
# depo klasörünü ve systemd birimlerini (işçi, cron, yedek) hazırlar. Cloudflare Tunnel adımları docs/INSTALL.md'de.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then echo "root olarak çalıştırın: sudo bash $0" >&2; exit 1; fi
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST=/var/www/ownmemories
STORAGE="${OM_STORAGE:-/srv/ownmemories}"
DB_NAME="${OM_DB_NAME:-ownmemories}"
DB_USER="${OM_DB_USER:-ownmemories}"
DB_PASS="${OM_DB_PASS:-$(openssl rand -hex 16)}"

echo "==> Paketler"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 mysql-server \
  php8.3 libapache2-mod-php8.3 php8.3-cli php8.3-mysql php8.3-gd php8.3-imagick php8.3-zip php8.3-intl php8.3-mbstring php8.3-xml php8.3-exif \
  libimage-exiftool-perl ffmpeg libvips-tools libheif-examples imagemagick rsync openssl

echo "==> Dosyalar: $DEST"
mkdir -p "$DEST"
rsync -a --exclude '.git' --exclude 'web/config/config.php' --exclude 'web/config/installed.lock' --exclude 'storage/' "$SRC/" "$DEST/"
chown -R www-data:www-data "$DEST/web/config"

echo "==> Depo klasörü: $STORAGE"
mkdir -p "$STORAGE"/{blobs,derived,uploads,import,imports,logs,sessions,tmp}
chown -R www-data:www-data "$STORAGE"
chmod 750 "$STORAGE"

echo "==> PHP ayarları"
cat > /etc/php/8.3/apache2/conf.d/90-ownmemories.ini <<INI
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 512M
max_execution_time = 300
INI
cp /etc/php/8.3/apache2/conf.d/90-ownmemories.ini /etc/php/8.3/cli/conf.d/90-ownmemories.ini
sed -i 's/^memory_limit = 512M/memory_limit = 1024M/' /etc/php/8.3/cli/conf.d/90-ownmemories.ini

echo "==> Apache"
cp "$SRC/server/apache-ownmemories.conf" /etc/apache2/sites-available/ownmemories.conf
a2enmod -q rewrite remoteip headers >/dev/null
a2dissite -q 000-default >/dev/null || true
a2ensite -q ownmemories >/dev/null
systemctl reload apache2

echo "==> Veritabanı"
mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"

echo "==> systemd birimleri"
for u in ownmemories-worker.service ownmemories-cron.service ownmemories-cron.timer ownmemories-backup.service ownmemories-backup.timer; do
  cp "$SRC/server/$u" /etc/systemd/system/
done
systemctl daemon-reload
systemctl enable --now ownmemories-cron.timer
# İşçi kurulum (setup.php) bittikten sonra başlar; kurulmadan başlatılırsa hata verip yeniden dener.
systemctl enable ownmemories-worker.service

cat <<DONE

Kurulum hazır. Sıradaki adımlar:
  1) Tarayıcıda http://<sunucu-ip>/setup.php adresini açın ve şunları girin:
       Veritabanı: $DB_NAME   Kullanıcı: $DB_USER   Parola: $DB_PASS
       Depolama klasörü: $STORAGE
  2) Kurulumdan sonra işçiyi başlatın:  sudo systemctl start ownmemories-worker
  3) Cloudflare Tunnel: docs/INSTALL.md → "Cloudflare Tunnel"
  4) Yedek: /etc/ownmemories-backup.env içine BACKUP_DIR=/mnt/yedek/ownmemories yazın,
     sonra:  sudo systemctl enable --now ownmemories-backup.timer
Bu parolayı not alın; tekrar gösterilmeyecek.
DONE
