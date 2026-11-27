# OwnMemories — Kurulum

Hazırlayan: Mehmet BALKAY

Bu belgede iki kurulum yolu anlatılıyor:

- **A. Test hostingi** — herhangi bir PHP/MySQL hostingde deneme
- **B. Evdeki Ubuntu sunucusu** — asıl kurulum, Cloudflare Tunnel ile `https://ownmemories.balkay.net`

Bunlara ek olarak **C** Google Takeout aktarımını, **D** yedeklemeyi, **E** test hostingden Ubuntu'ya taşımayı anlatıyor.

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

### B2. Cloudflare Tunnel

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

Betik kodu `/var/www/ownmemories` klasörüne kopyalar ve işçiyi yeniden başlatır. Yapılandırma ve fotoğraflar
korunur; veritabanı değişiklikleri ilk istekte kendiliğinden uygulanır. Yönlendirmeleri kontrol etmek için:

```bash
bash tests/http/routes.sh http://127.0.0.1
```
