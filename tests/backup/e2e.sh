#!/usr/bin/env bash
# Yedekleme sisteminin gerçek uçtan uca testi (restic + gerçek veritabanı + gerçek depo klasörü).
# Sistem servislerine dokunmaz: tüm yollar geçici klasöre yönlendirilir, systemctl bir kayıt betiğiyle değiştirilir.
#
#   sudo OM_CONFIG=/yol/web/config/config.php bash tests/backup/e2e.sh [ÇALIŞMA_KLASÖRÜ]
#
# İsteğe bağlı: sshd varsa uzak hedef 127.0.0.1:2222 üzerinde gerçek SFTP ile denenir (OM_E2E_SFTP=0 ile kapatılır).
# Çıktının sonunda özet sayılar ve durum dosyasının yolu yazılır (panel ekran görüntüleri için config.php'de
# 'backup' => ['status_file' => …, 'spool_dir' => …] ayarlanabilir).
set -Eeuo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
T="${1:-$(mktemp -d /tmp/om-backup-e2e.XXXXXX)}"
mkdir -p "$T"
T="$(cd "$T" && pwd)"
export OM_CONFIG="${OM_CONFIG:-$REPO/web/config/config.php}"
export OM_ROOT="$T/install" OM_STATE_DIR="$T/state" OM_CACHE_DIR="$T/cache" OM_BACKUP_ENV="$T/etc/backup.env"
export OM_SYSTEMD_DIR="$T/systemd" SYSTEMCTL="$T/bin/systemctl" OM_BK_ALLOW_SAME_DISK=1 OM_SETUP_NO_APT=1
mkdir -p "$T/bin" "$OM_ROOT/web/includes" "$T/disk" "$T/nas"
cp "$REPO/web/includes/core.php" "$OM_ROOT/web/includes/core.php"
cat >"$SYSTEMCTL" <<EOF
#!/usr/bin/env bash
echo "systemctl \$*" >>"$T/systemctl.log"
EOF
chmod +x "$SYSTEMCTL"

pass=0
check() { # check "açıklama" komut…
  local what=$1; shift
  if "$@"; then pass=$((pass + 1)); echo "  ✓ $what"; else echo "  ✗ $what" >&2; exit 1; fi
}
st() { php -r '$d = json_decode(file_get_contents($argv[1]), true); $p = explode(".", $argv[2]); foreach ($p as $k) { $d = is_numeric($k) ? $d[(int) $k] : ($d[$k] ?? null); } echo is_bool($d) ? ($d ? "true" : "false") : (is_scalar($d) ? $d : json_encode($d));' "$OM_STATE_DIR/backup-status.json" "$1"; }
cfg() { php -r '$c = require $argv[1]; foreach (explode(".", $argv[2]) as $p) { $c = $c[$p]; } echo $c;' "$OM_CONFIG" "$1"; }
STORAGE="$(cfg storage.path)"; DB="$(cfg db.name)"
bk_snaps() ( . "$REPO/server/lib/backup-common.sh"; bk_load_env; bk_restic "$1" snapshots --json --host "$BACKUP_HOST" --tag ownmemories | php -r 'echo count(json_decode(stream_get_contents(STDIN), true) ?: []);' )
mysqlc() { MYSQL_PWD="$(cfg db.pass)" mysql -h "$(cfg db.host)" -u "$(cfg db.user)" -N -B "$@"; }

# ------------------------------------------------------------- uzak hedef: gerçek SFTP (sshd) ya da yerel klasör
SFTP=0
if [[ "${OM_E2E_SFTP:-1}" == 1 && -x /usr/sbin/sshd ]]; then
  mkdir -p "$T/ssh" "$T/sftp"
  chmod 700 "$T/ssh"
  ssh-keygen -q -t ed25519 -N '' -f "$T/ssh/id"
  cp "$T/ssh/id.pub" "$T/ssh/authorized_keys"
  ssh-keygen -q -t ed25519 -N '' -f "$T/ssh/host"
  mkdir -p /run/sshd
  cat >"$T/ssh/sshd_config" <<CONF
