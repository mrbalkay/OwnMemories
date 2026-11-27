#!/usr/bin/env bash
# OwnMemories güncelleme: depodaki son kodu çalışan kuruluma kopyalar ve işçiyi yeniden başlatır.
#   cd ~/OwnMemories && git pull && sudo bash server/update.sh
# Yapılandırma (web/config) ve depolama klasörü korunur; veritabanı migrasyonları ilk istekte kendiliğinden uygulanır.
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo "root olarak çalıştırın: sudo bash $0" >&2; exit 1; fi
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${OM_DEST:-/var/www/ownmemories}"
[[ -f "$DEST/web/config/config.php" ]] || { echo "Kurulum bulunamadı: $DEST (önce install-ubuntu.sh)" >&2; exit 1; }
rsync -a --delete \
  --exclude '.git' --exclude 'web/config/config.php' --exclude 'web/config/installed.lock' \
  --exclude 'storage/' --exclude 'web/storage/' --exclude 'tests/e2e/screens/' \
  "$SRC/" "$DEST/"
chown -R www-data:www-data "$DEST/web/config"
systemctl restart ownmemories-worker 2>/dev/null || true
systemctl reload apache2 2>/dev/null || true
echo "Güncellendi: $(git -C "$SRC" log -1 --format='%h %s')"
