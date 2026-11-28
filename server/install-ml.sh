#!/usr/bin/env bash
# OwnMemories akıllı arama servisi (ownmemories-ml) kurulumu / güncellemesi. Tekrar çalıştırılabilir.
#   sudo bash server/install-ml.sh
# update.sh ve install-ubuntu.sh bunu kendiliğinden çağırır.
#
# Yapar: python3-venv kurar, /opt/ownmemories-ml sanal ortamına sabit sürümlü paketleri kurar, modeli
# Hugging Face'ten /var/lib/ownmemories/models/<model> altına indirir (sürdürmeli, boyut + sha256 doğrulamalı),
# metin modelinin int8 kopyasını üretir (~600 MB daha az RAM), yüz tanıma modelini (Kişiler; immich-app/buffalo_l,
# ~190 MB) indirir, /etc/ownmemories/ml.env yazar (web/config/config.php'den) ve ownmemories-ml systemd servisini
# kurup başlatır. Yüz modeli indirilemezse servis yine kurulur; akıllı arama çalışır, yüz tanıma kapalı kalır.
# Fotoğraftaki yazı (OCR): PP-OCRv6 algılama + tanıma modelleri (~23 MB, ModelScope; boyut + sha256 sabit) <models>/ppocr-v6
# altına iner; ek Python paketi gerekmez (onnxruntime/numpy/pillow). İndirilemezse yalnızca yazıyla arama kapalı kalır.
#
# Ayarlar (ortam değişkeni ya da mevcut /etc/ownmemories/ml.env içinde korunur):
#   OM_ML_MODEL    (varsayılan immich-app/ViT-B-16-SigLIP2__webli)   OM_ML_THREADS (varsayılan min(4, CPU))
#   OM_FACE_MODEL  (varsayılan immich-app/buffalo_l; "0" = yüz tanıma kapalı. ~2.6 GB'tan az RAM'de kendiliğinden kapalı)
#                  Not: InsightFace buffalo_l ağırlıkları yalnızca ticari olmayan kullanım içindir.
#   OM_OCR         (varsayılan 1; "0" = fotoğraftaki yazı kapalı, model indirilmez)
#   OM_ML=0 ya da /etc/ownmemories/ml.disabled dosyası  -> akıllı aramayı kurma / durdur
# Çıkış kodu: 0 başarılı, 1 hata (update.sh bunu uyarıya çevirir, güncellemeyi durdurmaz).
set -euo pipefail
export PYTHONDONTWRITEBYTECODE=1
if [[ $EUID -ne 0 ]]; then echo "root olarak çalıştırın: sudo bash $0" >&2; exit 1; fi

SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${OM_DEST:-/var/www/ownmemories}"
VENV="${OM_ML_VENV:-/opt/ownmemories-ml}"
MODELS="${OM_ML_MODEL_DIR:-/var/lib/ownmemories/models}"
ETC=/etc/ownmemories
ENVF="$ETC/ml.env"
UNIT=/etc/systemd/system/ownmemories-ml.service
APP="$DEST/ml"
[[ -d "$APP/ownmemories_ml" ]] || APP="$SRC/ml"

warn() { echo "UYARI: $*" >&2; }
envval() { [[ -f "$ENVF" ]] && sed -n "s/^$1=\"\{0,1\}\([^\"]*\)\"\{0,1\}$/\1/p" "$ENVF" | tail -1 || true; }

if [[ "${OM_ML:-1}" == "0" || -f "$ETC/ml.disabled" ]]; then
  echo "==> Akıllı arama devre dışı (OM_ML=0 veya $ETC/ml.disabled); servis durduruluyor."
  systemctl disable --now ownmemories-ml.service 2>/dev/null || true
  exit 0
fi

