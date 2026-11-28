#!/usr/bin/env bash
# OwnMemories güncelleme: depodaki son kodu çalışan kuruluma kopyalar, işçiyi yeniden başlatır ve
# yer adı verisini (server/install-geo.sh) ve akıllı arama servisini (server/install-ml.sh) kurar/günceller.
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
# Yerler (GeoNames yer adları): indir/güncelle ve içe aktar. Başarısız olursa güncelleme yine tamamlanır.
if ! bash "$DEST/server/install-geo.sh"; then
  echo "UYARI: Yer adı verisi (GeoNames) indirilemedi veya yüklenemedi; fotoğraflar etkilenmez, Yerler sayfası boş kalır." >&2
  echo "       Sonra tekrar deneyin: sudo bash $DEST/server/install-geo.sh" >&2
fi
# Akıllı arama servisi (ownmemories-ml): kurulum/güncelleme. Başarısız olursa güncelleme yine tamamlanır.
if ! bash "$DEST/server/install-ml.sh"; then
  echo "UYARI: Akıllı arama (ownmemories-ml) kurulamadı veya güncellenemedi; fotoğraflar ve normal arama etkilenmez." >&2
  echo "       Sonra tekrar deneyin: sudo bash $DEST/server/install-ml.sh   (günlük: journalctl -u ownmemories-ml)" >&2
fi
# Sunucu yedeği (restic): paket + systemd birimleri. Kurulmamışsa birimler etkin olmaz ve hiçbir şey bozulmaz.
command -v restic >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic >/dev/null 2>&1 \
  || echo "UYARI: restic kurulamadı (sudo apt-get install -y restic); yedekleme için gerekli." >&2
for u in ownmemories-backup.service ownmemories-backup.timer ownmemories-backup.path; do
  install -m 0644 "$DEST/server/$u" "/etc/systemd/system/$u"
done
systemctl daemon-reload 2>/dev/null || true
if [[ -f /etc/ownmemories/backup.env ]]; then
  systemctl enable --now ownmemories-backup.timer ownmemories-backup.path >/dev/null 2>&1 || true
else
  echo
  if [[ -f /etc/ownmemories-backup.env ]]; then
    # Eski rsync yedeği geçiş dönemi boyunca her gece çalışmaya devam eder (backup.sh → backup-legacy.sh).
    systemctl enable --now ownmemories-backup.timer >/dev/null 2>&1 || true
    echo "!! Eski rsync yedeği çalışmaya devam ediyor, ancak şifreli, sürümlü ve denetlenen yeni yedeğe geçin:"
  else
    echo "!! Sunucu yedeği KURULMAMIŞ: fotoğraflarınızın tek kopyası bu sunucuda."
  fi
  echo "   Bir kez çalıştırın (5 dk):  sudo bash $SRC/server/setup-backup.sh"
  echo
fi
echo "Güncellendi: $(git -C "$SRC" log -1 --format='%h %s')"
