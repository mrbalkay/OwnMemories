#!/usr/bin/env bash
# OwnMemories sunucu yedeği (restic): şifreli, sürümlü, doğrulanan yedek.
#
# Ne yedeklenir (tek, tutarlı anlık görüntü):
#   - veritabanı dökümü: mysqldump --single-transaction            → backup-staging/db/ownmemories.sql
#     (sıkıştırılmadan: restic kendisi zstd ile sıkıştırır ve değişmeyen kısımları tekilleştirir; gzip bunu bozardı)
#   - config.php ve MANIFEST.json (sürüm, şema, depo yolu)       → backup-staging/
#   - orijinal dosyalar: <depo>/blobs
#   - isteğe bağlı küçük resim/önizleme/videolar: <depo>/derived (BACKUP_INCLUDE_DERIVED=1; yeniden üretilebilir)
# Her hedefe (BACKUP_TARGETS) ayrı ayrı yazılır; saklama: 7 günlük, 8 haftalık, 24 aylık, 5 yıllık.
# Haftada bir 'restic check --read-data-subset' ve küçük bir geri yükleme testi yapılır.
# Sonuç /var/lib/ownmemories/backup-status.json dosyasına yazılır (yönetim paneli → Yedekleme).
#
# Kurulum ve ayarlar:   sudo bash server/setup-backup.sh
# Elle çalıştırma:      sudo systemctl start ownmemories-backup     (ya da panelde "Şimdi yedekle")
# Seçenekler: --manual  --check (denetimi şimdi zorla)  --no-check  --target ID  --no-restore-test
set -Eeuo pipefail
. "$(cd "$(dirname "$0")" && pwd)/lib/backup-common.sh"

TRIGGER=scheduled
FORCE_CHECK=0
NO_CHECK=0
ONLY=""
RESTORE_TEST=1
while (($#)); do
  case "$1" in
    --manual) TRIGGER=manual ;;
    --check) FORCE_CHECK=1 ;;
    --no-check) NO_CHECK=1 ;;
    --no-restore-test) RESTORE_TEST=0 ;;
    --target) ONLY="$2"; shift ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) bk_die "bilinmeyen seçenek: $1" ;;
  esac
  shift
done

# Panelden gelen "Şimdi yedekle" isteği: ilk iş olarak silinir (systemd .path birimi döngüye girmesin).
if [[ -e "$BK_SPOOL_DIR/run-request" ]]; then
  rm -f "$BK_SPOOL_DIR/run-request"
  TRIGGER=manual
fi

if ! bk_load_env; then
  # Eski (rsync) yedek ayarlıysa, yeni sistem kurulana kadar gece yedeği kesilmesin.
  LEGACY_ENV="${OM_LEGACY_BACKUP_ENV:-/etc/ownmemories-backup.env}"
  if [[ -r "$LEGACY_ENV" ]] && grep -q '^BACKUP_DIR=.' "$LEGACY_ENV"; then
    bk_warn "Yeni şifreli yedek kurulmamış; eski rsync yedeği ($LEGACY_ENV) çalıştırılıyor. Geçmek için: sudo bash ~/OwnMemories/server/setup-backup.sh"
    set -a; . "$LEGACY_ENV"; set +a
    exec bash "$(cd "$(dirname "$0")" && pwd)/backup-legacy.sh"
  fi
  echo "Sunucu yedeği kurulmamış. Kurmak için:  sudo bash ~/OwnMemories/server/setup-backup.sh"
  exit 0
fi
[[ -n "$BACKUP_TARGETS" ]] || bk_die "Ayar dosyasında hedef yok ($OM_BACKUP_ENV). Kurulumu yeniden çalıştırın."
[[ -n "${RESTIC_PASSWORD:-}" ]] || bk_die "RESTIC_PASSWORD ayarlı değil ($OM_BACKUP_ENV)."
command -v restic >/dev/null || bk_die "restic kurulu değil: sudo apt-get install -y restic"
[[ -r "$OM_CONFIG" ]] || bk_die "config.php okunamıyor: $OM_CONFIG"

mkdir -p "$OM_STATE_DIR" "$OM_CACHE_DIR"
exec 9>"$OM_STATE_DIR/backup.lock"
if ! flock -n 9; then
  bk_log "Başka bir yedek zaten çalışıyor; bu istek atlandı."
  exit 0
fi

# Arka planda, diğer işleri yavaşlatmadan (systemd birimi de Nice/IOScheduling ayarlar).
renice -n 10 -p $$ >/dev/null 2>&1 || true
ionice -c2 -n7 -p $$ >/dev/null 2>&1 || true

