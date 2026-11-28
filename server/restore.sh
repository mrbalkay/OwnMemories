#!/usr/bin/env bash
# OwnMemories geri yükleme ve geri yükleme testi (restic yedeklerinden).
#
#   sudo bash server/restore.sh                       menü
#   sudo bash server/restore.sh list    [--target ID]                 sürümleri (anlık görüntüleri) listeler
#   sudo bash server/restore.sh test    [--target ID] [--sample 30]   rastgele dosyalar + son DB dökümü geçici klasöre
#                                                                     geri yüklenir ve doğrulanır (SHA-1 = dosya adı)
#   sudo bash server/restore.sh to DIR  [--target ID] [--snapshot ID] [--db YENI_DB]
#                                                                     her şeyi YENİ bir klasöre geri yükler (canlı sisteme dokunmaz);
#                                                                     --db verilirse döküm o veritabanına aktarılır
#   sudo bash server/restore.sh in-place [--target ID] [--snapshot ID] [--storage YOL] [--yes]
#                                                                     canlı sistemi yedekten geri getirir (veritabanı + orijinaller)
#
# Yeni bir sunucuya felaket sonrası geri yükleme: önce install-ubuntu.sh + setup.php, sonra setup-backup.sh ile
# AYNI hedefi ve AYNI yedek parolasını girin, ardından:  sudo bash server/restore.sh in-place
set -Eeuo pipefail
. "$(cd "$(dirname "$0")" && pwd)/lib/backup-common.sh"
SYSTEMCTL="${SYSTEMCTL:-systemctl}"

CMD="${1:-menu}"
[[ $# -gt 0 ]] && shift
DEST=""
if [[ "$CMD" == to && $# -gt 0 && "$1" != --* ]]; then DEST="$1"; shift; fi
ONLY="" SNAP="latest" SAMPLE=30 QUIET=0 YES=0 NEWDB="" STORAGE_OVERRIDE=""
while (($#)); do
  case "$1" in
    --target) ONLY="$2"; shift ;;
    --snapshot) SNAP="$2"; shift ;;
    --sample) SAMPLE="$2"; shift; [[ "$SAMPLE" =~ ^[1-9][0-9]{0,4}$ ]] || bk_die "--sample 1-99999 arası bir sayı olmalı" ;;
    --db) NEWDB="$2"; shift ;;
    --storage) STORAGE_OVERRIDE="${2%/}"; shift ;;
    --quiet) QUIET=1 ;;
    --yes) YES=1 ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) bk_die "bilinmeyen seçenek: $1" ;;
  esac
  shift
done

say() { (( QUIET )) || echo "$*"; }

bk_load_env || bk_die "Yedek ayarı bulunamadı ($OM_BACKUP_ENV). Önce: sudo bash ~/OwnMemories/server/setup-backup.sh
  (Yeni sunucuya geri yüklüyorsanız sihirbazda eski hedefi ve eski yedek parolasını girin.)"
command -v restic >/dev/null || bk_die "restic kurulu değil: sudo apt-get install -y restic"

TARGET="${ONLY:-${BACKUP_TARGETS%% *}}"
[[ " $BACKUP_TARGETS " == *" $TARGET "* ]] || bk_die "Böyle bir hedef yok: $TARGET (hedefler: $BACKUP_TARGETS)"
mkdir -p "$OM_STATE_DIR" "$OM_CACHE_DIR"
WORK="$(mktemp -d "$OM_STATE_DIR/restore-run.XXXXXX")"
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT

# Anlık görüntü bilgisi: kimlik, zaman, yollar (staging + depo). $SNAP 'latest' ya da bir kimlik.
snapshot_info() {
  bk_restic "$TARGET" snapshots --json --host "$BACKUP_HOST" --tag ownmemories >"$WORK/snaps.json" 2>"$WORK/snaps.err" \
    || bk_die "Sürümler okunamadı ($TARGET): $(bk_short_error "$WORK/snaps.err")"
  php -r '
    $s = json_decode((string) file_get_contents($argv[1]), true) ?: [];
    usort($s, fn($a, $b) => strcmp($a["time"], $b["time"]));
    $want = $argv[2]; $pick = null;
    foreach ($s as $x) { if ($want === "latest" || str_starts_with($x["id"], $want) || ($x["short_id"] ?? "") === $want) { $pick = $x; } }
    if (!$pick) { exit(1); }
    $staging = ""; $blobs = "";
    foreach ($pick["paths"] as $p) { if (str_ends_with($p, "/backup-staging")) $staging = $p; elseif (str_ends_with($p, "/blobs")) $blobs = $p; }
    echo $pick["id"], "\n", $pick["time"], "\n", $staging, "\n", $blobs, "\n", implode(" ", $pick["paths"]), "\n";
  ' "$WORK/snaps.json" "$SNAP" >"$WORK/snap.txt" || bk_die "Anlık görüntü bulunamadı: $SNAP"
  { read -r SNAP_ID; read -r SNAP_TIME; read -r SNAP_STAGING; read -r SNAP_BLOBS; read -r SNAP_PATHS; } <"$WORK/snap.txt"
  SNAP_STORAGE="${SNAP_BLOBS%/blobs}"
  [[ -n "$SNAP_STAGING" && -n "$SNAP_BLOBS" ]] || bk_die "Anlık görüntü OwnMemories biçiminde değil: $SNAP_ID"
}

# Döküm dosyasını doğrular: gzip bütünlüğü + "Dump completed" son satırı.
# Döküm: ownmemories.sql (yeni) veya ownmemories.sql.gz (eski). dump_file KÖK → yol
dump_file() { if [[ -f "$1/db/ownmemories.sql" ]]; then echo "$1/db/ownmemories.sql"; else echo "$1/db/ownmemories.sql.gz"; fi; }
dump_cat() { if [[ "$1" == *.gz ]]; then zcat "$1"; else cat "$1"; fi; }
dump_ok() { [[ -f "$1" ]] || return 1; if [[ "$1" == *.gz ]]; then gzip -t "$1" 2>/dev/null || return 1; fi; dump_cat "$1" | tail -n 1 | grep -q 'Dump completed'; }

# Root (unix soketi) ile mysql; olmazsa config.php kimliği.
mysql_admin() {
  if [[ $EUID -eq 0 ]] && mysql -N -B -e 'SELECT 1' >/dev/null 2>&1; then mysql "$@"; return; fi
  [[ -f "$WORK/my.cnf" ]] || bk_mysql_defaults "$WORK/my.cnf"
  mysql --defaults-extra-file="$WORK/my.cnf" "$@"
}

import_dump() { # import_dump DOSYA DB
  local f=$1 db=$2
  mysql_admin -e "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || return 1
  # Hedefte olup dökümde olmayan tablolar kalmasın.
  local tables
  tables="$(mysql_admin -N -B -e "SELECT GROUP_CONCAT(CONCAT('\`',table_name,'\`')) FROM information_schema.tables WHERE table_schema='$db'")"
  if [[ -n "$tables" && "$tables" != NULL ]]; then
    mysql_admin -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS $tables;" "$db" || return 1
  fi
  dump_cat "$f" | mysql_admin "$db"
}

cmd_list() {
  echo "Hedef: $TARGET ($(bk_tvar "$TARGET" LABEL)) → $(bk_location "$TARGET")"
  bk_restic "$TARGET" snapshots --host "$BACKUP_HOST" --tag ownmemories
}

