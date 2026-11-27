#!/usr/bin/env python3
"""App Store Connect API ile Bundle ID'yi hazırlar (bağımlılıksız). OwnCalendar'daki betiğin uyarlaması.

- net.balkay.ownmemories ve .widgets Bundle ID'leri yoksa oluşturur
- İkisinde de APP_GROUPS yeteneğini açar (widget ile anı önbelleği paylaşımı için)
- Bu Bundle ID'lere ait, yetenekleri eskimiş Xcode yönetimli geliştirme profillerini siler
  (Xcode -allowProvisioningUpdates ile hemen yeniden oluşturur)

Ortam: ASC_KEY_ID, ASC_ISSUER_ID, ASC_KEY_PATH
"""
import os, sys, urllib.parse
sys.path.insert(0, os.path.dirname(__file__))
from asc_api import call, errtext  # noqa: E402

APP_ID = "net.balkay.ownmemories"
BUNDLES = {
    APP_ID: ("OwnMemories", ["APP_GROUPS"]),
    APP_ID + ".widgets": ("OwnMemories Widgets", ["APP_GROUPS"]),
}


changed = False
for ident, (name, caps) in BUNDLES.items():
    st, res = call("GET", "/bundleIds?" + urllib.parse.urlencode({"filter[identifier]": ident, "limit": 20}))
    if st != 200:
        print(f"::error::Bundle ID sorgulanamadı ({st}): {errtext(res)}")
        sys.exit(1)
    match = [b for b in res["data"] if b["attributes"]["identifier"] == ident]
    if match:
        bid = match[0]["id"]
        print(f"Bundle ID mevcut: {ident}")
    else:
        st, res = call("POST", "/bundleIds", {"data": {"type": "bundleIds", "attributes": {
            "identifier": ident, "name": name, "platform": "IOS"}}})
        if st not in (200, 201):
            print(f"::error::Bundle ID oluşturulamadı {ident} ({st}): {errtext(res)}")
            sys.exit(1)
        bid = res["data"]["id"]
        changed = True
        print(f"Bundle ID oluşturuldu: {ident}")

    st, res = call("GET", f"/bundleIds/{bid}/bundleIdCapabilities")
    have = {c["attributes"]["capabilityType"] for c in res.get("data", [])} if st == 200 else set()
    for cap in caps:
        if cap in have:
            print(f"  {cap}: açık")
            continue
        attrs = {"capabilityType": cap}
        if cap == "APPLE_ID_AUTH":
            attrs["settings"] = [{"key": "APPLE_ID_AUTH_APP_CONSENT",
                                  "options": [{"key": "PRIMARY_APP_CONSENT"}]}]
        st, res = call("POST", "/bundleIdCapabilities", {"data": {
            "type": "bundleIdCapabilities", "attributes": attrs,
            "relationships": {"bundleId": {"data": {"type": "bundleIds", "id": bid}}}}})
        if st in (200, 201):
            changed = True
            print(f"  {cap}: açıldı")
        else:
            print(f"::warning::{ident} için {cap} açılamadı ({st}): {errtext(res)}")

    # Eski Xcode yönetimli profilleri temizle (yetenek değişince eşleşmez hale gelirler)
    st, res = call("GET", f"/bundleIds/{bid}/profiles?limit=50")
    for p in res.get("data", []) if st == 200 else []:
        pname = p["attributes"].get("name", "")
        if pname.startswith("iOS Team") or pname.startswith("XC "):
            dst, dres = call("DELETE", f"/profiles/{p['id']}")
            print(f"  profil silindi: {pname}" if dst in (200, 204) else f"  profil silinemedi: {pname} ({dst})")

