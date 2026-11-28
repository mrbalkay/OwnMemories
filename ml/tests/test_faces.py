"""Yüz tanıma uçtan uca testi: geçici veritabanı + geçici depolama, gerçek buffalo_l modeli, LFW görselleri.

    OM_TEST_DB_HOST=127.0.0.1 OM_TEST_DB_USER=om OM_TEST_DB_PASS=om \\
    python -I ml/tests/test_faces.py --face-model-dir <models>/buffalo_l --lfw <lfw kökü (Ad_Soyad/*.jpg)>

Yapar: om_facetest_<pid> veritabanını kurar (schema.sql; yüz tabloları yoksa sözleşmedeki DDL), 3 kişinin 5'er
fotoğrafını + yüzsüz bir görseli + iki kişilik bir kolajı derived/preview olarak ekler, CLIP modeli olmadan
servisi çalıştırır (yüz kısmı tek başına çalışmalı) ve şunları dener: face_scans (yüzsüz görsel dahil), normalize
kutular, kümeleme (3 adsız kişi, saf), asset_person 'face' satırları, /faces/status, reddedilen kişiye yeniden
atanmama, kullanıcı atamasının korunması, elle etiketin (manual) bozulmaması, birleştirme sonrası yeniden
kümeleme, sonradan gelen fotoğrafın adlandırılmış kişiye artımlı atanması, kalıcı silme sonrası önbellek
yenilenmesi, hatalı istekler. Ayrıca yüz modeli yokken servisin ayakta kaldığını denetler. Sonunda veritabanını siler.
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
import uuid

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))
sys.path.insert(0, HERE)

import pymysql  # noqa: E402
from PIL import Image  # noqa: E402

from ownmemories_ml import config, service  # noqa: E402
from test_service import FAILS, check, http, sql_statements  # noqa: E402

FACE_DDL = """
CREATE TABLE IF NOT EXISTS people (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NULL, cover_face_id BIGINT UNSIGNED NULL, hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY ix_people_user (user_id),
  KEY ix_people_name (user_id, name));
CREATE TABLE IF NOT EXISTS asset_person (asset_id CHAR(36) NOT NULL, person_id INT UNSIGNED NOT NULL,
  source ENUM('manual','takeout','face') NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (asset_id, person_id),
  KEY ix_ap_person (person_id));
CREATE TABLE IF NOT EXISTS faces (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_id CHAR(36) NOT NULL,
  user_id INT UNSIGNED NOT NULL, person_id INT UNSIGNED NULL, rejected_person_id INT UNSIGNED NULL, x1 FLOAT, y1 FLOAT,
  x2 FLOAT, y2 FLOAT, score FLOAT NOT NULL, embedding BLOB NOT NULL, model VARCHAR(80) NOT NULL,
  assigned_by ENUM('auto','user') NULL, created_at DATETIME NOT NULL, KEY ix_faces_asset (asset_id),
  KEY ix_faces_user_person (user_id, person_id));
CREATE TABLE IF NOT EXISTS face_scans (asset_id CHAR(36) PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
  model VARCHAR(80) NOT NULL, faces INT NOT NULL, scanned_at DATETIME NOT NULL, KEY ix_fs_user (user_id));