cmd_test() {
  local t0; t0=$(date +%s)
  snapshot_info
  say "Geri yükleme testi: $TARGET, anlık görüntü ${SNAP_ID:0:8} ($SNAP_TIME)"
  bk_restic "$TARGET" ls --json --recursive "$SNAP_ID" "$SNAP_BLOBS" >"$WORK/ls.json" 2>"$WORK/ls.err" \
    || fail_test "Dosya listesi okunamadı: $(bk_short_error "$WORK/ls.err")"
  php -r '
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $l) { $j = json_decode($l, true);
      if (is_array($j) && ($j["type"] ?? "") === "file" && isset($j["path"])) echo $j["path"], "\n"; }' "$WORK/ls.json" >"$WORK/all.txt"
  local total; total=$(wc -l <"$WORK/all.txt")
  shuf -n "$SAMPLE" "$WORK/all.txt" >"$WORK/sample.txt" || true
  local -a inc=(--include "$SNAP_STAGING/db" --include "$SNAP_STAGING/MANIFEST.json")
  while IFS= read -r p; do inc+=(--include "$p"); done <"$WORK/sample.txt"
  mkdir -p "$WORK/out"
  bk_restic "$TARGET" restore "$SNAP_ID" --target "$WORK/out" "${inc[@]}" >"$WORK/restore.log" 2>&1 \
    || fail_test "Geri yükleme başarısız: $(bk_short_error "$WORK/restore.log")"
  local ok=0 bad=0 badlist=""
  while IFS= read -r p; do
    local f="$WORK/out$p" name stem
    name="$(basename "$p")"; stem="${name%%.*}"
    if [[ -f "$f" && "$(sha1sum "$f" | cut -d' ' -f1)" == "$stem" ]]; then ok=$((ok + 1)); else bad=$((bad + 1)); badlist+=" $name"; fi
  done <"$WORK/sample.txt"
  local dump db_ok=false db_msg=""
  dump="$(dump_file "$WORK/out$SNAP_STAGING")"
  if dump_ok "$dump"; then
    db_ok=true
    db_msg="döküm $(( $(stat -c %s "$dump") / 1024 )) KB sağlam"
    # Mümkünse geçici bir veritabanına gerçekten içe aktar.
    local tdb="om_restoretest_$$"
    if import_dump "$dump" "$tdb" >"$WORK/import.log" 2>&1; then
      local n_tables n_assets
      n_tables="$(mysql_admin -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$tdb'")"
      n_assets="$(mysql_admin -N -B -e "SELECT COUNT(*) FROM assets" "$tdb" 2>/dev/null || echo '?')"
      db_msg+=", içe aktarıldı: $n_tables tablo, $n_assets öğe"
      (( n_tables > 0 )) || db_ok=false
    else
      db_msg+=" (test veritabanına aktarılamadı: yetki yok; yalnızca bütünlük denetlendi)"
    fi
    mysql_admin -e "DROP DATABASE IF EXISTS \`$tdb\`" >/dev/null 2>&1 || true
  else
    db_msg="veritabanı dökümü eksik veya bozuk"
  fi
  local all_ok=false
  [[ $bad -eq 0 && $db_ok == true ]] && all_ok=true
  # Yedekte orijinal varken hiçbir dosya denetlenemediyse test geçmiş sayılmaz.
  if (( total > 0 && ok == 0 )); then all_ok=false; fi
  local msg="$ok/$((ok + bad)) dosya doğrulandı (yedekte toplam $total orijinal); $db_msg; $(( $(date +%s) - t0 )) sn"
  [[ $bad -gt 0 ]] && msg+="; HATALI:$badlist"
  php -r 'echo json_encode(["ok" => $argv[1] === "true", "target" => $argv[2], "snapshot_id" => substr($argv[3], 0, 8), "files_checked" => (int) $argv[4],
    "files_failed" => (int) $argv[5], "db_ok" => $argv[6] === "true", "message" => $argv[7]], JSON_UNESCAPED_UNICODE);' \
    "$all_ok" "$TARGET" "$SNAP_ID" "$ok" "$bad" "$db_ok" "$msg" | bk_status restore-test
  if [[ $all_ok == true ]]; then
    echo "Geri yükleme testi BAŞARILI: $msg"
  else
    echo "Geri yükleme testi BAŞARISIZ: $msg" >&2
    exit 1
  fi
}

fail_test() {
  php -r 'echo json_encode(["ok" => false, "target" => $argv[1], "files_checked" => 0, "db_ok" => false, "message" => $argv[2]], JSON_UNESCAPED_UNICODE);' \
    "$TARGET" "$1" | bk_status restore-test
  bk_die "$1"
}

