# OwnMemories — Kurulum

Hazırlayan: Mehmet BALKAY

Bu belgede iki kurulum yolu anlatılıyor:

- **A. Test hostingi** — herhangi bir PHP/MySQL hostingde deneme
- **B. Evdeki Ubuntu sunucusu** — asıl kurulum, Cloudflare Tunnel ile `https://ownmemories.balkay.net`

Bunlara ek olarak **C** Google Takeout aktarımını, **D** yedeklemeyi, **E** test hostingden Ubuntu'ya taşımayı,
**F** güncellemeyi, **G** akıllı aramayı (doğal dil ile fotoğraf arama) anlatıyor.

## Gereksinimler

- PHP 8.1 veya üstü. Gerekli eklentiler: `pdo_mysql`, `zip`, `mbstring`, `intl`, `gd` (veya `imagick`).
  `exif` isteğe bağlı.
- MySQL 5.7+ / 8.0 veya MariaDB 10.4+.
- Apache ve `mod_rewrite`. Rewrite yoksa API `api/index.php?r=/v1/...` adresinden de çalışır.
- İsteğe bağlı ama önerilir: `exiftool`, `ffmpeg`/`ffprobe`, `vipsthumbnail`.
  - Bunlar yoksa da sistem çalışır.
  - iPhone HEIC fotoğraflarının küçük resmi için vips veya HEIC destekli Imagick gerekir; yoksa yer tutucu görünür.
  - Video küçük resmi için ffmpeg gerekir.
  - Kurulum sayfası ve **Yönetim → Sistem** sayfası hangi araçların bulunduğunu gösterir.

## A. Test hostingi

1. Depodaki **tüm** dosyaları yükleyin (`web/`, `bin/` ve diğerleri).
   - **Document root `web/` klasörü olmalı.** Hosting buna izin vermiyorsa `web/` içeriğini `public_html`'e koyun ve `bin/` klasörünü bir üst dizine yükleyin.
2. Hosting panelinden boş bir MySQL veritabanı ve kullanıcı oluşturun.
3. `https://test-adresiniz/setup.php` adresini açın ve şunları girin:
   - Veritabanı bilgileri
   - Uygulama adresi
   - Depolama klasörü: mümkünse web kökünün dışında, örneğin `/home/kullanici/ownmemories-storage`
   - Sahip hesabınız
4. **Cron ekleyin (zorunlu).** Küçük resimler ve Takeout aktarımı arka plan işleriyle ilerler.
   - Hosting panelinde CLI cron varsa:
     ```
     * * * * * php /home/kullanici/ownmemories/bin/cron.php
     ```
   - CLI cron yoksa: **Yönetim → Sistem** sayfasındaki "Web cron adresi"ni bir cron servisiyle her dakika çağırın.
5. PHP sınırları: `upload_max_filesize` ve `post_max_size` en az **64M** olmalı. `web/.htaccess` bunu
   mod_php'de ayarlar; FPM kullanan hostinglerde panelden ayarlayın.
6. Giriş yapın. Eşiniz için **Yönetim → Kullanıcılar** sayfasından hesap oluşturun.

Yerel geliştirme için:
```bash
php -S 127.0.0.1:8080 -t web web/router-dev.php
```

## B. Evdeki Ubuntu sunucusu (Ubuntu Server 22.04 veya üstü)

### B1. Temel kurulum

```bash
sudo apt install -y git
git clone https://github.com/mrbalkay/OwnMemories.git
cd OwnMemories
sudo OM_STORAGE=/srv/ownmemories bash server/install-ubuntu.sh
```

Betik şunları yapar:
- Apache, dağıtımın varsayılan PHP sürümünü (en az 8.1), MySQL (yoksa MariaDB), exiftool, ffmpeg ve libvips'i kurar.
- Dosyaları `/var/www/ownmemories` klasörüne kopyalar.
- Veritabanını ve kullanıcısını oluşturur.
- Depo klasörünü (`/srv/ownmemories`) hazırlar.
- Şu systemd birimlerini kurar: `ownmemories-worker` (işçi), `ownmemories-cron.timer` (dakikalık), `ownmemories-backup.timer` (gece yedeği).

Betik sonunda veritabanı parolasını yazar; bunu not alın. Ardından:

