# OwnMemories

Hazırlayan: Mehmet BALKAY

Kendi sunucunuzda çalışan, Google Fotoğraflar alternatifi kişisel fotoğraf ve video arşivi:
saf PHP + MySQL sunucu, web uygulaması, yönetim paneli ve (Aşama 2'de) iOS uygulaması.
OwnCalendar'ın kardeş uygulamasıdır.

| Klasör | İçerik |
|---|---|
| `web/` | Document root: kurulum (`setup.php`), web uygulaması (`/app/`), yönetim (`/admin/`), REST API (`/api/v1/`) |
| `bin/` | `worker.php` (iş kuyruğu işçisi), `cron.php`, `import-takeout.php` |
| `server/` | Ubuntu kurulum betiği, systemd birimleri, Cloudflare Tunnel örneği |
| `docs/` | `ARCHITECTURE.md` (sözleşme), `INSTALL.md` (kurulum), tasarım ve planlar |
| `tests/` | `php tests/run.php` |

Kurulum (test hostingi, Ubuntu + Cloudflare Tunnel, Google Takeout aktarımı, yedekleme): [`docs/INSTALL.md`](docs/INSTALL.md)

Yerel geliştirme: `php -S 127.0.0.1:8080 -t web web/router-dev.php`

Testler: `php tests/run.php` — veritabanı testleri için `OM_TEST_DB_HOST`, `OM_TEST_DB_USER`,
`OM_TEST_DB_PASS` ortam değişkenleri verilir.
