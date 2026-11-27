# OwnMemories — Mimari Sözleşme

Hazırlayan: Mehmet BALKAY

Bu belge web/iOS istemcileri ile sunucu (saf PHP + MySQL) arasındaki **bağlayıcı sözleşmedir**.
Alan adları, JSON şekilleri ve URL'ler burada yazıldığı gibi uygulanır.

- Alan adı (üretim): `https://ownmemories.balkay.net` (Cloudflare Tunnel). Kod alan adına bağlı değildir (`config('app.url')`).
- Sunucu: PHP 8.1+ (framework/Composer yok), MySQL 5.7+/8.0 veya MariaDB 10.4+, PDO.
- Diller: Türkçe (varsayılan) + İngilizce. Tüm zamanlar UTC saklanır.
- iOS (Aşama 2): Bundle ID `net.balkay.ownmemories`, App Group `group.net.balkay.ownmemories`, iOS 17+.

## 1. Depo yapısı

```
web/                  document root
  setup.php           ilk kurulum sihirbazı (kurulumdan sonra 404)
  login.php logout.php index.php cron.php (web cron: ?key=)
  app/                web uygulaması (oturum gerekli)
  admin/              yönetim paneli (is_admin kullanıcılar)
  api/index.php       REST API ön denetleyicisi
  includes/           çekirdek (web'den erişim kapalı)
    core.php          tüm kütüphaneleri yükler (yan etkisiz) — bootstrap.php, CLI ve testler kullanır
    functions.php i18n.php migrate.php tools.php auth.php layout.php
    media.php         meta veri okuma, küçük resim/önizleme, çekim zamanı çözümleme
    storage.php       SHA-1 blob deposu, türev yolları
    assets.php        asset oluşturma, yineleme, çöp, kalıcı silme
    albums.php        albümler ve ortak albümler
    jobs.php          iş kuyruğu, render işi, cron/bakım
    takeout.php       Takeout eşleştirme (saf fonksiyonlar)
    importer.php      Takeout içe aktarma yürütücüsü
    uploads.php       parçalı yükleme
    api.php           yönlendirici ve uç işleyicileri
    serve.php         Range destekli dosya sunumu
  install/schema.sql  şema · install/migrations/NNN_*.sql
  lang/{tr,en}/*.php  çeviriler
bin/                  worker.php · cron.php · import-takeout.php
server/               Ubuntu kurulum betiği, systemd birimleri, cloudflared örneği
tests/                php tests/run.php
```

## 2. Depolama

`config('storage.path')` (web kökü dışında olmalı) altında:

| Yol | İçerik |
|---|---|
| `blobs/aa/bb/<sha1>.<uzantı>` | Orijinaller. İçerik özetine göre **tek kopya** (`blobs.ref_count`); asla değiştirilmez |
| `derived/thumb/<id[0:2]>/<asset id>.webp\|jpg` | 360 px küçük resim |
| `derived/preview/<id[0:2]>/<asset id>.webp\|jpg` | 2048 px önizleme |
| `uploads/<upload id>.part` | Yarım kalan yüklemeler (48 saat) |
| `import/` | Takeout zip'leri için varsayılan klasör |
| `imports/<id>/index.json` | İçe aktarma sırasında giriş indeksi |
| `tmp/` `logs/` `sessions/` | Geçici dosyalar, günlükler, PHP oturumları |

## 3. Veritabanı (özet — kesin hali `web/install/schema.sql`)

- `users` — email (unique), password_hash, name, is_admin, locale, timezone, quota_bytes (NULL = sınırsız), used_bytes, sync_version, status (`active`/`suspended`)
- `api_tokens` — token_hash (sha256), name, platform, last_used_at, expires_at
- `blobs` — sha1 PK, size, mime, ext, path, ref_count
- `assets` — id (UUID), user_id, blob_sha1 (user_id ile unique), type (`photo`/`video`), original_filename, mime, size, width/height (**ekranda görünen**, yön uygulanmış), duration_ms, taken_at (UTC), taken_tz_offset (dk), date_source (`takeout`/`exif`/`filename`/`file`/`user`), latitude/longitude/altitude, camera_make/model, lens, description, is_favorite, is_archived, shared_from_others, trashed_at, source (`takeout`/`ios`/`web`), source_ref (iOS localIdentifier veya Takeout yolu), live_pair_id, edited_of_id, thumb_state (`pending`/`ready`/`placeholder`/`failed`), version
- `deleted_checksums` — kalıcı silinenlerin SHA-1'i: tekrar içe aktarılmaz/yüklenmez
- `albums` (source `takeout`/`user`), `album_assets`, `album_members` (role `viewer`/`contributor`), `asset_people`
- `jobs` — iş kuyruğu (`render`, `import_step`); 3 deneme, sonra `failed`
- `imports`, `import_items` — her içe aktarma ve her medya dosyasının sonucu (`imported`/`duplicate`/`no_metadata`/`skipped_trashed`/`failed`)
- `uploads`, `site_settings`, `rate_limits`, `audit_log`

