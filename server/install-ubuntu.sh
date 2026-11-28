#!/usr/bin/env bash
# OwnMemories — Ubuntu Server (22.04 / 24.04 / daha yeni) ve Debian 12+ kurulum betiği (root olarak çalıştırın).
# Dağıtımın varsayılan PHP sürümünü kullanır (en az 8.1).
#   sudo bash server/install-ubuntu.sh
# Yapar: paketleri kurar, /var/www/ownmemories'e kopyalar, Apache sitesini, veritabanını,
# depo klasörünü ve systemd birimlerini (işçi, cron, yedek, akıllı arama) hazırlar. Cloudflare Tunnel adımları docs/INSTALL.md'de.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then echo "root olarak çalıştırın: sudo bash $0" >&2; exit 1; fi
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST=/var/www/ownmemories
STORAGE="${OM_STORAGE:-/srv/ownmemories}"
DB_NAME="${OM_DB_NAME:-ownmemories}"
DB_USER="${OM_DB_USER:-ownmemories}"
DB_PASS="${OM_DB_PASS:-$(openssl rand -hex 16)}"

echo "==> Sistem: $(. /etc/os-release && echo "$PRETTY_NAME")"
echo "==> Paketler"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
has_pkg() { apt-cache show "$1" >/dev/null 2>&1; }
DB_PKG=mysql-server
has_pkg mysql-server || DB_PKG=mariadb-server
HEIF_PKG=""
has_pkg libheif-examples && HEIF_PKG=libheif-examples
# iPhone HEIC fotoğraflarını çözmek için HEVC çözücü eklentisi (Ubuntu 24.04+ ayrı paket)
has_pkg libheif-plugin-libde265 && HEIF_PKG="$HEIF_PKG libheif-plugin-libde265"
apt-get install -y -qq apache2 "$DB_PKG" \
  php libapache2-mod-php php-cli php-mysql php-gd php-imagick php-zip php-intl php-mbstring php-xml \
  libimage-exiftool-perl ffmpeg libvips-tools $HEIF_PKG imagemagick rsync openssl curl python3-venv

PHPV="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if ! php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
  echo "Bu sistemdeki PHP $PHPV; OwnMemories en az PHP 8.1 ister (Ubuntu 22.04 veya üstü)." >&2
  exit 1
fi
echo "    PHP $PHPV, veritabanı: $DB_PKG"

echo "==> Dosyalar: $DEST"
mkdir -p "$DEST"
rsync -a --exclude '.git' --exclude 'web/config/config.php' --exclude 'web/config/installed.lock' --exclude 'storage/' "$SRC/" "$DEST/"
chown -R www-data:www-data "$DEST/web/config"

echo "==> Depo klasörü: $STORAGE"
mkdir -p "$STORAGE"/{blobs,derived,uploads,import,imports,logs,sessions,tmp}
chown -R www-data:www-data "$STORAGE"
chmod 750 "$STORAGE"

echo "==> PHP ayarları"
cat > "/etc/php/$PHPV/apache2/conf.d/90-ownmemories.ini" <<INI
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 512M
max_execution_time = 300
INI
cp "/etc/php/$PHPV/apache2/conf.d/90-ownmemories.ini" "/etc/php/$PHPV/cli/conf.d/90-ownmemories.ini"
sed -i 's/^memory_limit = 512M/memory_limit = 1024M/' "/etc/php/$PHPV/cli/conf.d/90-ownmemories.ini"

echo "==> Apache"
cp "$SRC/server/apache-ownmemories.conf" /etc/apache2/sites-available/ownmemories.conf
a2enmod -q rewrite remoteip headers >/dev/null
a2dissite -q 000-default >/dev/null || true
a2ensite -q ownmemories >/dev/null
systemctl restart apache2

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

echo "==> Akıllı arama (ownmemories-ml, ~1.5 GB model indirilir)"
ML_OK=1
bash "$DEST/server/install-ml.sh" || ML_OK=0

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
if (( ! ML_OK )); then
  echo "UYARI: Akıllı arama kurulamadı (model indirilemedi veya yetersiz RAM). Diğer her şey çalışır;" >&2
  echo "       sonra tekrar deneyin: sudo bash $DEST/server/install-ml.sh" >&2
fi