1. `http://<sunucu-ip>/setup.php` adresini açın. Betiğin verdiği veritabanı bilgilerini girin ve depolama klasörü olarak `/srv/ownmemories` yazın.
2. İşçiyi başlatın:
   ```bash
   sudo systemctl start ownmemories-worker
   ```
3. Çalıştığını kontrol edin:
   ```bash
   systemctl status ownmemories-worker
   ```
   **Yönetim → Genel bakış** sayfasında "İşçi son görülme" değeri birkaç saniye olmalı.

> **Disk:** 200 GB+ arşiv, önizlemeler ve büyüme payı için en az 1 TB veri diski önerilir.
> Depo klasörünü (`/srv/ownmemories`) bu diske bağlayın.

### B2. Tailscale (önerilen)

Uygulama internete açılmaz; yalnız Tailscale ağınızdaki (tailnet) cihazlardan erişilir. iOS uygulaması için HTTPS
adresi Tailscale'in kendi sertifikasıyla sağlanır.

1. Tailscale yönetim panelinde (login.tailscale.com) **DNS** sayfasında **MagicDNS** ve **HTTPS Certificates** açık olsun.
2. Sunucuda Tailscale bağlıyken uygulamayı HTTPS ile yayınlayın (yeniden başlatmalarda da kalıcıdır):
   ```bash
   sudo tailscale serve --bg 80
   ```
   ```bash
   tailscale serve status
   ```
   Çıktıdaki adres `https://<makine-adı>.<tailnet-adınız>.ts.net` biçimindedir.
3. `/var/www/ownmemories/web/config/config.php` dosyasında `'url' =>` satırını bu adresle değiştirin.
4. Telefonda ve bilgisayarda Tailscale uygulamasını kurup aynı hesapla giriş yapın; adresi tarayıcıda açın.
5. Eşiniz için: Tailscale panelinde **Users → Invite** ile tailnet'e davet edin veya yalnız bu makineyi
   **Share** ile paylaşın. OwnMemories'te de **Yönetim → Kullanıcılar** sayfasından hesabını oluşturun.

> iOS uygulaması yedeklemeyi bu adres üzerinden yapar. Telefonda Tailscale bağlı değilse yedekleme bekler ve
> bağlantı gelince devam eder.

### B3. Cloudflare Tunnel (isteğe bağlı, internetten erişim için)

1. Cloudflare'de `balkay.net` alan adının etkin olduğundan emin olun.
2. Sunucuda `cloudflared` kurun:
   ```bash
   curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
   ```
   ```bash
   echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared noble main" | sudo tee /etc/apt/sources.list.d/cloudflared.list
   ```
   ```bash
   sudo apt update && sudo apt install -y cloudflared
   ```
3. Tüneli oluşturun:
   ```bash
   cloudflared tunnel login
   ```
   ```bash
   cloudflared tunnel create ownmemories
   ```
   ```bash
   cloudflared tunnel route dns ownmemories ownmemories.balkay.net
   ```
4. Yapılandırmayı yerleştirin:
   - `server/cloudflared-config.example.yml` dosyasını `/etc/cloudflared/config.yml` olarak kopyalayın.
   - İçindeki `<TUNNEL-UUID>` değerini kendi tünel kimliğinizle değiştirin.
   - Kimlik dosyasını (`~/.cloudflared/<UUID>.json`) `/etc/cloudflared/` klasörüne kopyalayın.
5. Servisi kurun:
   ```bash
   sudo cloudflared service install
   ```
   ```bash
   sudo systemctl enable --now cloudflared
   ```
6. Kurulumda uygulama adresini `https://ownmemories.balkay.net` olarak girin. Yerel IP ile kurduysanız
   `web/config/config.php` içindeki `app.url` değerini düzeltin.

Önemli notlar:
- **Yükleme sınırı:** Cloudflare ücretsiz planda tek istek en fazla 100 MB olabilir. OwnMemories bu yüzden
  dosyaları en fazla 50 MB'lık parçalar halinde yükler; ek ayar gerekmez.
- **Cloudflare Access kullanacaksanız** yalnız `/admin/` yolunu korumaya alın. `/api/` korunursa iOS uygulaması bağlanamaz. Uygulamanın kendi girişi zaten var.
- **Önbellek:** Medya yanıtları `Cache-Control: private` ile gönderilir, Cloudflare bunları önbelleğe almaz.
  "Cache Everything" gibi bir kural eklemeyin.