MODEL="${OM_ML_MODEL:-$(envval OM_ML_MODEL)}"; MODEL="${MODEL:-immich-app/ViT-B-16-SigLIP2__webli}"
CPUS="$(nproc 2>/dev/null || echo 2)"
THREADS="${OM_ML_THREADS:-$(envval OM_ML_THREADS)}"; THREADS="${THREADS:-$(( CPUS < 4 ? CPUS : 4 ))}"
MODEL_NAME="${MODEL##*/}"
MEM_MB=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024 ))
FACE_MODEL="${OM_FACE_MODEL-$(envval OM_FACE_MODEL)}"
if [[ -z "$FACE_MODEL" ]]; then
  if (( MEM_MB < 2600 )); then
    FACE_MODEL="0"
    warn "Bu sunucuda ${MEM_MB} MB RAM var; yüz tanıma (~400 MB daha) kapalı bırakıldı. Açmak için: OM_FACE_MODEL=immich-app/buffalo_l"
  else
    FACE_MODEL="immich-app/buffalo_l"
  fi
fi

echo "==> Akıllı arama (ownmemories-ml): model $MODEL, $THREADS iş parçacığı"
if (( MEM_MB < 1800 )); then
  warn "Bu sunucuda ${MEM_MB} MB RAM var; akıllı arama en az ~2 GB ister. Kurulum atlandı."
  exit 1
fi

# --- 1) Python sanal ortamı
export DEBIAN_FRONTEND=noninteractive
if ! python3 -c 'import ensurepip, venv' >/dev/null 2>&1; then
  apt-get update -qq || true
  PYV="$(python3 -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")')"
  apt-get install -y -qq python3-venv >/dev/null 2>&1 || apt-get install -y -qq "python$PYV-venv"
fi
if ! python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)'; then
  warn "Python 3.10 veya üstü gerekli ($(python3 -V)); akıllı arama kurulamadı."
  exit 1
fi
if [[ ! -x "$VENV/bin/python" ]] || ! "$VENV/bin/python" -c 'import sys' >/dev/null 2>&1; then
  rm -rf "$VENV"
  python3 -m venv "$VENV"
fi
REQ_SUM="$(cat "$APP/requirements.txt" "$APP/requirements-quantize.txt" | sha256sum | cut -c1-16)"
if [[ "$(cat "$VENV/.requirements" 2>/dev/null)" != "$REQ_SUM" ]]; then
  echo "    Python paketleri kuruluyor (onnxruntime, numpy, tokenizers, pillow, pymysql)…"
  "$VENV/bin/pip" install -q --disable-pip-version-check --upgrade pip
  # Yalnızca hazır paketler (wheel): kaynaktan derleme (cmake, derleyici) sunucuda denenmez.
  if ! "$VENV/bin/pip" install -q --disable-pip-version-check --only-binary=:all: -r "$APP/requirements.txt"; then
    warn "Akıllı arama paketleri bu Python sürümü ($("$VENV/bin/python" -V 2>&1)) için hazır bulunamadı."
    exit 1
  fi
  # İsteğe bağlı: int8 dönüşümü için (onnx). Kurulamazsa servis yine çalışır, yalnızca biraz daha fazla RAM kullanır.
  QUANT_OK=1
  "$VENV/bin/pip" install -q --disable-pip-version-check --only-binary=:all: -r "$APP/requirements-quantize.txt" || QUANT_OK=0
  if (( QUANT_OK )); then
    echo "$REQ_SUM" > "$VENV/.requirements"
  else
    warn "int8 dönüşüm aracı (onnx) kurulamadı; tam metin modeli kullanılacak (~600 MB daha fazla RAM)."
  fi
fi

# --- 2) Model
mkdir -p "$MODELS"
FREE_MB=$(( $(df -Pk "$MODELS" | awk 'NR==2 {print $4}') / 1024 ))
if [[ ! -f "$MODELS/$MODEL_NAME/.complete" ]] && (( FREE_MB < 3000 )); then
  warn "$MODELS için yeterli disk yok (${FREE_MB} MB boş, ~3 GB gerekli)."
