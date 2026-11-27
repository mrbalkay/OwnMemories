#!/usr/bin/env bash
# Kurulu bir OwnMemories sunucusunda yönlendirmeleri ve erişim korumalarını kontrol eder (Apache .htaccess dahil).
#   bash tests/http/routes.sh http://127.0.0.1:8090
set -u
BASE="${1:-http://127.0.0.1:8080}"
fail=0
check() { # açıklama, beklenen kod, yol, [ek curl argümanları]
  local desc="$1" want="$2" path="$3"; shift 3
  local got
  got=$(curl -s -o /tmp/om_routes_body -w '%{http_code}' "$@" "$BASE$path")
  if [[ "$got" == "$want" ]]; then echo "  ok   $desc ($got)"; else echo "  FAIL $desc: beklenen $want, gelen $got"; fail=1; fi
}
check "API ping (rewrite)"            200 /api/v1/ping
grep -q '"ok":true' /tmp/om_routes_body || { echo "  FAIL ping JSON değil"; fail=1; }
check "API rewrite yoksa ?r= yolu"     200 '/api/index.php?r=/v1/ping'
check "Bearer başlığı PHP'ye ulaşır"   401 /api/v1/me -H 'Authorization: Bearer gecersiz-token'
grep -q 'invalid_token' /tmp/om_routes_body || { echo "  FAIL Authorization başlığı PHP'ye ulaşmadı"; fail=1; }
check "Bilinmeyen API yolu JSON 404"   404 /api/v1/yok-boyle-bir-yol
check "includes/ kapalı"               403 /includes/functions.php
check "config/ kapalı"                 403 /config/config.php
check "install/ kapalı"                403 /install/schema.sql
check "lang/ kapalı"                   403 /lang/tr/app.php
check "Giriş sayfası"                  200 /login.php
[[ $fail -eq 0 ]] && echo "routes: PASS" || { echo "routes: FAIL"; exit 1; }