Port 2222
ListenAddress 127.0.0.1
HostKey $T/ssh/host
AuthorizedKeysFile $T/ssh/authorized_keys
PidFile $T/ssh/sshd.pid
StrictModes no
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
UsePAM no
Subsystem sftp internal-sftp
CONF
  /usr/sbin/sshd -f "$T/ssh/sshd_config" && SFTP=1
  sleep 0.5
fi
cleanup() {
  [[ -f "$T/ssh/sshd.pid" ]] && kill "$(cat "$T/ssh/sshd.pid")" 2>/dev/null || true
  ssh-keygen -R "[127.0.0.1]:2222" >/dev/null 2>&1 || true
}
trap cleanup EXIT
if (( SFTP )); then
  export OM_BK_OFFSITE=sftp OM_BK_OFFSITE_SFTP="$(id -un)@127.0.0.1" OM_BK_OFFSITE_PORT=2222 OM_BK_OFFSITE_PATH="$T/sftp/repo" OM_BK_SSH_KEY="$T/ssh/id" OM_BK_LIMIT_UPLOAD=50000
  echo "Uzak hedef: gerçek SFTP (sshd 127.0.0.1:2222)"
else
  export OM_BK_OFFSITE=local OM_BK_OFFSITE_PATH="$T/nas/repo"
  echo "Uzak hedef: yerel klasör (sshd yok)"
fi
export OM_BK_DISK="$T/disk/repo" OM_BK_DISK_LABEL="Harici disk (test)"

echo "== 1. Kurulum (etkileşimsiz)"
bash "$REPO/server/setup-backup.sh" --non-interactive >"$T/setup1.log" 2>&1 || { cat "$T/setup1.log"; exit 1; }
check "ayar dosyası 0600" test "$(stat -c %a "$OM_BACKUP_ENV")" = 600
check "parola üretildi ve bir kez gösterildi" grep -q 'YEDEK PAROLASI' "$T/setup1.log"
check "iki depo oluşturuldu" test "$(grep -c 'yeni şifreli yedek deposu' "$T/setup1.log")" = 2
check "systemd birimleri ve saat ayarı" test -f "$OM_SYSTEMD_DIR/ownmemories-backup.path" -a -f "$OM_SYSTEMD_DIR/ownmemories-backup.timer.d/schedule.conf"
check "zamanlayıcı ve path etkinleştirildi" grep -q 'enable --now ownmemories-backup.path' "$T/systemctl.log"
check "durum: kurulu, 2 hedef" test "$(st configured)/$(st targets.1.id)" = "true/offsite"
check "istek klasörü 2770" test "$(stat -c %a "$OM_STATE_DIR/backup-spool")" = 2770
check "parola depoda/durumda yok" bash -c "! grep -rq \"\$(sed -n \"s/^RESTIC_PASSWORD='\\(.*\\)'/\\1/p\" '$OM_BACKUP_ENV')\" '$OM_STATE_DIR' '$REPO/web' '$REPO/server'"
PW1="$(grep '^RESTIC_PASSWORD=' "$OM_BACKUP_ENV")"

echo "== 2. Kurulumu yeniden çalıştırma (idempotent)"
( unset OM_BK_DISK OM_BK_DISK_LABEL OM_BK_OFFSITE OM_BK_OFFSITE_SFTP OM_BK_OFFSITE_PORT OM_BK_OFFSITE_PATH OM_BK_SSH_KEY OM_BK_LIMIT_UPLOAD
  bash "$REPO/server/setup-backup.sh" --non-interactive >"$T/setup2.log" 2>&1 ) || { cat "$T/setup2.log"; exit 1; }
check "parola korundu" test "$(grep '^RESTIC_PASSWORD=' "$OM_BACKUP_ENV")" = "$PW1"
check "parola yeniden gösterilmedi" bash -c "! grep -q 'YEDEK PAROLASI' '$T/setup2.log'"
check "mevcut depolar tanındı" test "$(grep -c 'mevcut yedek deposu bulundu' "$T/setup2.log")" = 2
check "ayarlar aynı kaldı" diff <(grep -v '^#' "$OM_BACKUP_ENV") <(grep -v '^#' "$OM_BACKUP_ENV.bak")

