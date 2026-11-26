# OwnMemories Aşama 1 — Sunucu, Takeout Aktarımı, Web Uygulama Planı

Hazırlayan: Mehmet BALKAY

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Saf PHP + MySQL ile çalışan OwnMemories sunucusu: kurulum sihirbazı, hesaplar, medya deposu, iş kuyruğu, Google Takeout içe aktarma, REST API, web zaman çizelgesi ve yönetim paneli.

**Architecture:** OwnCalendar'ın düzeni (front controller'lı `/api/v1`, `includes/` çekirdeği, `lang/`, `setup.php`, otomatik migrasyon) temel alınır. Ağır işler MySQL tabanlı iş kuyruğuna yazılır; Ubuntu'da systemd işçisi, paylaşımlı hostingde dakikalık cron tüketir. Dış araçlar (exiftool, ffmpeg, vips) varsa kullanılır, yoksa PHP eklentilerine geri düşülür.

**Tech Stack:** PHP 8.1+ (framework/Composer yok), PDO MySQL (MySQL 5.7+/8, MariaDB 10.4+), saf JS (ES modülleri), Leaflet (CDN), GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-26-ownmemories-design.md`

## Global Constraints

- PHP 8.1+, `declare(strict_types=1);`, Composer/framework yok. MySQL 5.7+ uyumlu SQL: `SKIP LOCKED`, CTE, `JSON_TABLE` **kullanılmaz**.
- InnoDB, utf8mb4_unicode_ci, tüm zamanlar UTC; `assets.taken_at` DATETIME(UTC) + `taken_tz_offset` (dakika).
- Orijinal dosyalara asla yazılmaz; orijinaller web kökünden doğrudan sunulmaz.
- Hiçbir dosya sessizce atlanmaz: her içe aktarma öğesi `import_items` satırı alır.
- Büyük dosyalar belleğe okunmaz (akış/`hash_file`/`stream_copy_to_stream`).
- Parçalı yüklemede parça ≤ 50 MB (Cloudflare 100 MB sınırı).
- Yanıtlarda medya için `Cache-Control: private, max-age=31536000, immutable` (türevler) / `private, no-store` (orijinal); hiçbir yanıt herkese açık önbelleğe alınmaz.
- Diller: `tr` (varsayılan) + `en`; tüm kullanıcı metinleri `t('grup.anahtar')`.
- Belgelerde "Hazırlayan: Mehmet BALKAY".
- Varsayılan saat dilimi geri düşüşü: `Europe/Istanbul`.
- Çöp kutusu süresi: 30 gün.
- Alan adı (prod): `https://ownmemories.balkay.net`; kod alan adına bağımlı olmaz (`config('app.url')`).

## Review Focus

1. **Türkçe/Unicode ve çok uzun dosya adları zip içinde** (`Doğum günü – İzmir (1).jpg`, CP437 bayraklı adlar) → doğru eşleşmeli, bozuk ad üretmemeli. Test: Task 7 `test_match_unicode_names`.
2. **Aynı fotoğraf birden çok klasörde (yıl klasörü + albümler)** → tek asset, çok albüm üyeliği. Test: Task 8 `test_import_same_file_in_two_albums`.
3. **Aktarım yarıda kesilip yeniden başlatılır** → kaldığı yerden devam, çift kayıt yok. Test: Task 8 `test_import_resume_after_interrupt`.
4. **Hiç tarih bilgisi olmayan dosya** → dosya adındaki tarih, o da yoksa dosya zamanı; raporda `no_metadata` ama içeride. Test: Task 7 `test_resolve_date_fallbacks`.
5. **Hostingde HEIC/ffmpeg desteği yok** → asset yine içe aktarılır, küçük resim yer tutucu, iş `skipped` olur (hata değil). Test: Task 4 `test_thumbnail_unsupported_returns_placeholder`.

---

## Dosya yapısı

```
web/                         document root
  index.php                  giriş yoksa /login.php'ye, varsa /app/'e yönlendirir
  setup.php login.php logout.php
  app/index.php              web uygulaması kabuğu
  admin/ index.php users.php imports.php jobs.php system.php _layout.php
  api/index.php api/.htaccess
  includes/
    bootstrap.php functions.php i18n.php auth.php migrate.php
    tools.php                dış araç tespiti ve çalıştırma
    media.php                meta veri okuma, küçük resim/önizleme, video poster
    storage.php              blob deposu (içerik özetine göre), yollar
    assets.php               asset oluşturma, yineleme kontrolü, silme/çöp
    albums.php               albümler, üyeler
    jobs.php                 iş kuyruğu
    takeout.php              Takeout eşleştirme + meta veri çözümleme (saf fonksiyonlar)
    importer.php             Takeout içe aktarma yürütücüsü (zip, dilimli)
    uploads.php              parçalı yükleme
    api.php                  json_out, ApiError, input yardımcıları, route tablosu
    serve.php                dosya sunumu (Range)
  install/schema.sql install/migrations/
  lang/tr/*.php lang/en/*.php   (common, auth, app, admin)
  assets/app/*.js app.css   assets/admin/admin.css assets/img/
  config/ (setup yazar; erişim kapalı)
bin/ worker.php cron.php import-takeout.php
server/ install-ubuntu.sh ownmemories-worker.service ownmemories-cron.service ownmemories-cron.timer cloudflared-config.example.yml
tests/ run.php lib.php fixtures/ unit/*.php db/*.php
docs/ ARCHITECTURE.md INSTALL.md
.github/workflows/php.yml
```

