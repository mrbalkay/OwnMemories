#!/usr/bin/env bash
# OwnMemories Yerler: GeoNames yer verisini indirir ve veritabanına yükler. Tekrar çalıştırılabilir.
#   sudo bash server/install-geo.sh [--force]
# update.sh ve install-ubuntu.sh bunu kendiliğinden çağırır.
#
# İndirilenler (/var/lib/ownmemories/geo, ~30 MB indirme, ~60 MB açılmış): cities500 (dünya, nüfusu 500+ yerleşimler),
# TR (Türkiye'deki tüm yerleşimler ve mahalleler, il/ilçe adları), admin1CodesASCII, admin2Codes, countryInfo.
# Dosyalar 30 günden yeniyse yeniden indirilmez (--force: her durumda). Uygulama çalışırken hiçbir dış
# servise istek atmaz; konumlar yalnızca bu yerel veriyle eşleştirilir.
# Veri: GeoNames (https://www.geonames.org) — Creative Commons Attribution 4.0 (CC BY 4.0).
# Ayarlar: OM_GEO_DIR (varsayılan /var/lib/ownmemories/geo), OM_GEO=0 → atla.
# Çıkış kodu: 0 başarılı, 1 hata (update.sh bunu uyarıya çevirir).
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo "root olarak çalıştırın: sudo bash $0" >&2; exit 1; fi
if [[ "${OM_GEO:-1}" == "0" ]]; then echo "==> Yerler (GeoNames) atlandı (OM_GEO=0)."; exit 0; fi

SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${OM_DEST:-/var/www/ownmemories}"
[[ -f "$DEST/bin/geo-import.php" ]] || DEST="$SRC"
GEO="${OM_GEO_DIR:-/var/lib/ownmemories/geo}"
BASE="https://download.geonames.org/export/dump"
FORCE=0; [[ "${1:-}" == "--force" ]] && FORCE=1

echo "==> Yerler: GeoNames verisi ($GEO)"
command -v unzip >/dev/null || { apt-get install -y -qq unzip >/dev/null || { echo "unzip kurulamadı" >&2; exit 1; }; }
mkdir -p "$GEO"
fetch() { # $1 dosya adı
  local f="$1" out="$GEO/$1"
  if [[ $FORCE == 0 && -s "$out" && -n "$(find "$out" -mtime -30 2>/dev/null)" ]]; then return 0; fi
  echo "    indiriliyor: $f"
  if curl -fsSL --retry 3 --connect-timeout 20 -o "$out.part" "$BASE/$f"; then
    mv -f "$out.part" "$out"; touch "$out"
  else
    rm -f "$out.part"
    if [[ -s "$out" ]]; then echo "UYARI: $f indirilemedi; eldeki kopya kullanılıyor." >&2; else echo "HATA: $f indirilemedi" >&2; return 1; fi
  fi
}
for f in cities500.zip TR.zip admin1CodesASCII.txt admin2Codes.txt countryInfo.txt; do fetch "$f"; done
unzip -o -q "$GEO/cities500.zip" cities500.txt -d "$GEO"
unzip -o -q "$GEO/TR.zip" TR.txt -d "$GEO"
chmod -R a+rX "$GEO"
if [[ ! -f "$DEST/web/config/config.php" ]]; then
  echo "    Veri indirildi; kurulum (setup.php) tamamlanınca yüklemek için: sudo bash $DEST/server/install-geo.sh"
  exit 0
fi
# Web kullanıcısıyla çalıştır (günlük dosyaları root'a geçmesin); klasörü okuyamıyorsa root olarak.
if id www-data >/dev/null 2>&1 && runuser -u www-data -- test -r "$GEO/cities500.txt" && runuser -u www-data -- test -r "$DEST/web/config/config.php"; then
  runuser -u www-data -- php "$DEST/bin/geo-import.php" --dir="$GEO" $([[ $FORCE == 1 ]] && echo --force)
else
  php "$DEST/bin/geo-import.php" --dir="$GEO" $([[ $FORCE == 1 ]] && echo --force)
fi