fi
echo "    Model indiriliyor / doğrulanıyor: $MODELS/$MODEL_NAME"
MODEL_OK=1
( cd "$APP" && "$VENV/bin/python" -m ownmemories_ml download --model "$MODEL" --dest "$MODELS" ) || MODEL_OK=0
if (( MODEL_OK )); then
  # int8 token tablosu: ~600 MB daha az RAM, sorgu kalitesi korunur (fp32 ile kosinüs ≥ 0.99).
  # Dönüşüm bir kez, metin modelinin ~3 katı bellek ister (SigLIP2: ~3.4 GB).
  AVAIL_MB=$(( ( $(awk '/^MemAvailable:/ {print $2}' /proc/meminfo) + $(awk '/^SwapFree:/ {print $2}' /proc/meminfo) ) / 1024 ))
  TXT_MB=$(( $(stat -c %s "$MODELS/$MODEL_NAME/textual/model.onnx" 2>/dev/null || echo 0) / 1048576 ))
  NEED_MB=$(( TXT_MB * 3 + 400 ))
  if [[ -f "$MODELS/$MODEL_NAME/textual/model.int8.onnx" ]]; then
    :
  elif (( TXT_MB < 200 )); then
    :  # harici ağırlıklı / küçük metin modeli: dönüşüm yok
  elif ! "$VENV/bin/python" -c 'import onnx' >/dev/null 2>&1; then
    :  # dönüşüm aracı yok (yukarıda uyarıldı)
  elif (( AVAIL_MB >= NEED_MB )); then
    ( cd "$APP" && "$VENV/bin/python" -m ownmemories_ml quantize --model-dir "$MODELS/$MODEL_NAME" ) \
      || warn "Metin modeli int8'e çevrilemedi; tam (fp32) model kullanılacak (~600 MB daha fazla RAM)."
  else
    warn "int8 dönüşümü için yeterli boş bellek yok (${AVAIL_MB} MB, ~${NEED_MB} MB gerekli); tam metin modeli kullanılacak."
  fi
fi
# Yüz tanıma modeli (isteğe bağlı): başarısızlık kurulumu durdurmaz.
if [[ "$FACE_MODEL" != "0" ]]; then
  echo "    Yüz tanıma modeli indiriliyor / doğrulanıyor: $MODELS/${FACE_MODEL##*/} (~190 MB)"
  if ! ( cd "$APP" && "$VENV/bin/python" -m ownmemories_ml download --model "$FACE_MODEL" --dest "$MODELS" ); then
    warn "Yüz tanıma modeli indirilemedi; Kişiler'de otomatik yüz tanıma kapalı (elle etiketleme çalışır). Sonra tekrar deneyin."
  fi
fi
# Fotoğraftaki yazı (OCR) modeli (isteğe bağlı, ~23 MB): başarısızlık kurulumu durdurmaz.
OCR="${OM_OCR-$(envval OM_OCR)}"; OCR="${OCR:-1}"
if [[ "$OCR" != "0" ]]; then
  echo "    Fotoğraftaki yazı (OCR) modeli indiriliyor / doğrulanıyor: $MODELS/ppocr-v6 (~23 MB)"
  # İsteğe bağlı ayna: OM_OCR_MODEL_BASE (ortam ya da ml.env; sudo ortamı taşımadığı için ml.env'den de okunur).
  OCR_BASE_URL="${OM_OCR_MODEL_BASE-$(envval OM_OCR_MODEL_BASE)}"
  if ! ( cd "$APP" && env ${OCR_BASE_URL:+OM_OCR_MODEL_BASE="$OCR_BASE_URL"} "$VENV/bin/python" -m ownmemories_ml download --model ocr --dest "$MODELS" ); then
    warn "OCR modeli indirilemedi; fotoğraftaki yazıyla arama kapalı (diğer aramalar çalışır). Sonra tekrar deneyin."
  fi
fi
chown -R root:root "$MODELS"
chmod -R u=rwX,go=rX "$MODELS"

