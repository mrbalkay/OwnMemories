#!/usr/bin/env bash
# OwnMemories — "Bağlantıyla paylaş" için herkese açık paylaşım ucu (Tailscale Funnel).
#
#   sudo bash server/setup-share.sh             # kur + Funnel'ı açmayı sor
#   sudo bash server/setup-share.sh --funnel    # kur + Funnel'ı sormadan aç
#   sudo bash server/setup-share.sh --no-funnel # yalnızca yerel siteyi kur (bağlantılar tailnet içinde çalışır)
#   sudo bash server/setup-share.sh --status    # durum
#   sudo bash server/setup-share.sh --off       # Funnel'ı kapat (bağlantılar yalnız tailnet içinde açılır)
#
# Ne yapar:
#   1. Apache'de yalnızca 127.0.0.1:<port> (varsayılan 8091) üzerinde dinleyen ayrı bir site kurar. Bu sitenin
#      kökü web/s'tir: sadece /s/<belirteç> paylaşım sayfaları sunulur; giriş, uygulama, yönetim ve API YOKTUR.
#   2. Tailscale adınızı bulur (ör. https://balkays.taild9431e.ts.net) ve paylaşım adresini
#      https://<makine>.<tailnet>.ts.net:8443 olarak yapılandırmaya yazar (shares.public_url).
#   3. İsterseniz "tailscale funnel --bg --https=8443 http://127.0.0.1:8091" ile YALNIZCA bu portu internete açar.
#      Ana uygulamanız (tailscale serve, 443) tailnet'e özel kalır.
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo "root olarak çalıştırın: sudo bash $0" >&2; exit 1; fi

SRC="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${OM_DEST:-/var/www/ownmemories}"
PORT="${OM_SHARE_PORT:-8091}"
FPORT="${OM_FUNNEL_PORT:-8443}"
SITE=/etc/apache2/sites-available/ownmemories-share.conf
CONFIG="$DEST/web/config/config.php"
MODE="ask"
case "${1:-}" in
  --funnel) MODE="on" ;;
  --no-funnel) MODE="off" ;;
  --status) MODE="status" ;;
  --off) MODE="disable" ;;
  "") ;;
  *) echo "Bilinmeyen seçenek: $1 (--funnel | --no-funnel | --status | --off)" >&2; exit 1 ;;
esac

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }

[[ -f "$CONFIG" ]] || { echo "Kurulum bulunamadı: $CONFIG (önce install-ubuntu.sh ve setup.php)" >&2; exit 1; }
[[ "$FPORT" =~ ^(443|8443|10000)$ ]] || { echo "Funnel yalnızca 443, 8443 veya 10000 portlarını açabilir (OM_FUNNEL_PORT=$FPORT)." >&2; exit 1; }
if [[ "$FPORT" == "443" ]]; then
  echo "443 ana uygulamanın (tailscale serve) portudur; onu Funnel'a açmak TÜM uygulamayı internete açar. 8443 kullanın." >&2
  exit 1
fi

ts_name() {
  command -v tailscale >/dev/null || return 1
  tailscale status --json 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)["Self"]["DNSName"].rstrip("."))' 2>/dev/null
}

config_set_url() {  # $1 = adres ('' = sil)
  OM_URL="$1" php -r '
    $f = $argv[1];
    $c = require $f;
    if (!is_array($c)) { fwrite(STDERR, "config okunamadı\n"); exit(1); }
    $u = getenv("OM_URL");
    if ($u === "") { unset($c["shares"]["public_url"]); } else { $c["shares"]["public_url"] = $u; }
    $tmp = $f . ".tmp";
    file_put_contents($tmp, "<?php\n// OwnMemories yapılandırması — setup.php tarafından yazıldı.\nreturn " . var_export($c, true) . ";\n");
    rename($tmp, $f);
  ' "$CONFIG"
  chown www-data:www-data "$CONFIG"; chmod 640 "$CONFIG"
}

config_url() { php -r '$c = require $argv[1]; echo $c["shares"]["public_url"] ?? "";' "$CONFIG"; }

if [[ "$MODE" == "status" ]]; then
  say "Paylaşım ucu durumu"
  [[ -f /etc/apache2/sites-enabled/ownmemories-share.conf ]] && ok "Apache paylaşım sitesi etkin (127.0.0.1:$PORT)" || warn "Apache paylaşım sitesi kurulu değil"
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/s/assets/share.css" || true); echo "  yerel deneme: HTTP $code"
  echo "  paylaşım adresi (config): $(config_url || true)"
  command -v tailscale >/dev/null && { echo; tailscale funnel status 2>&1 | sed 's/^/  /'; }
  exit 0
