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
server/               Ubuntu kurulum betiği, systemd birimleri, cloudflared örneği, install-ml.sh
ml/                   akıllı arama servisi ownmemories-ml (Python, ONNX) — bkz. §7
tests/                php tests/run.php
```

## 2. Depolama

`config('storage.path')` (web kökü dışında olmalı) altında:

| Yol | İçerik |
|---|---|
| `blobs/aa/bb/<sha1>.<uzantı>` | Orijinaller. İçerik özetine göre **tek kopya** (`blobs.ref_count`); asla değiştirilmez |
| `derived/thumb/<id[0:2]>/<asset id>.webp\|jpg` | 360 px küçük resim |
| `derived/preview/<id[0:2]>/<asset id>.webp\|jpg` | 2048 px önizleme |
| `derived/face/<yüz id[0:2]>/<yüz id>.jpg` | 256 px kare yüz kırpması (önizlemeden, ilk istekte; asset kalıcı silinince silinir) |
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
- `albums` (source `takeout`/`user`), `album_assets`, `album_members` (role `viewer`/`contributor`), `asset_people` (eski Takeout adları; 006'dan beri okunmaz/yazılmaz)
- `people` — id, user_id, name (NULL = adlandırılmamış yüz kümesi), cover_face_id, hidden, created_at, updated_at. Adlar kullanıcı başına tekildir (kodla; karşılaştırma `utf8mb4_unicode_ci`)
- `asset_person` — (asset_id, person_id) PK, source `manual`/`takeout`/`face`: "bu fotoğrafta kim var"ın tek kaynağı. Yüz ataması `face` satırı açar (manual/takeout varsa dokunmaz); kişinin o fotoğrafta yüzü kalmazsa `face` satırı silinir. Elle etiketleme mevcut satırı `manual` yapar
- `faces` — id, asset_id, user_id, person_id, rejected_person_id (kullanıcının "bu o değil" dediği kişi; kümeleme tekrar atamaz), x1/y1/x2/y2 (0..1, ekranda görünen yönlendirilmiş görüntüye göre), score, embedding (BLOB, float32 LE, 512, L2-normalize), model, assigned_by (`auto`/`user`; `user` olanları kümeleme taşımaz). ownmemories-ml yazar ve kümeler
- `face_scans` — asset_id PK, user_id, model, faces (0 dahil), scanned_at: taranan asset'ler
- Kişiler temizliği: asset kalıcı silinince yüzleri, taramaları, etiketleri ve yüz kırpmaları silinir; satırı ve yüzü kalmamış **adsız** kümeler silinir, adlı kişiler fotoğrafsız da kalır (listede görünmez). Migrasyon 006 `asset_people`'ı kullanıcı+ad başına bir kişiye ve `takeout` satırlarına taşır
- `jobs` — iş kuyruğu (`render`, `import_step`); 3 deneme, sonra `failed`
- `imports`, `import_items` — her içe aktarma ve her medya dosyasının sonucu (`imported`/`duplicate`/`no_metadata`/`skipped_trashed`/`failed`)
- `uploads`, `site_settings`, `rate_limits`, `audit_log`
- `geo_places` — GeoNames yerleşimleri (id = geonameid, name, name_tr (Türkçe ad: "Roma", "İstanbul"), feature_code, country_code, admin1/admin2 kod + ad (il/eyalet, ilçe), lat/lng, population, cell = 0,1°'lik ızgara hücresi, indeksli). `bin/geo-import.php` doldurur (§8)
- `geo_countries` — code PK, name (İngilizce), name_tr (ICU'dan Türkçe: "Fransa")
- `asset_places` — asset_id PK, user_id, place_id (en yakın yer), city_id (şehir düzeyi; Yerler sayfası ve `place=` süzgeci bunu kullanır), name/city/district (yalnız TR, ilçe)/admin1/country/country_code/region, distance_km, lat/lng (bulunurken kullanılan konum: değişince yeniden bulunur), search_key (katlanmış adlar, metin araması), geo_version. Konumu olup yakında yer olmayan asset için place_id NULL satır (işlendi)
- `asset_embeddings` — asset_id PK, user_id, model (ör. `ViT-B-16-SigLIP2__webli`), vec (MEDIUMBLOB, float32 little-endian, L2-normalize, 768 boyut = 3072 bayt), created_at. ownmemories-ml yazar; asset kalıcı silinince PHP siler (§7)
- `asset_quality` (009) — asset_id PK, user_id, phash (BIGINT UNSIGNED, 64 bit DCT algısal özet), sharpness (Laplace varyansı), brightness (0..255), color (VARBINARY(48), 4×4 ortalama RGB), is_screenshot, algo, computed_at. ownmemories-ml yazar; asset kalıcı silinince PHP siler (§8)
- `cleanup_dismissed` (009) — (user_id, kind, item_key) PK, asset_ids, created_at: Temizlik'te "Bunlar farklı" / "Bu iyi" ile gizlenen öneriler (§8)

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
 "place":{"id":311046,"name":"Alsancak","city":"İzmir","district":"Konak","admin1":"İzmir","country":"Türkiye",
          "country_code":"TR","region":null,"label":"Alsancak, Konak, İzmir, Türkiye","short":"Alsancak, Konak"},
 "updated_at":"2026-11-26T10:00:00Z","version":42}
```
`place` konum yoksa, yer verisi kurulu değilse ya da yer henüz bulunmadıysa `null`; `place.id` şehir düzeyindeki yerin kimliğidir (§8).
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
| GET | `assets` | `limit≤500 (200)`, `before` (ISO), `before_id`, `favorite=1`, `archived=1`, `trashed=1`, `album=<id>`, `person=<id>` (kendi kişisi; değilse 404), `place=<şehir id>` (§8; sayı değilse 404), `q` (dosya adı/açıklama/kamera/kişi adı/yer adı — yer adları Türkçe büyük/küçük harf ve aksan duyarsız: "kadikoy" = "KADIKÖY"), `from`, `to`, `has_location=1`, `type=photo\|video` | `{assets:[…], next:{before,before_id}\|null}` — `taken_at DESC, id DESC`, anahtar kümesi sayfalama |
| GET | `assets/summary` | aynı süzgeçler | `{months:[{month:"2019-07",count}]}` (ay, yerel çekim gününe göre) |
| GET | `assets/days` | süzgeçler + `month=YYYY-MM` (zorunlu; hatalıysa 422) | `{month, days:[{day:"2019-07-14",count,cover_asset_id,before}]}` — yerel güne göre (`taken_at + taken_tz_offset`, yoksa UTC; gün başlıklarıyla aynı), yeni gün önce; `before` o güne atlamak için `assets?before=` imleci (günün en yeni `taken_at`'i + 1 sn). Web takvimi kullanır. |
| GET | `assets/map` | süzgeçler + `bbox=minLng,minLat,maxLng,maxLat` | `{points:[{id,lat,lng,city?,place?}]}` (≤ 5000; `city`/`place` yer bulunduysa — harita ipuçları) |
| POST | `assets/check` | `{sha1:[…≤500]}` | `{missing:[…]}` (silinmişler "var" sayılır) |
| POST | `assets/trash` · `assets/restore` · `assets/purge` | `{ids:[…≤1000]}` | `{count}` |
| GET | `assets/{id}` | – | `{asset}` |
| PATCH | `assets/{id}` | `{is_favorite?,is_archived?,description?,taken_at?,tz_offset?,latitude?,longitude?}` | `{asset}` (sahibi) |
| GET | `assets/{id}/thumb` · `preview` | – | görüntü; hazır değilse 202 + SVG yer tutucu |
| GET | `assets/{id}/original` | – | orijinal (indirme), Range |
| GET | `assets/{id}/video` | – | orijinal (satır içi), Range |
| POST | `uploads` | `{filename,size,sha1?,meta?:{taken_at,tz_offset,latitude,longitude,is_favorite,description,source_ref,live_pair_source_ref,edited_of_source_ref}}` | 201 `{id,chunk_size:52428800,received:0}` |
| PUT | `uploads/{id}?offset=N[&complete=1]` | ham bayt (≤ 50 MB) veya `Content-Range: bytes a-b/total` | `{received}`; `complete=1` ve son parçaysa yükleme hemen tamamlanır: `{received, complete:{status,asset}}`; konum farklıysa 409 `offset_mismatch` + `received` |
| GET | `uploads/{id}` | – | `{received}` (devam için) |
| POST | `uploads/{id}/complete` | – | `{status:"created"\|"duplicate"\|"deleted_before", asset}`; özet tutmazsa 422 `checksum_mismatch`, eksikse 409 `incomplete` |
| GET | `albums` | – | `{albums:[…]}` (sahip olunan + paylaşılan) |
| POST | `albums` | `{title,description?,ids?}` | 201 `{album}` |
| GET · PATCH · DELETE | `albums/{id}` | PATCH: `{title?,description?,cover_asset_id?}` | `{album}` / `{ok}` |
| POST · DELETE | `albums/{id}/assets` | `{ids}` | `{added}` / `{removed}` |
| POST | `albums/{id}/members` | `{email,role:"viewer"\|"contributor"}` | `{member}` |
| DELETE | `albums/{id}/members/{userId}` | – | `{ok}` (sahip çıkarır veya üye ayrılır) |
| GET | `users/lookup?email=` | – | `{exists,name}` |
| GET | `assets/random?count=12` | – | `{assets:[…]}` — son 30 gün dışındaki rastgele fotoğraflar (widget) |
| GET | `memories?date=YYYY-MM-DD` | (varsayılan: kullanıcının bugünü) | `{date, memories:[{id,kind,title,place,years_ago,date,count,cover_asset_id,asset_ids,assets}]}` — `kind`: `on_this_day` / `this_week` (bugün yoksa ±3 gün) / `trip` (yıldönümü bugünün ±3 gününe düşen gezi; aynı yılın günlük anısının yerini alır, `end_date`, `days`) / `favorites`. Başlıklar yer adlarıyla Türkçe ekli kurulur (§8) |
| GET | `places` | `favorite=1`, `type` | `{places:[{id,name,admin1,country,country_code,region,count,cover_asset_id,lat,lng}], ready, pending}` — şehir düzeyinde yerler (zaman çizelgesi görünürlük kuralları), sayıya göre azalan; kapak: fotoğraf > favori > en yeni. `ready`: yer verisi kurulu mu; `pending`: yeri bekleyen konumlu öğe |
| GET | `places/{id}` | – | `{place:{…, first_at, last_at}}`; kullanıcının o yerde öğesi yoksa 404 |
| POST | `devices/report` | `{device_id,name,model,os_version,app_version,pending,uploaded_total,last_backup_at,last_error}` | `{ok,server_time}` — iOS yedekleme durumu (Yönetim → Genel bakış) |
| GET | `search` | `q` (≤ 200 karakter), `from`, `to` (ISO), `favorite=1`, `sort=relevance\|date` (varsayılan relevance), `limit≤200 (60)`, `offset` | `{mode:"smart"\|"text", assets:[…], next_offset:int\|null, total, status:{indexed,total,pending,model,ready}\|null}` — akıllı mod: ML hizmetinden (config `ml.url`, varsayılan `http://127.0.0.1:8765`, zaman aşımı `ml.timeout` 3 sn, isteğe bağlı `ml.min_score`) en fazla 500 sıralı kimlik alınır; zaman çizelgesi görünürlük kurallarıyla (yalnız kendi kütüphanesi, çöp/arşiv hariç, tek kare kuralı) süzülür, sıra korunur. Dosya adı/açıklama/kamera/kişi eşleşmeleri öne alınır (hibrit). Sorguda adlandırılmış (gizli olmayan) bir kişinin adı tam sözcük olarak geçerse (`Ali plajda`; büyük/küçük harf ve i/ı duyarsız, en uzun ad) o kişinin fotoğrafları en başa gelir, kendi aralarında kalan sözcüklerle (`plajda`) akıllı sırada; diğer sonuçlar ardından korunur (ör. `Deniz` adlı kişi varken `deniz kenarı` aramasında manzaralar kaybolmaz). Hizmet yok, hata ya da sonuç yoksa `assets?q=` ile aynı metin araması (`mode:"text"`, tarihe göre); hizmet yanıt verip ilgi eşiğini geçen olmadığında metin araması da boşsa `mode:"smart"`, boş liste. Boş `q` → boş liste. `status` yalnız ilk sayfada (offset=0) |
| GET | `search/status` | – | ML `/status` vekili: `{available:true,indexed,total,pending,model,ready}` ya da `{available:false}` |
| GET | `people?include_hidden=0\|1` | – | `{people:[Person]}` — önce adlılar (fotoğraf sayısına göre azalan), sonra en az 2 fotoğraflı adsız kümeler; gizliler yalnız `include_hidden=1` ile; fotoğrafsız adlılar görünmez |
| GET | `people/{id}` | – | `{person}` |
| PATCH | `people/{id}` | `{name?:string\|null, hidden?:bool, cover_face_id?:int\|null}` | `{person}`; ad başka kişinin adıysa 409 `name_exists` + `person_id` ve `person` (istemci birleştirmeyi önerir); kapak bu kişinin yüzü değilse 422 |
| POST | `people/{id}/merge` | `{into:id}` | `{person}` (hedef) — yüzler, etiketler (`manual` > `takeout` > `face`) ve retler hedefe taşınır, kaynak silinir; hedef adsızsa kaynağın adını alır |
| DELETE | `people/{id}` | – | `{ok}` — etiketleri silinir, yüzleri atanmamış + reddedilmiş olur |
| GET | `people/status` | – | ML `/faces/status` vekili: `{available:true,scanned,total,faces,people,ready}` ya da `{available:false}` |
| GET | `assets/{id}/people` | – | `{people:[{id,name\|null,source}], faces:[{id,x1,y1,x2,y2,person_id\|null,person_name\|null}]}` (yalnız sahibi; kişiler adlılar önce, yüzler soldan sağa) |
| POST | `assets/{id}/people` | `{person_id?}` veya `{name?}` (yoksa oluşturulur) | aynı yük — `manual` etiket |
| DELETE | `assets/{id}/people/{personId}` | – | aynı yük — etiket silinir; kişinin bu fotoğraftaki yüzleri ayrılır ve reddedilir |
| POST | `assets/people` | `{ids:[…≤500], person_id? \| name?}` | `{count, person}` — toplu elle etiket (yalnız kendi asset'leri sayılır; hiçbiri kendisinin değilse 404 ve kişi oluşturulmaz) |
| PATCH | `faces/{id}` | `{person_id:int}` · `{name:string}` · `{person_id:null}` | asset'in kişi yükü — atama `assigned_by='user'` (o kişiye ret varsa kalkar); `null` ayırır ve `rejected_person_id` yapar |
| GET | `cleanup` | – | `{ml:{available,indexed}, analysis:{analyzed,total}, groups:[{id,reason:"duplicate"\|"similar",keep_id,keep_reasons:[favorite\|resolution\|sharpest\|location\|original],bytes,taken_at,assets:[Asset+quality]}], blurry:[…], dark:[…+why:"dark"\|"bright"], screenshots:[…], short_videos:[…], stats:{potential_bytes,groups,group_assets,blurry,blurry_bytes,dark,dark_bytes,screenshots,screenshots_bytes,short_videos,short_videos_bytes}}` — Temizlik (§8). Listeler en çok 300 öğe (sayılar `stats`'ta tam); ML yoksa `groups` boş, listeler yine dolu |
| POST | `cleanup/resolve` | `{keep:[…], trash:[…]}` ya da `{groups:[{keep,trash},…]}` (toplam ≤ 1000) | `{trashed, freed_bytes, skipped}` — yalnız **çöpe taşır** (asla kalıcı silmez); Live Photo fotoğrafı giderse videosu da; ≥ 2 tutulan küme "farklı" olarak hatırlanır; aynı kimlik (bir grupta ya da gruplar arasında) hem keep hem trash ise 422; `keep` dolu ama hiçbiri artık yerinde değilse (eski sayfa / başka cihazda silinmiş) o parça atlanır (`skipped`), grubun son kopyaları çöpe gitmez |
| POST | `cleanup/dismiss` | `{kind:"group"\|"blurry"\|"dark"\|"screenshot"\|"video", ids:[…]}` | `{dismissed}` — "Bunlar farklı" (grup: üyeleri bu kümenin alt kümesi olan grup bir daha gelmez; yeni bir üye katılırsa yeniden görünür) / "Bu iyi" (tek öğe) |
| POST | `cleanup/reset` | – | `{restored}` — gizlenen tüm öneriler geri gelir |
| GET | `faces/{id}/thumb` | – | 256×256 JPEG yüz kırpması (kutunun uzun kenarı + her yana %35 dolgu, görüntü içinde kalır), `private, max-age=31536000, immutable`; önizleme yoksa 404 |
| GET | `admin/backup` | – (yalnızca yönetici; diğerlerine 403) | `{backup:{configured, level:"ok\|warn\|error\|unconfigured", targets:[{id,type,label,location,last_status,last_success_at,last_failure_at,snapshot_id,snapshot_count,repo_size,data_added,error,last_check_at,last_check_ok,health:{level,reason}}], next_run_at, running, requested, can_request, last_run, restore_test, schedule, retention, include_derived, setup_command}}` |
| POST | `admin/backup/run` | – (yalnızca yönetici; oturumda CSRF) | 202 `{backup}` — "Şimdi yedekle": istek dosyası yazar; zaten sıradaysa/çalışıyorsa yeni istek oluşturmaz. Kurulmamışsa 409 `backup_not_configured` |


**Sunucu yedeği:** `server/backup.sh` (root, restic) sonucu `/var/lib/ownmemories/backup-status.json` dosyasına yazar
(root:www-data 0640, gizli bilgi içermez); PHP (`web/includes/backup.php`) yalnızca okur ve sağlığı hesaplar (yeşil: son başarı
≤ 30 sa; sarı: 30–48 sa, son deneme hatalı veya denetim sorunlu; kırmızı: 48 sa'ten eski/hiç yok). "Şimdi yedekle"
`/var/lib/ownmemories/backup-spool/run-request` dosyasını yazar (root:www-data 2770); `ownmemories-backup.path` birimi
yedeği root olarak başlatır, `backup.sh` dosyayı ilk iş siler. Ayarlar ve anahtarlar yalnızca `/etc/ownmemories/backup.env`
(root 0600). Yollar `config.php` → `backup.status_file` / `backup.spool_dir` ile değiştirilebilir (geliştirme/test).
Güvenlik: `config.php` www-data tarafından yazılabildiği için root betikler onu **sahibinin yetkisiyle** (`setpriv`)
değerlendirir ve değerleri doğrular (satır sonu/denetim karakteri yok; mysql ayar dosyasına seçenek sokulamaz) — web
uygulamasını ele geçiren biri "Şimdi yedekle" ile root kodu çalıştıramaz. Panelin "çalışıyor" bilgisi PID'in komut satırında
`backup` geçtiğinde geçerlidir (yeniden başlatma sonrası PID yeniden kullanımı).

**Person nesnesi:** `{"id":12,"name":"Ayşe"|null,"count":42,"cover_face_id":881|null,"cover_asset_id":"uuid"|null,"hidden":false}` —
`count` çöpte olmayan fotoğraflar; kapak: seçilmiş `cover_face_id` (hâlâ bu kişinin yüzüyse), yoksa en yüksek puanlı yüz, yüz yoksa
en yeni fotoğraf (`cover_face_id` null). `GET assets/{id}`'deki `people[]` geriye uyumluluk için adlı kişilerin adlarıdır.
Yeniden adlandırma, birleştirme, yüz atama/ayırma, kişi silme ve yüzü olan etiket çıkarma sonrası PHP ML'e
`POST /faces/recluster {user_id}` gönderir (ateşle-unut, `ml.recluster_timeout` 0.5 sn). Tüm kişi/yüz uçları
yalnız kendi verisine açıktır; başkasının kişi, yüz veya asset kimliği 404.

Yetki: asset uçları yalnız sahibine; paylaşılan albüm üyesi albümdeki asset'leri görüntüler/indirir (salt okunur).
Katkıda bulunan yalnız kendi asset'lerini ekler/çıkarır; sahip herkesinkini çıkarır. Yükleme kaynağı Bearer ile `ios`, oturumla `web`.

iOS `source_ref` biçimi: `<localIdentifier>` (orijinal), `<localIdentifier>#live` (Live Photo videosu,
`live_pair_source_ref` = fotoğrafınki), `<localIdentifier>#edited` (düzenlenmiş hali, `edited_of_source_ref` = orijinalinki).
Aynı `#edited` kaynağının yeni hali gelince eski düzenlenmiş kopya çöpe taşınır.

## 5. İş kuyruğu ve cron

- `geocode` — tek asset'in yerini bulur (konumlu yükleme / içe aktarma ve konum değişikliği sonrası; yer verisi yoksa iş koyulmaz).
- `geocode_backfill` — eksik, konumu değişmiş ya da eski veri sürümüyle işlenmiş asset'leri id sırasıyla 400'lük gruplar halinde işler; süresi dolunca `after` imleciyle kendini yeniden kuyruğa koyar (kaldığı yerden sürer). `geo-import` veri değişince ve Yönetim → Sistem'deki düğme koyar.
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

## 7. Akıllı arama (ownmemories-ml)

Doğal dil ile fotoğraf arama ("kumsalda köpek", "doğum günü pastası", "karlı dağ"), Immich'teki gibi.
Ayrı bir Python süreci (`ml/ownmemories_ml`, systemd `ownmemories-ml`) görüntü vektörlerini üretir ve sorgu
metnini aynı uzaya kodlar; PHP yalnızca HTTP ile konuşur, ML çalışmıyorsa metin aramasına düşer.

- **Model:** Hugging Face'teki Immich ONNX dışa aktarımı, varsayılan `immich-app/ViT-B-16-SigLIP2__webli`
  (SigLIP2, çok dilli Gemma tokenizer, 768 boyut). `OM_ML_MODEL` ile değiştirilebilir; DB'deki `model` sütunu
  kısa addır. Model değişince eski satırlar yok sayılır ve dizinleyici hepsini yavaş yavaş yenisiyle değiştirir.
- **Ön işleme (Immich ile aynı):** `visual/preprocess_cfg.json` — SigLIP2: 224×224'e kırpmadan "squash"
  (bicubic), `(x/255 − 0.5)/0.5`, NCHW float32. Metin: boşluklar sadeleşir, `tokenizer_kwargs.clean=canonicalize`
  olduğu için noktalama atılır ve küçük harfe çevrilir (Türkçe `İ` → `i`), `<pad>` ile 64 token'a doldurulur /
  kırpılır (sondaki `<eos>` tokenizer ekler), girdi `text` int32 [1,64]. NLLB modellerinde metnin önüne
  `tur_Latn` eklenir (`OM_ML_LANG`). Çıktılar L2-normalize; benzerlik = iç çarpım.
- **Kaynak görüntü:** `derived/thumb` (360 px; kısa kenarı modelin girdisinden — SigLIP2 224, NLLB 384 — küçükse `derived/preview`), yoksa
  `preview`, `poster`. Videolar için de bu dosyalar videonun karesidir. `thumb_state='ready'` olan her asset
  (çöpteki/arşivdeki dahil — süzme PHP'de) dizinlenir. Dosya yoksa/okunamazsa asset geri bırakılır ve
  artan aralıklarla (5 dk … 6 sa) yeniden denenir.
- **Dizinleyici:** aynı süreçte arka plan iş parçacığı; `id` sırasıyla 512'lik dilimler, 16'lık gruplar,
  `INSERT … ON DUPLICATE KEY UPDATE`. İş parçacığı ek +5 nice ile çalışır (servis zaten Nice=10). DB
  hatalarında 5→60 sn geri çekilme. İş yokken 20 sn bekler.
- **Arama:** kullanıcı başına vektör matrisi bellekte (numpy, 30 bin fotoğraf ≈ 90 MB). Her aramada
  `COUNT(*)`/`MAX(created_at)` ile denetlenir: yeni satırlar eklenir, silme olduysa yeniden yüklenir.
  Son 256 sorgunun metin vektörü önbellekte (sayfalama ucuz).
- **Bellek:** metin modelinin token tablosu (256k×768) kurulumda satır başına ölçekli int8'e çevrilir
  (`textual/model.int8.onnx`, `python -m ownmemories_ml quantize`). MatMul'lar bilerek float32 kalır:
  onnxruntime `quantize_dynamic` ile tüm model int8 yapıldığında Türkçe isabet 30/32'den 24–25/32'ye düştü.

| Ölçüm (4 CPU, onnxruntime 1.25, ViT-B-16-SigLIP2) | Değer |
|---|---|
| Görsel kodlama (4 iş parçacığı) | ~120 ms/görüntü (~30 bin/saat); 2 iş parçacığı ~190 ms; 1 → ~330 ms |
| Görüntü çözme (360 px küçük resim) | ~9 ms |
| Metin sorgusu (4 / 2 / 1 iş parçacığı) | ~45 / 70 / 135 ms; önbellekten <1 ms |
| Puanlama + ilk 500 (30 bin / 100 bin vektör) | 1.6 / 7.4 ms |
| Süreç RSS: görsel + int8 metin | ~1.2–1.3 GB (fp32 metin modeliyle ~1.85 GB, tepe 2.4 GB) |
| int8 dönüşümü (kurulumda bir kez) | ~17 sn, tepe ~3.4 GB RAM; fp32 ile vektör kosinüsü min 0.9956 |
| Örnek 18 fotoğraf / 32 TR+EN sorgu, ilk 3 isabet | fp32 30/32, int8 30/32 |

**Varsayılan ilgi eşiği:** istekte `min_score` yoksa (PHP varsayılanı) servis, sorgunun o kullanıcının tüm
vektörlerine karşı puan dağılımına bakar ve yığından sıyrılanları tutar: `z = (puan − ort) / std ≥ OM_ML_MIN_Z`
**veya** `puan ≥ OM_ML_MIN_SCORE` (modele özgü "kesin ilgili" puan; sorguyla eşleşen fotoğraflar kütüphanenin
büyük kısmı olduğunda z düşük kalır diye). Varsayılanlar `config.CUTOFF`: SigLIP2 (z 2.0, puan 0.10), NLLB
(z 2.0, puan 0.07), bilinmeyen model (z 2.0, puan yok). 50 vektörden küçük kütüphanelerde eşik uygulanmaz.
Hiçbir şey geçmezse sonuç boştur ve PHP metin aramasına düşer ("köpek" köpeksiz kütüphanede sonuç vermez).
İstek `min_score` gönderirse yalnızca o mutlak eşik uygulanır (PHP `ml.min_score`). Ölçüm (XM3600, 1000
görüntü, 31 kısa Türkçe sorgu): SigLIP2'de ortalama 36 sonuç (kütüphanenin %3.6'sı), kesinlik 0.35, duyarlılık
0.65; tek bir doğru görseli olan Türkçe açıklamalarda doğru görselin %97'si eşiği geçer.

### Model karşılaştırması (Türkçe)

`ml/tests/xm3600_bench.py` ile Crossmodal-3600'ün ilk 1000 görüntüsünde (kısa kenarı 360 px küçük resimler,
uygulamadaki gibi) ölçüldü; metin modelleri int8 token tablolu. "Anahtar sorgu" = 31 kısa Türkçe sorgu
(köpek, kumsal, karlı dağ, doğum günü pastası…), doğruluk etiketi İngilizce açıklamalardan.

| Model | TR açıklama R@1 / R@5 | EN açıklama R@1 | TR anahtar sorgu P@10 / mAP | Görsel kodlama (4 çekirdek) | RSS |
|---|---|---|---|---|---|
| **ViT-B-16-SigLIP2__webli** (varsayılan) | 60.8 / 85.0 | 75.5 | 0.57 / 0.53 | ~120 ms | ~1.2 GB |
| nllb-clip-base-siglip__v1 | 71.7 / 92.2 | 68.4 | 0.56 / 0.50 | ~350 ms | ~1.8 GB |
| XLM-Roberta-Base-ViT-B-32__laion5b_s13b_b90k | 63.5 / 87.3 | 68.2 | 0.54 / 0.48 | ~100 ms | ~2.0 GB (fp32 metin) |

Kullanıcıların yazdığı kısa sorgularda SigLIP2 ile NLLB başa baş (SigLIP2 "insan kalabalığı", "kilise",
"pasta"=kek'te zayıf; NLLB "at", "çocuk", "uçak"ta zayıf), NLLB uzun cümlelerde belirgin önde ama 3 kat yavaş
dizinler, ~0.6 GB fazla RAM ister ve İngilizcede geride. Bu yüzden varsayılan SigLIP2; uzun cümlelerle arayan
ve RAM'i bol (≥ 6 GB) sunucular `OM_ML_MODEL=immich-app/nllb-clip-base-siglip__v1` seçebilir. Denenen ve
alınmayan değişiklikler: sorgu başına boşluk (Gemma tokenizer ilk kelimeye `▁` koymaz) ve "bir fotoğraf: "
şablonu kısa sorgularda +2–4 isabet verdi ama açıklama ölçümünde fark yaratmadı/NLLB'de kötüleştirdi; Immich ile
birebir aynı ön işleme korundu.

### HTTP uçları (yalnızca `127.0.0.1:8765`, JSON)

| Yöntem | Yol | Gövde / sorgu | Yanıt |
|---|---|---|---|
| GET | `/health` | – | `{ok:true, model, dim:768, indexed, pending, ready, indexer[, error]}` — `ready`: metin modeli yüklü ve DB erişilebilir; `indexed`/`pending` tüm kullanıcılar (5 sn önbellek) |
| POST | `/search` | `{user_id, text, limit≤500 (100), min_score:float\|null}` | `{results:[{id, score}], model, took_ms}` — skora göre azalan. Model yüklenmeden 503, hatalı istek 400 |
| GET | `/status?user_id=N` | – | `{indexed, total, pending, model, ready}` — `total`: kullanıcının `thumb_state='ready'` asset sayısı; `ready`: model hazır ve en az bir vektör var |
| GET | `/faces/status?user_id=N` | – | `{available, model, ready, scanned, total, faces, people[, error]}` — `total`: `thumb_state='ready'`, çöpte olmayan, canlı fotoğrafın video yarısı olmayan asset; `scanned`: bunlardan `face_scans` satırı olan (bu yüz modeliyle); `people`: gizli olmayan kişiler; `available`/`ready`: yüz modeli yüklü (ve tablolar var). 3 sn önbellek |
| POST | `/cleanup/groups` | `{user_id}` | `{groups:[{assets:[id…],reason:"duplicate"\|"similar",min_sim,max_hamming}], analyzed, total, indexed, took_ms, cached}` — benzer gruplar (§8); kütüphane değişmedikçe bellekten. `asset_quality` yoksa 503 |
| POST | `/faces/recluster` | `{user_id}` | `{queued:true, available:true}` — kullanıcı için tam kümeleme geçişini kuyruğa alır (dizinleyici iş parçacığında, birkaç saniye içinde). Yüz modeli yoksa `{queued:false, available:false}` |

`/health` ayrıca `faces: bool` (yüz modeli yüklü mü) ve `cleanup: bool` (`asset_quality` tablosu var mı) döndürür.

### Yüz tanıma (Kişiler)

Aynı süreç, aynı arka plan döngüsünde (`service.py` `index_loop`) yüz algılama + tanıma + kümeleme yapar
(`faces.py`, `clustering.py`). Model: Immich'in `immich-app/buffalo_l` ONNX paketi (InsightFace SCRFD
`det_10g` algılayıcı + ArcFace `w600k_r50` tanıyıcı, `<OM_ML_MODEL_DIR>/buffalo_l/{detection,recognition}/model.onnx`,
~190 MB). **Lisans: InsightFace buffalo_l ağırlıkları yalnızca ticari olmayan araştırma/kişisel kullanım
içindir** (kod Apache-2.0/MIT, ağırlıklar değil); OwnMemories kişisel, kendi sunucunuzda çalışan bir uygulama
olarak bu koşula uyar, ticari kullanımda `OM_FACE_MODEL=0` yapın.

- **Ön işleme Immich ile birebir** (`immich_ml/models/facial_recognition/{detection,recognition,_ops}.py`): kaynak
  türetilmiş önizleme (yoksa video kapağı, yoksa küçük resim; zaten EXIF'e göre döndürülmüş), RGB; uzun kenar 640
  olacak şekilde ölçeklenip 640×640 tuvalin sol üstüne (letterbox), `(x−127.5)/128`; SCRFD çözümü (adım 8/16/32,
  hücre başına 2 çapa, kutu = merkez ∓ uzaklık·adım, 5 nokta = merkez + ofset·adım), skor ≥ `OM_FACE_MIN_SCORE`
  (0.7), NMS IoU 0.4. Her yüz 5 noktadan standart ArcFace şablonuna Umeyama benzerlik dönüşümüyle 112×112
  hizalanır, `(x−127.5)/127.5`, 512 boyutlu vektör L2-normalize edilir. OpenCV yerine numpy + Pillow: NMS açgözlü
  numpy, `warpAffine` PIL affine ile (OpenCV piksel merkezi kuralına göre düzeltilmiş). Immich'in cv2 tabanlı
  koduyla karşılaştırma (LFW, 40 görüntü): kutular ≤ 1 px, hizalanmış kesit ortalama 0.49/255 fark, vektör kosinüsü
  ≥ 0.998 (ortalama 0.9997). Tek fark: letterbox ölçeklemesi PIL bilinear (kenar yumuşatmalı), cv2 `INTER_LINEAR` değil.
- En kısa kenarı `OM_FACE_MIN_SIZE` (32 px, kaynak görüntüde) altındaki yüzler atılır (kalabalıktaki minik yüzler).
- Yazım (tek işlem): `faces` satırları (kutu 0..1, görüntülenen görüntüye göre) + `face_scans` (yüz yoksa da,
  `faces=0`). Varlık arada silinmişse hiçbir şey yazılmaz (`INSERT … SELECT … FROM assets`). Dosya yoksa ya da
  okunamıyorsa üstel geri çekilmeyle ertelenir (CLIP gibi). Çöpteki varlıklar ve canlı fotoğrafların video yarısı
  taranmaz.
- **Kümeleme** (Immich `recognizeFaces`, `person.service.ts`): kullanıcı başına bellekte tutulan vektörlerle
  kosinüs uzaklığı. Her atanmamış yüz için eşleşmeler = uzaklığı < `OM_FACE_MAX_DISTANCE` (0.5) en yakın
  `OM_FACE_MIN_FACES` (3) yüz (kendisi dahil); yalnız kendisiyle eşleşen atlanır; eşleşme sayısı ≥ min_faces
  ise "çekirdek", değilse geçişin sonuna ertelenir. Kişi = eşleşmelerdeki kişilerin en sık olanı (eşitlikte en
  yakın), yoksa eşik içindeki en yakın kişili yüz; yüzün `rejected_person_id`'si asla seçilmez. Kişi yoksa ve yüz
  çekirdekse ve eşleşmelerin ≥ min_faces'i atanmamışsa adsız kişi (`people.name` NULL, kapak = en yüksek skorlu
  yüz) oluşturulup hepsi atanır. Atama koşulludur (`UPDATE faces JOIN people … WHERE person_id IS NULL AND NOT
  rejected_person_id <=> p.id`): PHP aynı anda birleştirme/silme yaparsa yarış zararsızdır. Atanan yüz için
  `asset_person`'a `INSERT IGNORE … source='face'` (elle/takeout satırı varsa dokunulmaz). `assigned_by='user'`
  ya da zaten kişisi olan yüzler hiç taşınmaz. Geçiş sonunda kapağı olmayan / kapağı artık kendisine ait olmayan
  kişilere en iyi yüzleri kapak yapılır.
- Ne zaman: her yüz toplu işinden sonra **artımlı** (yeni yüzler + eşik içindeki atanmamış komşuları); tarama
  kuyruğu boşalınca yeni yüzü olan kullanıcılar ve açılışta bir kez atanmamış yüzü olan herkes için **tam geçiş**;
  ayrıca `POST /faces/recluster` (PHP, adlandırma/birleştirme sonrası). Tam geçiş büyük kütüphanede saniyeler
  sürer (50 bin yüz, 20 bin atanmamış: ~5 sn), bu yüzden istek kaynaklı tam geçiş kullanıcı başına en çok 30 sn'de
  bir (arada gelen istekler birleşir), kuyruk boşalması kaynaklı en çok 30 dk'da bir. Immich'in "force" yeniden
  kümelemesi (tüm otomatik atamaları sıfırlama) yok.
- **CLIP'i aç bırakmamak:** her turda bir CLIP toplu işi (16) + `OM_FACE_BATCH` (4) görüntülük yüz işi; CLIP
  kuyruğu boşsa yüz işi 16'ya çıkar. Aynı düşük öncelikli iş parçacığı (nice +5, systemd Nice=10), aynı ONNX iş
  parçacığı sayısı; ayrı iş parçacığı yok (CPU aşırı yüklenmez). Ölçüm (4 CPU): SigLIP2 141 ms/görüntü, yüz
  algılama 114 ms/görüntü + tanıma ~100 ms/yüz → dizinleme sırasında CLIP hızı 7.1 → ~5.9 görüntü/sn.
- Model ya da tablolar (migration 006) yoksa yüz kısmı sessizce kapalıdır (tablolar 5 dk'da bir yeniden
  denetlenir); CLIP etkilenmez. CLIP modeli yüklenemezse yüz kısmı yine çalışır.

**Ölçümler** (`ml/tests/face_eval.py`, LFW, 4 CPU, Python 3.13, onnxruntime 1.25):

| Ölçüm | Sonuç |
|---|---|
| Aynı kişi çiftleri (40 kişi × 8 foto, 1113 çift) | uzaklık < 0.5: %93.4 (medyan 0.31, < 0.6: %98.7) |
| Farklı kişi çiftleri (49 608) | uzaklık > 0.5: %100 (en küçük 0.70, medyan 0.99) |
| Kümeleme, 10 kişi × 4–8 foto (65 yüz) | 10 küme, saflık %100, 64 kimlik yüzünden 63'ü kendi kümesinde (1 atanmamış), bölünme yok |
| Kümeleme, 30 kişi × 4–15 foto + 150 çeldirici kişi (1–2 foto) + 49 arka plan yüzü (515 yüz), rastgele sırayla artımlı geliş | 30 küme, saflık %100, kimlik yüzlerinin %99.5'i kendi kümesinde (2 atanmamış), bölünme yok, çeldirici/arka plan yüzlerinden hiçbiri kümelenmedi (min_faces 2'de çeldiricilerin 2 fotoğraflık çiftleri de — doğru olarak — küme olur). Tek tam geçişle aynı sonuç |
| Gecikme | 1 yüzlü görüntü ~0.27–0.3 sn; yüzsüz ~0.11 sn (algılama); her ek yüz ~0.08–0.1 sn (25 yüzlü 1440×960: 2.1 sn, algılama 0.16 sn) |
| RAM | model yükü +367 MB RSS, çıkarım sonrası +377 MB, tepe ~560 MB (süreç toplamı, CLIP'siz) |
| Kümeleme süresi | 515 yüz tam geçiş 3–24 ms (numpy, bellekte; benzerlik matrisi ~32 MB'lık parçalarla) |

Not: LFW yüzleri 250×250 kırpılmış ünlü fotoğraflarıdır; gerçek aile albümlerinde (yaş farkı, profil, düşük ışık)
aynı kişinin bazı yüzleri ayrı kümelere düşebilir — Kişiler sayfasındaki birleştirme bunun için.

### Yapılandırma (`/etc/ownmemories/ml.env`, 0640 root:www-data)

`server/install-ml.sh` yazar: `OM_DB_HOST/PORT/NAME/USER/PASS`, `OM_STORAGE` (web/config/config.php'den),
`OM_ML_MODEL`, `OM_ML_MODEL_DIR` (`/var/lib/ownmemories/models`), `OM_ML_PORT` (8765), `OM_ML_THREADS`
(min(4, CPU)). İsteğe bağlı: `OM_ML_BATCH` (16), `OM_ML_IDLE` (20), `OM_ML_TEXT_QUANT` (1), `OM_ML_INDEX` (1),
`OM_ML_LANG` (tr), `OM_ML_MIN_Z` / `OM_ML_MIN_SCORE` (varsayılan ilgi eşiği, yukarıda). Yüz tanıma:
`OM_FACE_MODEL` (`immich-app/buffalo_l`; `0` = kapalı, kurulum ~2.6 GB'tan az RAM'de `0` yazar),
`OM_FACE_MIN_SCORE` (0.7), `OM_FACE_MIN_SIZE` (32), `OM_FACE_MAX_DISTANCE` (0.5), `OM_FACE_MIN_FACES` (3),
`OM_FACE_BATCH` (4). Kurulum betiği kendi yazmadığı `OM_ML_*` / `OM_FACE_*` satırlarını korur. DB/depolama değişkenleri yoksa servis `OM_CONFIG_PHP`'yi (`php -r`) okur. `localhost`
verilirse PHP gibi MySQL Unix soketi kullanılır.

### Dosyalar

`ml/ownmemories_ml/`: `service.py` (HTTP + dizinleyici + bellek içi dizin), `model.py` (ön işleme, ONNX),
`faces.py` (yüz algılama/hizalama/tanıma), `clustering.py` (yüz kümeleme), `cleanup.py` (Temizlik: kalite ölçümleri, benzer gruplar),
`download.py` (HF indirici: sürdürme, boyut + sha256), `quantize.py`, `config.py`, `__main__.py`
(`serve` / `download` / `quantize` / `embed-text`). Testler: `ml/tests/eval_samples.py` (kalite, gecikme, RAM),
`ml/tests/test_service.py` (geçici DB ile uçtan uca), `ml/tests/test_cutoff.py` (ilgi eşiği, DB/model gerekmez),
`ml/tests/xm3600_bench.py` (Türkçe kalite karşılaştırması, yukarıda), `ml/tests/test_faces.py` (yüz tanıma,
geçici DB + LFW ile uçtan uca), `ml/tests/face_eval.py` (LFW uzaklık/kümeleme/gecikme/RAM ölçümü),
`ml/tests/test_face_ops.py` (NMS, Umeyama, hizalama, kümeleme birim testleri; model/DB gerekmez).

## 8. Yerler (çevrimdışı ters coğrafi kodlama)

Fotoğraf konumları **hiçbir dış servise gönderilmeden** yer adlarına çevrilir. Veri: [GeoNames](https://www.geonames.org)
(Creative Commons Attribution 4.0 — atıf web'de Yerler sayfasının altında, iOS'ta Yerler listesinin altında ve Yönetim → Sistem'de).

- **İndirme** (`server/install-geo.sh`, `update.sh` ve `install-ubuntu.sh` çağırır): `/var/lib/ownmemories/geo` içine
  `cities500.zip` (dünya, nüfusu 500+ yerleşimler), `TR.zip` (Türkiye'nin tüm yerleşimleri ve mahalleleri; il/ilçe adları
  Türkçe harfli), `admin1CodesASCII.txt`, `admin2Codes.txt`, `countryInfo.txt` — ~30 MB indirme. 30 günden yeni dosyalar
  yeniden indirilmez (`--force`). İndirme başarısızsa eldeki kopya kullanılır; ilk kurulumda başarısızsa güncelleme yine biter.
- **İçe aktarma** (`bin/geo-import.php` → `geo_import()`): dosya özetinden bir sürüm (`site_settings.geo_version`) üretir;
  değişmediyse atlar. `geo_places_new`'e 500'lük toplu INSERT ile yükler, `RENAME TABLE` ile atomik değiştirir
  (~290 bin yer, ~46 MB, ~7 sn, 128 MB PHP belleği yeter). Tarihî/terk edilmiş/dinî (PPLH, PPLQ, PPLW, PPLCH) ve adında rakam
  olan yerler ("Paris 01 Louvre") alınmaz. Türkçe adlar: Türkiye'deki yerlerde `alternatenames` içinden aynı yazılışın Türkçe
  harfli biçimi ("Istanbul" → "İstanbul"); yabancı şehirlerde `web/includes/places_data.php` tablosu (Rome → Roma, Athens → Atina…);
  ülkelerde ICU (`Locale::getDisplayRegion(…, 'tr')`, yoksa tablo). Veri değiştiyse `geocode_backfill` kuyruğa girer.
- **Arama** (`geo_lookup`): noktanın 0,1°'lik hücresi ve ±2 komşu hücredeki yerler (yoksa ±6) tek indeksli sorguyla gelir,
  uzaklık PHP'de (haversine) hesaplanır (~1,5 ms). 50 km'de yer yoksa `null`.
  - `name`: en yakın yer 3 km içindeyse o (mahalle/köy: "Moda", "Alsancak"), değilse şehir.
  - `city`: mahalle (PPLX) olmayan yerler arasında `uzaklık / nüfus^¼` en küçük olan; seçilen il merkezi değilse ve 12 km
    (nüfus ≥ 1 M) / 6 km (≥ 250 bin) içinde daha kalabalık bir il merkezi/başkent varsa o (Konak → İzmir, Üsküdar → İstanbul);
    il merkezi olmayan ama en az 5 kat kalabalık (≥ 100 bin; 3 km) bir yerleşim de şehri kapsar (Libération semti → Nice).
  - `district`: yalnız Türkiye'de ilçe; `admin1`: il / eyalet; `region`: `places_data.php` bölgeleri (Kapadokya, 16 km).
- **Biçim**: `label` = ad, ilçe, şehir, il, ülke (tekrarsız; boşluk farkı yok sayılır): "Moda, Kadıköy, İstanbul, Türkiye".
  Türkiye'de ilçe biliniyorsa "şehir" (yakındaki büyük yerleşim, başka ilçede olabilir) etikete girmez: "Çavuşin, Avanos, Nevşehir";
  yer il merkezinin kendisiyse ilçe önce: "Osmangazi, Bursa". `short` (gün başlığı) = ilk iki parça, Türkiye dışında ülkeyle:
  "Moda, Kadıköy" · "Bozcaada, Çanakkale" · "Paris, Fransa". Konum, yer bulunduktan sonra değiştiyse (iş bekliyor) `place` null.
- **Arama anahtarı**: tüm adlar (Türkçe + yerel + İngilizce ülke adı + bölge) `places_fold()` ile katlanır ("Kadıköy" → "kadikoy");
  `q` de katlanıp kelime başı `LIKE` ile eşleşir.
- **Anılar**: Türkçe bulunma eki `tr_locative()` (ünlü uyumu, sert ünsüzden sonra -ta/-te, kesme işareti; okunuşu farklı adlar
  tablodan: "Nice'te"). Ev = en çok farklı ayda (≥ 3) fotoğraf çekilen şehir. Ev dışında (40 km+) geçen ardışık günler (en çok 1 boş
  gün) gezidir (≥ 3 öğe); baskın yer en az %60 ise o yer (bölge önce), değilse tek ülkedeyse ülke. Başlıklar: 1 gün "Bozcaada'da bir
  gün", hafta sonunu içeren 2–3 gün "Paris'te bir hafta sonu", 2–5 gün "Roma'da 4 gün", 6–9 gün "Bozcaada'da bir hafta",
  10+ gün ya da ülke "Kapadokya, Kasım 2021". Günlük anılar evden uzaktaysa: "2 yıl önce bugün Roma'da".
`ml/tests/test_face_ops.py` (NMS, Umeyama, hizalama, kümeleme birim testleri; model/DB gerekmez),
`ml/tests/test_cleanup.py` (Temizlik: pHash/netlik/renk imzası/ekran görüntüsü kuralı/gruplama; model/DB gerekmez).

## 8. Temizlik (benzer ve gereksiz fotoğraflar)

Birebir aynı dosyalar yüklemede SHA-1 ile zaten engellenir (blob tekilleştirme). Temizlik **yakın kopyaları**
(seri çekimler, yeniden kaydedilmiş/küçültülmüş/WhatsApp'tan geçmiş kopyalar, düzenlenmiş kopyalar) ve gereksiz
kareleri (bulanık, çok karanlık/aşırı parlak, ekran görüntüsü, 1 sn'den kısa video, cepte kalmış simsiyah video)
bulur. Web: `#/cleanup` (menü → Diğer → Temizlik; Ayarlar'dan da). iOS: Ayarlar → Kütüphane → Temizlik.
**Hiçbir şey kalıcı silinmez**: uygulama seçilenleri Çöp kutusuna taşır (30 gün sonra cron siler).

**Ölçümler** (`ml/ownmemories_ml/cleanup.py`, dizinleyicinin her turunda, türetilmiş önizlemeden —
yoksa video kapağı/küçük resim; 2048 px WebP önizlemede ~100 ms, JPEG ~50 ms; CLIP işi sürerken de tur başına 32 görüntü):
`phash` (32×32 gri → DCT → sol üst 8×8, ortancaya göre 64 bit), `sharpness` (gri görüntü ≤ 1024 px uzun kenar,
Laplace, 4×4 karoların en netlerinden ikincisinin varyansı — arka planı bulanık portre "bulanık" sayılmaz),
`brightness` (ortalama), `color` (4×4 RGB), `is_screenshot` (dosya adı `Screenshot`/`Ekran Resmi`/`Ekran
görüntüsü`…, kamera bilgisi olmayan PNG ya da bilinen iPhone/iPad/Android ekran boyutu; PHP `cleanup_is_screenshot`
aynı kural). Yöntem değişirse `ALGO` artırılır ve eski satırlar yeniden hesaplanır.

**Gruplama** (`group_assets`, ML `POST /cleanup/groups`, numpy; kullanıcının çöpte/arşivde olmayan, tek kare
kuralına uyan, ≥ 64 px fotoğrafları; CLIP vektörleri aramanın bellek içi dizininden):
1. *Seri çekim*: çekim zamanı **güvenilir** (date_source exif/takeout/filename/user; `file` toplu kopyalamada hepsi
   aynı olabilir) iki fotoğraf ≤ 10 sn arayla ve kosinüs ≥ 0.93, ya da ≤ 120 sn arayla ve ≥ 0.955.
2. *Görsel kopya* (zamandan bağımsız): pHash Hamming ≤ 8 ve kosinüs ≥ 0.86 (aday çiftler 4×16 bitlik bantlarla).
3. *Küresel*: kosinüs ≥ 0.98, yalnızca çekim zamanı güvenilmez fotoğraflardan (EXIF'i silinmiş kopyalar) tüm
   kütüphaneye (bloklu matris çarpımı; m·n > 40 bin² ise atlanır).
2 ve 3'te renk imzası farkı ≤ 16 ve en-boy oranı farkı ≤ 1.25 kat olmalı (aynı düzende ama farklı renkteki kareler,
düşük dokulu gökyüzü/deniz fotoğrafları birleşmesin). **Yazı ağırlıklı kareler** (belge sayfası, slayt, fiş,
ekran görüntüsü) için üç yolda da yalnız neredeyse birebir kopya kenarı kurulur (Hamming ≤ 6 ve kosinüs ≥ 0.975):
farklı belge sayfaları CLIP'te 0.89–0.96 benzer ve saniyeler arayla çekilir; bu kural olmadan 6 farklı sayfa tek
"benzer" grupta toplanıyor, "Önerileri uygula" beşini çöpe atıyordu. Yazı ağırlığı = `asset_quality.is_screenshot`
ya da CLIP metin istemi ("a page of printed text") benzerliği ≥ 0.06 ve "a photo" isteminden ≥ 0.005 yüksek
(SigLIP2'de belgeler ≥ 0.09 / +0.027, fotoğraflar ≤ 0.05 / −0.017); metin modeli yoksa yalnız ekran görüntüsü kuralı.
Kenarlar güçlüden zayıfa birleştirilir; iki küme ancak tüm
çiftleri kosinüs ≥ 0.84 ise ve toplam ≤ 24 ise birleşir (zincirleme engeli). Neden: tüm çiftler Hamming ≤ 6,
kosinüs ≥ 0.88, netlik oranı ≤ 3 ve parlaklık farkı ≤ 25 ise `duplicate` ("Neredeyse aynı"), değilse `similar`.
Sonuç `(vektör sayısı/son, kalite sayısı/son, asset sayısı/en büyük version)` parmak iziyle kullanıcı başına
bellekte tutulur (çöpe taşıma/geri yükleme version'ı artırır); Temizlik'i açmış kullanıcılar için dizinleyici
boştayken (en çok dakikada bir) değişmişse yeniden hesaplanır. Süre: 270 fotoğraf ~20–60 ms, 30 bin ~2 sn,
100 bin (%10'u dosya tarihli) ~7 sn (4 CPU).

**En iyisi** (PHP `cleanup_pick_best`): favori (+1000) > çözünürlük (100 × piksel/en büyük) > netlik (60 ×
oran) > konum (+12) > kamera bilgisi (+8, EXIF'i silinmiş kopyalara karşı) > dosya boyutu (10 × oran, az
sıkıştırılmış); `edited_of_id` olan ya da adı kopya gibi olan (`(1)`, `-edited`, `kopya`) −25. Gerekçeler
(`keep_reasons`) arayüzde "En iyisi: En yüksek çözünürlük · En net" olarak gösterilir.

**Listeler** (PHP, `asset_quality` + meta veri; favoriler ve bir gruptakiler hariç, öncelik: grup > ekran
görüntüsü > karanlık/parlak > bulanık): bulanık `sharpness < 25` (kısa kenar ≥ 256 px), karanlık `brightness < 22`,
aşırı parlak `> 236`, kısa video `duration_ms < 1000` (Live Photo videosu hariç), cepte kayıt: kapak karesi
`brightness < 22` olan video.

**Doğruluk** (gerçek fotoğraflarla, `p1…p18` + üretilmiş kopyalar, 86 dosya yükleme API'siyle): 18 grup,
yanlış birleştirme 0 (benzer ama farklı fotoğraflar 6–30 sn arayla çekilmiş olsa da — orman yolu ×2, sisli deniz ×2,
iskele ×2, masa ×2 — ayrı kaldı; LFW'de aynı kişinin farklı fotoğrafları da gruplanmadı), aynı fotoğrafın kopya
çiftlerinin %92'si doğru grupta (kaçanlar: %10 kırpılıp renkleri değiştirilmiş düzenleme, 3.5× aşırı pozlanmış ya
da %7'ye karartılmış kopya — ikisi "karanlık/parlak" listesine düşer). Bulanık kopyalar (σ ≥ 2 Gauss) netliği
1–25, net fotoğraflar ≥ 80.


## 9. Fotoğraftaki yazı (OCR)

**Motor:** PaddleOCR PP-OCRv6 çok dilli modelleri (RapidOCR'ın ONNX dışa aktarımı, Apache-2.0): "tiny" algılama
(1.8 MB) + "small" tanıma (21 MB, 18.7 bin karakter; Türkçe harfler dahil). `ml/ownmemories_ml/ocr.py` yalnız
onnxruntime + numpy + Pillow kullanır: DB son işlemesi (eşik 0.3 → 2×2 genişletme → 8-komşu bağlı bileşenler (satır
koşuları + birleşim-bul) → dışbükey zarf + en küçük döndürülmüş dikdörtgen → kutu puanı ≥ 0.5 → genişletme 1.6) ve
dörtgen kırpma (PIL `QUAD`) numpy ile yazıldı; `rapidocr` paketi (OpenCV 170 MB + shapely + pyclipper + omegaconf)
gerekmez. Tanıma CTC; kod çözme Türkçe/İngilizce/Batı Avrupa karakter kümesiyle sınırlı, sık karışmalar Türkçeye
çevrilir (Č/Ć → Ğ, Í → İ, Õ → Ö, Š/Ș → Ş, "$AİR" → "ŞAİR", büyük harfli sözcükte 1/0 → I/O, iSTANBUL → İSTANBUL).
Gürültü süzgeci: satır güveni < 0.80, harf/rakamsız ya da tek harfli satırlar atılır; toplam < 3 harf/rakam ya da
harfsiz < 6 rakamlık sonuçlar "yazı yok" sayılır.

**Seçim ölçümü** (`ml/tests/ocr_bench.py`; sentetik küme `ocr_synth.py`: 41 tabela/fiş/belge/menü/ekran görüntüsü,
döndürme/perspektif/bulanıklık/gürültü; gerçek küme `ocr_commons.py`: Wikimedia Commons'tan 23 Türkçe tabela/eczane/
dükkân fotoğrafı; sözcük bulma oranı = doğru metindeki sözcüklerin katlanmış OCR çıktısında bulunma oranı):

| Motor | sentetik | gerçek | CPU / yazılı görüntü | yazısız 172 fotoğrafta sanrı |
|---|---|---|---|---|
| **PP-OCRv6 tiny det + small rec, 1280 px (seçilen)** | **%99.7** | **%88** | ~0.7–1.0 sn | 1 ("1000"; artık süzülüyor) |
| PP-OCRv6 small det + small rec, 960 px | %99.7 | %84 | ~1.6 sn | 0 |
| RapidOCR 3.10 paketi (aynı modeller + açı sınıflandırıcı) | %98.8 | %82 | ~3.6–5.5 sn | – |
| Tesseract 5.3 tur+eng, psm 3 | %66 | %11 | ~0.5 sn | – |
| Tesseract 5.3 tur+eng, psm 11 | %69 | %34 | ~0.8–11 sn | – |

Tesseract temiz belgede harf doğruluğunda iyi (İ/ş/ğ tam) ama döndürülmüş/fotoğraf yazısında ve tabelalarda zayıf;
PP-OCR her türde güçlü. Python 3.13 ve 3.14'te sonuçlar birebir aynı.

**Dizinleyici** (`service.py` `_ocr_step`): CLIP kuyruğu boşken tur başına `OM_OCR_BATCH` (8) fotoğraf
(`type='photo'`, küçük resmi hazır, çöpte değil; `asset_text` satırı olmayan ya da `engine` farklı olan); kaynak
türetilmiş önizleme (2048 px; yoksa video kapağı/küçük resim). Algılama uzun kenarı 1280 px'e ölçekli görüntüde,
tanıma tam çözünürlükten kırpılan satırlarda. Yazı yoksa da satır yazılır (`words=0`: tarandı). Ölçülen hız (4 iş
parçacığı, 2048 px önizleme): yazısız ~0.1–0.15 sn, yazılı ~0.2–0.5 sn; 227 fotoğraflık geliştirme kütüphanesi 38 sn.

**Tablo** `asset_text` (migration 011): `asset_id` PK, `user_id`, `text` (satırlar `\n` ile), `folded` (ascii_bin;
`" elektrik faturasi "`), `lang` (tr/en tahmini), `words`, `engine` (`ppocr-v6/1`), `computed_at`; `FULLTEXT(folded)`.
Katlama: Türkçe küçük harf (İ→i, I→ı) + aksan atma + yalnız `[a-z0-9]` sözcükler; PHP `ocr_fold()` (= `places_fold`)
ve Python `ocr.fold()` ortak örneklerle sınanır (`tests/fixtures/ocr/fold.json`).

**Arama** (`web/includes/ocr.php`, `GET /v1/search` içinde): sorgu sözcükleri katlanır; tüm sözcükler eşleşmeli.
Puan: 3 tam sözcük, 2 sözcük başı (Türkçe ekler: "fatura" ⊂ "faturası"), 1 sözcük içi (≥ 4 harf: "köfte" ⊂
"çiğköfte"); iki harfli sözcükler yalnız tam sözcük. Adaylar önce FULLTEXT'ten (`+fatura*`, boolean), sözcük başı
eşleşmesi 50'den azsa ve ≥ 4 harfli sözcük varsa ayrıca `LIKE` taramasıyla (durak sözcükleri ve FULLTEXT dizini
yoksa yalnız LIKE). 100 bin fotoğraf / 30 bin yazılı yapay kütüphanede sorgu 0.1–0.16 sn (eşleşme yoksa FULLTEXT
~10 ms + LIKE ~0.1 sn). Sıra: adlandırılmış kişi → **yazı (puan ≥ 2)** → akıllı ∩ metin → dosya adı/açıklama →
yazı (puan 1) → akıllı. Hizmet yoksa: yazı (≥ 2) → dosya adı/açıklama → yazı (1), tarihe göre. Yazıda bulunan
sonuçlarda `text_match: {term, snippet, parts:[{t, m}]}` (en çok sözcüğün eşleştiği satır, ≤ 90 karakter pencere;
`m` vurgulanacak özgün sözcük), yanıtta `text_matches` (yazı eşleşmesi sayısı). `GET /v1/search/status` hizmet
varken `ocr: {scanned, total, with_text}` da döner.

**Uçlar:** `GET /v1/assets/{id}/text` (sahibi ya da paylaşılan albüm üyesi) →
`{status: "done"|"none"|"pending"|"unavailable", text, lines[], words, lang, engine, computed_at}`.
Kalıcı silmede satır silinir (`ocr_purge_asset`). ML `/health` → `ocr: bool` (+ `ocr_error`).

**Arayüz:** web `web/assets/app/ocr.js` (karo rozeti `.tile-ocr`, başlıkta `.search-ocr`, bilgi panelinde katlanabilir
`.i-ocr` + kopyala; kanca sınıfları dosyanın başında, üç temada stil), iOS `AssetInfoView` "Fotoğraftaki yazı"
bölümü (seçilebilir metin, aramadan gelindiyse vurgu, "Yazıyı kopyala") ve arama hücresinde rozet;
yönetim paneli "Fotoğraftaki yazı (OCR)" ilerlemesi.

**Testler:** `tests/db/OcrTest.php`, `tests/unit/OcrFoldTest.php`, `ml/tests/test_ocr.py [--model-dir]`,
`ml/tests/ocr_bench.py` (motor karşılaştırması), `tests/e2e/smoke.mjs` (`OM_E2E_OCR_Q=eczane` ile).