# --- 3) Ortam dosyası (web/config/config.php'den)
mkdir -p "$ETC"
CONF="$DEST/web/config/config.php"
TMP="$(mktemp)"
{
  echo "# ownmemories-ml ayarları — server/install-ml.sh yazdı ($(date -u +%F)). Model ve iş parçacığı değişiklikleri korunur."
  if [[ -f "$CONF" ]]; then
    php -r '
      $c = require $argv[1];
      $q = fn($v) => "\"" . addcslashes(str_replace(["\r", "\n"], "", (string) $v), "\\\"") . "\"";
      $db = $c["db"] ?? [];
      echo "OM_DB_HOST=", $q($db["host"] ?? "127.0.0.1"), "\n";
      echo "OM_DB_PORT=", $q($db["port"] ?? 3306), "\n";
      echo "OM_DB_NAME=", $q($db["name"] ?? ""), "\n";
      echo "OM_DB_USER=", $q($db["user"] ?? ""), "\n";
      echo "OM_DB_PASS=", $q($db["pass"] ?? ""), "\n";
      echo "OM_STORAGE=", $q(rtrim((string) ($c["storage"]["path"] ?? ""), "/")), "\n";
    ' "$CONF"
  else
    echo "# config.php henüz yok (setup.php tamamlanmadı): servis veritabanı ayarlarını $CONF'tan okur."
  fi
  echo "OM_ML_MODEL=\"$MODEL\""
  echo "OM_ML_MODEL_DIR=\"$MODELS\""
  echo "OM_ML_PORT=\"8765\""
  echo "OM_ML_THREADS=\"$THREADS\""
  echo "OM_FACE_MODEL=\"$FACE_MODEL\""
  echo "OM_OCR=\"$OCR\""
  # Kullanıcının elle eklediği diğer OM_ML_* / OM_FACE_* / OM_OCR_* ayarları (ör. OM_ML_MIN_SCORE, OM_OCR_BATCH) korunur.
  if [[ -f "$ENVF" ]]; then
    grep -E '^OM_(ML|FACE|OCR)_[A-Z_]+=' "$ENVF" | grep -vE '^OM_ML_(MODEL|MODEL_DIR|PORT|THREADS)=|^OM_FACE_MODEL=' || true
  fi
} > "$TMP"
install -o root -g www-data -m 0640 "$TMP" "$ENVF"
rm -f "$TMP"

# --- 4) systemd birimi
UNIT_NEW="$(mktemp)"
cat > "$UNIT_NEW" <<UNIT
# OwnMemories akıllı arama servisi (ownmemories-ml) — server/install-ml.sh yazdı, elle düzenlemeyin.
# Yalnızca 127.0.0.1:8765 dinler. Günlük: journalctl -u ownmemories-ml
[Unit]
Description=OwnMemories smart search + face recognition (ML)
After=network.target mysql.service mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$APP
EnvironmentFile=-$ENVF
Environment=PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 OM_CONFIG_PHP=$CONF
ExecStart=$VENV/bin/python -m ownmemories_ml serve
Restart=on-failure
RestartSec=15
# Düşük öncelik: dizinleme arka planda, web'i yavaşlatmadan
Nice=10
CPUWeight=50
IOSchedulingClass=best-effort
IOSchedulingPriority=7
# SigLIP2 görsel + int8 metin modeli ~1.3 GB (+ yüz tanıma ~0.4 GB); tam metin modeliyle ~2.8 GB tepe
MemoryHigh=3000M
MemoryMax=4G
# Servis diske yazmaz; depolamayı ve modelleri yalnızca okur
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=read-only
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
LockPersonality=true

[Install]
WantedBy=multi-user.target
UNIT
install -m 0644 "$UNIT_NEW" "$UNIT"
rm -f "$UNIT_NEW"
systemctl daemon-reload

if (( ! MODEL_OK )); then
  systemctl disable --now ownmemories-ml.service 2>/dev/null || true
  warn "Akıllı arama modeli indirilemedi (internet / Hugging Face erişimi?). Arama, dosya adı/açıklama ile çalışmaya devam eder."
  warn "Daha sonra tekrar deneyin:  sudo bash $DEST/server/install-ml.sh"
  exit 1
fi
systemctl enable ownmemories-ml.service >/dev/null 2>&1
systemctl restart ownmemories-ml.service
for _ in $(seq 1 30); do
  if H="$(curl -fsS --max-time 2 http://127.0.0.1:8765/health 2>/dev/null)" && [[ "$H" == *'"ready": true'* ]]; then
    echo "    Akıllı arama hazır: $H"
    exit 0
  fi
  sleep 2
done
warn "ownmemories-ml başlatıldı ama henüz hazır değil; durum: journalctl -u ownmemories-ml -n 50"
exit 0