echo "== 3. İlk yedek"
t0=$(date +%s)
bash "$REPO/server/backup.sh" >"$T/backup1.log" 2>&1 || { cat "$T/backup1.log"; exit 1; }
d1=$(( $(date +%s) - t0 ))
check "iki hedef de başarılı" test "$(st targets.0.last_status)/$(st targets.1.last_status)" = "ok/ok"
check "anlık görüntü kimliği yazıldı" test -n "$(st targets.0.snapshot_id)"
check "bütünlük denetimi yapıldı" test "$(st targets.0.last_check_ok)/$(st targets.1.last_check_ok)" = "true/true"
check "geri yükleme testi çalıştı" test "$(st restore_test.ok)" = true
check "çalışma bitti olarak işaretlendi" test "$(st running)" = null
check "durum dosyası 0640" test "$(stat -c %a "$OM_STATE_DIR/backup-status.json")" = 640
FILES1="$(st targets.0.files_total)"; ADDED1="$(st targets.0.data_added)"; SIZE1="$(st targets.0.repo_size)"

echo "== 4. Panelden 'Şimdi yedekle' (istek dosyası) + tekilleştirme"
echo '{}' >"$OM_STATE_DIR/backup-spool/run-request"
bash "$REPO/server/backup.sh" >"$T/backup2.log" 2>&1 || { cat "$T/backup2.log"; exit 1; }
check "istek dosyası silindi" test ! -e "$OM_STATE_DIR/backup-spool/run-request"
check "tetikleyici elle" test "$(st last_run.trigger)" = manual
check "ikinci yedek 2 sürüm" test "$(st targets.0.snapshot_count)" = 2
ADDED2="$(st targets.0.data_added)"
check "ikinci yedekte çok az veri eklendi (tekilleştirme)" test "$ADDED2" -lt $(( ADDED1 / 4 + 1 ))

echo "== 5. Geri yükleme testi (50 rastgele dosya)"
bash "$REPO/server/restore.sh" test --sample 50 --target offsite >"$T/rtest.log" 2>&1 || { cat "$T/rtest.log"; exit 1; }
check "uzak hedeften doğrulama başarılı" grep -q 'BAŞARILI' "$T/rtest.log"

echo "== 6. Tam geri yükleme yeni klasöre + yeni veritabanı"
mysql -e "DROP DATABASE IF EXISTS om_e2e_restored" 2>/dev/null || mysqlc -e "DROP DATABASE IF EXISTS om_e2e_restored"
bash "$REPO/server/restore.sh" to "$T/restored" --target disk --db om_e2e_restored >"$T/rfull.log" 2>&1 || { cat "$T/rfull.log"; exit 1; }
SRC_ASSETS="$(mysqlc -e 'SELECT COUNT(*) FROM assets' "$DB")"
DST_ASSETS="$(mysqlc -e 'SELECT COUNT(*) FROM assets' om_e2e_restored)"
check "öğe sayısı aynı ($SRC_ASSETS)" test "$SRC_ASSETS" = "$DST_ASSETS"
check "orijinaller bire bir aynı" diff -r -q "$STORAGE/blobs" "$T/restored$STORAGE/blobs"
BAD=0; while IFS= read -r f; do n="$(basename "$f")"; [[ "$(sha1sum "$f" | cut -d' ' -f1)" == "${n%%.*}" ]] || BAD=$((BAD + 1)); done < <(find "$T/restored$STORAGE/blobs" -type f)
check "tüm geri yüklenen dosyaların SHA-1'i adıyla aynı" test "$BAD" = 0
mysqlc -e "DROP DATABASE om_e2e_restored"

echo "== 7. Hata: yerel hedefe yazılamıyor"
mv "$T/disk/repo" "$T/disk/repo.away"; echo 'bu bir klasör değil' >"$T/disk/repo"
set +e; bash "$REPO/server/backup.sh" --no-check >"$T/backup3.log" 2>&1; rc=$?; set -e
check "yedek hata koduyla bitti" test "$rc" = 1
check "yerel hedef başarısız, uzak hedef başarılı" test "$(st targets.0.last_status)/$(st targets.1.last_status)" = "failed/ok"
check "hata metni kaydedildi" test -n "$(st targets.0.error)"
check "hata metninde parola yok" bash -c "! grep -qF \"\$(sed -n \"s/^RESTIC_PASSWORD='\\(.*\\)'/\\1/p\" '$OM_BACKUP_ENV')\" '$OM_STATE_DIR/backup-status.json'"
check "son çalışma başarısız" test "$(st last_run.ok)" = false
ERR="$(st targets.0.error)"
cp "$OM_STATE_DIR/backup-status.json" "$T/status-failed.json"
rm -f "$T/disk/repo"; mv "$T/disk/repo.away" "$T/disk/repo"

