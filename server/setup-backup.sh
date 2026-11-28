#!/usr/bin/env bash
# OwnMemories sunucu yedeği kurulum sihirbazı (bir kez, root olarak; ayarları değiştirmek için yeniden çalıştırılabilir).
#
#   sudo bash ~/OwnMemories/server/setup-backup.sh
#   sudo bash ~/OwnMemories/server/setup-backup.sh --show            mevcut ayarlar (gizli bilgiler olmadan)
#   sudo bash ~/OwnMemories/server/setup-backup.sh --show-password   yedek parolasını yeniden gösterir
#
# Hedefler: (1) yerel/harici disk ve/veya (2) uzak: Backblaze B2, S3 uyumlu (Wasabi, Hetzner, MinIO…), SFTP
# (başka bir bilgisayar, arkadaşınızın sunucusu) ya da bağlı ağ klasörü (NAS). Yedekler restic ile şifrelenir.
# Erişim anahtarları ve yedek parolası YALNIZCA /etc/ownmemories/backup.env dosyasında durur (root:root 0600).
#
# Etkileşimsiz kullanım (test/otomasyon): --non-interactive ve şu ortam değişkenleri
#   OM_BK_DISK=/mnt/usb/ownmemories-restic   (boş = yerel disk hedefi yok)
#   OM_BK_OFFSITE=none|b2|s3|sftp|local
#     b2/s3:  OM_BK_OFFSITE_ENDPOINT OM_BK_OFFSITE_BUCKET OM_BK_OFFSITE_PREFIX OM_BK_OFFSITE_KEY_ID OM_BK_OFFSITE_SECRET [OM_BK_OFFSITE_REGION]
#     sftp:   OM_BK_OFFSITE_SFTP=kullanici@makine OM_BK_OFFSITE_PORT=22 OM_BK_OFFSITE_PATH=ownmemories-yedek [OM_BK_SSH_KEY]
#     local:  OM_BK_OFFSITE_PATH=/mnt/nas/ownmemories-restic
#   OM_BK_LIMIT_UPLOAD (KiB/s)  OM_BK_INCLUDE_DERIVED=0|1  OM_BK_SCHEDULE=03:10  OM_BK_PASSWORD (mevcut depo parolası)
#   Verilmeyen değerler mevcut ayardan alınır (yeniden çalıştırma ayarları korur).
#   Yollar: OM_BACKUP_ENV OM_STATE_DIR OM_CACHE_DIR OM_ROOT OM_SYSTEMD_DIR SYSTEMCTL (bkz. server/lib/backup-common.sh)
set -Eeuo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/lib/backup-common.sh"
SYSTEMCTL="${SYSTEMCTL:-systemctl}"
OM_SYSTEMD_DIR="${OM_SYSTEMD_DIR:-/etc/systemd/system}"
INSTALL_ROOT="${OM_ROOT:-/var/www/ownmemories}"

NI=0 RUN_NOW="" MODE=setup
while (($#)); do
  case "$1" in
    --non-interactive|-y) NI=1 ;;
    --run-now) RUN_NOW=1 ;;
    --no-run) RUN_NOW=0 ;;
    --show) MODE=show ;;
    --show-password) MODE=password ;;
    -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    *) bk_die "bilinmeyen seçenek: $1" ;;
  esac
  shift
done
[[ $EUID -eq 0 || -n "${OM_SETUP_ALLOW_NONROOT:-}" ]] || bk_die "root olarak çalıştırın: sudo bash $0"

