# OwnMemories yedekleme — backup.sh, restore.sh ve setup-backup.sh'nin ortak işlevleri (source edilir).
#
# Ayarlar /etc/ownmemories/backup.env içindedir (root:root 0600; parola ve erişim anahtarları YALNIZCA orada).
# Testler için yollar ortam değişkenleriyle değiştirilebilir:
#   OM_BACKUP_ENV   ayar dosyası            (/etc/ownmemories/backup.env)
#   OM_STATE_DIR    durum/istek/çalışma      (/var/lib/ownmemories)
#   OM_CACHE_DIR    restic önbelleği         (/var/cache/ownmemories/restic)
#   OM_ROOT         kurulum klasörü          (/var/www/ownmemories)
#   OM_CONFIG       config.php               ($OM_ROOT/web/config/config.php)
#   OM_WEB_GROUP    web sunucusu grubu       (www-data)

OM_BACKUP_ENV="${OM_BACKUP_ENV:-/etc/ownmemories/backup.env}"
OM_STATE_DIR="${OM_STATE_DIR:-/var/lib/ownmemories}"
OM_CACHE_DIR="${OM_CACHE_DIR:-/var/cache/ownmemories/restic}"
OM_WEB_GROUP="${OM_WEB_GROUP:-www-data}"
OM_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BK_STATUS_FILE="$OM_STATE_DIR/backup-status.json"
BK_SPOOL_DIR="$OM_STATE_DIR/backup-spool"
BK_STAGING="$OM_STATE_DIR/backup-staging"

bk_log() { printf '%s %s\n' "$(date '+%F %T')" "$*"; }
bk_warn() { printf '%s UYARI: %s\n' "$(date '+%F %T')" "$*" >&2; }
bk_die() { printf 'HATA: %s\n' "$*" >&2; exit 1; }

# Tek tırnaklı, bash'in güvenle source edebileceği değer.
bk_quote() { local s=$1; printf "'%s'" "${s//\'/\'\\\'\'}"; }

bk_load_env() {
  [[ -r "$OM_BACKUP_ENV" ]] || return 1
  # shellcheck disable=SC1090
  set -a; . "$OM_BACKUP_ENV"; set +a
  OM_ROOT="${OM_ROOT:-/var/www/ownmemories}"
  OM_CONFIG="${OM_CONFIG:-$OM_ROOT/web/config/config.php}"
  BACKUP_HOST="${BACKUP_HOST:-ownmemories}"
  BACKUP_TARGETS="${BACKUP_TARGETS:-}"
  BACKUP_SCHEDULE="${BACKUP_SCHEDULE:-03:10}"
  BACKUP_INCLUDE_DERIVED="${BACKUP_INCLUDE_DERIVED:-0}"
  BACKUP_KEEP_DAILY="${BACKUP_KEEP_DAILY:-7}"
  BACKUP_KEEP_WEEKLY="${BACKUP_KEEP_WEEKLY:-8}"
  BACKUP_KEEP_MONTHLY="${BACKUP_KEEP_MONTHLY:-24}"
  BACKUP_KEEP_YEARLY="${BACKUP_KEEP_YEARLY:-5}"
  BACKUP_CHECK_SUBSET="${BACKUP_CHECK_SUBSET:-2%}"
  BACKUP_CHECK_DAYS="${BACKUP_CHECK_DAYS:-7}"
  BACKUP_RESTORE_TEST="${BACKUP_RESTORE_TEST:-1}"
  return 0
}

# Hedefe ait değişken: bk_tvar disk REPO → $BACKUP_DISK_REPO
bk_tvar() { local n="BACKUP_${1^^}_$2"; printf '%s' "${!n-}"; }

# Gösterim için hedef konumu (kimlik bilgisi içermez).
bk_location() {
  local repo; repo="$(bk_tvar "$1" REPO)"
  printf '%s' "$repo" | sed -E 's#(https?://)[^/@]+@#\1#'
}

# restic'i bir hedef için çalıştırır: bk_restic disk snapshots --json
bk_restic() {
  local id=$1; shift
  local -a opts=(--cache-dir "$OM_CACHE_DIR")
  local v
  v="$(bk_tvar "$id" LIMIT_UPLOAD)"; [[ -n "$v" && "$v" != 0 ]] && opts+=(--limit-upload "$v")
  v="$(bk_tvar "$id" SFTP_COMMAND)"; [[ -n "$v" ]] && opts+=(-o "sftp.command=$v")
  (
    export RESTIC_REPOSITORY; RESTIC_REPOSITORY="$(bk_tvar "$id" REPO)"
    export RESTIC_PASSWORD
    for k in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION B2_ACCOUNT_ID B2_ACCOUNT_KEY RCLONE_CONFIG; do
      v="$(bk_tvar "$id" "$k")"
      if [[ -n "$v" ]]; then export "$k=$v"; else unset "$k"; fi
    done
    exec restic "${opts[@]}" "$@"
  )
}