echo "== 8. Eşzamanlı çalışma kilidi"
exec 8>"$OM_STATE_DIR/backup.lock"; flock -n 8
bash "$REPO/server/backup.sh" >"$T/backup4.log" 2>&1
check "kilit varken ikinci yedek atlandı" grep -q 'zaten çalışıyor' "$T/backup4.log"
exec 8>&-

echo "== 9. Canlı sistemi geri getirme (in-place) — kopya veritabanı ve kopya depo üzerinde"
IP_DB=om_e2e_inplace
mysqlc -e "DROP DATABASE IF EXISTS $IP_DB; CREATE DATABASE $IP_DB"
MYSQL_PWD="$(cfg db.pass)" mysqldump -h "$(cfg db.host)" -u "$(cfg db.user)" --single-transaction --no-tablespaces "$DB" | mysqlc "$IP_DB"
mkdir -p "$T/live"; cp -a "$STORAGE/blobs" "$T/live/blobs"
php -r '$c = require $argv[1]; $c["db"]["name"] = $argv[2]; $c["storage"]["path"] = $argv[3]; file_put_contents($argv[4], "<?php return " . var_export($c, true) . ";\n");' \
  "$OM_CONFIG" "$IP_DB" "$T/live" "$T/inplace-config.php"
(
  export OM_CONFIG="$T/inplace-config.php"
  bash "$REPO/server/backup.sh" --target disk --no-check --no-restore-test >"$T/backup5.log" 2>&1 || { cat "$T/backup5.log"; exit 1; }
  BEFORE="$(mysqlc -e 'SELECT COUNT(*) FROM assets' "$IP_DB")"
  # Felaket: yarısı silinsin, bir tablo bozulsun.
  find "$T/live/blobs" -type f | sort | awk 'NR % 2 == 0' | xargs rm -f
  mysqlc -e "DELETE FROM assets LIMIT 50; DROP TABLE albums" "$IP_DB"
  # Yedekten sonra gelen yeni bir orijinal (yedekte yok) ve bozulmuş (kısalmış) bir kopya.
  mkdir -p "$T/live/blobs/zz/zz"; echo yeni >"$T/live/blobs/zz/zz/yeni-yuklenen.jpg"
  DMG="$(find "$T/live/blobs" -type f -name '*.*' ! -path '*/zz/*' | sort | head -n1)"; DMG_REL="${DMG#"$T/live/blobs"}"
  truncate -s 10 "$DMG"
  bash "$REPO/server/restore.sh" in-place --target disk --yes >"$T/rinplace.log" 2>&1 || { cat "$T/rinplace.log"; exit 1; }
  AFTER="$(mysqlc -e 'SELECT COUNT(*) FROM assets' "$IP_DB")"
  check "öğeler geri geldi ($BEFORE)" test "$BEFORE" = "$AFTER"
  check "silinen tablo geri geldi" test "$(mysqlc -e "SHOW TABLES LIKE 'albums'" "$IP_DB")" = albums
  check "yedekte olmayan yeni orijinal silinmedi" test "$(cat "$T/live/blobs/zz/zz/yeni-yuklenen.jpg")" = yeni
  check "bozuk kopya silinmeden kenara alındı" test "$(stat -c %s "$(ls -d "$T"/live/.restore-damaged-*)/blobs$DMG_REL")" = 10
  rm -rf "${T:?}/live/blobs/zz"
  check "silinen ve bozulan orijinaller geri geldi" diff -r -q "$STORAGE/blobs" "$T/live/blobs"
  check "güvenlik dökümü alındı" bash -c "ls '$OM_STATE_DIR'/pre-restore-*.sql.gz >/dev/null"
  check "servisler (zamanlanmış işler dahil) durdurulup başlatıldı" bash -c "grep -q 'stop ownmemories-cron.timer' '$T/systemctl.log' && grep -q 'start apache2' '$T/systemctl.log' && grep -q 'start ownmemories-cron.timer' '$T/systemctl.log'"

  # Geri yükleme yarıda hata verirse servisler yine başlatılmalı (bozuk anlık görüntü kimliği değil: olmayan hedef depo).
  : >"$T/systemctl.log"
  find "$T/live/blobs" -type f | sort | head -n 3 | xargs rm -f
  mv "$T/disk/repo/data" "$T/disk/repo/data.away"
  set +e; bash "$REPO/server/restore.sh" in-place --target disk --yes >"$T/rinplace2.log" 2>&1; rc=$?; set -e
  mv "$T/disk/repo/data.away" "$T/disk/repo/data"
  check "bozuk depoda geri getirme hata verdi" test "$rc" != 0
  check "hatadan sonra servisler yeniden başlatıldı" grep -q 'start apache2' "$T/systemctl.log"

  echo "== 10. Fotoğraf diski bağlı değil (orijinallerin çoğu yok): yedek alınmaz, alarm verilir"
  SNAPS_BEFORE="$(st targets.0.snapshot_count)"
  mv "$T/live/blobs" "$T/live/blobs.away"; mkdir "$T/live/blobs"
  set +e; bash "$REPO/server/backup.sh" --target disk --no-check --no-restore-test >"$T/backup6.log" 2>&1; rc=$?; set -e
  rmdir "$T/live/blobs"; mv "$T/live/blobs.away" "$T/live/blobs"
  check "yedek hata verdi" test "$rc" = 1
  check "panelde anlaşılır hata" bash -c "php -r '\$d = json_decode(file_get_contents(\$argv[1]), true); exit(str_contains(\$d[\"targets\"][0][\"error\"] ?? \"\", \"Fotoğraf diski bağlı mı\") ? 0 : 1);' '$OM_STATE_DIR/backup-status.json'"
  check "boş hâlin anlık görüntüsü alınmadı" test "$(bk_snaps disk)" = "$SNAPS_BEFORE"
)
pass=$((pass + 12))