B=$'\e[1m' D=$'\e[2m' G=$'\e[32m' Y=$'\e[33m' R=$'\e[31m' N=$'\e[0m'
[[ -t 1 ]] || { B="" D="" G="" Y="" R="" N=""; }
h1() { printf '\n%s== %s ==%s\n' "$B" "$*" "$N"; }
info() { printf '%s\n' "$*"; }
ok() { printf '%s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '%s! %s%s\n' "$Y" "$*" "$N" >&2; }

ask() { # ask "Soru" varsayılan → $REPLY
  local q=$1 def=${2-}
  if (( NI )); then REPLY=$def; return; fi
  if [[ -n "$def" ]]; then read -r -p "$q [$def]: " REPLY; REPLY=${REPLY:-$def}; else read -r -p "$q: " REPLY; fi
}
ask_secret() { # ask_secret "Soru" mevcut → $REPLY (boş bırakılırsa mevcut korunur)
  local q=$1 cur=${2-}
  if (( NI )); then REPLY=$cur; return; fi
  if [[ -n "$cur" ]]; then read -r -s -p "$q [kayıtlı; değiştirmek için yeni değeri yazın, korumak için Enter]: " REPLY
  else read -r -s -p "$q: " REPLY; fi
  echo
  REPLY=${REPLY:-$cur}
}
ask_yn() { # ask_yn "Soru" e|h → 0/1
  local q=$1 def=${2:-e} a
  if (( NI )); then [[ "$def" == e ]]; return; fi
  read -r -p "$q [$([[ $def == e ]] && echo 'E/h' || echo 'e/H')]: " a
  a=${a:-$def}
  [[ "${a,,}" == e* || "${a,,}" == y* ]]
}

# ----------------------------------------------------------------- mevcut ayarlar
HAVE_ENV=0
if bk_load_env; then HAVE_ENV=1; fi

if [[ $MODE == password ]]; then
  (( HAVE_ENV )) || bk_die "Yedek henüz kurulmamış."
  echo "Yedek parolası: $RESTIC_PASSWORD"
  exit 0
fi
if [[ $MODE == show ]]; then
  (( HAVE_ENV )) || { echo "Yedek henüz kurulmamış. Kurmak için: sudo bash $0"; exit 0; }
  echo "Ayar dosyası : $OM_BACKUP_ENV"
  echo "Zaman        : her gece $BACKUP_SCHEDULE (± 20 dk)"
  echo "Kapsam       : orijinaller + veritabanı + ayarlar$([[ $BACKUP_INCLUDE_DERIVED == 1 ]] && echo ' + küçük resimler/önizlemeler')"
  for id in $BACKUP_TARGETS; do
    printf "%-13s: %s\n" "Hedef $id" "$(bk_tvar "$id" LABEL) → $(bk_location "$id")$([[ -n "$(bk_tvar "$id" LIMIT_UPLOAD)" ]] && echo " (sınır $(bk_tvar "$id" LIMIT_UPLOAD) KiB/s)")"
  done
  exit 0
fi

cur_targets=" ${BACKUP_TARGETS:-} "
DISK_PATH=""
[[ "$cur_targets" == *" disk "* ]] && DISK_PATH="${BACKUP_DISK_REPO:-}"
DISK_LABEL="${BACKUP_DISK_LABEL:-Harici disk}"
OFF_TYPE="none"
[[ "$cur_targets" == *" offsite "* ]] && OFF_TYPE="${BACKUP_OFFSITE_TYPE:-none}"
OFF_ENDPOINT="${BACKUP_OFFSITE_ENDPOINT:-}" OFF_BUCKET="${BACKUP_OFFSITE_BUCKET:-}" OFF_PREFIX="${BACKUP_OFFSITE_PREFIX:-ownmemories}"
OFF_KEY_ID="${BACKUP_OFFSITE_AWS_ACCESS_KEY_ID:-}" OFF_SECRET="${BACKUP_OFFSITE_AWS_SECRET_ACCESS_KEY:-}" OFF_REGION="${BACKUP_OFFSITE_AWS_DEFAULT_REGION:-}"
OFF_SFTP="${BACKUP_OFFSITE_SFTP_HOST:-}" OFF_PORT="${BACKUP_OFFSITE_SFTP_PORT:-22}" OFF_PATH="${BACKUP_OFFSITE_PATH:-}"
SSH_KEY="${BACKUP_OFFSITE_SSH_KEY:-/root/.ssh/ownmemories_backup}"
LIMIT="${BACKUP_OFFSITE_LIMIT_UPLOAD:-}"
INCLUDE_DERIVED="${BACKUP_INCLUDE_DERIVED:-0}"
SCHEDULE="${BACKUP_SCHEDULE:-03:10}"
PASSWORD="${RESTIC_PASSWORD:-}"
# Eski rsync yedeğinin klasörü (server/backup.sh'nin önceki sürümü) öneri olarak.
if [[ -z "$DISK_PATH" && $HAVE_ENV == 0 && -r /etc/ownmemories-backup.env ]]; then
  legacy="$(sed -n 's/^BACKUP_DIR=//p' /etc/ownmemories-backup.env | tr -d "\"'" | head -n1)"
  [[ -n "$legacy" ]] && DISK_PATH="${legacy%/}-restic"
fi

# Etkileşimsiz: OM_BK_* verilmişse onu kullan.
if (( NI )); then
  [[ -n "${OM_BK_DISK+x}" ]] && DISK_PATH="$OM_BK_DISK"
  [[ -n "${OM_BK_DISK_LABEL+x}" ]] && DISK_LABEL="$OM_BK_DISK_LABEL"
  [[ -n "${OM_BK_OFFSITE+x}" ]] && OFF_TYPE="$OM_BK_OFFSITE"
  [[ -n "${OM_BK_OFFSITE_ENDPOINT+x}" ]] && OFF_ENDPOINT="$OM_BK_OFFSITE_ENDPOINT"
  [[ -n "${OM_BK_OFFSITE_BUCKET+x}" ]] && OFF_BUCKET="$OM_BK_OFFSITE_BUCKET"
  [[ -n "${OM_BK_OFFSITE_PREFIX+x}" ]] && OFF_PREFIX="$OM_BK_OFFSITE_PREFIX"
  [[ -n "${OM_BK_OFFSITE_KEY_ID+x}" ]] && OFF_KEY_ID="$OM_BK_OFFSITE_KEY_ID"
  [[ -n "${OM_BK_OFFSITE_SECRET+x}" ]] && OFF_SECRET="$OM_BK_OFFSITE_SECRET"
  [[ -n "${OM_BK_OFFSITE_REGION+x}" ]] && OFF_REGION="$OM_BK_OFFSITE_REGION"
  [[ -n "${OM_BK_OFFSITE_SFTP+x}" ]] && OFF_SFTP="$OM_BK_OFFSITE_SFTP"
  [[ -n "${OM_BK_OFFSITE_PORT+x}" ]] && OFF_PORT="$OM_BK_OFFSITE_PORT"
  [[ -n "${OM_BK_OFFSITE_PATH+x}" ]] && OFF_PATH="$OM_BK_OFFSITE_PATH"
  [[ -n "${OM_BK_SSH_KEY+x}" ]] && SSH_KEY="$OM_BK_SSH_KEY"
  [[ -n "${OM_BK_LIMIT_UPLOAD+x}" ]] && LIMIT="$OM_BK_LIMIT_UPLOAD"
  [[ -n "${OM_BK_INCLUDE_DERIVED+x}" ]] && INCLUDE_DERIVED="$OM_BK_INCLUDE_DERIVED"
  [[ -n "${OM_BK_SCHEDULE+x}" ]] && SCHEDULE="$OM_BK_SCHEDULE"
  [[ -n "${OM_BK_PASSWORD:-}" ]] && PASSWORD="$OM_BK_PASSWORD"
