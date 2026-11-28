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
- `asset_embeddings` — asset_id PK, user_id, model (ör. `ViT-B-16-SigLIP2__webli`), vec (MEDIUMBLOB, float32 little-endian, L2-normalize, 768 boyut = 3072 bayt), created_at. ownmemories-ml yazar; asset kalıcı silinince PHP siler (§7)

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
| GET | `assets/summary` | aynı süzgeçler | `{months:[{month:"2019-07",count}]}` (ay, yerel çekim gününe göre) |
| GET | `assets/days` | süzgeçler + `month=YYYY-MM` (zorunlu; hatalıysa 422) | `{month, days:[{day:"2019-07-14",count,cover_asset_id,before}]}` — yerel güne göre (`taken_at + taken_tz_offset`, yoksa UTC; gün başlıklarıyla aynı), yeni gün önce; `before` o güne atlamak için `assets?before=` imleci (günün en yeni `taken_at`'i + 1 sn). Web takvimi kullanır. |
| GET | `assets/map` | süzgeçler + `bbox=minLng,minLat,maxLng,maxLat` | `{points:[{id,lat,lng}]}` (≤ 5000) |
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
| GET | `memories?date=YYYY-MM-DD` | (varsayılan: kullanıcının bugünü) | `{date, memories:[{id,kind,title,years_ago,date,count,cover_asset_id,asset_ids,assets}]}` — `kind`: `on_this_day` / `this_week` (bugün yoksa ±3 gün) / `favorites` |
| POST | `devices/report` | `{device_id,name,model,os_version,app_version,pending,uploaded_total,last_backup_at,last_error}` | `{ok,server_time}` — iOS yedekleme durumu (Yönetim → Genel bakış) |
| GET | `search` | `q` (≤ 200 karakter), `from`, `to` (ISO), `favorite=1`, `sort=relevance\|date` (varsayılan relevance), `limit≤200 (60)`, `offset` | `{mode:"smart"\|"text", assets:[…], next_offset:int\|null, total, status:{indexed,total,pending,model,ready}\|null}` — akıllı mod: ML hizmetinden (config `ml.url`, varsayılan `http://127.0.0.1:8765`, zaman aşımı `ml.timeout` 3 sn, isteğe bağlı `ml.min_score`) en fazla 500 sıralı kimlik alınır; zaman çizelgesi görünürlük kurallarıyla (yalnız kendi kütüphanesi, çöp/arşiv hariç, tek kare kuralı) süzülür, sıra korunur. Dosya adı/açıklama/kamera/kişi eşleşmeleri öne alınır (hibrit). Hizmet yok, hata ya da sonuç yoksa `assets?q=` ile aynı metin araması (`mode:"text"`, tarihe göre); hizmet yanıt verip ilgi eşiğini geçen olmadığında metin araması da boşsa `mode:"smart"`, boş liste. Boş `q` → boş liste. `status` yalnız ilk sayfada (offset=0) |
| GET | `search/status` | – | ML `/status` vekili: `{available:true,indexed,total,pending,model,ready}` ya da `{available:false}` |

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

### Yapılandırma (`/etc/ownmemories/ml.env`, 0640 root:www-data)

`server/install-ml.sh` yazar: `OM_DB_HOST/PORT/NAME/USER/PASS`, `OM_STORAGE` (web/config/config.php'den),
`OM_ML_MODEL`, `OM_ML_MODEL_DIR` (`/var/lib/ownmemories/models`), `OM_ML_PORT` (8765), `OM_ML_THREADS`
(min(4, CPU)). İsteğe bağlı: `OM_ML_BATCH` (16), `OM_ML_IDLE` (20), `OM_ML_TEXT_QUANT` (1), `OM_ML_INDEX` (1),
`OM_ML_LANG` (tr), `OM_ML_MIN_Z` / `OM_ML_MIN_SCORE` (varsayılan ilgi eşiği, yukarıda). Kurulum betiği
kendi yazmadığı `OM_ML_*` satırlarını korur. DB/depolama değişkenleri yoksa servis `OM_CONFIG_PHP`'yi (`php -r`) okur. `localhost`
verilirse PHP gibi MySQL Unix soketi kullanılır.

### Dosyalar

`ml/ownmemories_ml/`: `service.py` (HTTP + dizinleyici + bellek içi dizin), `model.py` (ön işleme, ONNX),
`download.py` (HF indirici: sürdürme, boyut + sha256), `quantize.py`, `config.py`, `__main__.py`
(`serve` / `download` / `quantize` / `embed-text`). Testler: `ml/tests/eval_samples.py` (kalite, gecikme, RAM),
`ml/tests/test_service.py` (geçici DB ile uçtan uca), `ml/tests/test_cutoff.py` (ilgi eşiği, DB/model gerekmez),
`ml/tests/xm3600_bench.py` (Türkçe kalite karşılaştırması, yukarıda).