Testler: `php tests/run.php` (bağımlılıksız mini çalıştırıcı). `tests/unit/` veritabanı gerektirmez; `tests/db/` `OM_TEST_DB_*` ortam değişkenleri verilmişse geçici bir veritabanında şemayı kurup çalışır, verilmemişse "skipped" yazar.

---

### Task 1: İskelet, çekirdek fonksiyonlar, test çalıştırıcı, CI

**Files:**
- Create: `web/includes/bootstrap.php`, `web/includes/functions.php`, `web/includes/i18n.php`, `web/lang/{tr,en}/common.php`, `web/.htaccess`, `web/config/.htaccess`, `web/includes/.htaccess`, `tests/run.php`, `tests/lib.php`, `tests/unit/FunctionsTest.php`, `.github/workflows/php.yml`, `.gitignore`, `README.md`

**Interfaces:**
- Produces: `config(string $key, $default=null)`, `config_all(?array $set=null): array`, `is_installed(): bool`, `db(): PDO`, `db_connect(array $c): PDO`, `e(?string): string`, `t(string $key, array $vars=[]): string`, `locale(): string`, `url(string $path=''): string`, `asset(string $path): string`, `app_log(string $channel, string $msg): void`, `storage_root(): string` (config `storage.path`), `uuid4(): string`, `now_utc(): string` ('Y-m-d H:i:s'), `csrf_token()/csrf_field()/csrf_verify()`, `csp_nonce()`, `redirect()`, `flash()/flashes()`, `rate_limit(string,int,int): bool`, `site_setting()/site_setting_set()`. Sabitler: `OM_WEB`, `OM_ROOT` (depo kökü), `OM_VERSION='0.1.0'`. Bootstrap bayrakları: `OM_API`, `OM_ADMIN`, `OM_CLI`.
- Test kütüphanesi: `test(string $name, callable $fn)`, `assert_eq($exp, $act, string $msg='')`, `assert_true(bool, string='')`, `assert_throws(string $class, callable)`, `test_db(): ?PDO` (şemalı temiz test veritabanı veya null), `fixture(string $rel): string`.

- [ ] **Step 1:** `tests/unit/FunctionsTest.php`: `test_uuid4_format` (regex `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`), `test_e_escapes` (`e('<a "x">')` = `&lt;a &quot;x&quot;&gt;`), `test_t_fallback` (bilinmeyen anahtar anahtarı döner; `{n}` yer tutucusu değişir), `test_config_dot` (`config_all(['a'=>['b'=>1]])` sonrası `config('a.b')===1`).
- [ ] **Step 2:** `php tests/run.php` → FAIL (dosyalar yok).
- [ ] **Step 3:** Fonksiyonları OwnCalendar `web/includes/functions.php` desenine göre yaz (önek `owncal_` yerine `om_`), bootstrap kurulmamışsa `/setup.php`'ye yönlendirir; CLI'de hata mesajıyla çıkar.
- [ ] **Step 4:** `php tests/run.php` → tüm testler PASS; `find web bin tests -name '*.php' -exec php -l {} \;` hatasız.
- [ ] **Step 5:** CI `php.yml`: PHP 8.1 ve 8.3 matrisi, `mysql:8.0` servis, sözdizimi kontrolü + `php tests/run.php` (`OM_TEST_DB_*` ile).
- [ ] **Step 6:** Commit `feat: iskelet, çekirdek fonksiyonlar, test çalıştırıcı`.

### Task 2: Şema, migrasyon, kurulum sihirbazı

**Files:**
- Create: `web/install/schema.sql`, `web/includes/migrate.php`, `web/setup.php`, `web/includes/tools.php`, `tests/db/SchemaTest.php`, `tests/unit/ToolsTest.php`