RUN_START=$(date +%s)
WORK="$(mktemp -d "$OM_STATE_DIR/backup-run.XXXXXX")"
chmod 700 "$WORK"
cleanup() {
  local rc=$?
  rm -rf "$WORK"
  if [[ -n "${STATUS_STARTED:-}" && -z "${STATUS_FINISHED:-}" ]]; then
    # Beklenmedik durma: bu çalışmada sonucu yazılmamış hedefler başarısız sayılır (panel yeşil kalmasın).
    local id
    for id in ${BACKUP_TARGETS:-}; do
      [[ -n "$ONLY" && "$id" != "$ONLY" ]] && continue
      [[ " ${DONE_TARGETS:-} " == *" $id "* ]] && continue
      printf 'Yedek beklenmedik şekilde durdu (çıkış kodu %s). Ayrıntı: journalctl -u ownmemories-backup' "$rc" | bk_status backup-result "$id" failed 0 || true
    done
    echo '{}' | bk_status finish failed || true
  fi
  exit $rc
}
trap cleanup EXIT
trap 'exit 143' TERM INT HUP

bk_status start "$TRIGGER" "$$"
STATUS_STARTED=1
bk_log "OwnMemories yedeği başladı ($TRIGGER): hedefler: $BACKUP_TARGETS"

fail_all() {
  local msg=$1 id
  for id in $BACKUP_TARGETS; do
    [[ -n "$ONLY" && "$id" != "$ONLY" ]] && continue
    printf '%s' "$msg" | bk_status backup-result "$id" failed 0
    DONE_TARGETS="${DONE_TARGETS:-} $id"
  done
  echo '{}' | bk_status finish failed
  STATUS_FINISHED=1
  bk_die "$msg"
}

STORAGE="$(bk_cfg storage.path)"
STORAGE="${STORAGE%/}"
DB_NAME="$(bk_cfg db.name)"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_$-]+$ ]] || fail_all "config.php: geçersiz veritabanı adı."
[[ "$STORAGE" == /?* ]] || fail_all "config.php: depo yolu tam yol olmalı: $STORAGE"
[[ -d "$STORAGE/blobs" ]] || fail_all "Orijinallerin klasörü bulunamadı: $STORAGE/blobs. Fotoğraf diski bağlı mı? (df -h, lsblk)"

# ---------------------------------------------------------------- hazırlık: DB dökümü + ayarlar
rm -rf "$BK_STAGING"
mkdir -p "$BK_STAGING/db" "$BK_STAGING/config"
chmod 700 "$BK_STAGING"
bk_mysql_defaults "$WORK/my.cnf"
DUMP="$BK_STAGING/db/ownmemories.sql"
# Yer denetimi: döküm yaklaşık veri boyutu kadar yer tutar.
DB_BYTES="$(mysql --defaults-extra-file="$WORK/my.cnf" -N -B -e "SELECT COALESCE(SUM(data_length),0) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)"
FREE_BYTES=$(( $(df -Pk "$BK_STAGING" | awk 'NR==2 {print $4}') * 1024 ))
if (( DB_BYTES > 0 && FREE_BYTES < DB_BYTES * 3 / 2 )); then
  fail_all "Veritabanı dökümü için yer yok: $OM_STATE_DIR üzerinde $((FREE_BYTES / 1048576)) MB boş, ~$((DB_BYTES * 3 / 2 / 1048576)) MB gerekli."
fi
bk_log "Veritabanı dökümü: $DB_NAME"
if ! mysqldump --defaults-extra-file="$WORK/my.cnf" --single-transaction --quick --routines --triggers --hex-blob \
      --no-tablespaces --default-character-set=utf8mb4 --skip-dump-date "$DB_NAME" >"$DUMP.part" 2>"$WORK/dump.err"; then
  fail_all "Veritabanı dökümü alınamadı: $(bk_short_error "$WORK/dump.err")"
fi
if ! tail -n 1 "$DUMP.part" | grep -q 'Dump completed'; then
  fail_all "Veritabanı dökümü eksik: $(bk_short_error "$WORK/dump.err")"
fi
mv "$DUMP.part" "$DUMP"
DUMP_BYTES=$(stat -c %s "$DUMP")
cp "$OM_CONFIG" "$BK_STAGING/config/config.php"
chmod 600 "$BK_STAGING/config/config.php"