### Yineleme kuralları
1. Aynı kullanıcıda aynı SHA-1 → yinelenen.
2. SHA-1 farklı ama aynı `taken_at` (saniye) + aynı genişlik×yükseklik + aynı dosya adı (küçük harf) → yinelenen.
3. `deleted_checksums`'ta olan SHA-1 → `deleted_before` (alınmaz).
Yinelenen bir iOS yüklemesi, iOS localIdentifier'ını mevcut asset'in `source_ref`'ine bağlar.

### Tek kare kuralı (zaman çizelgesi)
- Live Photo videosu (`type=video` ve `live_pair_id` bir fotoğrafı gösteriyorsa) zaman çizelgesinde gizlenir; fotoğraf görünür.
- Düzenlenmiş kopyası olan orijinal gizlenir; düzenlenmiş kopya görünür, `edited_of_id` ile orijinale ulaşılır.

### Çekim zamanı önceliği
Takeout sidecar `photoTakenTime` > EXIF `DateTimeOriginal` (+`OffsetTimeOriginal`) / Apple `CreationDate` / QuickTime UTC > dosya adındaki tarih (yalnız tarihse yerel 12:00) > dosya zamanı. Ofset bilinmiyorsa `Europe/Istanbul` kabul edilir.

## 4. REST API (`/api/v1/...`)

- JSON gövde; hata: HTTP durum + `{"error":{"code":"...","message":"...", ...ek}}`.
- Kimlik: `Authorization: Bearer <token>` (iOS) **veya** oturum çerezi + `X-CSRF-Token` (web, GET dışı isteklerde zorunlu).
- `.htaccess`: `/api/v1/*` → `api/index.php?r=/v1/...`; mod_rewrite yoksa `api/index.php?r=/v1/...` doğrudan çalışır.
- Doğrulama hatası → 422 `validation_failed` (`field`), kota → 413 `quota_exceeded`, yok/erişim yok → 404 `not_found`, yetki → 403 `forbidden`, hız → 429 `rate_limited`.

### Asset nesnesi
```json
{"id":"uuid","type":"photo","filename":"IMG_1.HEIC","mime":"image/heic","size":2345678,
 "width":3024,"height":4032,"duration_ms":null,
 "taken_at":"2019-07-01T12:04:05Z","taken_local":"2019-07-01T15:04:05+03:00","tz_offset":180,"date_source":"takeout",
 "latitude":38.4192,"longitude":27.1287,"altitude":12.0,"camera_make":"Apple","camera_model":"iPhone 8","lens":null,
 "description":null,"is_favorite":false,"is_archived":false,"shared_from_others":false,"trashed_at":null,
 "source":"takeout","live_pair_id":null,"edited_of_id":null,"thumb_state":"ready",
 "updated_at":"2026-11-26T10:00:00Z","version":42}
```
`GET assets/{id}` ek olarak `access` (`own`/`view`), `people[]`, `albums[{id,title}]` ve (paylaşılan ise) `owner_name` döner.

### Album nesnesi
```json
{"id":"uuid","title":"Tatil 2019","description":null,"cover_asset_id":"uuid","count":42,
 "role":"owner","owner_id":1,"owner_name":"Mehmet","shared":true,"source":"takeout",
 "updated_at":"2026-11-26T10:00:00Z","version":43}
```
`GET albums/{id}` ek olarak `members[{user_id,name,email,role}]` döner.

### Uçlar

