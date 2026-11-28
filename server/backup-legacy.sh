#!/usr/bin/env bash
# ESKİ OwnMemories yedeği (restic öncesi): MySQL dökümü + depo klasörü (rsync, silinenleri 30 gün ayrı klasörde tutar).
# Yalnızca /etc/ownmemories-backup.env (BACKUP_DIR=…) varken ve yeni yedek (setup-backup.sh) henüz KURULMAMIŞKEN
# server/backup.sh tarafından çağrılır; böylece güncellemeden sonra gece yedeği kesilmez. Şifreleme ve sürümleme
# yoktur: yeni sisteme geçmek için  sudo bash ~/OwnMemories/server/setup-backup.sh
set -euo pipefail
OM_ROOT="${OM_ROOT:-/var/www/ownmemories}"
. "$(cd "$(dirname "$0")" && pwd)/lib/backup-common.sh"
: "${BACKUP_DIR:?BACKUP_DIR ayarlanmalı}"
CONFIG="${OM_CONFIG:-$OM_ROOT/web/config/config.php}"
OM_CONFIG="$CONFIG"
STAMP="$(date +%F)"
mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/storage" "$BACKUP_DIR/deleted/$STAMP"

read_cfg() { bk_cfg "$1"; }   # config.php root olarak çalıştırılmaz (bkz. backup-common.sh)
DB_HOST="$(read_cfg db.host)"; DB_PORT="$(read_cfg db.port)"; DB_NAME="$(read_cfg db.name)"
DB_USER="$(read_cfg db.user)"; DB_PASS="$(read_cfg db.pass)"; STORAGE="$(read_cfg storage.path)"

# Fotoğraf diski bağlı değilse --delete her şeyi "deleted" klasörüne taşır ve 30 gün sonra silerdi: önce dur.
if [[ ! -d "$STORAGE/blobs" || -z "$(ls -A "$STORAGE/blobs" 2>/dev/null)" ]]; then
  echo "HATA: $STORAGE/blobs yok ya da boş (fotoğraf diski bağlı mı?). Yedek alınmadı; mevcut yedeğe dokunulmadı." >&2
  exit 1
fi
MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --quick --routines -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" | gzip > "$BACKUP_DIR/db/ownmemories-$STAMP.sql.gz"
find "$BACKUP_DIR/db" -name 'ownmemories-*.sql.gz' -mtime +30 -delete

rsync -a --delete --backup --backup-dir="$BACKUP_DIR/deleted/$STAMP" \
  --exclude 'tmp/' --exclude 'uploads/' --exclude 'sessions/' "$STORAGE/" "$BACKUP_DIR/storage/"
find "$BACKUP_DIR/deleted" -mindepth 1 -maxdepth 1 -type d -mtime +30 -exec rm -rf {} +
echo "Yedek tamam: $BACKUP_DIR ($STAMP)"