# CI makinesi her seferinde yeni bir geliştirme sertifikası oluşturur; sınır dolmasın diye
# YALNIZCA önceki CI çalıştırmalarının API ile oluşturduğu GELİŞTİRME sertifikaları iptal edilir.
# Dağıtım (App Store) sertifikalarına ve elle oluşturulmuş sertifikalara dokunulmaz.
st, res = call("GET", "/certificates?limit=200")
if st == 200:
    for c in res.get("data", []):
        a_ = c["attributes"]
        ctype = a_.get("certificateType", "")
        label = f"{a_.get('name', '')} {a_.get('displayName', '')}"
        if ctype in ("DEVELOPMENT", "IOS_DEVELOPMENT") and "Created via API" in label:
            dst, _ = call("DELETE", f"/certificates/{c['id']}")
            print(f"  CI geliştirme sertifikası iptal edildi ({ctype})" if dst in (200, 204) else f"  sertifika iptal edilemedi ({dst})")
else:
    print(f"::warning::Sertifikalar okunamadı ({st}): {errtext(res)}")

print("Apple hazırlığı tamam." + (" (değişiklik yapıldı)" if changed else ""))


# ---- TestFlight dahili test grubu (yalnızca TESTER_EMAIL verilirse) ----
def testflight_internal_group(email):
    st, res = call("GET", "/apps?" + urllib.parse.urlencode({"filter[bundleId]": APP_ID}))
    apps = res.get("data", []) if st == 200 else []
    if not apps:
        print("::warning::App Store Connect'te uygulama kaydı bulunamadı; TestFlight grubu atlandı.")
        return
    app_id = apps[0]["id"]
    st, res = call("GET", f"/apps/{app_id}/betaGroups?limit=50")
    groups = [g for g in res.get("data", []) if g["attributes"].get("isInternalGroup")] if st == 200 else []
    if groups:
        gid = groups[0]["id"]
        print(f"TestFlight dahili grubu mevcut: {groups[0]['attributes'].get('name')}")
    else:
        st, res = call("POST", "/betaGroups", {"data": {"type": "betaGroups",
            "attributes": {"name": "Dahili Test", "isInternalGroup": True, "hasAccessToAllBuilds": True},
            "relationships": {"app": {"data": {"type": "apps", "id": app_id}}}}})
        if st not in (200, 201):
            print(f"::warning::TestFlight grubu oluşturulamadı ({st}): {errtext(res)}")
            return
        gid = res["data"]["id"]
        print("TestFlight dahili grubu oluşturuldu: Dahili Test")
    st, res = call("GET", f"/betaGroups/{gid}/betaTesters?limit=200")
    if st == 200 and any(t["attributes"].get("email", "").lower() == email for t in res.get("data", [])):
        print("Test kullanıcısı zaten grupta.")
        return
    st, res = call("POST", "/betaTesters", {"data": {"type": "betaTesters",
        "attributes": {"email": email, "firstName": "", "lastName": ""},
        "relationships": {"betaGroups": {"data": [{"type": "betaGroups", "id": gid}]}}}})
    if st in (200, 201):
        print("Test kullanıcısı gruba eklendi, TestFlight daveti gönderildi.")
        return
    st3, r3 = call("GET", "/betaTesters?" + urllib.parse.urlencode({"filter[email]": email}))
    ids = [t["id"] for t in r3.get("data", [])] if st3 == 200 else []
    if ids:
        st4, r4 = call("POST", f"/betaGroups/{gid}/relationships/betaTesters", {"data": [{"type": "betaTesters", "id": ids[0]}]})
        print("Mevcut test kullanıcısı gruba eklendi." if st4 in (200, 204) else f"::warning::Gruba eklenemedi ({st4}): {errtext(r4)}")
    else:
        print(f"::warning::Test kullanıcısı eklenemedi ({st}): {errtext(res)}")


_email = os.environ.get("TESTER_EMAIL", "").strip().lower()
if _email:
    try:
        testflight_internal_group(_email)
    except Exception as e:  # derlemeyi bu adım yüzünden durdurma
        print(f"::warning::TestFlight grup adımı atlandı: {e}")