**Interfaces:**
- Produces: tablolar (aşağıda), `migrations_run(): int`, `SCHEMA_VERSION = 1`, `tools_detect(bool $fresh=false): array` → `['exiftool'=>?string yol,'ffmpeg'=>?string,'ffprobe'=>?string,'vipsthumbnail'=>?string,'imagick'=>bool,'imagick_heic'=>bool,'gd'=>bool,'gd_webp'=>bool,'zip'=>bool,'exec'=>bool]` (sonuç `site_settings.tools_json`'a önbelleklenir), `tool_run(array $argv, int $timeoutSec=600): array{code:int,out:string,err:string}` (`proc_open`, kabuk yok).

Tablolar (sütun adları bağlayıcı; tipler kurucuya):
- `users`: id, email UNIQUE, password_hash, name, is_admin TINYINT, locale, timezone, quota_bytes BIGINT NULL, used_bytes BIGINT DEFAULT 0, sync_version BIGINT, status, created_at, last_seen_at
- `api_tokens`: id, user_id, token_hash CHAR(64) UNIQUE, name, platform, created_at, last_used_at, expires_at
- `blobs`: sha1 CHAR(40) PK, size BIGINT, mime, ext, path (depo köküne göreli), ref_count INT, created_at
- `assets`: id CHAR(36) PK, user_id, blob_sha1, type ENUM('photo','video'), original_filename, mime, size, width, height, duration_ms, taken_at DATETIME, taken_tz_offset SMALLINT NULL, date_source ENUM('takeout','exif','filename','file'), latitude DECIMAL(9,6) NULL, longitude DECIMAL(9,6) NULL, altitude, camera_make, camera_model, lens, description TEXT, is_favorite, is_archived, shared_from_others, trashed_at NULL, source ENUM('takeout','ios','web'), source_ref VARCHAR(512), live_pair_id CHAR(36) NULL, edited_of_id CHAR(36) NULL, thumb_state ENUM('pending','ready','placeholder','failed'), created_at, updated_at, version BIGINT; UNIQUE(user_id, blob_sha1); INDEX(user_id, trashed_at, taken_at); INDEX(user_id, version)
- `deleted_checksums`: user_id, sha1, deleted_at; PK(user_id, sha1)
- `albums`: id CHAR(36), user_id (sahip), title, description, cover_asset_id, source ENUM('takeout','user'), source_ref, created_at, updated_at, version
- `album_assets`: album_id, asset_id, added_by, sort_order, added_at; PK(album_id, asset_id)
- `album_members`: album_id, user_id, role ENUM('viewer','contributor'), created_at; PK(album_id,user_id)
- `asset_people`: asset_id, name; PK(asset_id,name)
- `jobs`: id BIGINT AI, type, payload JSON(TEXT), status ENUM('queued','running','done','failed','skipped'), attempts, priority TINYINT, run_after DATETIME, locked_by VARCHAR(64) NULL, locked_at NULL, last_error TEXT, created_at, updated_at; INDEX(status, priority, run_after)
- `imports`: id, user_id, status ENUM('queued','scanning','importing','done','failed','paused'), source_dir, files_total, files_done, counts_json, current_zip, current_entry, started_at, finished_at, last_error
- `import_items`: id, import_id, zip_name, entry_path VARCHAR(1024), entry_hash CHAR(40) (sha1(zip_name+entry_path)), result ENUM('imported','duplicate','no_metadata','skipped_trashed','failed','metadata_only'), asset_id NULL, detail TEXT; UNIQUE(import_id, entry_hash)
- `uploads`: id CHAR(36), user_id, filename, size BIGINT, sha1 CHAR(40) NULL, received BIGINT, meta_json, created_at, expires_at
- `site_settings`, `rate_limits`, `audit_log` — OwnCalendar ile aynı.

- [ ] **Step 1:** `tests/db/SchemaTest.php::test_schema_installs_and_is_idempotent` — şemayı iki kez uygula, hata yok; `SHOW TABLES` yukarıdaki 16 tabloyu içerir. `tests/unit/ToolsTest.php::test_tool_run_captures_output` — `tool_run(['php','-r','echo 42;'])` → code 0, out "42"; `test_tool_run_no_shell` — argüman `'; echo hacked'` literal geçer.
- [ ] **Step 2:** Çalıştır → FAIL.
- [ ] **Step 3:** `schema.sql` (`CREATE TABLE IF NOT EXISTS`), `migrate.php` (OwnCalendar mantığı, `om_migrate` kilidi), `tools.php`.
- [ ] **Step 4:** `setup.php`: adımlar — gereksinim kontrolü (PHP sürümü, pdo_mysql, zip, gd/imagick, yazılabilir klasörler, `tools_detect` tablosu), DB bilgileri (bağlantı testi), uygulama URL'si + depolama klasörü (varsayılan `dirname(OM_WEB).'/storage'`; web kökü içindeyse `.htaccess` `Require all denied` yazılır), sahip hesabı (ad, e-posta, parola ≥ 10 karakter, `is_admin=1`). `config/config.php` + `installed.lock` yazar; kurulu ise 404.
- [ ] **Step 5:** Testler PASS; yerelde `php -S 127.0.0.1:8080 -t web` ile setup elle tamamlanır, `/login.php` açılır.
- [ ] **Step 6:** Commit `feat: şema, migrasyon, kurulum sihirbazı, araç tespiti`.

### Task 3: Hesaplar ve oturum

**Files:**
- Create: `web/includes/auth.php`, `web/login.php`, `web/logout.php`, `web/index.php`, `web/lang/{tr,en}/auth.php`, `tests/db/AuthTest.php`

**Interfaces:**
- Produces: `user_create(string $email, string $password, string $name, bool $isAdmin=false, ?int $quotaBytes=null): int`, `user_verify_password(string $email, string $password): ?array`, `current_user(): ?array`, `require_login(): array`, `require_admin(): array`, `login_user(array $u): void`, `logout_user(): void`, `api_token_issue(int $userId, string $name, string $platform): string` (düz token döner, sha256 saklanır), `api_user_from_bearer(): ?array`.

- [ ] **Step 1:** Testler: `test_create_and_verify_user`, `test_duplicate_email_throws` (`ValidationError`), `test_wrong_password_returns_null`, `test_token_roundtrip` (`api_token_issue` → `$_SERVER['HTTP_AUTHORIZATION']='Bearer …'` → `api_user_from_bearer()['id']`), `test_email_case_insensitive`.
- [ ] **Step 2:** FAIL. **Step 3:** Uygula (`password_hash` DEFAULT, oturum kimliği girişte yenilenir, giriş 10/15dk IP+e-posta hız sınırı). `login.php` sade form, pastel tema. Herkese açık kayıt yok.
- [ ] **Step 4:** PASS. **Step 5:** Commit `feat: hesaplar, oturum, API token`.

### Task 4: Medya araç takımı

**Files:**
- Create: `web/includes/media.php`, `tests/unit/MediaTest.php`, `tests/fixtures/media/` (test sırasında GD ile üretilen JPEG; EXIF'li örnek `exif_sample.jpg` küçük, depoya eklenir; `tiny.mp4` ffmpeg varsa testte üretilir)

**Interfaces:**
- Consumes: `tools_detect()`, `tool_run()`.
- Produces:
  - `media_kind(string $path, ?string $filename=null): ?string` → `'photo'|'video'|null` (uzantı + `finfo`; HEIC/HEIF/JPG/PNG/GIF/WEBP/DNG/TIFF; MP4/MOV/M4V/3GP/AVI/MKV/MTS).
  - `media_probe(string $path, string $kind): array` → anahtarlar: `mime, width, height, duration_ms, taken_local (Y-m-d H:i:s|null), tz_offset (int dk|null), lat, lng, alt, make, model, lens, orientation, description`. Sıra: exiftool `-json -n` → (foto) `exif_read_data` → (video) ffprobe.
  - `media_render(string $src, string $kind, string $dest, int $maxEdge, string $format='webp'|'jpeg'): string` → `'ready'|'placeholder'` ; sıra vipsthumbnail → Imagick → GD (GD HEIC açamaz → placeholder); video için ffmpeg ile 1. saniyeden kare, ffmpeg yoksa placeholder. EXIF yönü uygulanır. WebP yoksa JPEG.
  - Boyutlar: `THUMB_EDGE=360`, `PREVIEW_EDGE=2048`.

- [ ] **Step 1:** Testler: `test_kind_by_extension_and_magic`, `test_probe_reads_exif_date_and_gps` (exif_sample.jpg: beklenen tarih ve koordinat fixture ile birlikte `exif_sample.json`'da), `test_probe_without_exif_returns_nulls`, `test_render_photo_thumbnail_dimensions` (4000×3000 GD görüntü → uzun kenar 360), `test_render_respects_orientation`, `test_thumbnail_unsupported_returns_placeholder` (sahte `.heic`, tüm araçlar devre dışı → `'placeholder'`, dosya yazılmaz), `test_video_poster_when_ffmpeg` (ffmpeg yoksa skip).
- [ ] **Step 2:** FAIL. **Step 3:** Uygula; araçları test için devre dışı bırakmak üzere `tools_detect` sonucunu ezen `tools_override(?array $o): void`. **Step 4:** PASS. **Step 5:** Commit `feat: medya meta veri ve küçük resim araçları`.

### Task 5: Blob deposu, asset oluşturma, yineleme, çöp

**Files:**
- Create: `web/includes/storage.php`, `web/includes/assets.php`, `tests/db/AssetsTest.php`

**Interfaces:**
- Consumes: `media_kind`, `media_probe`, `job_enqueue` (Task 6 — bu task'ta `jobs.php` iskeleti sadece `job_enqueue` ile oluşturulur).
- Produces:
  - `blob_put(string $srcPath, string $sha1, string $ext, bool $move=false): array` (blob satırı; dosya `blobs/aa/bb/<sha1>.<ext>`; var ise ref_count++; kopyalama akışla).
  - `blob_path(string $sha1): string` mutlak yol. `blob_release(string $sha1): void` (ref_count--, 0 ise dosya + türevler silinir).
  - `derived_path(string $assetId, string $variant /* thumb|preview|video */, string $ext): string` (`derived/<variant>/<ilk 2>/<id>.<ext>`).
  - `asset_ingest(int $userId, string $srcPath, array $in): array` → `['status'=>'created'|'duplicate'|'deleted_before', 'asset_id'=>?string]`. `$in` anahtarları: `filename, source, source_ref, sha1?(yoksa hesaplanır), taken_at_utc?, tz_offset?, date_source?, lat?, lng?, alt?, description?, is_favorite?, is_archived?, shared_from_others?, move?`. Verilmeyen tarih/konum `media_probe`'dan. Yineleme: önce (user_id, sha1); sonra ikincil kontrol (aynı kullanıcı, `taken_at` aynı saniye, aynı width×height, aynı `original_filename` küçük harf). `deleted_checksums`'ta varsa `deleted_before`. Oluşturulunca `jobs`: `render` (thumb+preview), video ise `render` poster. `used_bytes` artar.
  - `asset_trash(int $userId, array $ids): int`, `asset_restore(int $userId, array $ids): int`, `asset_purge(int $userId, array $ids): int` (blob_release + `deleted_checksums` + used_bytes azalt), `trash_purge_expired(int $days=30): int`.
  - `asset_bump_version(int $userId): int` (users.sync_version++ ve döner; her değişiklikte assets.version'a yazılır).
  - `asset_to_json(array $row): array` (API nesnesi, `docs/ARCHITECTURE.md` §Asset).

- [ ] **Step 1:** Testler: `test_ingest_creates_asset_and_blob`, `test_ingest_same_file_twice_is_duplicate`, `test_ingest_secondary_match_duplicate` (farklı bayt, aynı tarih/boyut/ad → duplicate), `test_same_file_two_users_one_blob` (ref_count 2, iki asset), `test_purge_records_deleted_checksum_and_blocks_reingest`, `test_trash_restore_roundtrip`, `test_trash_purge_expired_respects_30_days`, `test_used_bytes_tracking`.
- [ ] **Step 2:** FAIL. **Step 3:** Uygula (asset oluşturma tek transaction; blob dosyası transaction dışında önce yazılır, hata olursa release). **Step 4:** PASS. **Step 5:** Commit `feat: blob deposu ve asset yaşam döngüsü`.

### Task 6: İş kuyruğu, işçi, cron

**Files:**
- Create: `web/includes/jobs.php`, `bin/worker.php`, `bin/cron.php`, `tests/db/JobsTest.php`

**Interfaces:**
- Produces: `job_enqueue(string $type, array $payload, int $priority=5, int $delaySec=0): int`, `job_claim(string $workerId): ?array` (MySQL 5.7 uyumlu: `UPDATE jobs SET status='running', locked_by=?, locked_at=NOW(), attempts=attempts+1 WHERE status='queued' AND run_after<=NOW() ORDER BY priority, id LIMIT 1` sonra `locked_by` ile seç; 30 dk'dan eski `running` işler kuyruğa geri alınır), `job_complete(int $id, string $status='done', ?string $err=null)`, `jobs_run_for(int $budgetSec, string $workerId): int` (işlenen sayı), `job_handlers(): array` (`'render' => fn(array $p): string`, `'import_step' => …`). Başarısızlıkta: attempts < 3 ise `queued` + `run_after = NOW() + attempts*5dk`, değilse `failed`.
- `render` işleyicisi: `media_render` ile thumb (360) + preview (2048), `assets.thumb_state` güncellenir; placeholder → iş `skipped`.
- `bin/worker.php`: sonsuz döngü, iş yoksa 2 sn uyku, SIGTERM'de temiz çıkış (`pcntl` varsa). `bin/cron.php`: kilitli (`GET_LOCK('om_cron',0)`), `jobs_run_for(50)`, günde bir `trash_purge_expired()` ve süresi geçmiş `uploads` temizliği. Web'den çağrılabilir sürüm: `web/cron.php?key=<config cron.key>` (hostingde URL tabanlı cron için).

- [ ] **Step 1:** Testler: `test_enqueue_claim_complete`, `test_two_workers_never_claim_same_job` (iki ayrı PDO bağlantısı ile ardışık claim), `test_retry_then_failed_after_3`, `test_stale_running_requeued`, `test_render_handler_sets_thumb_state`.
- [ ] **Step 2:** FAIL. **Step 3:** Uygula. **Step 4:** PASS. **Step 5:** Commit `feat: iş kuyruğu, işçi ve cron`.

### Task 7: Takeout eşleştirme ve meta veri çözümleme (saf fonksiyonlar)

**Files:**
- Create: `web/includes/takeout.php`, `tests/unit/TakeoutTest.php`, `tests/fixtures/takeout/*.json` (örnek JSON şekilleri)

**Interfaces:**
- Produces:
  - `takeout_classify(array $entryPaths): array` → `['media'=>[path=>…], 'sidecars'=>[path=>…], 'album_meta'=>[dir=>path], 'other'=>[…]]`. Yollar zip içi göreli, `Takeout/Google Fotoğraflar/...` veya `Takeout/Google Photos/...` öneki tanınır (önek sonrası ilk klasör = "kova").
  - `takeout_match_sidecar(string $mediaPath, array $sidecarIndex): ?string` — `$sidecarIndex` aynı klasördeki sidecar adları. Denenecek adaylar sırayla: `<ad>.json`; `<ad>.supplemental-metadata.json` ve 46/47/51 karaktere kesilmiş halleri (`.supplemental-metadat.json`, `.supplemental-me.json`, `.suppl.json` vb. — kural: sidecar adı `.json` öncesi kısmı, `<ad>.supplemental-metadata` dizgesinin bir öneki ve en az `<ad>` + `.s` uzunluğunda); numaralı kopya `IMG(1).jpg` → `IMG.jpg(1).json` ve `IMG.jpg.supplemental-metadata(1).json`; ad 46/47 karakterden uzunsa kesilmiş `<ad'ın ilk 46|47 karakteri>.json`; düzenlenmiş eki (`-edited`, `-düzenlendi`, `-bearbeitet`, `-modifié`, `-editado`) atılarak orijinalin sidecar'ı; Live Photo videosu (`IMG_1.MP4`) için `IMG_1.HEIC.json`/`IMG_1.JPG.json`. Hiçbiri yoksa null.
  - `takeout_parse_sidecar(string $json): array` → `title, description, taken_ts (int|null, photoTakenTime.timestamp), lat, lng, alt (geoData; 0/0 → null, sonra geoDataExif), favorited bool, archived bool, trashed bool, people string[], from_shared bool (googlePhotosOrigin.fromSharedAlbum veya fromPartnerSharing varlığı)`.
  - `takeout_resolve(array $sidecar /*parse sonucu veya []*/, array $probe /*media_probe*/, string $filename, ?int $fileMtime): array` → `taken_at_utc, tz_offset, date_source, lat, lng, alt, description, is_favorite, is_archived, shared_from_others`. Kurallar spec §7.1: tarih sidecar > EXIF (ofset varsa UTC'ye çevrilir, yoksa Europe/Istanbul kabul) > dosya adı (`/(19|20)\d{2}[-_]?\d{2}[-_]?\d{2}[-_ T]?\d{2}[-_.:]?\d{2}[-_.:]?\d{2}/`, sonra yalnız tarih) > mtime. `tz_offset`: EXIF ofseti; yoksa sidecar zamanı ile EXIF yerel saati arasındaki fark (15 dk'ya yuvarlanmış, ±14 saat içinde); yoksa Europe/Istanbul'un o tarihteki ofseti. Konum sidecar geoData > geoDataExif > EXIF GPS.
  - `takeout_album_title(string $dir, ?string $metadataJson): ?string` — `metadata.json` varsa `title`; kova `Photos from YYYY` / `YYYY yılına ait fotoğraflar` desenindeyse null (albüm değil); `Arşiv/Archive`, `Çöp Kutusu/Trash`, `Failed Videos` gibi özel kovalar null.
  - `takeout_live_pairs(array $mediaPaths): array` → `[photoPath => videoPath]` (aynı klasör, aynı taban ad, foto uzantısı HEIC/JPG/JPEG + video MOV/MP4).
  - `takeout_edited_original(string $path, array $mediaPathsInDir): ?string`.

- [ ] **Step 1:** Testler (veri tablolu): `test_match_simple_json`, `test_match_supplemental_and_truncations` (en az 5 kesme biçimi), `test_match_numbered_duplicate`, `test_match_long_name_truncated_46_47`, `test_match_edited_uses_original`, `test_match_live_video_uses_photo_sidecar`, `test_match_unicode_names` (`Doğum günü – İzmir (1).jpg`, NFC/NFD farklı normalizasyon aynı sayılır), `test_parse_zero_geo_is_null`, `test_parse_shared_flag`, `test_resolve_sidecar_beats_exif`, `test_resolve_tz_offset_from_exif_difference` (EXIF yerel 2019-07-01 15:00, sidecar UTC 12:00 → +180), `test_resolve_date_fallbacks` (dosya adı `IMG_20180512_093011.jpg` → 2018-05-12 06:30:11 UTC; ad tarihsizse mtime), `test_album_title_rules` (TR ve EN yıl kovaları null; metadata.json başlığı döner), `test_live_pairs`.
- [ ] **Step 2:** FAIL. **Step 3:** Uygula (tüm karşılaştırmalar `Normalizer::normalize(…, FORM_C)` ve büyük/küçük harf duyarsız uzantı). **Step 4:** PASS. **Step 5:** Commit `feat: Takeout eşleştirme ve meta veri çözümleme`.

### Task 8: Takeout içe aktarma yürütücüsü

**Files:**
- Create: `web/includes/importer.php`, `bin/import-takeout.php`, `tests/db/ImporterTest.php`, `tests/lib_takeout.php` (testte geçici zip üreten yardımcı)

**Interfaces:**
- Consumes: Task 5 `asset_ingest`, Task 6 `job_enqueue`, Task 7 tüm `takeout_*`.
- Produces:
  - `import_create(int $userId, string $dir): int` (dizindeki `*.zip` ve açılmış klasörleri kaynak kabul eder; `import_step` işini kuyruğa koyar).
  - `import_step(int $importId, int $budgetSec): string` → `'more'|'done'`. Aşamalar: `scanning` (tüm zip'lerin giriş listesi okunur, dizin başına sidecar indeksi ve albüm metası `imports` yanında `storage/imports/<id>/index.json`'a yazılır, `files_total` belirlenir) → `importing` (zip zip, giriş giriş: `import_items`'ta `entry_hash` varsa atla; medya geçici dosyaya akışla çıkarılır (`ZipArchive::getStream`), sidecar zip'ler arası olabilir → indeksten okunur, `takeout_resolve`, `asset_ingest(move=true)`, albüm üyeliği, `asset_people`, sonuç satırı) → `done` (live pair ve edited bağlantıları ikinci geçişte `source_ref` üzerinden kurulur; `counts_json` yazılır). Bütçe dolunca durumu kaydedip `'more'` döner ve kendini yeniden kuyruğa koyar.
  - Sonuç eşlemesi: sidecar `trashed` → `skipped_trashed` (içe aktarılmaz); sidecar yok → içe aktarılır, `no_metadata`; ingest `duplicate`/`deleted_before` → `duplicate`; istisna → `failed` + detay; sadece JSON/HTML gibi medya dışı → satır yazılmaz ama `counts.other` sayılır.
  - Albümler: kova albümse (`takeout_album_title` ≠ null) `albums` (source `takeout`, source_ref kova adı) bul/oluştur; yinelenen asset de albüme eklenir.
  - `import_report(int $importId): array` (`counts`, `failed`/`no_metadata` listeleri sayfalı).
  - CLI: `php bin/import-takeout.php --user=<email> [--dir=<yol>]` → bütçesiz döngüyle `import_step`, ilerlemeyi satır satır yazar.

- [ ] **Step 1:** Testler (yardımcı ile 2 zip üret: zip1 `Photos from 2019/` içinde 3 foto + sidecar, `Tatil 2019/` albüm klasörü (metadata.json) içinde aynı fotolardan biri; zip2'de zip1'deki bir fotonun sidecar'ı, bir çöp öğesi, sidecar'sız bir foto, bir Live Photo çifti, `-edited` çifti): `test_import_counts_and_results`, `test_import_same_file_in_two_albums`, `test_import_sidecar_in_other_zip`, `test_import_skips_trashed_with_record`, `test_import_live_and_edited_links`, `test_import_resume_after_interrupt` (budget 0 ile adım adım; ortada yeni `import_step` çağrısı → aynı sonuç, çift asset yok), `test_import_rerun_same_dir_all_duplicates`.
- [ ] **Step 2:** FAIL. **Step 3:** Uygula. **Step 4:** PASS. **Step 5:** Commit `feat: Takeout içe aktarma`.

### Task 9: REST API, dosya sunumu, parçalı yükleme, albümler

**Files:**
- Create: `web/api/index.php`, `web/api/.htaccess`, `web/includes/api.php`, `web/includes/serve.php`, `web/includes/uploads.php`, `web/includes/albums.php`, `tests/db/ApiTest.php`, `tests/db/UploadsTest.php`, `tests/db/AlbumsTest.php`

**Interfaces:**
- Produces (fonksiyonlar): `api_route(string $method, string $path, array $user): array` (test edilebilir yönlendirici, `ApiError` fırlatır), `upload_start(int $userId, string $filename, int $size, ?string $sha1, array $meta): array`, `upload_chunk(string $id, int $userId, int $offset, $stream): int` (yeni alınan bayt; offset ≠ received → 409 `offset_mismatch`, `received` ile), `upload_complete(string $id, int $userId): array` (SHA-1 doğrular → `asset_ingest(source='web'|'ios')`), `album_create/update/delete`, `album_add_assets(string $albumId, int $userId, array $assetIds)`, `album_remove_assets`, `album_share(string $albumId, int $ownerId, string $email, string $role)`, `album_unshare`, `album_can(int $userId, string $albumId, string $perm /*view|contribute|own*/): bool`, `serve_file(string $path, string $mime, string $cacheControl): never` (Range, 206, `Accept-Ranges`, `ETag`).
- Uçlar (`/api/v1`), sözleşme `docs/ARCHITECTURE.md`'ye yazılır:
  - `GET ping`, `POST auth/login {email,password,device_name,platform}` → `{token,user}`, `POST auth/logout`, `GET me`
  - `GET assets?before=<taken_at ISO>&before_id=&limit=200&favorite=1&archived=1&trashed=1&album=<id>&q=&from=&to=&has_location=1` → `{assets:[…], next:{before,before_id}|null}` (taken_at DESC, id DESC; anahtar kümesi sayfalama)
  - `GET assets/summary` → `[{month:"2019-07",count}]` (zaman çizelgesi kaydırma çubuğu)
  - `GET assets/{id}`, `PATCH assets/{id} {is_favorite?,is_archived?,description?,taken_at?,latitude?,longitude?}`
  - `POST assets/trash {ids}`, `POST assets/restore {ids}`, `POST assets/purge {ids}`
  - `GET assets/{id}/thumb|preview|original|video` (thumb/preview hazır değilse 202 + yer tutucu SVG; `video` = orijinal, Range)
  - `POST assets/check {sha1:[…≤500]}` → `{missing:[…]}` (deleted_checksums "var" sayılır)
  - `POST uploads {filename,size,sha1?,meta?}` → `{id,chunk_size:52428800,received:0}`; `PUT uploads/{id}` gövde ham bayt + `Content-Range: bytes a-b/total` → `{received}`; `GET uploads/{id}` → `{received}`; `POST uploads/{id}/complete` → `{status,asset}`
  - `GET assets/map?bbox=` → `[{id,lat,lng}]` (≤ 5000, konumlu)
  - `GET albums` (sahip olunan + paylaşılan, `role` ile), `POST albums`, `GET/PATCH/DELETE albums/{id}`, `POST albums/{id}/assets {ids}`, `DELETE albums/{id}/assets {ids}`, `POST albums/{id}/members {email,role}`, `DELETE albums/{id}/members/{userId}`
  - `GET users/lookup?email=` (yalnız var/yok + ad; paylaşım formu için)
- Yetki kuralları: asset uçları yalnız sahibine; paylaşılan albüm üyesi albümdeki asset'lerin thumb/preview/original/video ve `GET assets/{id}` uçlarına erişir (salt okunur). Contributor kendi asset'lerini ekleyip çıkarabilir; başkasınınkini çıkaramaz.

- [ ] **Step 1:** Testler: `test_login_returns_token`, `test_assets_pagination_keyset` (aynı taken_at'lı 3 asset sayfa sınırında kaybolmaz), `test_filters_favorite_trashed_album`, `test_patch_bumps_version`, `test_check_missing_and_deleted`, `test_other_user_asset_404`, `test_shared_album_member_can_view_not_patch`, `test_contributor_add_own_remove_others_forbidden`, `test_upload_chunks_and_complete`, `test_upload_offset_mismatch_409`, `test_upload_sha1_mismatch_422`, `test_upload_resume_get_received`, `test_serve_range_206` (serve fonksiyonunu çıktı tamponlu test et).
- [ ] **Step 2:** FAIL. **Step 3:** Uygula; front controller OwnCalendar `web/api/index.php` deseni; web oturumunda CSRF zorunlu. **Step 4:** PASS. **Step 5:** `docs/ARCHITECTURE.md` yaz (depo yapısı, tablolar, nesne şekilleri: Asset, Album, Upload; uçlar; hata kodları). **Step 6:** Commit `feat: REST API, yükleme, albümler, dosya sunumu`.

### Task 10: Web uygulaması (`/app/`)

**Files:**
- Create: `web/app/index.php`, `web/assets/app/{main,api,store,timeline,viewer,albums,upload,map,search,util}.js`, `web/assets/app/app.css`, `web/lang/{tr,en}/app.php`

**Interfaces:**
- Consumes: Task 9 uçları (yalnız oradaki şekiller).
- Davranış (kabul ölçütleri, elle + Playwright duman testi `tests/e2e/smoke.mjs`):
  - Sol menü: Fotoğraflar, Favoriler, Albümler, Paylaşılan albümler, Harita, Arşiv, Çöp kutusu, (admin ise) Yönetim. Üstte arama kutusu + Yükle düğmesi.
  - Zaman çizelgesi: güne göre gruplu ("12 Temmuz 2019 Cuma"), ızgara (satır yüksekliği sabit, kareler kırpılmış), kaydırdıkça `next` ile yükleme, sağda `assets/summary`'den ay/yıl kaydırma çubuğu, 50.000 öğede akıcı (yalnız görünür grupların DOM'da tutulduğu sanal liste).
  - Seçim modu: uzun tık/checkbox, toplu favori/arşiv/çöp/albüme ekle.
  - Görüntüleyici: tam ekran, ← → klavye, kaydırma; preview, "Orijinal" düğmesi; video oynatma (`video` ucu); bilgi paneli (tarih yerel saatle + ofset, dosya adı, boyut, kamera, konum mini harita, açıklama düzenleme, albümler, kişiler); Live Photo için üzerine gelince video.
  - Albümler: oluştur, yeniden adlandır, kapak, paylaş (e-posta + rol), üyeler; paylaşılan albümde sahip adı görünür.
  - Yükleme: sürükle-bırak/çoklu dosya, tarayıcıda SHA-1 (Web Crypto, dosya 1 GB'tan büyükse sunucu doğrulamasına bırakılır), `assets/check`, 50 MB parçalı `PUT`, ilerleme çubuğu, yeniden deneme.
  - Harita: Leaflet 1.9.4 (cdnjs) + OSM döşemeleri, kümeleme (Leaflet.markercluster 1.5.3), tıklayınca o konumun fotoğrafları.
  - Arama: tarih aralığı, dosya adı/açıklama/kamera/kişi metni (`q`).
  - Tema: OwnCalendar pastel paleti (açık/koyu `prefers-color-scheme`), mobil genişlikte tek sütun menü.

- [ ] **Step 1:** `tests/e2e/smoke.mjs` (Playwright, yerel `php -S` + test veritabanı): giriş yap → zaman çizelgesinde ≥1 kare → görüntüleyiciyi aç → favori → Favoriler'de görünür → çöpe at → Çöp kutusu'nda görünür → geri al. Bu task'ta FAIL.
- [ ] **Step 2:** Uygula. **Step 3:** Duman testi PASS; 1280 ve 390 px genişlikte ekran görüntüsü alınıp göz kontrolü. **Step 4:** Commit `feat: web uygulaması`.

### Task 11: Yönetim paneli

**Files:**
- Create: `web/admin/{_layout,index,users,imports,jobs,system}.php`, `web/assets/admin/admin.css`, `web/lang/{tr,en}/admin.php`, `tests/db/AdminTest.php`

**Interfaces:**
- Consumes: `user_create`, `import_create`, `import_report`, `jobs_*`, `tools_detect`.
- Davranış: yalnız `is_admin` kullanıcılar (ayrı admin tablosu yok — kurucu kararı). 
  - Genel bakış: kullanıcı başına asset sayısı ve kullanılan alan, disk boş alanı (`disk_free_space`), kuyruk özeti, son içe aktarmalar.
  - Kullanıcılar: oluştur (eş hesabı), parola sıfırla, kota (GB), askıya al.
  - İçe aktarmalar: kullanıcı ve klasör seç (varsayılan `<depo>/import`), başlat/duraklat/devam; canlı ilerleme (5 sn yoklama, `files_done/files_total`, mevcut zip); rapor sayfası (sayımlar, `failed` ve `no_metadata` listeleri, CSV indir).
  - İşler: durum sayıları, başarısızlar listesi (hata metni), "yeniden dene", "tüm küçük resimleri yeniden üret".
  - Sistem: `tools_detect(true)` tablosu, PHP sınırları (`upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`), cron son çalışma zamanı (cron her çalıştığında `site_settings.cron_last_run`), sürüm.
- [ ] **Step 1:** Testler: `test_non_admin_forbidden`, `test_create_spouse_user`, `test_import_report_csv_rows`. **Step 2:** FAIL. **Step 3:** Uygula. **Step 4:** PASS. **Step 5:** Commit `feat: yönetim paneli`.

### Task 12: Kurulum belgeleri ve sunucu dosyaları

**Files:**
- Create: `docs/INSTALL.md`, `server/install-ubuntu.sh`, `server/ownmemories-worker.service`, `server/ownmemories-cron.service`, `server/ownmemories-cron.timer`, `server/cloudflared-config.example.yml`; Modify: `README.md`

- İçerik: (A) Test hostingi: dosyaları yükle, `setup.php`, cron (`* * * * * php /yol/bin/cron.php` veya `web/cron.php?key=…`), PHP ayarları (`upload_max_filesize ≥ 64M`, `post_max_size ≥ 64M`), Takeout zip'lerini FTP ile `import/` klasörüne, admin'den başlat. (B) Ubuntu 24.04: `install-ubuntu.sh` (apache2, php8.3 + pdo-mysql/gd/imagick/zip/intl/exif/mbstring, mysql-server, libimage-exiftool-perl, ffmpeg, libvips-tools, libheif; vhost; systemd birimleri), Cloudflare Tunnel kurulumu (`cloudflared tunnel create`, DNS route, örnek config), (C) Gece yedeği: `rsync` + `mysqldump` örnek timer, (D) Hostingden Ubuntu'ya taşıma: `mysqldump` + depo klasörü kopyası + `config.php` güncelleme.
- [ ] **Step 1:** `bash -n server/install-ubuntu.sh` hatasız; `systemd-analyze verify` mevcutsa birimler hatasız.
- [ ] **Step 2:** Commit `docs: kurulum, sunucu birimleri, yedekleme`.

---

## Self-review notları

- Spec §7.1 (Takeout kuralları) → Task 7/8; §7.2 API → Task 9; §7.4 web/admin/setup → Task 2/10/11; §11 kararları (deleted_checksums, tek kare, paylaşılan işareti, isteğe bağlı video dönüştürme) → Task 5/7/8/9; §12 (ikincil eşleştirme, kota, ortak blob) → Task 5; §13 (ortak albümler, hosting geri düşüşleri, cron) → Task 4/6/9.
- Video H.264 dönüştürme Aşama 1'de yok (Safari/iOS HEVC oynatır; spec §11). Anılar, iOS, widget'lar Aşama 2–3 planlarında.