"""

IDENTITIES = ("George_W_Bush", "Colin_Powell", "Tony_Blair")
LATE = "Colin_Powell"


def wait_for(fn, timeout: float = 120.0, step: float = 0.3):
    t0 = time.time()
    v = fn()
    while not v and time.time() - t0 < timeout:
        time.sleep(step)
        v = fn()
    return v


def main() -> int:
    import logging

    logging.basicConfig(level=logging.INFO, stream=sys.stderr, format="%(levelname)s %(threadName)s %(message)s")
    ap = argparse.ArgumentParser()
    ap.add_argument("--face-model-dir", required=True)
    ap.add_argument("--lfw", required=True)
    ap.add_argument("--threads", type=int, default=4)
    a = ap.parse_args()
    host = os.environ.get("OM_TEST_DB_HOST", "127.0.0.1")
    user = os.environ.get("OM_TEST_DB_USER", "om")
    pw = os.environ.get("OM_TEST_DB_PASS", "om")
    dbname = f"om_facetest_{os.getpid()}"
    root = pymysql.connect(host=host, user=user, password=pw, autocommit=True, charset="utf8mb4")
    root.cursor().execute(f"CREATE DATABASE `{dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    storage = tempfile.mkdtemp(prefix="om-face-store-")
    eng = srv = None
    try:
        c = pymysql.connect(host=host, user=user, password=pw, database=dbname, autocommit=True, charset="utf8mb4")
        cur = c.cursor()
        schema = open(os.path.join(HERE, "..", "..", "web", "install", "schema.sql"), encoding="utf-8").read()
        for st in sql_statements(schema) + sql_statements(FACE_DDL.replace(");", ");\n")):
            cur.execute(st)

        seq = [0]

        def add_asset(img_path: str | None, uid: int = 1, img: Image.Image | None = None, ext: str = "jpg") -> str:
            seq[0] += 1
            aid = str(uuid.uuid4())
            # önce dosya, sonra satır (PHP de türevleri üretip thumb_state='ready' yapar)
            d = os.path.join(storage, "derived", "preview", aid[:2])
            os.makedirs(d, exist_ok=True)
            # LFW 250x250; önizlemeler gerçekte ~1440 px: 2x büyüt (yüzler ~100-200 px)
            im = img if img is not None else Image.open(img_path).convert("RGB").resize((500, 500))
            im.save(os.path.join(d, f"{aid}.{ext}"), quality=92)
            cur.execute(
                "INSERT INTO assets (id, user_id, blob_sha1, type, original_filename, taken_at, source, thumb_state, "
                "created_at, updated_at) VALUES (%s,%s,%s,'photo','x.jpg',UTC_TIMESTAMP(),'web','ready',"
                "UTC_TIMESTAMP(),UTC_TIMESTAMP())", (aid, uid, f"{seq[0]:040d}"))
            return aid

        ids: dict[str, list[str]] = {}
        for who in IDENTITIES:
            files = sorted(os.listdir(os.path.join(a.lfw, who)))
            take = files[:5] if who != LATE else files[:4]
            ids[who] = [add_asset(os.path.join(a.lfw, who, f)) for f in take]
        late_file = os.path.join(a.lfw, LATE, sorted(os.listdir(os.path.join(a.lfw, LATE)))[10])
        blank = add_asset(None, img=Image.linear_gradient("L").convert("RGB").resize((600, 400)), ext="webp")
        # kolaj: Bush + Blair yan yana (iki yüz)
        col = Image.new("RGB", (1000, 500))
        col.paste(Image.open(os.path.join(a.lfw, IDENTITIES[0], sorted(os.listdir(os.path.join(a.lfw, IDENTITIES[0])))[6])).convert("RGB").resize((500, 500)), (0, 0))  # noqa: E501
        col.paste(Image.open(os.path.join(a.lfw, IDENTITIES[2], sorted(os.listdir(os.path.join(a.lfw, IDENTITIES[2])))[6])).convert("RGB").resize((500, 500)), (500, 0))  # noqa: E501
        collage = add_asset(None, img=col)
        other_user = add_asset(os.path.join(a.lfw, IDENTITIES[0], sorted(os.listdir(os.path.join(a.lfw, IDENTITIES[0])))[7]), uid=2)  # noqa: E501
        missing = str(uuid.uuid4())  # türev dosyası olmayan varlık: ertelenmeli
        cur.execute("INSERT INTO assets (id, user_id, blob_sha1, type, taken_at, source, thumb_state, created_at, "
                    "updated_at) VALUES (%s,1,%s,'photo',UTC_TIMESTAMP(),'web','ready',UTC_TIMESTAMP(),"
                    "UTC_TIMESTAMP())", (missing, "e" * 40))
        # manuel etiket: Bush'un ilk fotoğrafına "Elle" kişisi (yüz atamasıyla çakışmamalı)
        cur.execute("INSERT INTO people (user_id, name, hidden, created_at, updated_at) VALUES "
                    "(1, 'Elle', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        manual_pid = cur.lastrowid
        cur.execute("INSERT INTO asset_person VALUES (%s, %s, 'manual', UTC_TIMESTAMP())",
                    (ids[IDENTITIES[0]][0], manual_pid))
        n_assets = 14 + 1 + 1 + 1  # 14 kişi fotoğrafı + yüzsüz + kolaj + diğer kullanıcı (+ dosyasız)

        mdir = os.path.abspath(a.face_model_dir.rstrip("/"))
        cfg = config.load({
            "OM_DB_HOST": host, "OM_DB_USER": user, "OM_DB_PASS": pw, "OM_DB_NAME": dbname, "OM_STORAGE": storage,
            "OM_ML_MODEL_DIR": os.path.dirname(mdir), "OM_ML_MODEL": "yok/olmayan-clip-modeli",
            "OM_FACE_MODEL": "immich-app/" + os.path.basename(mdir), "OM_ML_THREADS": str(a.threads),
            "OM_ML_IDLE": "1", "OM_CONFIG_PHP": "/nonexistent",
        })
        eng = service.Engine(cfg)
        eng.recluster_gap = 0.0  # testte istekler arka arkaya
        srv = service.Server(("127.0.0.1", 0), service.make_handler(eng))
        port = srv.server_address[1]
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        threading.Thread(target=eng.index_loop, daemon=True).start()
        t0 = time.time()

        def scans():
            cur.execute("SELECT COUNT(*) FROM face_scans")
            return cur.fetchone()[0] >= n_assets

        check(bool(wait_for(scans, 180)), "tüm varlıklar tarandı (CLIP modeli olmadan)")
        took = time.time() - t0
        cur.execute("SELECT COUNT(*) FROM people WHERE name IS NULL")

        def clustered():
            cur.execute("SELECT COUNT(*) FROM people WHERE name IS NULL")
            return cur.fetchone()[0] >= 3

        wait_for(clustered, 30)
        print(f"  tarama + kümeleme: {time.time() - t0:.1f} sn ({took:.1f} sn tarama, model yükleme dahil)")
        st, h = http(port, "GET", "/health")
        check(h.get("faces") is True and h.get("indexer") is False, f"/health: yüz açık, CLIP kapalı: {h}")
        cur.execute("SELECT faces, model FROM face_scans WHERE asset_id = %s", (blank,))
        check(cur.fetchone() == (0, "buffalo_l"), "yüzsüz görsel face_scans'e faces=0 ile yazıldı")
        cur.execute("SELECT faces FROM face_scans WHERE asset_id = %s", (collage,))
        check(cur.fetchone() == (2,), "kolajda 2 yüz")
        cur.execute("SELECT COUNT(*) FROM face_scans WHERE asset_id = %s", (missing,))
        check(cur.fetchone()[0] == 0 and missing in eng.face_skip, "dosyası olmayan varlık ertelendi")
        cur.execute("SELECT MIN(LEAST(x1,y1,x2,y2)), MAX(GREATEST(x1,y1,x2,y2)), MIN(x2-x1), MIN(LENGTH(embedding)), "
                    "MAX(LENGTH(embedding)), COUNT(*) FROM faces")
        mn, mx, mw, lmin, lmax, nf = cur.fetchone()
        check(mn >= 0 and mx <= 1 and mw > 0 and lmin == lmax == 2048, f"kutular 0..1, vektör 2048 bayt ({nf} yüz)")
        cur.execute("SELECT x1, x2 FROM faces WHERE asset_id = %s ORDER BY x1", (collage,))
        boxes = cur.fetchall()
        check(len(boxes) == 2 and boxes[0][1] <= 0.5 <= boxes[1][0], f"kolaj kutuları sol/sağ yarıda: {boxes}")

        def person_of(aid):
            cur.execute("SELECT person_id FROM faces WHERE asset_id = %s ORDER BY score DESC LIMIT 1", (aid,))
            r = cur.fetchone()
            return r[0] if r else None

        pids = {}
        for who in IDENTITIES:
            ps = [person_of(x) for x in ids[who]]
            pids[who] = ps[0]
            check(ps[0] is not None and len(set(ps)) == 1, f"{who}: tüm fotoğraflar tek kişide {ps}")
        check(len(set(pids.values())) == 3, f"3 farklı kişi: {pids}")
        cur.execute("SELECT person_id FROM faces WHERE asset_id = %s ORDER BY x1", (collage,))
        cp = [r[0] for r in cur.fetchall()]
        check(cp == [pids[IDENTITIES[0]], pids[IDENTITIES[2]]], f"kolajdaki yüzler doğru kişilerde: {cp}")
        cur.execute("SELECT COUNT(*) FROM faces WHERE user_id = 2 AND person_id IS NOT NULL")
        check(cur.fetchone()[0] == 0, "başka kullanıcının tek yüzü kümelenmedi (kullanıcılar ayrı)")
        cur.execute("SELECT p.id, p.cover_face_id, f.person_id FROM people p LEFT JOIN faces f ON f.id = p.cover_face_id "
                    "WHERE p.name IS NULL")
        rows = cur.fetchall()
        check(all(r[1] and r[2] == r[0] for r in rows), "adsız kişilerin kapağı kendi yüzü")
        cur.execute("SELECT source, COUNT(*) FROM asset_person GROUP BY source ORDER BY source")
        src = dict(cur.fetchall())
        check(src.get("face") == 16 and src.get("manual") == 1, f"asset_person: 16 'face' + 1 'manual': {src}")
        cur.execute("SELECT COUNT(*) FROM faces WHERE assigned_by = 'auto'")
        check(cur.fetchone()[0] == 16, "otomatik atamalar assigned_by='auto'")

        st, s = http(port, "GET", "/faces/status?user_id=1")
        cur.execute("SELECT COUNT(*) FROM faces WHERE user_id = 1")
        check(st == 200 and s["scanned"] == 16 and s["total"] == 17 and s["faces"] == cur.fetchone()[0]
              and s["people"] == 4
              and s["ready"] is True and s["available"] is True, f"/faces/status: {s}")

        # adlandır (PHP), bir yüzü kullanıcı Blair'e atasın, bir Bush yüzünü reddetsin
        bush, powell, blair = (pids[w] for w in IDENTITIES)
        cur.execute("UPDATE people SET name = 'Bush' WHERE id = %s", (bush,))
        cur.execute("SELECT id FROM faces WHERE asset_id = %s AND person_id = %s", (ids[IDENTITIES[0]][1], bush))
        rej_face = cur.fetchone()[0]
        cur.execute("UPDATE faces SET person_id = NULL, assigned_by = NULL, rejected_person_id = %s WHERE id = %s",
                    (bush, rej_face))
        cur.execute("DELETE FROM asset_person WHERE asset_id = %s AND person_id = %s", (ids[IDENTITIES[0]][1], bush))
        # kullanıcı bir Blair yüzünü Powell'a taşır (assigned_by='user'): kümeleme geri almamalı
        cur.execute("SELECT id FROM faces WHERE asset_id = %s AND person_id = %s", (ids[IDENTITIES[2]][2], blair))
        user_face = cur.fetchone()[0]
        cur.execute("UPDATE faces SET person_id = %s, assigned_by = 'user' WHERE id = %s", (powell, user_face))
        st, r = http(port, "POST", "/faces/recluster", {"user_id": 1})
        check(st == 200 and r.get("queued") is True, f"/faces/recluster kuyruğa aldı: {r}")
        time.sleep(3)
        cur.execute("SELECT person_id, rejected_person_id FROM faces WHERE id = %s", (rej_face,))
        check(cur.fetchone() == (None, bush), "reddedilen yüz aynı kişiye geri atanmadı")
        cur.execute("SELECT person_id, assigned_by FROM faces WHERE id = %s", (user_face,))
        check(cur.fetchone() == (powell, "user"), "kullanıcı ataması (assigned_by='user') taşınmadı")
        cur.execute("SELECT source FROM asset_person WHERE asset_id = %s AND person_id = %s",
                    (ids[IDENTITIES[0]][0], manual_pid))
        check(cur.fetchone() == ("manual",), "elle etiket korunuyor")

        # birleştirme (PHP): adsız Powell kümesi yeni adlı "Powell" kişisine birleştirilir
        cur.execute("INSERT INTO people (user_id, name, hidden, created_at, updated_at) VALUES "
                    "(1, 'Powell', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        named_powell = cur.lastrowid
        cur.execute("UPDATE faces SET person_id = %s WHERE person_id = %s", (named_powell, powell))
        cur.execute("UPDATE asset_person SET person_id = %s WHERE person_id = %s", (named_powell, powell))
        cur.execute("DELETE FROM people WHERE id = %s", (powell,))
        http(port, "POST", "/faces/recluster", {"user_id": 1})
        time.sleep(2)
        # sonradan gelen Powell fotoğrafı -> artımlı olarak adlı kişiye
        late = add_asset(late_file)
        eng.wake.set()
        ok_late = bool(wait_for(lambda: person_of(late) is not None, 60))
        check(ok_late, "yeni fotoğraf tarandı ve kümelendi")
        check(person_of(late) == named_powell, f"yeni Powell fotoğrafı birleştirilmiş adlı kişiye atandı "
                                                f"({person_of(late)} / {named_powell})")
        cur.execute("SELECT source FROM asset_person WHERE asset_id = %s", (late,))
        check(cur.fetchall() == (("face",),), "yeni fotoğraf için asset_person 'face'")
        cur.execute("SELECT COUNT(*) FROM people WHERE id = %s", (powell,))
        check(cur.fetchone()[0] == 0, "silinen kişi yeniden oluşmadı")

        # kalıcı silme (PHP): bir Blair varlığının yüzleri silinir -> önbellek baştan yüklenmeli
        gone = ids[IDENTITIES[2]][0]
        cur.execute("DELETE FROM faces WHERE asset_id = %s", (gone,))
        cur.execute("DELETE FROM face_scans WHERE asset_id = %s", (gone,))
        cur.execute("DELETE FROM asset_person WHERE asset_id = %s", (gone,))
        cur.execute("DELETE FROM assets WHERE id = %s", (gone,))
        st, r = http(port, "POST", "/faces/recluster", {"user_id": 1})
        time.sleep(2)
        fs = eng.clusterer.sets.get(1)
        cur.execute("SELECT COUNT(*) FROM faces WHERE user_id = 1")
        check(fs is not None and fs.n == cur.fetchone()[0], "silme sonrası bellek kümesi DB ile aynı")

        # hatalı istekler
        check(http(port, "GET", "/faces/status")[0] == 400, "/faces/status user_id'siz -> 400")
        check(http(port, "POST", "/faces/recluster", {"user_id": 0})[0] == 400, "recluster user_id=0 -> 400")
        check(http(port, "POST", "/faces/recluster", raw=b"{x")[0] == 400, "recluster bozuk JSON -> 400")
        eng.stop.set()
        eng.wake.set()
        srv.shutdown()

        # yüz modeli yok: servis ayakta, yüz kısmı kapalı, istekler hata vermez
        cfg2 = config.load({
            "OM_DB_HOST": host, "OM_DB_USER": user, "OM_DB_PASS": pw, "OM_DB_NAME": dbname, "OM_STORAGE": storage,
            "OM_ML_MODEL_DIR": storage, "OM_ML_MODEL": "yok/olmayan", "OM_FACE_MODEL": "immich-app/buffalo_l",
            "OM_ML_IDLE": "1", "OM_CONFIG_PHP": "/nonexistent",
        })
        eng = service.Engine(cfg2)
        srv = service.Server(("127.0.0.1", 0), service.make_handler(eng))
        port = srv.server_address[1]
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        th = threading.Thread(target=eng.index_loop, daemon=True)
        th.start()
        th.join(10)
        st, h = http(port, "GET", "/health")
        check(st == 200 and h["faces"] is False, f"model yokken /health çalışıyor: {h}")
        st, s = http(port, "GET", "/faces/status?user_id=1")
        check(st == 200 and s["available"] is False and s["ready"] is False and "error" in s
              and s["scanned"] == 16, f"model yokken /faces/status: {s}")
        st, r = http(port, "POST", "/faces/recluster", {"user_id": 1})
        check(st == 200 and r["queued"] is False, "model yokken recluster: queued=false")
        print("  ", json.dumps(s, ensure_ascii=False))
    finally:
        if eng is not None:
            eng.stop.set()
            eng.wake.set()
        if srv is not None:
            srv.shutdown()
        root.cursor().execute(f"DROP DATABASE IF EXISTS `{dbname}`")
        shutil.rmtree(storage, ignore_errors=True)
    print("SONUÇ:", "BAŞARILI" if not FAILS else f"{len(FAILS)} HATA")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
