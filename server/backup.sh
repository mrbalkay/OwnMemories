#!/usr/bin/env bash
# OwnMemories yedeği: MySQL dökümü + depo klasörü (rsync, silinenleri 30 gün ayrı klasörde tutar).
# Gerekli ortam değişkenleri: BACKUP_DIR. İsteğe bağlı: OM_ROOT (varsayılan /var/www/ownmemories).
set -euo pipefail
OM_ROOT="${OM_ROOT:-/var/www/ownmemories}"
: "${BACKUP_DIR:?BACKUP_DIR ayarlanmalı}"
CONFIG="$OM_ROOT/web/config/config.php"
STAMP="$(date +%F)"
mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/storage" "$BACKUP_DIR/deleted/$STAMP"

read_cfg() { php -r '$c = require $argv[1]; $k = explode(".", $argv[2]); foreach ($k as $p) { $c = $c[$p]; } echo $c;' "$CONFIG" "$1"; }
DB_HOST="$(read_cfg db.host)"; DB_PORT="$(read_cfg db.port)"; DB_NAME="$(read_cfg db.name)"
DB_USER="$(read_cfg db.user)"; DB_PASS="$(read_cfg db.pass)"; STORAGE="$(read_cfg storage.path)"

MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --quick --routines -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" | gzip > "$BACKUP_DIR/db/ownmemories-$STAMP.sql.gz"
find "$BACKUP_DIR/db" -name 'ownmemories-*.sql.gz' -mtime +30 -delete

rsync -a --delete --backup --backup-dir="$BACKUP_DIR/deleted/$STAMP" \
  --exclude 'tmp/' --exclude 'uploads/' --exclude 'sessions/' "$STORAGE/" "$BACKUP_DIR/storage/"
find "$BACKUP_DIR/deleted" -mindepth 1 -maxdepth 1 -type d -mtime +30 -exec rm -rf {} +
echo "Yedek tamam: $BACKUP_DIR ($STAMP)"