fi

if [[ "$MODE" == "disable" ]]; then
  tailscale funnel --https="$FPORT" off 2>/dev/null || true
  name="$(ts_name || true)"
  [[ -n "$name" ]] && config_set_url "https://$name"
  ok "Funnel kapatıldı. Yeni bağlantılar yalnızca tailnet içinde açılır (https://${name:-<makine>.ts.net}/s/...)."
  exit 0
fi

say "1/3 Apache paylaşım sitesi (yalnızca 127.0.0.1:$PORT)"
a2enmod -q rewrite remoteip headers >/dev/null
sed -e "s#__DEST__#$DEST#g" -e "s#__PORT__#$PORT#g" "$SRC/server/apache-ownmemories-share.conf" > "$SITE"
a2ensite -q ownmemories-share >/dev/null
apache2ctl configtest >/dev/null 2>&1 || { apache2ctl configtest; echo "Apache yapılandırması hatalı; site devre dışı bırakılıyor." >&2; a2dissite -q ownmemories-share; exit 1; }
systemctl reload apache2
sleep 1
c1=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/s/assets/share.css" || true)
c2=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api/v1/ping" || true)
c3=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/login.php" || true)
[[ "$c1" == "200" ]] && ok "paylaşım sayfaları çalışıyor" || warn "paylaşım stil dosyası HTTP $c1 döndü (beklenen 200)"
[[ "$c2" != "200" && "$c3" != "200" ]] && ok "ana uygulama ve API bu porttan erişilemiyor (API $c2, giriş $c3)" || { echo "GÜVENLİK: ana uygulama paylaşım portundan açılıyor! Site kapatılıyor." >&2; a2dissite -q ownmemories-share; systemctl reload apache2; exit 1; }

say "2/3 Paylaşım adresi"
name="$(ts_name || true)"
if [[ -z "$name" ]]; then
  warn "Tailscale adı bulunamadı (tailscale kurulu ve bağlı mı?). Adres yazılmadı; bağlantılar açılan sayfanın adresini kullanır."
  PUBLIC=""
else
  PUBLIC="https://$name:$FPORT"
  config_set_url "$PUBLIC"
  ok "shares.public_url = $PUBLIC"
fi

say "3/3 Tailscale Funnel (yalnızca port $FPORT → 127.0.0.1:$PORT)"
if [[ "$MODE" == "ask" ]]; then
  read -r -p "  Paylaşım ucu şimdi internete açılsın mı? [e/H] " ans || ans=""
  [[ "$ans" =~ ^[eEyY] ]] && MODE="on" || MODE="off"
fi
if [[ "$MODE" == "on" ]]; then
  if tailscale funnel --bg --https="$FPORT" "http://127.0.0.1:$PORT"; then
    ok "Funnel açık."
  else
    warn "Funnel açılamadı. Tailnet ayarlarında Funnel izni gerekiyor:"
    cat <<TXT
     a) Yukarıda bir login.tailscale.com/f/funnel... bağlantısı görüyorsanız açın ve "Enable" deyin; ya da
     b) login.tailscale.com → Access controls (Policy file) içine şunu ekleyip kaydedin:
          "nodeAttrs": [
            { "target": ["autogroup:member"], "attr": ["funnel"] }
          ],
        (DNS sayfasında MagicDNS ve HTTPS Certificates açık olmalı.)
     Sonra yeniden:  sudo bash $SRC/server/setup-share.sh --funnel
TXT
  fi
else
  echo "  Funnel açılmadı. Bağlantılar şimdilik yalnızca tailnet içindeki cihazlarda açılır."
  echo "  Açmak için:  sudo tailscale funnel --bg --https=$FPORT http://127.0.0.1:$PORT"
fi
command -v tailscale >/dev/null && { echo; tailscale funnel status 2>&1 | sed 's/^/  /' || true; }

cat <<TXT

Bitti. Uygulamada bir albüm açın → "Bağlantıyla paylaş" → bağlantıyı kopyalayın veya QR kodu okutun.
Paylaşım adresleri: ${PUBLIC:-https://<makine>.<tailnet>.ts.net:$FPORT}/s/...
Yalnızca bu adres internete açıktır; uygulama, giriş ve yönetim tailnet'e özel kalır.
Kapatmak için:  sudo bash $SRC/server/setup-share.sh --off
TXT