mysqlc -e "DROP DATABASE $IP_DB"

echo "== 11. 'to --db' canlı veritabanının üzerine yazmaz"
set +e; bash "$REPO/server/restore.sh" to "$T/restored2" --target disk --db "$DB" >"$T/rto-live.log" 2>&1; rc=$?; set -e
check "canlı veritabanı adı reddedildi" test "$rc" = 1
check "reddin nedeni açık" grep -q 'canlı veritabanı' "$T/rto-live.log"
check "canlı veritabanı yerinde" test "$(mysqlc -e 'SELECT COUNT(*) FROM assets' "$DB")" = "$SRC_ASSETS"

echo "== 12. config.php web kullanıcısına aitse root olarak çalıştırılmaz"
if id www-data >/dev/null 2>&1; then
  PRIV="$(mktemp -d /tmp/om-e2e-priv.XXXXXX)"; chmod 755 "$PRIV"
  php -r '$c = require $argv[1]; file_put_contents($argv[2], "<?php file_put_contents(" . var_export($argv[3], true) . ", (string) posix_geteuid()); return " . var_export($c, true) . ";\n");' \
    "$OM_CONFIG" "$PRIV/config.php" "$PRIV/euid"
  chown www-data:www-data "$PRIV/config.php"; chmod 1777 "$PRIV"
  NAME="$( . "$REPO/server/lib/backup-common.sh"; OM_CONFIG="$PRIV/config.php" bk_cfg db.name )"
  check "değer okundu ($NAME)" test "$NAME" = "$DB"
  check "config.php www-data yetkisiyle çalıştı" test "$(cat "$PRIV/euid")" = "$(id -u www-data)"
  printf '<?php return ["db" => ["host" => "127.0.0.1\n[client]\nplugin-dir=/tmp", "user" => "om", "pass" => "x", "name" => "a"]];\n' >"$PRIV/config.php"
  chown www-data:www-data "$PRIV/config.php"
  set +e; ( . "$REPO/server/lib/backup-common.sh"; OM_CONFIG="$PRIV/config.php" bk_mysql_defaults "$PRIV/my.cnf" ) 2>/dev/null; rc=$?; set -e
  check "satır sonlu değerle mysql ayarına seçenek sokulamaz" test "$rc" != 0 -a "$(grep -c plugin-dir "$PRIV/my.cnf" 2>/dev/null || echo 0)" = 0
  rm -rf "${PRIV:?}"