fi

# ----------------------------------------------------------------- restic
if ! command -v restic >/dev/null; then
  if [[ -n "${OM_SETUP_NO_APT:-}" ]]; then bk_die "restic kurulu değil."; fi
  info "restic kuruluyor…"
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq restic >/dev/null || bk_die "restic kurulamadı: sudo apt-get install -y restic"
fi

CONFIG="${OM_CONFIG:-$INSTALL_ROOT/web/config/config.php}"
STORAGE=""
if [[ -r "$CONFIG" ]]; then OM_CONFIG="$CONFIG" STORAGE="$(OM_CONFIG="$CONFIG" bk_cfg storage.path 2>/dev/null || true)"; fi
[[ -n "$STORAGE" ]] || warn "OwnMemories yapılandırması bulunamadı ($CONFIG). Önce kurulumu (setup.php) tamamlayın; yedek yine de ayarlanır."

same_disk() { # same_disk YOL → 0 ise depo veya kök diskle aynı dosya sistemi
  local p=$1 probe
  probe="$p"; while [[ ! -e "$probe" && "$probe" != / ]]; do probe="$(dirname "$probe")"; done
  local dev; dev="$(stat -c %d "$probe")"
  [[ -n "$STORAGE" && -e "$STORAGE" && "$dev" == "$(stat -c %d "$STORAGE")" ]] && return 0
  [[ "$dev" == "$(stat -c %d /)" ]] && return 0
  return 1
}

