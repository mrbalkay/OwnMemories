# OwnMemories — GitHub'dan TestFlight'a Derleme Rehberi

Hazırlayan: Mehmet BALKAY

Bu rehberle Mac'e ihtiyaç duymadan uygulama GitHub Actions üzerinde derlenir, imzalanır
ve App Store Connect'e (TestFlight) yüklenir. Sonra iPhone'daki **TestFlight** uygulamasından
tek dokunuşla kurulur.

---

## 1. Team ID

Mevcut Apple Developer hesabında <https://developer.apple.com/account> → **Membership details**
bölümündeki **Team ID** değerini not al (10 karakter, ör. `AB12CD34EF`). → `APPLE_TEAM_ID`

## 2. App Store Connect'te uygulama kaydı

> Sertifika ve profiller ilk derlemede **otomatik** oluşturulur. Bundle ID yoksa iş akışı onu da oluşturur;
> elle yapılması gereken tek şey App Store Connect'teki uygulama kaydıdır (1. adımı yine de yapabilirsiniz):

1. <https://developer.apple.com/account/resources/identifiers/add/bundleId> → **App** →
   Description `OwnMemories`, Bundle ID (Explicit) `net.balkay.ownmemories` → Continue → Register.
2. <https://appstoreconnect.apple.com/apps> → **+** → **Yeni Uygulama** → iOS, Ad `OwnMemories`
   (alınmışsa `OwnMemories – Fotoğraflarım`), Birincil dil Türkçe, Bundle ID `net.balkay.ownmemories`, SKU `ownmemories`.

## 3. App Store Connect API anahtarı

1. App Store Connect → **Kullanıcılar ve Erişim** → **Entegrasyonlar** → **App Store Connect API**
   → **Ekip Anahtarları** → **+**
2. Ad: `GitHub Actions` · Erişim: **Admin**
   (Admin şart: bulutta yönetilen dağıtım sertifikasını yalnızca Admin anahtarı oluşturabilir.)
3. **API Anahtarını İndir** → `AuthKey_XXXXXXXXXX.p8` dosyası (yalnızca **bir kez** indirilebilir, sakla).
4. Sayfadaki değerler:
   - **Issuer ID** (üstte, UUID biçiminde) → `ASC_ISSUER_ID`
   - **Key ID** (anahtarın satırında) → `ASC_KEY_ID`

## 4. GitHub secret'ları

Depo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**:

| Secret | Değer |
|---|---|
| `APPLE_TEAM_ID` | 1. adımdaki Team ID |
| `ASC_ISSUER_ID` | 3. adımdaki Issuer ID |
| `ASC_KEY_ID` | 3. adımdaki Key ID |
| `ASC_KEY_P8` | `.p8` dosyasının **tüm içeriği** (`-----BEGIN PRIVATE KEY-----` dahil) |

Alternatif: dördünü tek bir `APPLE_DEVELOPER` secret'ına `ANAHTAR=değer` satırları halinde yazabilirsin
(P8 birden çok satır sürebilir, BEGIN/END satırları isteğe bağlı).

## 5. Derlemeyi başlat

> OwnCalendar için oluşturduğunuz API anahtarını aynen kullanabilirsiniz; secret'lar depoya özel olduğu için
> bu depoya da eklenmeleri gerekir.

- **Otomatik:** `main` dalında `ios/` klasörüne yapılan her gönderimde (push) derleme kendiliğinden başlar ve
  TestFlight'a yüklenir. iPhone'da TestFlight → OwnMemories → **Otomatik Güncellemeler** açıksa
  yeni sürüm telefona kendiliğinden kurulur.

- **Elle:** Depo → **Actions** → **iOS → TestFlight** → **Run workflow**
  (isteğe bağlı sürüm numarası girilebilir, ör. `1.0.1`).
- **Etiketle:** `git tag v1.0.1 && git push origin v1.0.1` → sürüm `1.0.1` olarak derlenir.

Derleme numarası her çalıştırmada otomatik artar. Süre: yaklaşık 10–15 dakika.
Bittiğinde Apple derlemeyi işler (10–30 dk), ardından e-posta gelir.

## 6. iPhone'a kur

1. App Store Connect → OwnMemories → **TestFlight** → **Dahili Test** → **+** grup oluştur,
   kendini (Apple ID e-postanı) ekle.
2. İlk derlemede **Export Compliance** sorulursa: proje `ITSAppUsesNonExemptEncryption = NO`
   içerdiği için normalde sorulmaz.
3. iPhone'a App Store'dan **TestFlight** uygulamasını kur → gelen davetle OwnMemories'ı yükle.
   Yeni derlemeler TestFlight'ta bildirimle gelir.

> Dahili test (en fazla 100 kişi) Apple incelemesi gerektirmez. Dışarıdan test edenler için
> **Harici Test** grubu oluşturup ilk derlemeyi Beta İncelemesi'ne göndermek gerekir.

## 7. Derleme kontrolü (imzasız)

`iOS derleme ve simülatör testi` iş akışı, `ios/` klasöründe değişiklik olan her Pull Request'te ve `main` dışındaki dallara gönderimde
uygulamanın simülatör için derlenip derlenmediğini kontrol eder. Secret gerektirmez;
Actions sekmesinden elle de çalıştırılabilir.

## Sorun giderme

| Hata | Çözüm |
|---|---|
| `GitHub secret eksik` | 4. adımdaki dört secret'ı kontrol et |
| `No Accounts` / `Authentication failed` | Key ID / Issuer ID / .p8 eşleşmiyor ya da anahtar iptal edilmiş |
| `Cloud signing permission error` | API anahtarının rolü **Admin** olmalı |
| `No profiles for ...` / App Group hatası | Developer portalında Identifiers → App Groups → `group.net.balkay.ownmemories` oluştur, iki App ID'ye ekle |
| `The bundle version must be higher` | `BUILD_NUMBER_OFFSET` değerini (iş akışında) büyüt |
| `App record not found` | 2.2 adımındaki App Store Connect uygulama kaydını oluştur |

## Maliyet notu

GitHub'ın macOS dakikaları **özel** depolarda 10 kat sayılır (ücretsiz plan: ayda 2.000 dk →
yaklaşık 200 macOS dakikası ≈ 13–20 derleme). Herkese açık depolarda ücretsizdir.