SCHEMA="$(mysql --defaults-extra-file="$WORK/my.cnf" -N -B -e "SELECT value FROM site_settings WHERE \`key\`='schema_version'" "$DB_NAME" 2>/dev/null || true)"
BLOBS="$(mysql --defaults-extra-file="$WORK/my.cnf" -N -B -e "SELECT COUNT(*), COALESCE(SUM(size),0) FROM blobs" "$DB_NAME" 2>/dev/null || echo '0 0')"
OM_VER="$( { sed -n "s/.*define('OM_VERSION', '\([^']*\)').*/\1/p" "$OM_ROOT/web/includes/core.php" 2>/dev/null || true; } | head -n1)"
php -r 'echo json_encode(["created_at" => gmdate("c"), "app_version" => $argv[1], "schema_version" => $argv[2], "storage_path" => $argv[3],
  "db_name" => $argv[4], "blob_count" => (int) $argv[5], "blob_bytes" => (int) $argv[6], "include_derived" => $argv[7] === "1",
  "hostname" => gethostname(), "staging_path" => $argv[8]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";' \
  "${OM_VER:-?}" "${SCHEMA:-?}" "$STORAGE" "$DB_NAME" ${BLOBS:-0 0} "$BACKUP_INCLUDE_DERIVED" "$BK_STAGING" > "$BK_STAGING/MANIFEST.json"
bk_log "Döküm hazır: $((DUMP_BYTES / 1024)) KB; orijinaller: ${BLOBS%%[[:space:]]*} dosya"
# Güvenlik: veritabanındaki orijinallerin çoğu diskte yoksa (ör. fotoğraf diski bağlanmamış, klasör boş) bu
# "boş" hâli yedeklemek yerine durup alarm verilir; eski sürümler yedekte olduğu gibi kalır.
DB_BLOB_COUNT="${BLOBS%%[[:space:]]*}"; [[ "$DB_BLOB_COUNT" =~ ^[0-9]+$ ]] || DB_BLOB_COUNT=0
DISK_BLOB_COUNT=$(find "$STORAGE/blobs" -type f ! -name '*.part' ! -name '*.part-*' 2>/dev/null | wc -l)
if (( DB_BLOB_COUNT >= 20 && DISK_BLOB_COUNT * 2 < DB_BLOB_COUNT )) && [[ -z "${OM_BACKUP_ALLOW_MISSING:-}" ]]; then
  fail_all "Orijinallerin çoğu diskte yok: veritabanında $DB_BLOB_COUNT, $STORAGE/blobs içinde $DISK_BLOB_COUNT dosya. Fotoğraf diski bağlı mı? Eksik hâl yedeklenmedi; önceki yedekler korunuyor. (Bilerek devam etmek için: sudo OM_BACKUP_ALLOW_MISSING=1 bash server/backup.sh)"
fi

PATHS=("$BK_STAGING" "$STORAGE/blobs")
[[ "$BACKUP_INCLUDE_DERIVED" == 1 && -d "$STORAGE/derived" ]] && PATHS+=("$STORAGE/derived")

# ---------------------------------------------------------------- her hedef
ANY_FAIL=0
FIRST_OK=""
DOW=$(date +%u)
for id in $BACKUP_TARGETS; do
  [[ -n "$ONLY" && "$id" != "$ONLY" ]] && continue
  label="$(bk_tvar "$id" LABEL)"
  bk_log "[$id] ${label:-$id} → $(bk_location "$id")"
  t0=$(date +%s)
  bk_restic "$id" unlock >/dev/null 2>&1 || true
  if bk_restic "$id" backup --json --quiet --host "$BACKUP_HOST" --tag ownmemories --tag "$TRIGGER" \
       --exclude '*.part-*' --exclude '*.part' "${PATHS[@]}" >"$WORK/$id.json" 2>"$WORK/$id.err"; then
    bk_status backup-result "$id" ok $(( $(date +%s) - t0 )) "$WORK/$id.json" </dev/null
    DONE_TARGETS="${DONE_TARGETS:-} $id"
    bk_log "[$id] tamam: $(bk_status summary "$WORK/$id.json" || echo '?')"
    [[ -z "$FIRST_OK" ]] && FIRST_OK=$id
  else
    rc=$?
    err="$(bk_short_error "$WORK/$id.err")"
    [[ -n "$err" ]] || err="restic çıkış kodu $rc"
    # Kod 3: bazı dosyalar okunamadı ama anlık görüntü oluştu — yine de hata say (eksik yedek).
    printf '%s' "$err" | bk_status backup-result "$id" failed $(( $(date +%s) - t0 ))
    DONE_TARGETS="${DONE_TARGETS:-} $id"
    bk_warn "[$id] yedek BAŞARISIZ: $err"
    ANY_FAIL=1
    continue
  fi

  # Saklama: her gece forget; haftada bir (veya denetim günü) prune ile yer açılır.
  last_check="$(bk_status get last_check_at "$id")"
  due_check=0
  if (( FORCE_CHECK )); then due_check=1
  elif (( ! NO_CHECK )); then
    if [[ -z "$last_check" ]] || (( $(date +%s) - $(date -d "$last_check" +%s 2>/dev/null || echo 0) > (BACKUP_CHECK_DAYS * 86400 - 6 * 3600) )); then due_check=1; fi
  fi
  forget_args=(forget --host "$BACKUP_HOST" --tag ownmemories --group-by host \
    --keep-daily "$BACKUP_KEEP_DAILY" --keep-weekly "$BACKUP_KEEP_WEEKLY" --keep-monthly "$BACKUP_KEEP_MONTHLY" --keep-yearly "$BACKUP_KEEP_YEARLY")
  (( due_check )) && forget_args+=(--prune)
  maint_err=""
  if ! bk_restic "$id" "${forget_args[@]}" >"$WORK/$id.forget" 2>&1; then
    maint_err="Eski sürümler temizlenemedi: $(bk_short_error "$WORK/$id.forget")"
    bk_warn "[$id] $maint_err"
  fi
  if (( due_check )); then
    bk_log "[$id] bütünlük denetimi (restic check --read-data-subset=$BACKUP_CHECK_SUBSET)"
    if bk_restic "$id" check --read-data-subset="$BACKUP_CHECK_SUBSET" >"$WORK/$id.check" 2>&1 && [[ -z "$maint_err" ]]; then
      bk_status check "$id" ok </dev/null
      bk_log "[$id] denetim sorunsuz"
    else
      { [[ -n "$maint_err" ]] && echo "$maint_err"; bk_short_error "$WORK/$id.check"; } | bk_status check "$id" failed
      bk_warn "[$id] denetim sorun buldu: $(bk_short_error "$WORK/$id.check")"
    fi
  elif [[ -n "$maint_err" ]]; then
    printf '%s' "$maint_err" | bk_status check "$id" failed
  fi
  bk_restic "$id" snapshots --json --host "$BACKUP_HOST" --tag ownmemories >"$WORK/$id.snaps" 2>/dev/null || echo 'null' >"$WORK/$id.snaps"
  bk_restic "$id" stats --json --mode raw-data >"$WORK/$id.stats" 2>/dev/null || echo 'null' >"$WORK/$id.stats"
  bk_status stats "$id" "$WORK/$id.snaps" "$WORK/$id.stats"
done

# ---------------------------------------------------------------- haftalık geri yükleme testi (ilk başarılı hedef)
if (( RESTORE_TEST )) && [[ "$BACKUP_RESTORE_TEST" == 1 && -n "$FIRST_OK" ]]; then
  last_rt="$(php -r '$d = json_decode((string) @file_get_contents($argv[1]), true); echo $d["restore_test"]["at"] ?? "";' "$BK_STATUS_FILE")"
  if (( FORCE_CHECK )) || [[ -z "$last_rt" ]] || (( $(date +%s) - $(date -d "$last_rt" +%s 2>/dev/null || echo 0) > (BACKUP_CHECK_DAYS * 86400 - 6 * 3600) )); then
    bk_log "Geri yükleme testi ($FIRST_OK)"
    bash "$(dirname "$0")/restore.sh" test --target "$FIRST_OK" --sample 20 --quiet || bk_warn "Geri yükleme testi başarısız (ayrıntı: panel → Yedekleme)"
  fi
fi

SRC_FILES=$(find "${PATHS[@]:1}" -type f 2>/dev/null | wc -l)
SRC_BYTES=$(du -sb "${PATHS[@]:1}" 2>/dev/null | awk '{s+=$1} END {print s+0}')
printf '{"db_dump_bytes":%d,"source_bytes":%d,"source_files":%d,"duration_s":%d}' "$DUMP_BYTES" "$SRC_BYTES" "$SRC_FILES" $(( $(date +%s) - RUN_START )) \
  | bk_status finish "$([[ $ANY_FAIL == 0 ]] && echo ok || echo failed)"
STATUS_FINISHED=1
rm -f "$DUMP"   # döküm yedeklerde; sunucuda ikinci bir düz kopya tutulmaz
if (( ANY_FAIL )); then
  bk_warn "Yedek bir veya daha fazla hedefte başarısız oldu."
  exit 1
fi
bk_log "Yedek tamam ($(( $(date +%s) - RUN_START )) sn)."