fi

echo "== 13. Kurulum: yeni hedef + eski parolalı mevcut depo → yeni depo oluşturulmadan durur"
(
  export OM_BACKUP_ENV="$T/etc2/backup.env" OM_STATE_DIR="$T/state2"
  export OM_BK_DISK="$T/disk2/repo" OM_BK_OFFSITE=local OM_BK_OFFSITE_PATH="$T/disk/repo"
  set +e; bash "$REPO/server/setup-backup.sh" --non-interactive >"$T/setup3.log" 2>&1; rc=$?; set -e
  check "parola uyuşmazlığında durdu" test "$rc" = 1
  check "uyuşmazlık açıkça söylendi" grep -q 'parola uymuyor' "$T/setup3.log"
  check "yeni hedefte farklı parolalı depo oluşturulmadı" test ! -e "$T/disk2/repo/config"
  export OM_BK_PASSWORD; OM_BK_PASSWORD="$( . "$REPO/server/lib/backup-common.sh"; OM_BACKUP_ENV="$T/etc/backup.env" bk_load_env; printf '%s' "$RESTIC_PASSWORD")"
  bash "$REPO/server/setup-backup.sh" --non-interactive >"$T/setup4.log" 2>&1 || { cat "$T/setup4.log"; exit 1; }
  check "doğru parolayla: mevcut depo tanındı, yeni depo oluşturuldu" bash -c "grep -q 'mevcut yedek deposu bulundu' '$T/setup4.log' && grep -q 'yeni şifreli yedek deposu' '$T/setup4.log'"
)
pass=$((pass + 4))

echo "== 14. Geçiş dönemi: yeni yedek kurulmamışken eski rsync yedeği çalışmaya devam eder"
(
  export OM_BACKUP_ENV="$T/yok/backup.env" OM_LEGACY_BACKUP_ENV="$T/legacy.env"
  echo "BACKUP_DIR=$T/legacy" >"$OM_LEGACY_BACKUP_ENV"
  bash "$REPO/server/backup.sh" >"$T/legacy1.log" 2>&1 || { cat "$T/legacy1.log"; exit 1; }
  check "eski yedek çalıştı" test -f "$T/legacy/db/ownmemories-$(date +%F).sql.gz"
  check "orijinaller eşlendi" diff -r -q "$STORAGE/blobs" "$T/legacy/storage/blobs"
  # Fotoğraf diski bağlı değilse eski yedek --delete ile her şeyi silmemeli.
  php -r '$c = require $argv[1]; $c["storage"]["path"] = $argv[2]; file_put_contents($argv[3], "<?php return " . var_export($c, true) . ";\n");' "$OM_CONFIG" "$T/bos-depo" "$T/legacy-config.php"
  mkdir -p "$T/bos-depo"
  set +e; OM_CONFIG="$T/legacy-config.php" bash "$REPO/server/backup.sh" >"$T/legacy2.log" 2>&1; rc=$?; set -e
  check "boş depoda eski yedek durdu" test "$rc" != 0
  check "eski yedekteki orijinaller yerinde" diff -r -q "$STORAGE/blobs" "$T/legacy/storage/blobs"
)
pass=$((pass + 4))

echo
echo "Tüm denetimler geçti: $pass"
echo "Kaynak: $(find "$STORAGE/blobs" -type f | wc -l) orijinal, $(du -sh "$STORAGE/blobs" | cut -f1), $SRC_ASSETS öğe; ilk yedek ${d1}s"
echo "İlk yedek: $FILES1 dosya, eklenen $ADDED1 bayt, depo $SIZE1 bayt; ikinci yedekte eklenen $ADDED2 bayt"
echo "Hata metni örneği: $ERR"
echo "Geri yükleme testi: $(grep -E 'BAŞARILI' "$T/rtest.log")"
echo "Durum dosyası: $OM_STATE_DIR/backup-status.json   (başarısız hâli: $T/status-failed.json)"
echo "Çalışma klasörü: $T"