# config.php'den değer okur: bk_cfg db.name
# config.php bir PHP dosyasıdır ve web sunucusu (www-data) tarafından yazılabilir. root olarak çalıştırmak, web
# uygulamasını ele geçiren birine root yetkisi verirdi (yedek her gece ve panelden "Şimdi yedekle" ile root çalışır).
# Bu yüzden dosya, SAHİBİNİN yetkisiyle değerlendirilir (root'a aitse ve başkası yazamıyorsa root olarak).
# Değerde satır sonu/denetim karakteri varsa reddedilir (mysql ayar dosyasına seçenek sokulamasın).
bk_cfg() {
  local -a run=()
  if [[ $EUID -eq 0 ]]; then
    local f owner perm
    f="$(readlink -f -- "$OM_CONFIG" 2>/dev/null || printf '%s' "$OM_CONFIG")"
    owner="$(stat -L -c %u -- "$f" 2>/dev/null || echo 0)"
    perm="$(stat -L -c %a -- "$f" 2>/dev/null || echo 600)"
    if [[ "$owner" != 0 || $(( 8#$perm & 8#022 )) -ne 0 ]]; then
      [[ "$owner" == 0 ]] && owner="$(id -u "$OM_WEB_GROUP" 2>/dev/null || echo 65534)"
      command -v setpriv >/dev/null || { echo "setpriv bulunamadı (util-linux)" >&2; return 3; }
      run=(setpriv --reuid="$owner" --regid="$(id -g "$owner" 2>/dev/null || echo 65534)" --clear-groups --)
    fi
  fi
  "${run[@]}" php -d display_errors=stderr -r '$c = require $argv[1]; foreach (explode(".", $argv[2]) as $p) { if (!is_array($c) || !array_key_exists($p, $c)) { exit(2); } $c = $c[$p]; }
    if (!is_scalar($c) || preg_match("/[\\x00-\\x1f\\x7f]/", (string) $c)) { fwrite(STDERR, "config.php: geçersiz değer: " . $argv[2] . "\n"); exit(4); } echo $c;' "$OM_CONFIG" "$1"
}

# Durum JSON yardımcısı (server/lib/backup-status.php).
bk_status() { php "$OM_LIB_DIR/backup-status.php" "$BK_STATUS_FILE" "$@"; }

# Hata metnindeki gizli değerleri maskeler (stdin → stdout).
bk_scrub() {
  local s; s="$(cat)"
  local k v
  for k in RESTIC_PASSWORD $(compgen -v | grep -E '_(KEY|KEY_ID|SECRET|SECRET_ACCESS_KEY|ACCOUNT_KEY|PASSWORD|TOKEN)$' || true); do
    v="${!k-}"
    [[ ${#v} -ge 6 ]] && s="${s//"$v"/***}"
  done
  printf '%s' "$s"
}

# restic hata çıktısından kısa, okunur bir mesaj (son anlamlı satırlar).
bk_short_error() {
  grep -v -E '^\s*$|^\{' "$1" 2>/dev/null | tail -n 6 | cut -c1-400 | bk_scrub
}

# İç içe bir mysql istemci ayar dosyası (parola komut satırında görünmesin).
bk_mysql_defaults() (
  f=$1
  set -e
  umask 077
  host="$(bk_cfg db.host)"; port="$(bk_cfg db.port 2>/dev/null || echo 3306)"; user="$(bk_cfg db.user)"; pass="$(bk_cfg db.pass)"
  [[ "$host" =~ ^[A-Za-z0-9._:/-]+$ ]] || { echo "config.php: geçersiz db.host" >&2; exit 1; }
  [[ "$port" =~ ^[0-9]{1,5}$ ]] || port=3306
  [[ "$user" =~ ^[A-Za-z0-9._@-]+$ ]] || { echo "config.php: geçersiz db.user" >&2; exit 1; }
  {
    echo '[client]'
    echo "host=$host"
    echo "port=$port"
    echo "user=$user"
    printf 'password="%s"\n' "$(printf '%s' "$pass" | sed 's/\\/\\\\/g; s/"/\\"/g')"
  } > "$f"
)

bk_server_tz() {
  local tz=""
  if command -v timedatectl >/dev/null 2>&1; then tz="$(timedatectl show -p Timezone --value 2>/dev/null || true)"; fi
  [[ -z "$tz" && -r /etc/timezone ]] && tz="$(head -n1 /etc/timezone)"
  [[ -z "$tz" && -L /etc/localtime ]] && tz="$(readlink /etc/localtime | sed 's#.*/zoneinfo/##')"
  printf '%s' "${tz:-Europe/Istanbul}"
}
