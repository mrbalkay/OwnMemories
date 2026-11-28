"""ownmemories-ml uçtan uca testi: geçici veritabanı + geçici depolama ile servisi çalıştırır.

    OM_TEST_DB_HOST=127.0.0.1 OM_TEST_DB_USER=om OM_TEST_DB_PASS=om \\
    python -I ml/tests/test_service.py --model-dir <models>/ViT-B-16-SigLIP2__webli --images <p1..p18.jpg klasörü>

Yapar: om_mltest_<pid> veritabanını kurar (schema.sql), örnek görselleri varlık + derived/thumb olarak ekler,
dizinleyicinin asset_embeddings'i doldurmasını bekler, /health /status /search'i, eksik dosya ertelemesini,
silme sonrası bellek önbelleğinin yenilenmesini ve hatalı istekleri dener; Temizlik için kalite ölçümlerini
(asset_quality) ve /cleanup/groups'u (WhatsApp benzeri kopya, aynı anda çekilmiş farklı fotoğraflar) denetler.
--ocr-model-dir <models>/ppocr-v6 verilirse fotoğraftaki yazıyı da (asset_text; tabela okunur, örnek fotoğraflarda
yazı çıkmaz) sınar; verilmezse OCR'ın modelsiz kapalı kaldığını denetler. Sonunda veritabanını siler.
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
import uuid

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))

import pymysql  # noqa: E402

from ownmemories_ml import config, service  # noqa: E402

FAILS: list[str] = []


def check(cond: bool, msg: str) -> None:
    print(("  ok   " if cond else "  FAIL ") + msg)
    if not cond:
        FAILS.append(msg)


def http(port: int, method: str, path: str, body: dict | None = None, raw: bytes | None = None):
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(f"http://127.0.0.1:{port}{path}", data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


def sql_statements(sql: str) -> list[str]:
    import re

    sql = re.sub(r"^\s*--.*$", "", sql, flags=re.M)
    return [p.strip() for p in re.split(r";\s*$", sql, flags=re.M) if p.strip()]


def test_cleanup(eng, port, cur, ids, storage, images) -> None:
    """Temizlik: kalite ölçümleri (asset_quality) ve /cleanup/groups."""
    from PIL import Image

    eng.quality_skip.clear()  # p12 dosyası sonradan geldi: erteleme süresini bekleme

    def wait(sql, want, secs=120):
        t0 = time.time()
        while time.time() - t0 < secs:
            cur.execute(sql)
            v = cur.fetchone()[0]
            if v == want:
                return v
            time.sleep(0.5)
        return v

    n = wait("SELECT COUNT(*) FROM asset_quality", 18)
    check(n == 18, f"kalite ölçümü: dosyası olan 18 varlık ({n})")
    cur.execute("SELECT MIN(LENGTH(color)), MIN(sharpness), MAX(is_screenshot), COUNT(phash) FROM asset_quality")
    cl, sh, shot, ph = cur.fetchone()
    check(cl == 48 and sh > 20 and shot == 0 and ph == 18, f"renk imzası 48 bayt, netlik, phash ({cl}, {sh}, {ph})")
    # Aynı anda "çekilmiş" (güvenilir zaman) ama farklı fotoğraflar gruplanmaz
    cur.execute("UPDATE assets SET date_source = 'exif', width = 800, height = 600")
    st, r = http(port, "POST", "/cleanup/groups", {"user_id": 1})
    check(st == 200 and r["groups"] == [] and r["cached"] is False, f"farklı fotoğraflar: grup yok ({r.get('groups')})")
    st, r2 = http(port, "POST", "/cleanup/groups", {"user_id": 1})
    check(r2.get("cached") is True, "değişiklik yoksa önbellekten")
    # WhatsApp benzeri kopya (küçültülmüş, düşük kalite, dosya tarihi): zamandan bağımsız eşleşir
    aid = str(uuid.uuid4())
    # Önce türev dosya, sonra 'ready' satırı (dizinleyici dosyasız gördüğü varlığı dakikalarca erteler).
    d = os.path.join(storage, "derived", "thumb", aid[:2])
    os.makedirs(d, exist_ok=True)
    im = Image.open(os.path.join(images, "p3.jpg")).convert("RGB")
    im.resize((600, 450), Image.Resampling.LANCZOS).save(os.path.join(d, aid + ".jpg"), "JPEG", quality=35)
    cur.execute("INSERT INTO assets (id, user_id, blob_sha1, type, original_filename, taken_at, date_source, width, "
                "height, source, thumb_state, created_at, updated_at) VALUES (%s,1,%s,'photo','IMG-WA0001.jpg',"
                "'2001-01-01','file',600,450,'web','ready',UTC_TIMESTAMP(),UTC_TIMESTAMP())", (aid, "c" * 40))
    wait(f"SELECT COUNT(*) FROM asset_quality q JOIN asset_embeddings e ON e.asset_id = q.asset_id "
         f"WHERE q.asset_id = '{aid}'", 1)
    time.sleep(1.2)  # kullanıcı dizini en çok saniyede bir tazelenir (UserIndex.checked)
    st, r = http(port, "POST", "/cleanup/groups", {"user_id": 1})
    g = r.get("groups") or []
    check(len(g) == 1 and sorted(g[0]["assets"]) == sorted([ids["p3"], aid]) and g[0]["reason"] == "duplicate",
          f"WhatsApp kopyası orijinalle 'duplicate' grubu ({g}, {r.get('indexed')}/{r.get('total')})")
    check(http(port, "POST", "/cleanup/groups", {"user_id": 2})[1]["groups"] == [], "başka kullanıcı: grup yok")
    cur.execute("UPDATE assets SET trashed_at = UTC_TIMESTAMP(), version = version + 1 WHERE id = %s", (aid,))
    check(http(port, "POST", "/cleanup/groups", {"user_id": 1})[1]["groups"] == [], "çöpe giden kopya grubu bozar")
    check(http(port, "POST", "/cleanup/groups", {"user_id": 0})[0] == 400, "/cleanup/groups user_id=0 -> 400")
    st, h = http(port, "GET", "/health")
    check(h.get("cleanup") is True, "/health cleanup: true")


def test_ocr(eng, port, cur, ids, storage) -> None:
    """Fotoğraftaki yazı: asset_text satırları, tabelada 'eczane', yazısız örneklerde boş yazı (sanrı yok)."""
    from PIL import Image, ImageDraw, ImageFont

    from ownmemories_ml import ocr as ocrmod

    aid = str(uuid.uuid4())
    im = Image.new("RGB", (1200, 700), (20, 120, 60))
    dr = ImageDraw.Draw(im)
    try:
        big, small = ImageFont.truetype("DejaVuSans-Bold.ttf", 150), ImageFont.truetype("DejaVuSans.ttf", 60)
    except OSError:
        big = small = ImageFont.load_default(size=120)
    dr.text((140, 150), "ECZANE", font=big, fill="white")
    dr.text((260, 420), "Nöbetçi Eczane", font=small, fill="white")
    d = os.path.join(storage, "derived", "preview", aid[:2])
    os.makedirs(d, exist_ok=True)
    im.save(os.path.join(d, aid + ".jpg"), quality=90)
    # Önce dosya, sonra satır (üretimde thumb_state='ready' türevler yazıldıktan sonra konur).
    cur.execute("INSERT INTO assets (id, user_id, blob_sha1, type, original_filename, taken_at, source, thumb_state, "
                "created_at, updated_at) VALUES (%s,1,%s,'photo','IMG_0001.jpg',UTC_TIMESTAMP(),'web','ready',"
                "UTC_TIMESTAMP(),UTC_TIMESTAMP())", (aid, "e" * 40))
    t0 = time.time()
    n = 0
    while time.time() - t0 < 240:
        cur.execute("SELECT COUNT(*) FROM asset_text WHERE engine = %s", (ocrmod.ENGINE,))
        n = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM asset_text WHERE asset_id = %s", (aid,))
        if cur.fetchone()[0] and n >= 17:
            break
        time.sleep(0.5)
    print(f"  OCR: {n} satır, {time.time() - t0:.1f} sn")
    check(n >= 17, f"asset_text: dosyası olan fotoğraflar tarandı ({n})")
    cur.execute("SELECT text, folded, words, lang, user_id FROM asset_text WHERE asset_id = %s", (aid,))
    row = cur.fetchone()
    check(row is not None and " eczane " in row[1] and " nobetci " in row[1] and row[2] >= 3 and row[3] == "tr"
          and row[4] == 1, f"tabela okundu: {row}")
    cur.execute("SELECT COUNT(*) FROM asset_text WHERE asset_id <> %s AND words > 0", (aid,))
    noisy = cur.fetchone()[0]
    cur.execute("SELECT GROUP_CONCAT(text SEPARATOR ' | ') FROM asset_text WHERE asset_id <> %s AND words > 0", (aid,))
    check(noisy <= 1, f"yazısız örnek fotoğraflarda yazı yok ya da yok denecek kadar az ({noisy}: {cur.fetchone()[0]})")
    st, h = http(port, "GET", "/health")
    check(h.get("ocr") is True, "/health ocr: true")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--model-dir", required=True)
    ap.add_argument("--images", required=True)
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--ocr-model-dir", default="", help="ppocr-v6 klasörü (verilirse fotoğraftaki yazı da sınanır)")
    a = ap.parse_args()
    host = os.environ.get("OM_TEST_DB_HOST", "127.0.0.1")
    user = os.environ.get("OM_TEST_DB_USER", "om")
    pw = os.environ.get("OM_TEST_DB_PASS", "om")
    dbname = f"om_mltest_{os.getpid()}"
    root = pymysql.connect(host=host, user=user, password=pw, autocommit=True, charset="utf8mb4")
    root.cursor().execute(f"CREATE DATABASE `{dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    storage = tempfile.mkdtemp(prefix="om-ml-store-")
    try:
        c = pymysql.connect(host=host, user=user, password=pw, database=dbname, autocommit=True, charset="utf8mb4")
        cur = c.cursor()
        schema = open(os.path.join(HERE, "..", "..", "web", "install", "schema.sql"), encoding="utf-8").read()
        for st in sql_statements(schema):
            cur.execute(st)
        names = sorted((f[:-4] for f in os.listdir(a.images) if f.endswith(".jpg")), key=lambda n: int(n[1:]))
        ids: dict[str, str] = {}
        for i, n in enumerate(names):
            aid = str(uuid.uuid4())
            ids[n] = aid
            uid = 2 if n == "p18" else 1  # p18 başka kullanıcıya ait
            cur.execute(
                "INSERT INTO assets (id, user_id, blob_sha1, type, original_filename, taken_at, source, thumb_state, "
                "created_at, updated_at) VALUES (%s,%s,%s,'photo',%s,UTC_TIMESTAMP(),'web','ready',UTC_TIMESTAMP(),"
                "UTC_TIMESTAMP())", (aid, uid, f"{i:040d}", n + ".jpg"))
            if n == "p12":
                continue  # türev dosyası yok: dizinleyici ertelemeli
            d = os.path.join(storage, "derived", "thumb", aid[:2])
            os.makedirs(d, exist_ok=True)
            shutil.copy(os.path.join(a.images, n + ".jpg"), os.path.join(d, aid + ".jpg"))
        # bekleyen (thumb_state != ready) varlık dizinlenmemeli
        cur.execute("INSERT INTO assets (id, user_id, blob_sha1, type, taken_at, source, thumb_state, created_at, "
                    "updated_at) VALUES (%s,1,%s,'photo',UTC_TIMESTAMP(),'web','pending',UTC_TIMESTAMP(),"
                    "UTC_TIMESTAMP())", (str(uuid.uuid4()), "f" * 40))

        cfg = config.load({
            "OM_DB_HOST": host, "OM_DB_USER": user, "OM_DB_PASS": pw, "OM_DB_NAME": dbname, "OM_STORAGE": storage,
            "OM_ML_MODEL_DIR": os.path.dirname(a.model_dir.rstrip("/")),
            "OM_ML_MODEL": os.path.basename(a.model_dir.rstrip("/")), "OM_ML_THREADS": str(a.threads),
            "OM_ML_IDLE": "1", "OM_CONFIG_PHP": "/nonexistent",
            "OM_OCR_MODEL_DIR": a.ocr_model_dir or "/nonexistent-ocr",
        })
        eng = service.Engine(cfg)
        srv = service.Server(("127.0.0.1", 0), service.make_handler(eng))
        port = srv.server_address[1]
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        st, h = http(port, "GET", "/health")
        check(st == 200 and h["ok"] is True, f"/health yanıt veriyor: {h}")
        st, r = http(port, "POST", "/search", {"user_id": 1, "text": "deniz"})
        check(st == 503, "model yüklenmeden /search 503")
        threading.Thread(target=eng.load_text, daemon=True).start()
        threading.Thread(target=eng.index_loop, daemon=True).start()
        t0 = time.time()
        while time.time() - t0 < 180:
            st, h = http(port, "GET", "/health")
            if h.get("ready") and h.get("indexed") == len(names) - 1:
                break
            time.sleep(0.5)
        print(f"  dizinleme + model yükleme: {time.time() - t0:.1f} sn")
        check(h.get("indexed") == len(names) - 1, f"17 varlık dizinlendi (p12 dosyasız): {h}")
        check(h.get("pending") == 1 and h.get("dim") == 768, "pending=1 (dosyası olmayan), dim=768")
        cur.execute("SELECT COUNT(*), MIN(LENGTH(vec)), MAX(LENGTH(vec)) FROM asset_embeddings")
        n, mn, mx = cur.fetchone()
        check(n == 17 and mn == mx == 768 * 4, f"asset_embeddings: {n} satır, vec {mn} bayt")
        st, s = http(port, "GET", "/status?user_id=1")
        check(s == {"indexed": 16, "total": 17, "pending": 1, "model": cfg.model_name, "ready": True},
              f"/status user 1: {s}")

        def top(q, uid=1, limit=3, **kw):
            st, r = http(port, "POST", "/search", {"user_id": uid, "text": q, "limit": limit, **kw})
            assert st == 200, r
            inv = {v: k for k, v in ids.items()}
            return [(inv[x["id"]], x["score"]) for x in r["results"]]

        for q, want in [("denizanası", "p5"), ("castle", "p2"), ("asma kilitler", "p10"), ("orman", "p7"),
                        ("buğday tarlası", "p3"), ("tren istasyonu", "p4"), ("jellyfish", "p5"),
                        ("floating market", "p6"), ("Kahve Fincanı!", "p9")]:
            t = top(q)
            check(t[0][0] in (want, "p13" if want == "p7" else want), f"'{q}' -> {t}")
        check("p2" in [n for n, _ in top("şato")], "'şato' -> p2 ilk 3'te")
        r = top("klavye", uid=1, limit=50)
        check(all(n != "p18" for n, _ in r), "başka kullanıcının varlığı sonuçlarda yok")
        check(top("klavye", uid=2)[0][0] == "p18", "kullanıcı 2 kendi varlığını buluyor")
        r = top("deniz", limit=50)
        check(len(r) == 16 and all(r[i][1] >= r[i + 1][1] for i in range(len(r) - 1)), "sonuçlar skora göre azalan")
        r = top("deniz", limit=50, min_score=0.1)
        check(all(x[1] >= 0.1 for x in r) and len(r) < 16, f"min_score süzüyor ({len(r)} sonuç)")
        st, r = http(port, "POST", "/search", {"user_id": 3, "text": "deniz"})
        check(st == 200 and r["results"] == [], "vektörü olmayan kullanıcı: boş sonuç")

        # silme -> önbellek yenilenmeli
        p5 = os.path.join(storage, "derived", "thumb", ids["p5"][:2], ids["p5"] + ".jpg")
        os.rename(p5, p5 + ".bak")  # dizinleyici hemen yeniden üretmesin
        cur.execute("DELETE FROM asset_embeddings WHERE asset_id = %s", (ids["p5"],))
        time.sleep(1.1)
        check(top("denizanası")[0][0] != "p5", "silinen vektör aramada görünmüyor")
        # eksik dosya geldi -> erteleme süresi dolunca dizinlenir (testte ertelemeyi sıfırla)
        d = os.path.join(storage, "derived", "preview", ids["p12"][:2])
        os.makedirs(d, exist_ok=True)
        shutil.copy(os.path.join(a.images, "p12.jpg"), os.path.join(d, ids["p12"] + ".jpg"))
        os.rename(p5 + ".bak", p5)
        eng.skip.clear()
        t0 = time.time()
        while time.time() - t0 < 30:
            cur.execute("SELECT COUNT(*) FROM asset_embeddings")
            if cur.fetchone()[0] == 18:
                break
            time.sleep(0.5)
        time.sleep(1.1)
        check(top("sisli ağaçlar")[0][0] == "p12", "sonradan gelen önizleme dizinlendi ve aranabiliyor (artımlı)")
        check(top("denizanası")[0][0] == "p5", "silinen vektör yeniden üretildi")

        # model değişimi: eski model satırları yok sayılır
        cur.execute("UPDATE asset_embeddings SET model = 'eski-model' WHERE asset_id = %s", (ids["p10"],))
        eng.stats_cache.clear()
        st, h = http(port, "GET", "/health")
        check(h["pending"] >= 1 or h["indexed"] == 17, "eski modelin satırı bekleyen sayılıyor / yeniden kodlanıyor")

        # hatalı istekler
        check(http(port, "POST", "/search", {"user_id": 0, "text": "x"})[0] == 400, "user_id=0 -> 400")
        check(http(port, "POST", "/search", {"user_id": 1, "text": "  "})[0] == 400, "boş metin -> 400")
        check(http(port, "POST", "/search", raw=b"{bozuk")[0] == 400, "bozuk JSON -> 400")
        check(http(port, "GET", "/status")[0] == 400, "/status user_id'siz -> 400")
        check(http(port, "GET", "/yok")[0] == 404, "bilinmeyen yol -> 404")
        st, r = http(port, "POST", "/search", {"user_id": 1, "text": "deniz", "limit": 10000})
        check(st == 200 and len(r["results"]) <= service.MAX_LIMIT, "limit 500 ile sınırlı")
        test_cleanup(eng, port, cur, ids, storage, a.images)
        if a.ocr_model_dir:
            test_ocr(eng, port, cur, ids, storage)
        else:
            st, h = http(port, "GET", "/health")
            check(h.get("ocr") is False and "OCR modeli yok" in (h.get("ocr_error") or ""),
                  f"OCR modeli yoksa kapalı, hata açıklaması var ({h.get('ocr_error')})")
        eng.stop.set()
        srv.shutdown()
    finally:
        root.cursor().execute(f"DROP DATABASE IF EXISTS `{dbname}`")
        shutil.rmtree(storage, ignore_errors=True)
    print("SONUÇ:", "BAŞARILI" if not FAILS else f"{len(FAILS)} HATA")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
