# OwnMemories — Tasarım Belgesi

Hazırlayan: Mehmet BALKAY
Tarih: 2026-11-26 · Durum: Taslak (onay bekliyor)

## 1. Amaç ve neden

Google Photos aboneliğini bırakmak ve onun yerini tam olarak tutan, **tamamen size ait,
istediğiniz yönde geliştirebileceğiniz** bir fotoğraf/video uygulamasına geçmek.
Immich, PhotoPrism gibi hazır projeler olgun ama size özel değil ve onları kendi
istediğiniz gibi geliştiremezsiniz. OwnMemories, OwnCalendar'ın kardeş uygulamasıdır:
aynı aile adı, aynı teknoloji tercihleri (saf PHP + MySQL, SwiftUI) ve aynı derleme düzeni.

Başarı ölçütü: Google'daki tüm arşiv (bugün 200 GB üzeri, büyüyecek) çekim tarihi, konum,
açıklama, favori ve albüm bilgileriyle eksiksiz OwnMemories'e taşınmış; iPhone'da çekilen
yeni fotoğraflar kendiliğinden sunucuya geçiyor; widget ve anı bildirimleri Google Photos'taki
"anılar" deneyimini veriyor ve Google aboneliği iptal edilebiliyor.

## 2. Hedefler, kapsam dışı ve olmaması gerekenler

**Hedefler**
- Google Takeout arşivinin tüm meta verileriyle eksiksiz içe aktarılması.
- iOS'ta arka planda otomatik yedekleme (sistem izin verdiği ölçüde, bkz. §7.3).
- iOS widget'ları ve aralıklı anı bildirimleri.
- Web arayüzü: zaman çizelgesi, görüntüleyici, albümler, favoriler, çöp kutusu, harita, arama.
- Evdeki özel sunucuda çalışma, dışarıdan `https://ownmemories.balkay.net` (Cloudflare Tunnel).

**Kapsam dışı (şimdilik)**
- Android uygulaması.
- App Store'da herkese açık yayın (uygulama TestFlight ile kişisel kullanılır).
- Docker, Composer, framework (OwnCalendar gibi saf PHP).
- Herkese açık kayıt (kullanıcıları yalnız yönetici oluşturur).

**Olmaması gerekenler (teknik olarak çalışsa bile başarısızlık sayılır)**
- Orijinal dosyaların değiştirilmesi veya meta verinin kaybolması.
- İçe aktarmada bir dosyanın **sessizce** atlanması (her atlanan dosya raporda görünmeli).
- Aynı fotoğrafın iki kez kaydedilmesi (yinelenen kayıtlar).
- Yedeklemenin fark edilmeden durması (uygulama son başarılı yedekleme zamanını ve bekleyen
  sayısını her zaman göstermeli).

## 3. Kısıtlar

- **Sunucu:** Evde bu işe ayrılmış makine. **Ubuntu Server 24.04 LTS** (kurucu kararı, bkz. §4).
  Apache 2.4 + mod_rewrite, PHP 8.3 (CLI + Apache modülü veya FPM), MySQL 8.0.
  Dış araçlar: `exiftool`, `ffmpeg`/`ffprobe`, `libvips` (`vipsthumbnail`, HEIC desteğiyle).
- **Erişim:** Cloudflare Tunnel (`cloudflared` systemd servisi) → `localhost:80`.
  Ücretsiz planda **tek istek gövdesi en fazla 100 MB** → tüm yüklemeler parçalı olmalı.
  Fotoğraf yanıtları `Cache-Control: private` ile gönderilmeli; Cloudflare önbelleğine girmemeli.
  `/api/` önüne Cloudflare Access konmamalı (iOS uygulaması erişemez); web girişi uygulamanın kendisindedir.
- **Hacim:** 200 GB+ orijinal; önizlemeler ve video dönüşümleri ~%15 ek yer. En az 1 TB disk önerilir.
- **Tek kopya riski:** Google bırakıldığında sunucu tek kopya olur. Kurulum belgesi gece
  yedeklemesini (ikinci disk veya harici disk, `rsync`) zorunlu adım olarak anlatmalı.