## C. Google Takeout aktarımı

1. [takeout.google.com](https://takeout.google.com) adresinde **yalnız "Google Fotoğraflar"** seçeneğini işaretleyin.
   Dosya türü `.zip`, boyut **50 GB** olsun. 200 GB için yaklaşık 4–5 zip dosyası gelir.
2. Zip'leri **açmadan** sunucudaki `<depo>/import/` klasörüne kopyalayın (Ubuntu'da `/srv/ownmemories/import/`):
   - Evde aynı ağdaysanız: `scp`, `rsync` veya bir USB disk.
   - Test hostinginde: FTP.
3. **Yönetim → İçe aktarmalar** sayfasında kullanıcıyı ve klasörü seçip **İçe aktarmayı başlat** düğmesine basın.
   - Aktarım arka planda ilerler; sayfayı kapatabilirsiniz.
   - Duraklatıp devam ettirebilirsiniz.
   - Yarıda kesilirse kaldığı yerden devam eder. Zaten alınan dosyalar tekrar alınmaz.
4. Komut satırını tercih ederseniz (Ubuntu'da daha hızlı):
   ```bash
   sudo -u www-data php /var/www/ownmemories/bin/import-takeout.php --user=eposta@ornek.com
   ```
   Kesilirse şu komutla devam edin:
   ```bash
   sudo -u www-data php /var/www/ownmemories/bin/import-takeout.php --resume=ID
   ```
5. Bitince raporu kontrol edin:
   - **Hata** satırı 0 olmalı.
   - "Meta veri dosyası yok" satırındaki dosyalar yine içeri alınmıştır; tarihleri fotoğrafın kendi
     bilgisinden, dosya adından veya dosya tarihinden alınır.
   - Rapor CSV olarak indirilebilir.
6. Her şey yerindeyse zip'leri silebilirsiniz. Orijinal dosyalar artık depoda.

Aktarılan bilgiler:
- Çekim tarihi ve saati (Google'da elle düzelttikleriniz dahil)
- Konum
- Açıklama
- Favori ve arşiv durumu
- Albümler (yıl klasörleri albüm sayılmaz)
- Kişi adları
- Live Photo çiftleri
- Düzenlenmiş kopyalar (orijinaliyle birlikte)

Google'ın çöp kutusundaki öğeler alınmaz.

## D. Yedekleme (önemli)

Google'ı bıraktığınızda bu sunucu tek kopya olur. **Gece yedeğini mutlaka açın.**

1. Yedek hedefini ayarlayın; ikinci bir disk veya harici disk olabilir:
   ```bash
   echo 'BACKUP_DIR=/mnt/yedek/ownmemories' | sudo tee /etc/ownmemories-backup.env
   ```
2. Gece yedeğini açın:
   ```bash
   sudo systemctl enable --now ownmemories-backup.timer
   ```
3. Elle denemek için:
   ```bash
   sudo systemctl start ownmemories-backup.service
   ```
   ```bash
   journalctl -u ownmemories-backup -n 20
   ```

`server/backup.sh` her gece 03:30'da şunları yapar:
- Veritabanını gzip'li döküm olarak kaydeder; son 30 gün saklanır.
- Depo klasörünü `rsync` ile eşler. Silinen dosyalar 30 gün boyunca `deleted/<tarih>` klasöründe tutulur.

Mümkünse yedeğin bir kopyasını evin dışında da tutun (ikinci bir konum veya şifreli bulut).

## E. Test hostingden Ubuntu'ya taşıma

1. Ubuntu'da **B1** adımlarını uygulayın, ama `setup.php` sayfasını **çalıştırmayın**.
2. Test hostingindeki veritabanını dökün (`mysqldump` veya panelden dışa aktarma) ve Ubuntu'daki boş veritabanına aktarın:
   ```bash
   mysql ownmemories < dump.sql
   ```
3. Test hostingindeki depolama klasörünün tamamını `/srv/ownmemories/` klasörüne kopyalayın:
   ```bash
   rsync -av kullanici@hosting:/home/kullanici/ownmemories-storage/ /srv/ownmemories/
   ```
   ```bash
   sudo chown -R www-data:www-data /srv/ownmemories
   ```
4. Hostingdeki `web/config/config.php` dosyasını `/var/www/ownmemories/web/config/` klasörüne kopyalayın ve şunları güncelleyin:
   - `db` bilgileri
   - `storage.path` → `/srv/ownmemories`
   - `app.url` → `https://ownmemories.balkay.net`

   Aynı klasöre boş bir `installed.lock` dosyası koyun.
5. İşçiyi başlatın:
   ```bash
   sudo systemctl start ownmemories-worker
   ```
   **Yönetim → Sistem** sayfasından araçları yeniden taratın; yeni sunucuda HEIC ve video desteği gelir.
6. HEIC fotoğrafların küçük resimleri hostingde yer tutucu kaldıysa **Yönetim → İşler → Tüm küçük resimleri yeniden üret** düğmesine basın.

## F. Güncelleme (Ubuntu)

Yeni sürümü almak için sunucuda deponun klonlandığı klasörde:

```bash
cd ~/OwnMemories && git pull
```

```bash
sudo bash server/update.sh
```

Betik kodu `/var/www/ownmemories` klasörüne kopyalar, işçiyi yeniden başlatır ve akıllı arama servisini
(`server/install-ml.sh`, bkz. G) kurar/günceller. Yapılandırma ve fotoğraflar korunur; veritabanı değişiklikleri
ilk istekte kendiliğinden uygulanır. Akıllı arama kurulamazsa (ör. model indirilemedi) betik bir **UYARI**
yazar ama güncelleme tamamlanır; arama dosya adı/açıklama ile çalışmaya devam eder. Yönlendirmeleri kontrol etmek için:

```bash
bash tests/http/routes.sh http://127.0.0.1
```

## G. Akıllı arama (Ubuntu)

Üst çubuktaki arama, "kumsalda köpek", "doğum günü pastası", "karlı dağ" gibi Türkçe (veya İngilizce)
cümlelerle fotoğrafların **içeriğine** göre arar. Bunu ayrı bir servis yapar: `ownmemories-ml`
(Python + ONNX, yalnızca sunucunun içinden `127.0.0.1:8765` adresinden erişilir, dışarıya açılmaz).
Fotoğraflar sunucudan hiçbir yere gönderilmez; model bir kez Hugging Face'ten indirilir.

**Kurulum:** `install-ubuntu.sh` ve `update.sh` kendiliğinden kurar. Elle kurmak / onarmak için:

```bash
sudo bash /var/www/ownmemories/server/install-ml.sh
```

Betik şunları yapar (tekrar çalıştırmak güvenlidir):

1. `python3-venv` kurar, `/opt/ownmemories-ml` sanal ortamına sabit sürümlü paketleri yükler
   (onnxruntime, numpy, tokenizers, pillow, pymysql).
2. Modeli (`immich-app/ViT-B-16-SigLIP2__webli`, ~1.5 GB) `/var/lib/ownmemories/models/` altına indirir.
   Yarım kalan indirme kaldığı yerden sürer; dosyalar boyut ve SHA-256 ile doğrulanır.
3. Metin modelinin int8 kopyasını üretir (~600 MB daha az RAM; bunun için bir kerelik ~3.5 GB boş bellek
   gerekir, yoksa tam model kullanılır).
4. Yüz tanıma modelini (Kişiler; Immich'in `immich-app/buffalo_l` paketi, ~190 MB) indirir. İndirilemezse
   kurulum durmaz: akıllı arama ve elle kişi etiketleme çalışır, yalnızca otomatik yüz tanıma kapalı kalır.
5. `/etc/ownmemories/ml.env` dosyasını `web/config/config.php`'deki veritabanı ve depolama ayarlarından yazar.
6. `ownmemories-ml` systemd servisini kurar, başlatır ve hazır olduğunu denetler.

**Gereksinimler:** en az 2 GB RAM (servis ~1.3 GB kullanır; yüz tanıma ~0.4 GB daha — 2.6 GB'tan az RAM'li
sunucuda yüz tanıma kendiliğinden kapalı kurulur), ~3.2 GB disk, ilk kurulumda internet.

**Yüz tanıma lisansı:** buffalo_l model ağırlıkları (InsightFace) yalnızca **ticari olmayan** kullanım içindir.
Kişisel arşiviniz için uygundur; ticari bir hizmette kullanacaksanız `OM_FACE_MODEL="0"` yapın.

**İlk dizinleme:** servis arka planda tüm fotoğrafların (ve videoların karesinin) vektörünü çıkarır.
4 çekirdekte saatte ~30.000 fotoğraf; düşük öncelikle çalışır, sunucuyu yormaz. Bu sürede arama sayfası
"Akıllı arama hazırlanıyor: 12.400 / 30.000" gibi bir not gösterir; dizinlenmiş fotoğraflar hemen aranabilir.
Yeni yüklenen fotoğraflar küçük resimleri oluştuktan sonra kendiliğinden eklenir.

**Yüz tarama:** aynı servis, akıllı arama dizinlemesiyle dönüşümlü olarak fotoğraflardaki yüzleri bulur ve
benzer yüzleri adsız kişiler halinde gruplar (Immich gibi: aynı kişiye ait en az 3 yüz gerekir). 4 çekirdekte
fotoğraf başına ~0.1–0.4 sn (akıllı arama dizinlemesi sürerken daha yavaş, o bitince tam hız). Kişiler sayfası
bu sürede "Yüzler taranıyor: 1.240 / 6.500" gösterir. Kişilere ad verdikçe ve grupları birleştirdikçe yeni
fotoğraflar adlı kişilere kendiliğinden eklenir.

**Durum ve günlük:**

```bash
systemctl status ownmemories-ml
journalctl -u ownmemories-ml -n 50
curl -s http://127.0.0.1:8765/health
```

**Ayarlar** (`/etc/ownmemories/ml.env`, değiştirdikten sonra `sudo systemctl restart ownmemories-ml`):

- `OM_ML_THREADS` — kullanılacak çekirdek sayısı (varsayılan en fazla 4). Az çekirdekli sunucuda 2 yapın.
- `OM_ML_MODEL` — başka bir Immich modeli. Varsayılan `immich-app/ViT-B-16-SigLIP2__webli` kısa sorgularda
  ("kumsal", "karlı dağ") en iyi hız/kalite dengesidir. Aramayı çoğunlukla uzun Türkçe cümlelerle yapıyorsanız
  ve sunucuda en az 6 GB RAM varsa `immich-app/nllb-clip-base-siglip__v1` cümlelerde belirgin daha isabetlidir
  (ama dizinlemesi 3 kat yavaş, ~0.6 GB daha fazla RAM ister; karşılaştırma: docs/ARCHITECTURE.md §7).
  Değiştirdikten sonra `sudo bash server/install-ml.sh` çalıştırın; tüm fotoğraflar yeniden dizinlenir.
- `OM_ML_MIN_Z` / `OM_ML_MIN_SCORE` — sonuç eşiği. Varsayılan olarak yalnızca sorguyla belirgin şekilde
  eşleşen fotoğraflar listelenir; daha çok (ama daha az isabetli) sonuç için `OM_ML_MIN_Z="1.5"`, eşiği
  tamamen kapatmak için `OM_ML_MIN_Z="0"`.
- `OM_FACE_MODEL` — yüz tanıma modeli (varsayılan `immich-app/buffalo_l`); `"0"` yüz tanımayı kapatır (elle
  kişi etiketleme yine çalışır). Kapalıyken açmak için değeri yazıp `sudo bash server/install-ml.sh` çalıştırın.
- `OM_FACE_MAX_DISTANCE` (0.5) — iki yüzün aynı kişi sayılması için en büyük kosinüs uzaklığı. Aynı kişi çok
  fazla gruba bölünüyorsa `0.55`–`0.6`; farklı kişiler karışıyorsa `0.45`.
- `OM_FACE_MIN_FACES` (3) — yeni bir kişi grubu için gereken benzer yüz sayısı.
- `OM_FACE_MIN_SIZE` (32) — bundan küçük (piksel) yüzler yok sayılır; `OM_FACE_MIN_SCORE` (0.7) algılama eşiği.

Elle eklediğiniz `OM_ML_*` / `OM_FACE_*` ayarları güncellemelerde korunur (veritabanı ayarları config.php'den yeniden yazılır).

**Kapatmak:** `sudo touch /etc/ownmemories/ml.disabled && sudo bash /var/www/ownmemories/server/install-ml.sh`
(geri açmak için dosyayı silip betiği yeniden çalıştırın). Kapalıyken arama dosya adı/açıklama ile çalışır.
