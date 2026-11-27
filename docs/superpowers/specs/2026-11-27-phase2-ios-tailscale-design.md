# OwnMemories Aşama 2 — Tailscale ve iOS Yedekleme Tasarımı

Hazırlayan: Mehmet BALKAY
Tarih: 2026-11-27 · Durum: Onaylandı (2026-11-27)

## Amaç
Sunucuya Cloudflare yerine Tailscale üzerinden HTTPS ile erişmek ve iPhone'da çekilen fotoğrafların
arka planda otomatik olarak sunucuya yedeklenmesini sağlayan iOS uygulamasının ilk TestFlight sürümü.

## Kararlar (sahibin onayıyla)
- **Erişim:** Tailscale. `tailscale serve --bg 80` ile `https://<makine>.<tailnet>.ts.net` (Tailscale'in Let's
  Encrypt sertifikası). Telefonda Tailscale uygulaması bağlı olmalı; değilse yedekleme bekler, uygulama
  "Sunucuya ulaşılamıyor" gösterir. Cloudflare'in 100 MB sınırı yok; parçalı yükleme (50 MB) devam ve kesinti
  sonrası kaldığı yerden sürme için korunur.
- **İlk sürüm kapsamı:** giriş, yedekleme (arka plan dahil), sunucudaki arşivin zaman çizelgesi ve görüntüleyici.
  Widget ve anı bildirimleri Aşama 3.
- **iOS:** SwiftUI, iOS 17+, XcodeGen, bundle `net.balkay.ownmemories`, OwnCalendar'ın GitHub Actions →
  TestFlight akışı (aynı Apple hesabı ve API anahtarı; secret'lar bu depoya eklenir).

## Yedekleme tasarımı
- PhotoKit "tam erişim". Her varlık için yüklenecek kaynaklar: orijinal fotoğraf/video; Live Photo'nun
  eşleşik videosu; düzenlenmişse düzenlenmiş tam boyut hali. Her kaynak `source_ref` taşır:
  `<localIdentifier>`, `<localIdentifier>#live`, `<localIdentifier>#edited`.
- Kaynak geçici dosyaya alınır (`PHAssetResourceManager`), SHA-1 hesaplanır, `assets/check` ile sorulur;
  sunucuda yoksa `uploads` oturumu açılır ve 50 MB parçalar **arka plan URLSession** ile dosyadan yüklenir
  (uygulama kapansa da sistem yüklemeyi sürdürür). Tüm parçalar bitince `complete` çağrılır.
- Meta veri: çekim zamanı (`PHAsset.creationDate`, cihaz saat dilimi), konum, favori. Sunucu yine dosyanın
  EXIF bilgisini okur; gönderilen zaman EXIF'ten önceliklidir.
- Bağlantılar: Live Photo videosu `live_pair_source_ref`, düzenlenmiş hali `edited_of_source_ref` ile gönderilir;
  sunucu ilgili asset'leri bağlar. Aynı `#edited` kaynağının yeni bir hali gelirse eskisi çöpe taşınır.
- Tetikleyiciler: uygulama açılışı/öne gelmesi, fotoğraf kütüphanesi değişikliği (açıkken), `BGAppRefreshTask`
  (kısa tarama), `BGProcessingTask` (ağ gerekli; ayara göre yalnız şarjda) ve arka plan URLSession olayları.
- Ayarlar: yalnız Wi-Fi (varsayılan açık), videoları dahil et (açık), yalnız şarjdayken (kapalı).
  "Gizli" albümdeki fotoğraflar varsayılan olarak yedeklenmez.
- iPhone'dan silmek sunucudan silmez. Takeout'tan gelmiş fotoğraflar SHA-1 (ve sunucudaki ikincil kontrol) ile
  tanınır, tekrar yüklenmez.
- Durum: son başarılı yedekleme, bekleyen sayısı, son hata. Uygulama bunu `devices/report` ile sunucuya bildirir;
  Yönetim panelinde cihaz bazında görünür.

## Sunucu değişiklikleri
- `devices` tablosu ve `POST /api/v1/devices/report`; admin genel bakışta cihaz listesi.
- `uploads` meta: `live_pair_source_ref`, `edited_of_source_ref`; eski `#edited` kopyanın çöpe taşınması.
- Kurulum belgesi: Tailscale bölümü (Cloudflare isteğe bağlı kalır).

## Kapsam dışı (bu sürüm)
Widget, anı bildirimleri, iPhone albümlerinin aktarımı, iOS 26 arka plan yükleme uzantısı (sonraki sürümde
değerlendirilecek), İngilizce iOS arayüzü (metinler Türkçe).

## Doğrulama
Sunucu: PHP testleri (TDD). iOS: çekirdek mantık için `swift test` (macOS CI), simülatörde imzasız derleme ve
açılış/çökme kontrolü (GitHub Actions), ardından TestFlight derlemesi.