| Metot | Yol | Gövde / sorgu | Yanıt |
|---|---|---|---|
| GET | `ping` | – | `{ok,version}` |
| POST | `auth/login` | `{email,password,device_name,platform}` | `{token,user}` (10 deneme/15 dk) |
| POST | `auth/logout` | – | `{ok}` (token silinir / web oturumu kapanır) |
| GET | `me` | – | `{user}` |
| GET | `assets` | `limit≤500 (200)`, `before` (ISO), `before_id`, `favorite=1`, `archived=1`, `trashed=1`, `album=<id>`, `q`, `from`, `to`, `has_location=1`, `type=photo\|video` | `{assets:[…], next:{before,before_id}\|null}` — `taken_at DESC, id DESC`, anahtar kümesi sayfalama |
| GET | `assets/summary` | aynı süzgeçler | `{months:[{month:"2019-07",count}]}` |
| GET | `assets/map` | süzgeçler + `bbox=minLng,minLat,maxLng,maxLat` | `{points:[{id,lat,lng}]}` (≤ 5000) |
| POST | `assets/check` | `{sha1:[…≤500]}` | `{missing:[…]}` (silinmişler "var" sayılır) |
| POST | `assets/trash` · `assets/restore` · `assets/purge` | `{ids:[…≤1000]}` | `{count}` |
| GET | `assets/{id}` | – | `{asset}` |
| PATCH | `assets/{id}` | `{is_favorite?,is_archived?,description?,taken_at?,tz_offset?,latitude?,longitude?}` | `{asset}` (sahibi) |
| GET | `assets/{id}/thumb` · `preview` | – | görüntü; hazır değilse 202 + SVG yer tutucu |
| GET | `assets/{id}/original` | – | orijinal (indirme), Range |
| GET | `assets/{id}/video` | – | orijinal (satır içi), Range |
| POST | `uploads` | `{filename,size,sha1?,meta?:{taken_at,tz_offset,latitude,longitude,is_favorite,description,source_ref,live_pair_source_ref,edited_of_source_ref}}` | 201 `{id,chunk_size:52428800,received:0}` |
| PUT | `uploads/{id}?offset=N` | ham bayt (≤ 50 MB) veya `Content-Range: bytes a-b/total` | `{received}`; konum farklıysa 409 `offset_mismatch` + `received` |
| GET | `uploads/{id}` | – | `{received}` (devam için) |
| POST | `uploads/{id}/complete` | – | `{status:"created"\|"duplicate"\|"deleted_before", asset}`; özet tutmazsa 422 `checksum_mismatch`, eksikse 409 `incomplete` |
| GET | `albums` | – | `{albums:[…]}` (sahip olunan + paylaşılan) |
| POST | `albums` | `{title,description?,ids?}` | 201 `{album}` |
| GET · PATCH · DELETE | `albums/{id}` | PATCH: `{title?,description?,cover_asset_id?}` | `{album}` / `{ok}` |
| POST · DELETE | `albums/{id}/assets` | `{ids}` | `{added}` / `{removed}` |
| POST | `albums/{id}/members` | `{email,role:"viewer"\|"contributor"}` | `{member}` |
| DELETE | `albums/{id}/members/{userId}` | – | `{ok}` (sahip çıkarır veya üye ayrılır) |
| GET | `users/lookup?email=` | – | `{exists,name}` |
| POST | `devices/report` | `{device_id,name,model,os_version,app_version,pending,uploaded_total,last_backup_at,last_error}` | `{ok,server_time}` — iOS yedekleme durumu (Yönetim → Genel bakış) |

Yetki: asset uçları yalnız sahibine; paylaşılan albüm üyesi albümdeki asset'leri görüntüler/indirir (salt okunur).
Katkıda bulunan yalnız kendi asset'lerini ekler/çıkarır; sahip herkesinkini çıkarır. Yükleme kaynağı Bearer ile `ios`, oturumla `web`.

iOS `source_ref` biçimi: `<localIdentifier>` (orijinal), `<localIdentifier>#live` (Live Photo videosu,
`live_pair_source_ref` = fotoğrafınki), `<localIdentifier>#edited` (düzenlenmiş hali, `edited_of_source_ref` = orijinalinki).
Aynı `#edited` kaynağının yeni hali gelince eski düzenlenmiş kopya çöpe taşınır.

## 5. İş kuyruğu ve cron

- `render` — küçük resim + önizleme (vipsthumbnail → Imagick → GD; video için ffmpeg karesi). Araç yoksa `placeholder` ve iş `skipped`.
- `import_step` — Takeout içe aktarmasını dilim dilim ilerletir ve kendini yeniden kuyruğa koyar.
- Tüketiciler: Ubuntu'da `bin/worker.php` (systemd), hostingde `bin/cron.php` veya `web/cron.php?key=…` (dakikalık).
- Günlük bakım: çöpte 30 günü dolanlar kalıcı silinir, süresi geçmiş yüklemeler ve eski işler temizlenir.

## 6. Takeout içe aktarma

Kaynak klasördeki `*.zip` dosyaları ve açılmış klasörler. Aşamalar: `scanning` → `importing` (her medya için
`import_items` satırı; yeniden çalıştırmada atlanır) → `linking` (Live Photo ve düzenlenmiş kopya bağlantıları) → `done`.
Sidecar eşleştirme kuralları `web/includes/takeout.php` başındaki açıklamada ve testlerinde (`tests/unit/TakeoutTest.php`).
Yıl klasörleri (`Photos from 2019` vb.) ve özel klasörler (Çöp Kutusu, Arşiv) albüm değildir; diğer klasörler
`metadata.json` başlığıyla (yoksa klasör adıyla) albüm olur. Çöpteki öğeler alınmaz (`skipped_trashed`).