cmd_to() {
  [[ -n "$DEST" ]] || bk_die "Hedef klasör verin: restore.sh to /mnt/geri-yukleme"
  [[ "$DEST" == /* ]] || DEST="$PWD/$DEST"
  if [[ -n "$NEWDB" ]]; then
    [[ "$NEWDB" =~ ^[A-Za-z0-9_]+$ ]] || bk_die "Veritabanı adı yalnızca harf, rakam ve _ içerebilir: $NEWDB"
    # 'to' canlı sisteme dokunmaz: canlı veritabanının üzerine yazmak yalnızca 'in-place' ile (onay ve güvenlik dökümüyle).
    if [[ -r "$OM_CONFIG" && "$NEWDB" == "$(bk_cfg db.name 2>/dev/null || true)" ]]; then
      bk_die "$NEWDB canlı veritabanı. Yeni bir ad verin (ör. ${NEWDB}_geri) ya da canlı sistemi geri getirmek için: restore.sh in-place"
    fi
    if [[ -n "$(mysql_admin -N -B -e "SELECT 1 FROM information_schema.tables WHERE table_schema='$NEWDB' LIMIT 1" 2>/dev/null)" ]]; then
      bk_die "$NEWDB veritabanı boş değil. Yeni (boş) bir veritabanı adı verin."
    fi
  fi
  local live_storage=""
  [[ -r "$OM_CONFIG" ]] && live_storage="$(bk_cfg storage.path 2>/dev/null || true)"
  if [[ -n "$live_storage" && ( "${DEST%/}" == "${live_storage%/}" || "${DEST%/}/" == "${live_storage%/}/"* ) ]]; then
    bk_die "Hedef klasör canlı depo içinde olamaz: $DEST"
  fi
  snapshot_info
  [[ -e "$DEST" && -n "$(ls -A "$DEST" 2>/dev/null)" ]] && bk_die "Klasör boş değil: $DEST (yeni, boş bir klasör seçin)"
  mkdir -p "$DEST"
  echo "Geri yükleniyor: ${SNAP_ID:0:8} ($SNAP_TIME) → $DEST"
  bk_restic "$TARGET" restore "$SNAP_ID" --target "$DEST"
  local dump; dump="$(dump_file "$DEST$SNAP_STAGING")"
  dump_ok "$dump" || bk_die "Veritabanı dökümü bozuk: $dump"
  echo
  echo "Tamam. Dosyalar:"
  echo "  Orijinaller:       $DEST$SNAP_BLOBS"
  echo "  Veritabanı dökümü: $dump"
  echo "  config.php:        $DEST$SNAP_STAGING/config/config.php"
  if [[ -n "$NEWDB" ]]; then
    echo "Veritabanı içe aktarılıyor: $NEWDB"
    import_dump "$dump" "$NEWDB" || bk_die "İçe aktarma başarısız"
    echo "  $NEWDB hazır ($(mysql_admin -N -B -e 'SELECT COUNT(*) FROM assets' "$NEWDB") öğe)."
  else
    echo "Veritabanını ayrıca içe aktarmak için:  sudo mysql YENI_DB < '$dump'"
  fi
}

# Canlı servisler: geri yükleme süresince durdurulur, ne olursa olsun (hata dahil) yeniden başlatılır.
LIVE_UNITS=(ownmemories-cron.timer ownmemories-worker ownmemories-ml apache2)
STOPPED_UNITS=()
stop_services() {
  local u
  for u in "${LIVE_UNITS[@]}"; do
    if $SYSTEMCTL is-active --quiet "$u" 2>/dev/null; then
      if $SYSTEMCTL stop "$u" 2>/dev/null; then STOPPED_UNITS+=("$u"); fi
    fi
  done
  if (( ${#STOPPED_UNITS[@]} )); then echo "Durduruldu: ${STOPPED_UNITS[*]}"; fi
  return 0
}
start_services() {
  (( ${#STOPPED_UNITS[@]} )) || return 0
  local i
  for (( i = ${#STOPPED_UNITS[@]} - 1; i >= 0; i-- )); do $SYSTEMCTL start "${STOPPED_UNITS[$i]}" 2>/dev/null || true; done
  echo "Yeniden başlatıldı: ${STOPPED_UNITS[*]}"
  STOPPED_UNITS=()
}

cmd_in_place() {
  snapshot_info
  [[ -r "$OM_CONFIG" ]] || bk_die "config.php yok: $OM_CONFIG — önce kurulumu (setup.php) tamamlayın; ya da 'to' ile yeni klasöre geri yükleyin."
  local storage db
  storage="${STORAGE_OVERRIDE:-$(bk_cfg storage.path)}"; storage="${storage%/}"
  db="$(bk_cfg db.name)"
  [[ "$db" =~ ^[A-Za-z0-9_$-]+$ ]] || bk_die "config.php: geçersiz veritabanı adı: $db"
  [[ "$storage" == /?* && "$storage" != *..* ]] || bk_die "Depo yolu tam yol olmalı: $storage"
  case "$storage" in
    /etc|/etc/*|/usr|/usr/*|/bin|/bin/*|/sbin|/sbin/*|/lib*|/boot|/boot/*|/root|/root/*|/proc|/proc/*|/sys|/sys/*|/dev|/dev/*|/var|/home|/srv|/mnt|/media|/tmp)
      bk_die "Depo yolu bir sistem klasörü olamaz: $storage" ;;
  esac

  # Yalnızca diskte EKSİK olan orijinaller geri yüklenir; diskteki dosyalara dokunulmaz (adları içeriklerinin SHA-1'i).
  # Böylece kısmi kayıpta tüm kütüphane kadar ek yer gerekmez.
  say "Yedekteki dosyalar listeleniyor…"
  bk_restic "$TARGET" ls --json --recursive "$SNAP_ID" "$SNAP_BLOBS" >"$WORK/ls.json" 2>"$WORK/ls.err" \
    || bk_die "Dosya listesi okunamadı: $(bk_short_error "$WORK/ls.err")"
  php -r '
    [$ls, $snapBlobs, $live, $out, $dirs] = array_slice($argv, 1);
    $miss = []; $damaged = []; $bytes = 0; $total = 0; $missDir = []; $dirBytes = [];
    foreach (file($ls, FILE_IGNORE_NEW_LINES) as $l) {
      $j = json_decode($l, true);
      if (!is_array($j) || ($j["type"] ?? "") !== "file" || !isset($j["path"]) || !str_starts_with($j["path"], $snapBlobs . "/")) { continue; }
      $total++;
      $rel = substr($j["path"], strlen($snapBlobs));
      $top = explode("/", ltrim($rel, "/"))[0];
      $dirBytes[$top] = ($dirBytes[$top] ?? 0) + (int) ($j["size"] ?? 0);
      $f = $live . "/blobs" . $rel;
      $size = (int) ($j["size"] ?? 0);
      if (is_file($f) && filesize($f) !== $size) { $damaged[] = $rel; }   // aynı ad, farklı boyut: bozuk kopya
      if (!is_file($f) || filesize($f) !== $size) {
        $miss[] = $j["path"]; $bytes += $size; $missDir[$top] = true;
      }
    }
    file_put_contents($out, $miss ? implode("\n", $miss) . "\n" : "");
    file_put_contents($out . ".damaged", $damaged ? implode("\n", $damaged) . "\n" : "");
    file_put_contents($dirs, $missDir ? implode("\n", array_keys($missDir)) . "\n" : "");
    // Çok sayıda eksik dosyada klasör bazında geri yüklenir: o klasörlerin tamamı kadar geçici yer gerekir.
    $restoreBytes = count($miss) > 2000 ? array_sum(array_intersect_key($dirBytes, $missDir)) : $bytes;
    echo $total, " ", count($miss), " ", $bytes, " ", $restoreBytes, "\n";' "$WORK/ls.json" "$SNAP_BLOBS" "$storage" "$WORK/missing.txt" "$WORK/missing-dirs.txt" >"$WORK/plan.txt"
  local n_total n_miss miss_bytes restore_bytes n_damaged
  read -r n_total n_miss miss_bytes restore_bytes <"$WORK/plan.txt"
  n_damaged=$(grep -c . "$WORK/missing.txt.damaged" || true)
  mkdir -p "$storage"
  local tmp_parent free_bytes need derived_bytes=0 with_derived=0
  tmp_parent="$(dirname "$storage")"
  free_bytes=$(( $(df -Pk "$tmp_parent" | awk 'NR==2 {print $4}') * 1024 ))
  need=$(( restore_bytes + restore_bytes / 20 + 200 * 1048576 ))
  # Küçük resimler yedekteyse ve diskte yoksa, yer yetiyorsa onlar da geri gelir (yoksa yeniden üretilir).
  if [[ " $SNAP_PATHS " == *" $SNAP_STORAGE/derived "* && -z "$(ls -A "$storage/derived" 2>/dev/null)" ]]; then
    derived_bytes="$(bk_restic "$TARGET" ls --json --recursive "$SNAP_ID" "$SNAP_STORAGE/derived" 2>/dev/null \
      | php -r '$s = 0; while (($l = fgets(STDIN)) !== false) { $j = json_decode($l, true); if (($j["type"] ?? "") === "file") { $s += (int) ($j["size"] ?? 0); } } echo $s;' || echo 0)"
    if (( free_bytes > need + derived_bytes + derived_bytes / 20 )); then with_derived=1; need=$(( need + derived_bytes + derived_bytes / 20 ));
    else bk_warn "Küçük resimler için yer yok; geri yüklenmeyecek (sonradan yeniden üretilebilir)."; fi
  fi
  if (( free_bytes < need )); then
    bk_die "Yer yetersiz: $tmp_parent üzerinde $((free_bytes / 1048576)) MB boş, eksik orijinaller için ~$((need / 1048576)) MB gerekli. Yer açın ya da 'restore.sh to /baska/disk' ile başka bir diske geri yükleyin. Hiçbir şey değiştirilmedi."
  fi

  cat <<EOF

DİKKAT: Canlı sistem yedekten geri getirilecek.
  Anlık görüntü : ${SNAP_ID:0:8} ($SNAP_TIME) — hedef: $TARGET
  Veritabanı    : $db  (mevcut tablolar SİLİNİP yedekteki hâli yüklenecek; önce güvenlik dökümü alınır)
  Orijinaller   : yedekte $n_total dosya; $storage/blobs içinde eksik olan $n_miss dosya ($((miss_bytes / 1048576)) MB) geri yazılır.
                  Diskteki hiçbir orijinal silinmez ve üzerine yazılmaz.$( (( n_damaged )) && printf '\n                  %s dosyanın boyutu yedektekinden farklı (bozuk): bunlar silinmeden\n                  %s klasörüne taşınır, yerlerine yedekteki sağlam kopya konur.' "$n_damaged" "$storage/.restore-damaged-…")
  Web, işçi ve zamanlanmış işler geri yükleme süresince durdurulur, sonra yeniden başlatılır.
EOF
  if (( ! YES )); then
    local ans
    read -r -p "Devam etmek için GERİ YÜKLE yazın: " ans
    [[ "$ans" == "GERİ YÜKLE" || "$ans" == "GERI YUKLE" ]] || bk_die "Vazgeçildi."
  fi
  local ts; ts="$(date +%Y%m%d-%H%M%S)"
  RESTORE_TMP="$tmp_parent/.om-restore-$ts"
  # Hata olsa bile servisler yeniden başlatılır.
  trap 'rc=$?; start_services; rm -rf "${WORK:?}"; if [[ $rc -ne 0 && -d "${RESTORE_TMP:-}" ]]; then echo "Geçici klasör (elle silebilirsiniz): $RESTORE_TMP" >&2; fi; exit $rc' EXIT
  stop_services
  local safety="$OM_STATE_DIR/pre-restore-$ts.sql.gz"
  bk_mysql_defaults "$WORK/my.cnf"
  if (umask 077; mysqldump --defaults-extra-file="$WORK/my.cnf" --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces "$db" 2>/dev/null | gzip >"$safety"); then
    chmod 600 "$safety"; echo "Güvenlik dökümü: $safety"
  else
    rm -f "$safety"; bk_warn "Mevcut veritabanının güvenlik dökümü alınamadı (boş olabilir)."
  fi
  mkdir -p "$RESTORE_TMP"
  local -a inc=(--include "$SNAP_STAGING")
  local p
  if (( n_miss > 0 && n_miss <= 2000 )); then
    while IFS= read -r p; do inc+=(--include "$p"); done <"$WORK/missing.txt"
  elif (( n_miss > 2000 )); then
    while IFS= read -r p; do inc+=(--include "$SNAP_BLOBS/$p"); done <"$WORK/missing-dirs.txt"
  fi
  if (( with_derived )); then inc+=(--include "$SNAP_STORAGE/derived"); fi
  echo "Dosyalar geri yükleniyor (büyük kütüphanelerde uzun sürebilir)…"
  bk_restic "$TARGET" restore "$SNAP_ID" --target "$RESTORE_TMP" "${inc[@]}" || bk_die "Dosyalar geri yüklenemedi; canlı sisteme dokunulmadı."
  local dump; dump="$(dump_file "$RESTORE_TMP$SNAP_STAGING")"
  dump_ok "$dump" || bk_die "Yedekteki veritabanı dökümü bozuk; canlı sisteme dokunulmadı. ($dump)"
  if (( n_damaged )); then
    local dmg="$storage/.restore-damaged-$ts" rel
    while IFS= read -r rel; do
      [[ -n "$rel" && "$rel" != *..* ]] || continue
      mkdir -p "$dmg/blobs$(dirname "$rel")"
      mv -n "$storage/blobs$rel" "$dmg/blobs$rel"
    done <"$WORK/missing.txt.damaged"
    echo "Bozuk $n_damaged kopya kenara alındı: $dmg"
  fi
  local sub src
  for sub in blobs derived; do
    src="$RESTORE_TMP$SNAP_STORAGE/$sub"
    [[ -d "$src" ]] || continue
    # Var olan dosyalara asla dokunulmaz (--ignore-existing); yalnızca eksikler eklenir. Aktarılan geçici kopya
    # hemen silinir (--remove-source-files), böylece aynı diskte ikinci bir tam kopya için yer gerekmez.
    mkdir -p "$storage/$sub"
    rsync -a --ignore-existing --remove-source-files "$src/" "$storage/$sub/"
  done
  echo "Veritabanı içe aktarılıyor: $db"
  import_dump "$dump" "$db" || bk_die "Veritabanı içe aktarılamadı. Güvenlik dökümü: $safety"
  mkdir -p "$storage"/{uploads,import,imports,logs,sessions,tmp,derived}
  if id "$OM_WEB_GROUP" >/dev/null 2>&1; then
    # Sembolik bağlantılar izlenmez (-h -P): yalnızca depo klasörünün kendisi.
    chown -R -h -P "$OM_WEB_GROUP:$OM_WEB_GROUP" "$storage" 2>/dev/null || true
  fi
  rm -rf "${RESTORE_TMP:?}"
  start_services
  echo
  echo "Geri yükleme tamam: $(mysql_admin -N -B -e 'SELECT COUNT(*) FROM assets' "$db") öğe; $n_miss orijinal geri yazıldı."
  echo "Küçük resimler yedekte yoksa: Yönetim → İşler → \"Tüm küçük resimleri yeniden üret\"."
}

cmd_menu() {
  cat <<EOF
OwnMemories geri yükleme — hedef: $TARGET ($(bk_tvar "$TARGET" LABEL))
  1) Sürümleri listele
  2) Geri yükleme testi (güvenli; canlı sisteme dokunmaz)
  3) Her şeyi yeni bir klasöre geri yükle
  4) Canlı sistemi yedekten geri getir (veritabanı + orijinaller)
  q) Çıkış
EOF
  local c; read -r -p "Seçim: " c
  case "$c" in
    1) cmd_list ;;
    2) cmd_test ;;
    3) read -r -p "Boş klasör (ör. /mnt/geri-yukleme): " DEST; cmd_to ;;
    4) read -r -p "Anlık görüntü kimliği (boş = en son): " c; SNAP="${c:-latest}"; cmd_in_place ;;
    *) exit 0 ;;
  esac
}

case "$CMD" in
  list) cmd_list ;;
  test) cmd_test ;;
  to) cmd_to ;;
  in-place) cmd_in_place ;;
  menu) cmd_menu ;;
  *) bk_die "bilinmeyen komut: $CMD (list | test | to DIR | in-place)" ;;
esac