- **iOS:** Minimum iOS 17 (OwnCalendar ile aynı), SwiftUI, XcodeGen, WidgetKit, PhotoKit "tam erişim".
- **Dil:** Türkçe varsayılan + İngilizce (OwnCalendar'daki `t()` / `lang/` düzeni).

## 4. Değerlendirilen yaklaşımlar

| | Seçenek | Sonuç |
|---|---|---|
| Sunucu dili | TypeScript (Node) / Go / Python / **saf PHP + MySQL** | **Saf PHP + MySQL** — sizin kararınız: OwnCalendar ile aynı altyapı, bildiğiniz ve geliştirebileceğiniz dil. Ağır işler (önizleme, video, EXIF) PHP'den dış araçlar çağrılarak, web isteğinden bağımsız arka plan işçilerinde yapılır. |
| İşletim sistemi | Windows + XAMPP / **Ubuntu Server** | **Ubuntu Server** — kurucu kararı. Arka plan işçileri ve `cloudflared` systemd ile güvenilir çalışır; ffmpeg/exiftool/libvips paketlerle kurulur; Windows'taki yol uzunluğu, Türkçe karakterli dosya adı ve habersiz yeniden başlatma sorunları olmaz. XAMPP desteklenmez. |
| İçe aktarma yeri | Tünelden yükleme / **sunucuda yerel** | **Sunucuda yerel** — Takeout zip'leri sunucudaki bir klasöre konur; 200 GB internetten geçmez. |
| Yinelenen kontrolü | Dosya adı / **içerik özeti (SHA-1)** | **SHA-1** — iOS, web ve Takeout'tan gelen aynı dosya tek kayıt olur. |

## 5. Sistem yapısı

```
iPhone (OwnMemories + widget)          Tarayıcı (web /app/)
            \                                /
             https://ownmemories.balkay.net  (Cloudflare Tunnel)
                            |
              Ubuntu sunucu: Apache + PHP 8.3
              ├── web/ (document root): site, /app/, /admin/, /api/v1/
              ├── bin/worker.php   (systemd, sürekli: iş kuyruğu)
              ├── bin/cron.php     (systemd timer, günlük: anılar, temizlik)
              ├── bin/import-takeout.php (CLI: Google Takeout aktarımı)
              ├── MySQL 8 (meta veri, iş kuyruğu)
              └── /srv/ownmemories/ (web kökü dışında)
                    originals/{yyyy}/{mm}/{uuid}.{uzantı}   dokunulmaz orijinaller
                    derived/{thumb|preview|video}/...       üretilen dosyalar
                    uploads/   yarım kalan parçalı yüklemeler
                    import/    Takeout zip'leri
```

- **İş kuyruğu:** MySQL `jobs` tablosu, `SELECT ... FOR UPDATE SKIP LOCKED` ile alınır.
  İş türleri: `extract_metadata`, `thumbnail`, `preview`, `transcode_video`, `pair_live_photo`.
  Başarısız iş 3 kez yeniden denenir, sonra admin panelde "hatalı" olarak görünür.
- **Dosya sunumu:** PHP üzerinden, oturum/token kontrolüyle; videolar için HTTP Range desteği
  (gerekirse `mod_xsendfile`). Orijinaller hiçbir zaman doğrudan web kökünden sunulmaz.
- **Önizlemeler:** küçük (~360 px, ızgara için), büyük (~2048 px, görüntüleyici için), WebP veya JPEG.
  HEVC/uyumsuz videolar web için H.264 MP4'e dönüştürülür; orijinal korunur.

## 6. Veri modeli (özet; kesin hali `web/install/schema.sql`)

InnoDB, utf8mb4, tüm zamanlar UTC. Her tablo `user_id` taşır (ileride aile üyesi eklenebilir).

- `users`, `api_tokens`, `admins`, `site_settings`, `rate_limits`, `audit_log` — OwnCalendar'daki desenle.
- `assets` — id (UUID), user_id, type (`photo`/`video`), checksum_sha1 (user_id ile benzersiz),
  original_filename, mime, size, width, height, duration_ms,
  taken_at (UTC), taken_tz_offset (dakika, yerel saat gösterimi için), latitude, longitude, altitude,
  camera_make, camera_model, lens, description, is_favorite, is_archived, trashed_at,
  source (`takeout`/`ios`/`web`), source_ref (iOS localIdentifier veya Takeout yolu),
  live_pair_id (Live Photo'nun fotoğraf+video eşi), edited_of_id (düzenlenmiş kopyanın orijinali),
  storage_path, created_at, updated_at, version.
- `albums`, `album_assets` — albümler ve sıralama.
- `asset_people` — Takeout'taki kişi adları (yüz tanıma gelene kadar etiket olarak).
- `uploads` — parçalı yükleme oturumları (id, user_id, beklenen boyut ve özet, alınan bayt).
- `imports`, `import_items` — her Takeout aktarımının ve her dosyanın sonucu
  (`imported`/`duplicate`/`no_metadata`/`failed` + neden).
- `jobs` — iş kuyruğu.
- `devices` — iOS cihazları, son yedekleme zamanı, bekleyen sayısı (admin ve web'de görünür).
- `memory_days` — günlük üretilen anı kümeleri (tarih, başlık, kapak, asset listesi).

Meta veri değişiklikleri (favori, albüm, çöp, açıklama) OwnCalendar'daki `version` + `cursor`
senkron deseniyle cihazlara dağıtılır; çakışmada son yazan kazanır.

## 7. Bileşenler

### 7.1 Google Takeout içe aktarma (Aşama 1)

Kullanım: Takeout'tan yalnız "Google Fotoğraflar" seçilir, 50 GB'lık zip'ler indirilir,
sunucuda `/srv/ownmemories/import/` klasörüne konur; `php bin/import-takeout.php` çalıştırılır
(admin panelde ilerleme ve rapor görünür). Aktarım **yeniden çalıştırılabilir** (yarıda kesilirse
kaldığı yerden devam eder, zaten alınmışları SHA-1 ile atlar).

Eşleştirme kuralları (her biri testle doğrulanmalı):
- Meta veri dosyası adları: `IMG_1234.jpg.json`, yeni biçim `IMG_1234.jpg.supplemental-metadata.json`
  ve bunun kısaltılmış halleri (`.supplemental-metad.json` vb.); 46–51 karakter kesilmiş adlar;
  `IMG(1).jpg` ↔ `IMG.jpg(1).json` gibi numaralı kopyalar; `-edited` / yerelleştirilmiş
  "-düzenlendi" ekli düzenlenmiş kopyalar (orijinalin JSON'unu kullanır).
- Live Photo / hareketli fotoğraf: aynı adlı HEIC/JPG + MOV/MP4 çifti `live_pair_id` ile bağlanır;
  Pixel MP dosyalarındaki gömülü video korunur.
- Albümler: `metadata.json` taşıyan klasörler albüm olur (başlık oradan); `Photos from YYYY`
  klasörleri albüm değildir. Bir fotoğraf birden çok klasörde varsa tek asset, çok albüm üyeliği.

Öncelik kuralları:
- **Tarih:** JSON `photoTakenTime` (Google'da elle düzeltilen tarihleri de içerir) anı belirler;
  yerel saat ofseti EXIF `OffsetTimeOriginal`'dan, yoksa konumdan saat dilimi, o da yoksa
  `Europe/Istanbul`. JSON yoksa EXIF `DateTimeOriginal`, o da yoksa dosya adındaki tarih, en son dosya tarihi.
- **Konum:** JSON `geoData` (elle düzeltilmiş olabilir) → `geoDataExif` → dosyadaki EXIF GPS.
  `0.0, 0.0` "konum yok" sayılır.
- **Diğer:** `description`, `favorited`, `archived`, `trashed` (çöptekiler içe aktarılmaz, raporda
  sayılır), `people[].name` → `asset_people`.

Rapor: toplam dosya, türlere göre içe aktarılan, yinelenen, meta verisi eşleşmeyen (dosya listesiyle),
hatalı. Eşleşmeyen dosyalar yine içe aktarılır (EXIF'ten tarih alınarak) ve raporda işaretlenir.
Orijinallere hiçbir zaman yazılmaz; "meta veriyi dosyaya gömerek dışa aktar" ileride ayrı bir özelliktir.

### 7.2 REST API (`/api/v1/...`)

OwnCalendar'ın sözleşme düzeninde (`docs/ARCHITECTURE.md` bu projede de bağlayıcı sözleşme olacak):
Bearer token (iOS) veya oturum çerezi + CSRF (web), JSON hata biçimi `{"error":{"code","message"}}`.
Ana uçlar:
- `auth/login`, `auth/logout`, `me`
- `assets/check` — SHA-1 listesi gönderilir, sunucuda olmayanlar döner (toplu, 500'lük).
- `uploads` (oturum aç) → `uploads/{id}` (parça gönder, `Content-Range`, parça ≤ 50 MB)
  → `uploads/{id}/complete` (SHA-1 doğrulanır, asset oluşur, işler kuyruğa girer).
- `assets` (zaman çizelgesi, sayfalı; tarih/konum/kamera/albüm filtresi), `assets/{id}`,
  `assets/{id}/thumb|preview|original|video`.
- `albums`, `sync` (meta veri değişiklikleri, cursor ile), `memories` (bugünün ve yakın günlerin anıları).

### 7.3 iOS uygulaması (Aşama 2–3)

OwnCalendar düzeni: `ios/project.yml` (XcodeGen), `ios/OwnMemories`, `ios/OwnMemoriesWidgets`,
`ios/Shared`; bundle `net.balkay.ownmemories`, App Group `group.net.balkay.ownmemories`;
GitHub Actions ile derleme kontrolü ve TestFlight (OwnCalendar'daki iş akışlarının uyarlaması).

**Yedekleme motoru**
- PhotoKit'ten tüm varlıklar listelenir; her `localIdentifier` için yerel durum tutulur
  (bekliyor / yükleniyor / tamam / hata).
- Orijinal kaynak (`PHAssetResourceManager`) geçici dosyaya alınır, SHA-1 hesaplanır,
  `assets/check` ile sorulur; sunucuda yoksa parçalı yüklenir. Live Photo'nun iki parçası da yüklenir;
  iPhone'da düzenlenmiş fotoğrafın orijinali ve düzenlenmiş hali yüklenir (`edited_of_id`).
- Arka planda çalışma, birlikte kullanılan yöntemlerle:
  1. **Arka plan URLSession** (dosyadan yükleme): uygulama kapansa da sistem yüklemeyi tamamlar.
  2. **BGProcessingTask:** şarjda ve ağda, gece toplu yedekleme. **BGAppRefreshTask:** kısa kontroller.
  3. **Belirgin konum değişikliği** (isteğe bağlı ayar, "Her zaman" konum izni): uygulamayı uyandırır.
  4. Uygulama açıldığında ve fotoğraf kütüphanesi değiştiğinde anında.
  5. iOS 26 ve sonrasında Apple'ın fotoğraf yedekleme uygulamaları için sunduğu arka plan yükleme
     uzantısı mevcutsa kullanılır (planlama aşamasında API doğrulanacak; yoksa 1–4 yeterli).
- Ayarlar: yalnız Wi-Fi / hücresel de kullan, videoları dahil et, yalnız şarjdayken.
- Ana ekranda her zaman: son başarılı yedekleme zamanı, bekleyen sayı, varsa hata.
- **Silme davranışı (kurucu kararı):** iPhone'dan silmek sunucudan silmez (yedek mantığı).
  OwnMemories'te silmek çöp kutusuna taşır (30 gün), istenirse iPhone'dan da silmeyi
  (iOS'un onay penceresiyle) teklif eder.

**Uygulama ekranları:** zaman çizelgesi (sunucudaki tüm arşiv, önizlemeler önbellekli), görüntüleyici,
albümler, favoriler, harita, arama, anılar, yedekleme durumu, ayarlar.

**Anılar ve widget (Aşama 3)**
- Sunucu her gece `memory_days` üretir: "X yıl önce bugün", "geçen ay bu hafta" ve konum/gezi kümeleri.
- **Bildirimler yerel bildirimdir** (APNs sunucusu gerekmez): uygulama arka planda anıları çeker ve
  kullanıcının seçtiği sıklık ve saatte (örn. her gün 09:00 / haftada bir / kapalı) bildirim planlar.
- **Widget'lar:** "Bu gün geçmişte", "Rastgele anı", "Albümden" — küçük/orta/büyük; görseller App
  Group önbelleğinde, belirli aralıklarla döner; dokununca uygulamada ilgili anı açılır.

### 7.4 Web arayüzü ve yönetim paneli (Aşama 1, sonra genişler)

OwnCalendar'daki gibi saf JS (`web/assets/app/`), pastel tema:
- `/app/`: aylara/günlere göre gruplu, kaydırdıkça yüklenen zaman çizelgesi; görüntüleyici (yakınlaştırma,
  video oynatma, bilgi paneli: tarih, konum haritası, kamera); favori; albüm; çöp; tarayıcıdan parçalı yükleme;
  harita görünümü (Leaflet + OpenStreetMap); tarih/yer/kamera araması; anılar şeridi.
- `/admin/`: kullanıcılar, içe aktarma ilerlemesi ve raporları, iş kuyruğu ve hatalar, disk kullanımı,
  cihazların son yedekleme zamanları, ayarlar.
- `setup.php`: ilk kurulum sihirbazı (veritabanı, sahip hesabı, depolama klasörü, araç kontrolü:
  exiftool/ffmpeg/vips bulundu mu).

## 8. Aşamalar

1. **Sunucu + Takeout aktarımı + web zaman çizelgesi.** Bitti sayılır: tüm Takeout arşivi raporu
   temiz şekilde içeride; web'de tarih, konum ve albümlerle gezilebiliyor.
2. **iOS uygulaması + arka plan yedekleme.** Bitti sayılır: yeni çekilen fotoğraflar kendiliğinden
   sunucuya geçiyor; Google'a yedekleme kapatılabilir.
3. **Anılar:** bildirimler, widget'lar, albüm ve harita ekranları (iOS).
4. **Akıllı özellikler:** yer adları (ters coğrafi kodlama), yüz tanıma, içerik araması, paylaşım.

Her aşama için ayrı uygulama planı yazılır. Aşama 4'ün yöntemi (saf PHP'nin yetmediği makine
öğrenmesi kısmı) o aşamada ayrıca tasarlanır.

## 9. Depo düzeni ve derleme

Her şey `mrbalkay/OwnMemories` deposunda:
```
web/        document root (OwnCalendar düzeni: includes/, api/, app/, admin/, lang/, install/)
bin/        worker.php, cron.php, import-takeout.php (web kökü dışında)
server/     Ubuntu kurulum betiği, systemd birimleri, cloudflared örnek yapılandırması
ios/        XcodeGen projesi, uygulama, widget, Shared, testler
docs/       ARCHITECTURE.md (iOS ↔ sunucu sözleşmesi), INSTALL.md, TESTFLIGHT.md, specs/, plans/
.github/workflows/   PHP sözdizimi + testler, iOS derleme kontrolü, TestFlight
```
Test: PHP çekirdeği (özellikle Takeout eşleştirme ve tarih/konum öncelikleri) için gerçek Takeout
örneklerine benzeyen sentetik dosyalarla otomatik testler; iOS çekirdek mantığı için `swift test`.

## 10. Kurucuya bırakılanlar

Tablo sütunlarının kesin hali, önizleme boyutları ve biçimi, parça boyutu (≤ 50 MB), iş kuyruğu
ayrıntıları, ekran tasarımlarının ayrıntısı (OwnCalendar'ın pastel görünümü temel alınır),
anı kümeleme algoritmasının ayrıntısı, kurulum betiğinin içeriği.

## 11. Kurucu kararları (inceleme sonrası; düzeltilebilir)

- **Silinenler geri gelmez:** Kalıcı silinen asset'in SHA-1'i `deleted_checksums` tablosunda tutulur;
  `assets/check` ve Takeout aktarımı bunları "zaten var" sayar. Admin/web'den bilerek geri alınabilir.
- **iPhone'da sonradan yapılan düzenleme:** yeni düzenlenmiş hali yüklenir ve önceki düzenlenmiş
  kopyanın yerini alır; orijinal asset hiç değişmez. Düzenleme geri alınırsa düzenlenmiş kopya kaldırılır.
- **Zaman çizelgesinde tek kare:** düzenlenmiş kopyası olan fotoğraf tek kare olarak düzenlenmiş haliyle
  görünür; bilgi panelinden orijinal açılır. Live Photo da tek karedir. Sayımlar buna göre yapılır.
- **iPhone meta verisi tek yönlü:** ilk yüklemede iOS favori bilgisi ve açıklama sunucuya geçer.
  iPhone albümleri v1'de aktarılmaz. OwnMemories'teki değişiklikler Fotoğraflar kütüphanesine geri yazılmaz.
  iOS'ta "Gizli" albümdeki fotoğraflar varsayılan olarak yedeklenmez (ayarla açılabilir).
- **Başkalarına ait öğeler (Takeout):** paylaşılan albümlerden gelen öğeler içe aktarılır,
  `shared_from_others` olarak işaretlenir, ana zaman çizelgesinde görünür ve raporda ayrıca sayılır.
- **Video dönüştürme isteğe bağlı:** Safari ve iOS HEVC oynatabildiği için videolar baştan dönüştürülmez;
  H.264 kopyası yalnız gerektiğinde (HEVC oynatamayan tarayıcı) ve düşük öncelikli işle üretilir.
  İşçi sayısı sunucu çekirdek sayısına göre ayarlanabilir.
- **Takeout eşleştirme testleri:** gerçek Takeout'un dosya/klasör listesi (yalnız adlar) Aşama 1
  planlamasında alınıp test verisine dönüştürülür.

## 12. Kullanıcılar ve yükleme kalitesi (sahibin cevapları)

- **Google'a yükleme kalitesi: Orijinal kalite.** Takeout dosyaları iPhone'dakilerle birebir aynı kabul
  edilir; birincil eşleştirme SHA-1'dir. Yine de güvenlik için ikinci kontrol eklenir: SHA-1 tutmazsa
  çekim anı (saniye) + boyutlar (genişlik×yükseklik) + orijinal dosya adı aynıysa yinelenen sayılır,
  yükleme atlanır ve iPhone `localIdentifier`'ı mevcut asset'e bağlanır.
- **Kullanıcılar: sahip ve eşi** (iki hesap, her birinin kendi kütüphanesi ve kendi iPhone yedeği).
  Eşler arası paylaşım biçimi §13'te.
- **Gelecekte müşteriler:** sistem tam çalıştıktan sonra başka kullanıcılara açılabilir. Bugün yapılmaz
  ama tasarım engellememeli: her şey `user_id` ile ayrık, kullanıcı başına depolama kotası ve kullanım
  sayacı (`users.quota_bytes`, `users.used_bytes`) şimdiden tutulur; kayıt ve ödeme o zaman tasarlanır.
- **Fiziksel depolama:** aynı dosya iki kullanıcıda olsa bile meta veri kullanıcı başına ayrı tutulur;
  disk tasarrufu için ortak dosya deposu (içerik özetine göre tek kopya) kurucu kararıdır ve istemciye
  hiçbir zaman "bu dosya başka bir kullanıcıda var" bilgisi sızdırılmaz.