check_local_path() { # klasörün kullanılabilirliği ve aynı disk uyarısı
  local p=$1
  [[ "$p" == /* ]] || { warn "Tam yol verin (/ ile başlamalı): $p"; return 1; }
  if same_disk "$p" && [[ -z "${OM_BK_ALLOW_SAME_DISK:-}" ]]; then
    warn "$p, fotoğrafların (veya sistemin) bulunduğu diskle AYNI diskte görünüyor."
    warn "Disk bozulursa yedek de kaybolur. Harici disk takılı ve bağlı (mount) mı? (lsblk, df -h)"
    if (( NI )); then warn "Etkileşimsiz kipte devam ediliyor (OM_BK_ALLOW_SAME_DISK=1 ile bu uyarı kapanır)."; return 0; fi
    ask_yn "Yine de bu klasör kullanılsın mı?" h || return 1
  fi
  mkdir -p "$p" || { warn "Klasör oluşturulamadı: $p"; return 1; }
  return 0
}

# ----------------------------------------------------------------- sorular
if (( ! NI )); then
  cat <<EOF
${B}OwnMemories sunucu yedeği${N}
Fotoğraf ve videolarınızın orijinalleri, veritabanı ve ayarlar her gece şifreli ve sürümlü olarak
yedeklenir (restic). En iyisi iki hedef: evdeki bir harici disk + evin dışında bir kopya (Backblaze B2 vb.).
Mevcut değerler köşeli parantezde; Enter ile korunur.
EOF
fi

h1 "1/4 Yerel veya harici disk"
if (( ! NI )); then
  if ask_yn "Yerel/harici disk hedefi kullanılsın mı? (ör. USB disk /mnt/yedek)" "$([[ -n "$DISK_PATH" || $HAVE_ENV == 0 ]] && echo e || echo h)"; then
    while :; do
      ask "Yedek klasörü (harici diskin bağlı olduğu yerde)" "${DISK_PATH:-/mnt/yedek/ownmemories-restic}"
      DISK_PATH=$REPLY
      check_local_path "$DISK_PATH" && break
    done
    ask "Bu hedefin adı (panelde görünür)" "$DISK_LABEL"; DISK_LABEL=$REPLY
  else
    DISK_PATH=""
  fi
elif [[ -n "$DISK_PATH" ]]; then
  check_local_path "$DISK_PATH" || bk_die "Yerel hedef kullanılamıyor: $DISK_PATH"
fi
[[ -n "$DISK_PATH" ]] && ok "Yerel hedef: $DISK_PATH" || info "Yerel hedef yok."

h1 "2/4 Uzak hedef (evin dışında bir kopya)"
if (( ! NI )); then
  cat <<EOF
  0) Yok
  1) Backblaze B2           (önerilen; ~6 \$/TB/ay, ilk 10 GB ücretsiz)
  2) Diğer S3 uyumlu        (Wasabi, Hetzner Object Storage, MinIO, AWS S3…)
  3) SFTP                   (başka bir bilgisayar, arkadaşınızın sunucusu, NAS üzerinde SSH)
  4) Bağlı ağ klasörü       (NAS / SMB / NFS olarak bağlanmış bir klasör)
EOF
  case "$OFF_TYPE" in b2) d=1 ;; s3) d=2 ;; sftp) d=3 ;; local) d=4 ;; *) d=$([[ $HAVE_ENV == 0 ]] && echo 1 || echo 0) ;; esac
  ask "Seçim" "$d"
  case "$REPLY" in 1) OFF_TYPE=b2 ;; 2) OFF_TYPE=s3 ;; 3) OFF_TYPE=sftp ;; 4) OFF_TYPE=local ;; *) OFF_TYPE=none ;; esac
fi

OFF_REPO="" OFF_LABEL="" OFF_SFTP_COMMAND=""
case "$OFF_TYPE" in
  b2|s3)
    if (( ! NI )); then
      if [[ $OFF_TYPE == b2 ]]; then
        info "${D}B2: Buckets → bucket'ınızın 'Endpoint' değeri (ör. s3.eu-central-003.backblazeb2.com);"
        info "Application Keys → yalnızca bu bucket'a erişen yeni bir anahtar (keyID + applicationKey).${N}"
        ask "Endpoint" "$OFF_ENDPOINT"
      else
        ask "Endpoint (ör. https://fsn1.your-objectstorage.com veya https://s3.wasabisys.com)" "$OFF_ENDPOINT"
      fi
      OFF_ENDPOINT=$REPLY
      ask "Bucket adı" "$OFF_BUCKET"; OFF_BUCKET=$REPLY
      ask "Bucket içindeki klasör" "$OFF_PREFIX"; OFF_PREFIX=$REPLY
      ask "Erişim anahtarı kimliği ($([[ $OFF_TYPE == b2 ]] && echo keyID || echo 'Access Key ID'))" "$OFF_KEY_ID"; OFF_KEY_ID=$REPLY
      ask_secret "Gizli anahtar ($([[ $OFF_TYPE == b2 ]] && echo applicationKey || echo 'Secret Access Key'))" "$OFF_SECRET"; OFF_SECRET=$REPLY
      [[ $OFF_TYPE == s3 ]] && { ask "Bölge (boş bırakılabilir)" "$OFF_REGION"; OFF_REGION=$REPLY; }
    fi
    [[ -n "$OFF_ENDPOINT" && -n "$OFF_BUCKET" && -n "$OFF_KEY_ID" && -n "$OFF_SECRET" ]] || bk_die "Uzak hedef için endpoint, bucket ve anahtarlar gerekli."
    ep="${OFF_ENDPOINT%/}"; [[ "$ep" == http://* || "$ep" == https://* ]] || ep="https://$ep"
    OFF_ENDPOINT="$ep"
    OFF_REPO="s3:$ep/$OFF_BUCKET${OFF_PREFIX:+/${OFF_PREFIX#/}}"
    OFF_LABEL=$([[ $OFF_TYPE == b2 ]] && echo "Backblaze B2" || echo "S3 ($(echo "$ep" | sed -E 's#https?://##; s#/.*##'))")
    ;;
  sftp)
    if (( ! NI )); then
      ask "Uzak bilgisayar (kullanıcı@adres; Tailscale adı da olur, ör. yedek@arkadas-nas)" "$OFF_SFTP"; OFF_SFTP=$REPLY
      ask "SSH portu" "$OFF_PORT"; OFF_PORT=$REPLY
      ask "Uzak klasör (göreli = kullanıcının ev klasörüne göre)" "${OFF_PATH:-ownmemories-yedek}"; OFF_PATH=$REPLY
    fi
    [[ "$OFF_SFTP" =~ ^[A-Za-z0-9._-]+@[A-Za-z0-9._:-]+$ ]] || bk_die "SFTP hedefi kullanıcı@adres biçiminde olmalı (boşluksuz)."
    [[ "$OFF_PORT" =~ ^[0-9]{1,5}$ ]] || bk_die "SSH portu sayı olmalı: $OFF_PORT"
    OFF_PATH="${OFF_PATH:-ownmemories-yedek}"
    if [[ ! -f "$SSH_KEY" ]]; then
      mkdir -p "$(dirname "$SSH_KEY")"; chmod 700 "$(dirname "$SSH_KEY")"
      ssh-keygen -q -t ed25519 -N '' -C "ownmemories-backup@$(hostname)" -f "$SSH_KEY"
      ok "Yedek için SSH anahtarı oluşturuldu: $SSH_KEY"
    fi
    OFF_SFTP_COMMAND="ssh -i $SSH_KEY -p $OFF_PORT -o BatchMode=yes -o ServerAliveInterval=60 -o StrictHostKeyChecking=accept-new $OFF_SFTP -s sftp"
    if ! ssh -i "$SSH_KEY" -p "$OFF_PORT" -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new "$OFF_SFTP" true 2>/dev/null; then
      info "Uzak bilgisayar bu anahtarı henüz tanımıyor. Şu satırı uzaktaki ~/.ssh/authorized_keys dosyasına ekleyin:"
      echo; cat "$SSH_KEY.pub"; echo
      if (( ! NI )) && ask_yn "Şimdi ssh-copy-id ile eklensin mi? (uzak kullanıcının parolası sorulur)" e; then
        ssh-copy-id -i "$SSH_KEY.pub" -p "$OFF_PORT" -o StrictHostKeyChecking=accept-new "$OFF_SFTP" || true
      fi
      ssh -i "$SSH_KEY" -p "$OFF_PORT" -o BatchMode=yes -o ConnectTimeout=10 "$OFF_SFTP" true 2>/dev/null \
        || bk_die "SSH bağlantısı kurulamadı ($OFF_SFTP). Anahtarı ekleyip sihirbazı yeniden çalıştırın."
    fi
    ok "SSH bağlantısı çalışıyor: $OFF_SFTP"
    OFF_REPO="sftp:$OFF_SFTP:$OFF_PATH"
    OFF_LABEL="SFTP (${OFF_SFTP#*@})"
    ;;
  local)
    if (( ! NI )); then
      while :; do ask "Bağlı ağ klasörü (ör. /mnt/nas/ownmemories-restic)" "$OFF_PATH"; OFF_PATH=$REPLY; check_local_path "$OFF_PATH" && break; done
    else
      [[ -n "$OFF_PATH" ]] || bk_die "OM_BK_OFFSITE_PATH gerekli."
      check_local_path "$OFF_PATH" || bk_die "Klasör kullanılamıyor: $OFF_PATH"
    fi
    OFF_REPO="$OFF_PATH"
    OFF_LABEL="Ağ klasörü"
    ;;
  none) ;;
  *) bk_die "Bilinmeyen uzak hedef türü: $OFF_TYPE" ;;
esac
if [[ $OFF_TYPE != none && $OFF_TYPE != local ]]; then
  if (( ! NI )); then
    ask "Uzağa yükleme hız sınırı, KiB/s (boş = sınırsız; ör. 2500 ≈ 20 Mbit/s, ev internetini yormamak için)" "$LIMIT"; LIMIT=$REPLY
  fi
  [[ -z "$LIMIT" || "$LIMIT" =~ ^[0-9]+$ ]] || bk_die "Hız sınırı sayı olmalı (KiB/s): $LIMIT"
fi
[[ $OFF_TYPE == none ]] && info "Uzak hedef yok." || ok "Uzak hedef: $OFF_LABEL → $(printf '%s' "$OFF_REPO" | sed -E 's#(https?://)[^/@]+@#\1#')"
[[ -n "$DISK_PATH" || $OFF_TYPE != none ]] || bk_die "En az bir hedef seçmelisiniz."

h1 "3/4 Kapsam ve zaman"
if (( ! NI )); then
  info "Orijinaller, veritabanı ve ayarlar her zaman yedeklenir. Küçük resimler/önizlemeler/dönüştürülmüş videolar"
  info "yeniden üretilebilir; dahil etmek yedeği büyütür ama geri yüklemeden sonra beklemeyi azaltır."
  if ask_yn "Küçük resim ve önizlemeler de yedeklensin mi?" "$([[ $INCLUDE_DERIVED == 1 ]] && echo e || echo h)"; then INCLUDE_DERIVED=1; else INCLUDE_DERIVED=0; fi
  while :; do ask "Her gece saat kaçta (SS:DD; ± 20 dk rastgele kaydırılır)" "$SCHEDULE"; SCHEDULE=$REPLY; [[ "$SCHEDULE" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] && break; warn "SS:DD biçiminde yazın, ör. 03:10"; done
fi
[[ "$SCHEDULE" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || bk_die "Geçersiz saat: $SCHEDULE"
[[ "$INCLUDE_DERIVED" == 1 ]] || INCLUDE_DERIVED=0

h1 "4/4 Şifreleme parolası"
NEW_PASSWORD=0
if [[ -z "$PASSWORD" ]]; then
  if (( ! NI )); then
    info "Bu hedeflerde eski bir sunucudan kalma OwnMemories yedeği varsa onun parolasını girin."
    ask_secret "Mevcut yedek parolası (yoksa boş bırakın; yeni ve güçlü bir parola üretilir)" ""
    PASSWORD=$REPLY
  fi
  if [[ -z "$PASSWORD" ]]; then
    PASSWORD="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 40)"
    NEW_PASSWORD=1
  fi
else
  ok "Kayıtlı parola korunuyor."
fi

TARGETS=""
[[ -n "$DISK_PATH" ]] && TARGETS="disk"
[[ $OFF_TYPE != none ]] && TARGETS="${TARGETS:+$TARGETS }offsite"

if (( ! NI )); then
  h1 "Özet"
  [[ -n "$DISK_PATH" ]] && info "  Yerel : $DISK_LABEL → $DISK_PATH"
  [[ $OFF_TYPE != none ]] && info "  Uzak  : $OFF_LABEL → $(printf '%s' "$OFF_REPO" | sed -E 's#(https?://)[^/@]+@#\1#')${LIMIT:+ (sınır $LIMIT KiB/s)}"
  info "  Kapsam: orijinaller + veritabanı + ayarlar$([[ $INCLUDE_DERIVED == 1 ]] && echo ' + küçük resimler')"
  info "  Zaman : her gece $SCHEDULE · saklama: 7 günlük, 8 haftalık, 24 aylık, 5 yıllık"
  ask_yn "Kaydedilsin mi?" e || bk_die "Vazgeçildi; hiçbir şey değişmedi."
fi

# ----------------------------------------------------------------- ayar dosyası
env_dir="$(dirname "$OM_BACKUP_ENV")"
mkdir -p "$env_dir"; chmod 700 "$env_dir"
if [[ -f "$OM_BACKUP_ENV" ]]; then cp -p "$OM_BACKUP_ENV" "$OM_BACKUP_ENV.bak"; chmod 600 "$OM_BACKUP_ENV.bak"; fi
tmp_env="$(mktemp "$env_dir/.backup.env.XXXXXX")"
chmod 600 "$tmp_env"
{
  echo "# OwnMemories yedek ayarları — server/setup-backup.sh yazdı ($(date '+%F %T'))."
  echo "# GİZLİDİR: yedek parolası ve erişim anahtarları içerir. root:root 0600. Depoya/sohbete koymayın."
  echo "# Değiştirmek için sihirbazı yeniden çalıştırın: sudo bash ~/OwnMemories/server/setup-backup.sh"
  echo "RESTIC_PASSWORD=$(bk_quote "$PASSWORD")"
  echo "OM_ROOT=$(bk_quote "$INSTALL_ROOT")"
  echo "BACKUP_HOST=$(bk_quote "${BACKUP_HOST:-ownmemories}")"
  echo "BACKUP_TARGETS=$(bk_quote "$TARGETS")"
  echo "BACKUP_SCHEDULE=$(bk_quote "$SCHEDULE")"
  echo "BACKUP_INCLUDE_DERIVED=$INCLUDE_DERIVED"
  echo "BACKUP_KEEP_DAILY=${BACKUP_KEEP_DAILY:-7}"
  echo "BACKUP_KEEP_WEEKLY=${BACKUP_KEEP_WEEKLY:-8}"
  echo "BACKUP_KEEP_MONTHLY=${BACKUP_KEEP_MONTHLY:-24}"
  echo "BACKUP_KEEP_YEARLY=${BACKUP_KEEP_YEARLY:-5}"
  echo "BACKUP_CHECK_SUBSET=$(bk_quote "${BACKUP_CHECK_SUBSET:-2%}")"
  echo "BACKUP_RESTORE_TEST=${BACKUP_RESTORE_TEST:-1}"
  if [[ -n "$DISK_PATH" ]]; then
    echo; echo "# Yerel / harici disk"
    echo "BACKUP_DISK_TYPE=local"
    echo "BACKUP_DISK_LABEL=$(bk_quote "$DISK_LABEL")"
    echo "BACKUP_DISK_REPO=$(bk_quote "$DISK_PATH")"
  fi
  if [[ $OFF_TYPE != none ]]; then
    echo; echo "# Uzak hedef"
    echo "BACKUP_OFFSITE_TYPE=$OFF_TYPE"
    echo "BACKUP_OFFSITE_LABEL=$(bk_quote "$OFF_LABEL")"
    echo "BACKUP_OFFSITE_REPO=$(bk_quote "$OFF_REPO")"
    echo "BACKUP_OFFSITE_LIMIT_UPLOAD=$(bk_quote "$LIMIT")"
    case "$OFF_TYPE" in
      b2|s3)
        echo "BACKUP_OFFSITE_ENDPOINT=$(bk_quote "$OFF_ENDPOINT")"
        echo "BACKUP_OFFSITE_BUCKET=$(bk_quote "$OFF_BUCKET")"
        echo "BACKUP_OFFSITE_PREFIX=$(bk_quote "$OFF_PREFIX")"
        echo "BACKUP_OFFSITE_AWS_ACCESS_KEY_ID=$(bk_quote "$OFF_KEY_ID")"
        echo "BACKUP_OFFSITE_AWS_SECRET_ACCESS_KEY=$(bk_quote "$OFF_SECRET")"
        echo "BACKUP_OFFSITE_AWS_DEFAULT_REGION=$(bk_quote "$OFF_REGION")"
        ;;
      sftp)
        echo "BACKUP_OFFSITE_SFTP_HOST=$(bk_quote "$OFF_SFTP")"
        echo "BACKUP_OFFSITE_SFTP_PORT=$(bk_quote "$OFF_PORT")"
        echo "BACKUP_OFFSITE_PATH=$(bk_quote "$OFF_PATH")"
        echo "BACKUP_OFFSITE_SSH_KEY=$(bk_quote "$SSH_KEY")"
        echo "BACKUP_OFFSITE_SFTP_COMMAND=$(bk_quote "$OFF_SFTP_COMMAND")"
        ;;
      local) echo "BACKUP_OFFSITE_PATH=$(bk_quote "$OFF_PATH")" ;;
    esac
  fi
} >"$tmp_env"
chown root:root "$tmp_env" 2>/dev/null || true
mv "$tmp_env" "$OM_BACKUP_ENV"
ok "Ayarlar kaydedildi: $OM_BACKUP_ENV (yalnızca root okuyabilir)"

# Yeni ayarları yükle (bk_restic bunları kullanır).
unset BACKUP_TARGETS BACKUP_DISK_REPO BACKUP_OFFSITE_REPO BACKUP_OFFSITE_SFTP_COMMAND BACKUP_OFFSITE_LIMIT_UPLOAD
bk_load_env

# ----------------------------------------------------------------- depoları hazırla
mkdir -p "$OM_CACHE_DIR"; chmod 700 "$OM_CACHE_DIR"
# Önce TÜM hedefler yoklanır, parola netleşir; yeni depolar ancak ondan sonra oluşturulur. (Aksi hâlde ilk hedef yeni
# parolayla oluşturulup ikinci hedefte eski parola girildiğinde iki depo farklı parolalarla kalırdı.)
probe_repo() { # probe_repo ID → PROBE: ok | wrong | missing | error (PROBE_OUT: hata metni)
  local id=$1 rc=0
  PROBE_OUT="$(bk_restic "$id" cat config 2>&1 >/dev/null)" || rc=$?
  if (( rc == 0 )); then PROBE=ok
  elif [[ $rc == 12 ]] || grep -qiE 'wrong password|no key found' <<<"$PROBE_OUT"; then PROBE=wrong
  elif [[ $rc == 10 ]] || grep -qiE 'does not exist|Is there a repository|unable to open config file|no such file|not found|NoSuchKey|key does not exist' <<<"$PROBE_OUT"; then PROBE=missing
  else PROBE=error
  fi
}
set_password() {
  sed -i "s|^RESTIC_PASSWORD=.*|RESTIC_PASSWORD=$(bk_quote "$1" | sed 's/[|&\\]/\\&/g')|" "$OM_BACKUP_ENV"
  RESTIC_PASSWORD=$1; PASSWORD=$1; NEW_PASSWORD=0
}
declare -A REPO_STATE=()
tries=0
while :; do
  wrong_id=""
  for id in $BACKUP_TARGETS; do
    probe_repo "$id"
    REPO_STATE[$id]=$PROBE
    case "$PROBE" in
      wrong) wrong_id=$id ;;
      error) bk_die "[$id] depoya ulaşılamadı: $(printf '%s' "$PROBE_OUT" | tail -n 4 | bk_scrub)" ;;
    esac
  done
  [[ -z "$wrong_id" ]] && break
  warn "[$wrong_id] Bu konumda bir OwnMemories/restic yedek deposu var ama parola uymuyor."
  (( NI )) && bk_die "[$wrong_id] Mevcut depo için doğru parolayı OM_BK_PASSWORD ile verin."
  for id in $BACKUP_TARGETS; do
    [[ ${REPO_STATE[$id]} == ok ]] && bk_die "Hedefler farklı parolalarla şifrelenmiş ($id ile $wrong_id). Aynı parolayı kullanan hedefler seçin ya da birini yeni (boş) bir klasöre/bucket'a taşıyın."
  done
  (( ++tries > 3 )) && bk_die "Parola doğrulanamadı."
  ask_secret "Bu deponun (eski) yedek parolası" ""
  [[ -n "$REPLY" ]] || bk_die "Parola girilmedi; hiçbir depo değiştirilmedi."
  set_password "$REPLY"
done
for id in $BACKUP_TARGETS; do
  if [[ ${REPO_STATE[$id]} == ok ]]; then
    ok "[$id] mevcut yedek deposu bulundu, parola doğru."
  elif out="$(bk_restic "$id" init 2>&1)"; then
    ok "[$id] yeni şifreli yedek deposu oluşturuldu."
  else
    bk_die "[$id] depo oluşturulamadı: $(printf '%s' "$out" | tail -n 4 | bk_scrub)"
  fi
done

# ----------------------------------------------------------------- durum, istek klasörü, betikler, systemd
mkdir -p "$OM_STATE_DIR" "$BK_SPOOL_DIR"
if getent group "$OM_WEB_GROUP" >/dev/null 2>&1; then
  chown root:"$OM_WEB_GROUP" "$OM_STATE_DIR" "$BK_SPOOL_DIR"
fi
chmod 750 "$OM_STATE_DIR"
chmod 2770 "$BK_SPOOL_DIR"   # web sunucusu "Şimdi yedekle" isteğini buraya yazar
{
  printf '{"schedule":"%s","timezone":"%s","include_derived":%s,"retention":{"daily":%d,"weekly":%d,"monthly":%d,"yearly":%d},"targets":[' \
    "$BACKUP_SCHEDULE" "$(bk_server_tz)" "$([[ $BACKUP_INCLUDE_DERIVED == 1 ]] && echo true || echo false)" \
    "$BACKUP_KEEP_DAILY" "$BACKUP_KEEP_WEEKLY" "$BACKUP_KEEP_MONTHLY" "$BACKUP_KEEP_YEARLY"
  sep=""
  for id in $BACKUP_TARGETS; do
    php -r 'echo $argv[1], json_encode(["id" => $argv[2], "type" => $argv[3], "label" => $argv[4], "location" => $argv[5], "upload_limit_kib" => $argv[6]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' \
      "$sep" "$id" "$(bk_tvar "$id" TYPE)" "$(bk_tvar "$id" LABEL)" "$(bk_location "$id")" "$(bk_tvar "$id" LIMIT_UPLOAD)"
    sep=","
  done
  echo ']}'
} | bk_status configure
ok "Durum dosyası: $BK_STATUS_FILE (panel → Yönetim → Yedekleme)"

# Çalışan kurulumdaki betikler bu sürümle aynı olsun (update.sh henüz çalışmadıysa).
if [[ "$HERE" != "$INSTALL_ROOT/server" && -d "$INSTALL_ROOT" ]]; then
  mkdir -p "$INSTALL_ROOT/server/lib"
  install -m 0755 "$HERE/backup.sh" "$HERE/backup-legacy.sh" "$HERE/restore.sh" "$HERE/setup-backup.sh" "$INSTALL_ROOT/server/"
  install -m 0644 "$HERE/lib/backup-common.sh" "$HERE/lib/backup-status.php" "$INSTALL_ROOT/server/lib/"
fi

mkdir -p "$OM_SYSTEMD_DIR/ownmemories-backup.timer.d"
for u in ownmemories-backup.service ownmemories-backup.timer ownmemories-backup.path; do
  install -m 0644 "$HERE/$u" "$OM_SYSTEMD_DIR/$u"
done
cat >"$OM_SYSTEMD_DIR/ownmemories-backup.timer.d/schedule.conf" <<EOF
# setup-backup.sh yazdı: gece yedeği saati
[Timer]
OnCalendar=
OnCalendar=*-*-* $BACKUP_SCHEDULE:00
EOF
$SYSTEMCTL daemon-reload
$SYSTEMCTL enable --now ownmemories-backup.timer >/dev/null 2>&1 || $SYSTEMCTL enable --now ownmemories-backup.timer
$SYSTEMCTL enable --now ownmemories-backup.path >/dev/null 2>&1 || $SYSTEMCTL enable --now ownmemories-backup.path
ok "Zamanlayıcı etkin: her gece $BACKUP_SCHEDULE (ownmemories-backup.timer); panelden 'Şimdi yedekle' (ownmemories-backup.path)"

if (( NEW_PASSWORD )); then
  cat <<EOF

${R}${B}┌──────────────────────────────────────────────────────────────────────┐
│  YEDEK PAROLASI — ŞİMDİ PAROLA YÖNETİCİNİZE KAYDEDİN                 │
└──────────────────────────────────────────────────────────────────────┘${N}

      ${B}$PASSWORD${N}

  Yedekler bu parolayla şifrelenir. Sunucu tamamen kaybolursa yedekleri
  YALNIZCA bu parolayla açabilirsiniz; parolasız yedek okunamaz.
  (Sunucu çalıştığı sürece yeniden görmek için: sudo bash $0 --show-password)
EOF
  if (( ! NI )); then read -r -p "Parolayı kaydettiyseniz Enter'a basın… " _; fi
fi

if [[ -z "$RUN_NOW" ]]; then
  if (( NI )); then RUN_NOW=0; elif ask_yn "İlk yedek şimdi arka planda başlatılsın mı? (büyük kütüphanelerde uzak hedefe ilk yükleme günler sürebilir)" e; then RUN_NOW=1; else RUN_NOW=0; fi
fi
if (( RUN_NOW )); then
  $SYSTEMCTL start --no-block ownmemories-backup.service
  ok "İlk yedek başladı. İzlemek için: journalctl -fu ownmemories-backup   · Panel: Yönetim → Yedekleme"
fi

cat <<EOF

${B}Hazır.${N}
  Durum            : Yönetim paneli → Yedekleme   (veya: systemctl status ownmemories-backup)
  Elle yedek       : sudo systemctl start ownmemories-backup
  Geri yükleme testi: sudo bash $INSTALL_ROOT/server/restore.sh test
  Ayarları değiştir: sudo bash $0
EOF
