"""ownmemories-ml HTTP servisi + arka plan dizinleyici (tek süreç).

Uç noktalar (yalnızca 127.0.0.1):
  GET  /health               {"ok","model","dim","indexed","pending","ready"}
  POST /search               {"user_id","text","limit"<=500,"min_score"} -> {"results":[{"id","score"}],"model"}
  GET  /status?user_id=N     {"indexed","total","pending","model","ready"}

Kullanıcı vektörleri bellekte (numpy, kullanıcı başına bir matris) tutulur; ilk aramada DB'den yüklenir,
her aramada COUNT/MAX(created_at) ile tazelik denetlenir: yeni satırlar eklenir, silinme varsa yeniden yüklenir.
"""
from __future__ import annotations

import ctypes
import json
import logging
import os
import signal
import sys
import threading
import time
from collections import OrderedDict
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

import numpy as np

from .config import Config

log = logging.getLogger("ownmemories-ml")

MAX_LIMIT = 500
VARIANTS = ("thumb", "preview", "poster")
EXTS = ("webp", "jpg")


def malloc_trim() -> None:
    """Model yüklemesinden kalan boş yığını işletim sistemine iade et (RSS'yi düşürür)."""
    try:
        ctypes.CDLL("libc.so.6").malloc_trim(0)
    except (OSError, AttributeError):
        pass


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None, microsecond=0)


class DB:
    """İş parçacığı başına bir pymysql bağlantısı."""

    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.local = threading.local()

    def conn(self):
        import pymysql

        c = getattr(self.local, "conn", None)
        if c is not None:
            try:
                c.ping(reconnect=True)
                return c
            except Exception:  # noqa: BLE001
                self.local.conn = None
        kw = {}
        if self.cfg.db_host == "localhost":
            # PHP (PDO) "localhost"ta Unix soketini kullanır; aynı MySQL kullanıcısıyla eşleşmek için biz de.
            sock = next((p for p in ("/run/mysqld/mysqld.sock", "/var/run/mysqld/mysqld.sock", "/tmp/mysql.sock")
                         if os.path.exists(p)), None)
            if sock:
                kw["unix_socket"] = sock
        c = pymysql.connect(
            host=self.cfg.db_host, port=self.cfg.db_port, user=self.cfg.db_user, password=self.cfg.db_pass,
            database=self.cfg.db_name, charset="utf8mb4", autocommit=True, connect_timeout=5,
            read_timeout=60, write_timeout=60, **kw,
        )
        with c.cursor() as cur:
            cur.execute("SET time_zone = '+00:00'")
        self.local.conn = c
        return c

    def query(self, sql: str, args=None) -> list[tuple]:
        with self.conn().cursor() as cur:
            cur.execute(sql, args)
            return list(cur.fetchall())

    def execute_many(self, sql: str, rows: list[tuple]) -> None:
        with self.conn().cursor() as cur:
            cur.executemany(sql, rows)

    def drop(self) -> None:
        c = getattr(self.local, "conn", None)
        self.local.conn = None
        if c is not None:
            try:
                c.close()
            except Exception:  # noqa: BLE001
                pass


MIN_N_FOR_CUT = 50  # bundan küçük kütüphanelerde dağılım istatistiği güvenilmez: kesme yok


def relevance_cut(scores: np.ndarray, min_z: float, abs_score: float | None) -> np.ndarray:
    """İstekte min_score yoksa uygulanan varsayılan ilgi eşiği (bool maske).

    Bir sorgunun kütüphanedeki puanlarının çoğu ilgisizdir; ilgili fotoğraflar bu yığından sıyrılır.
    Tutulur: z = (puan - ortalama) / std >= min_z  YA DA  puan >= abs_score (modele özgü "kesin ilgili"
    eşiği; sorguyla eşleşen fotoğraflar kütüphanenin büyük kısmı olduğunda z düşük kalır, bu yüzden).
    Hiçbiri geçmezse boş döner (PHP metin aramasına düşer: "köpek" araması köpeksiz kütüphanede sonuç vermez).
    """
    n = scores.shape[0]
    if n < MIN_N_FOR_CUT or min_z <= 0:
        return np.ones(n, dtype=bool)
    sd = float(scores.std())
    keep = scores >= float(scores.mean()) + min_z * sd if sd > 1e-6 else np.ones(n, dtype=bool)
    if abs_score:
        keep |= scores >= abs_score
    return keep


class UserIndex:
    __slots__ = ("ids", "mat", "count", "max_created", "checked", "lock")

    def __init__(self, dim: int):
        self.ids: list[str] = []
        self.mat = np.zeros((0, dim), dtype=np.float32)
        self.count = 0
        self.max_created = None
        self.checked = 0.0
        self.lock = threading.RLock()


class Engine:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.db = DB(cfg)
        self.visual = None
        self.text = None
        self.dim = 768
        self.error: str | None = None
        self.users: dict[int, UserIndex] = {}
        self.users_lock = threading.Lock()
        self.qcache: OrderedDict[str, np.ndarray] = OrderedDict()
        self.qlock = threading.Lock()
        self.stats_cache: dict[str, tuple[float, dict]] = {}
        self.stop = threading.Event()
        self.text_ready = threading.Event()
        self.visual_ready = threading.Event()
        self.skip: dict[str, tuple[float, int]] = {}  # asset_id -> (yeniden deneme zamanı, deneme sayısı)
        self.indexed_session = 0

    # ---------- modeller ----------
    def load_text(self) -> None:
        from .model import TextEncoder, text_model_path

        try:
            path = text_model_path(self.cfg.model_dir, self.cfg.text_quant)
            t0 = time.time()
            self.text = TextEncoder(self.cfg.model_dir, self.cfg.threads, onnx_file=path, language=self.cfg.language)
            self.dim = self.text.dim
            self.text.embed("başlangıç")
            malloc_trim()
            log.info("metin modeli yüklendi: %s (%.1f sn)", os.path.basename(path), time.time() - t0)
            self.text_ready.set()
        except Exception as e:  # noqa: BLE001
            self.error = f"metin modeli yüklenemedi: {e}"
            log.exception("metin modeli yüklenemedi")

    def load_visual(self) -> None:
        from .model import VisualEncoder

        t0 = time.time()
        self.visual = VisualEncoder(self.cfg.model_dir, self.cfg.threads)
        malloc_trim()
        log.info("görsel model yüklendi (%.1f sn)", time.time() - t0)
        self.visual_ready.set()

    @property
    def ready(self) -> bool:
        return self.text_ready.is_set()

    # ---------- arama ----------
    def embed_query(self, text: str) -> np.ndarray:
        key = text.strip()
        with self.qlock:
            v = self.qcache.get(key)
            if v is not None:
                self.qcache.move_to_end(key)
                return v
        v = self.text.embed(key)
        with self.qlock:
            self.qcache[key] = v
            while len(self.qcache) > 256:
                self.qcache.popitem(last=False)
        return v

    def _load_rows(self, user_id: int, since=None) -> tuple[list[str], np.ndarray]:
        model = self.cfg.model_name
        if since is None:
            rows = self.db.query("SELECT asset_id, vec FROM asset_embeddings WHERE user_id = %s AND model = %s",
                                 (user_id, model))
        else:
            rows = self.db.query("SELECT asset_id, vec FROM asset_embeddings WHERE user_id = %s AND model = %s "
                                 "AND created_at >= %s", (user_id, model, since))
        ids, vecs = [], []
        for aid, blob in rows:
            v = np.frombuffer(blob, dtype="<f4")
            if v.shape[0] != self.dim:
                continue
            ids.append(aid)
            vecs.append(v)
        mat = np.vstack(vecs).astype(np.float32, copy=False) if vecs else np.zeros((0, self.dim), dtype=np.float32)
        return ids, mat

    def user_index(self, user_id: int) -> UserIndex:
        with self.users_lock:
            ui = self.users.get(user_id)
            if ui is None:
                ui = self.users[user_id] = UserIndex(self.dim)
        with ui.lock:
            if time.time() - ui.checked < 1.0 and ui.count:
                return ui
            cnt, mx = self.db.query(
                "SELECT COUNT(*), MAX(created_at) FROM asset_embeddings WHERE user_id = %s AND model = %s",
                (user_id, self.cfg.model_name))[0]
            cnt = int(cnt or 0)
            if cnt == ui.count and mx == ui.max_created:
                ui.checked = time.time()
                return ui
            if ui.count and ui.max_created is not None and cnt > ui.count and mx is not None and mx >= ui.max_created:
                ids, mat = self._load_rows(user_id, since=ui.max_created)
                pos = {a: i for i, a in enumerate(ui.ids)}
                add_ids, add_rows = [], []
                for k, a in enumerate(ids):
                    i = pos.get(a)
                    if i is None:
                        add_ids.append(a)
                        add_rows.append(k)
                    else:
                        ui.mat[i] = mat[k]  # aynı varlık yeniden kodlandı
                if add_rows:
                    ui.mat = np.vstack([ui.mat, mat[add_rows]])
                    ui.ids.extend(add_ids)
                if len(ui.ids) != cnt:  # tutarsız (silme + ekleme): tam yükle
                    ui.ids, ui.mat = self._load_rows(user_id)
            else:
                ui.ids, ui.mat = self._load_rows(user_id)
            ui.count = cnt
            ui.max_created = mx
            ui.checked = time.time()
            return ui

    def search(self, user_id: int, text: str, limit: int, min_score: float | None) -> list[dict]:
        q = self.embed_query(text)
        ui = self.user_index(user_id)
        with ui.lock:
            mat, ids = ui.mat, ui.ids
        n = mat.shape[0]
        if n == 0:
            return []
        scores = mat @ q
        if min_score is not None:
            keep = scores >= min_score
        else:
            keep = relevance_cut(scores, self.cfg.min_z, self.cfg.min_score)
        cand = np.flatnonzero(keep)
        k = min(limit, cand.size)
        if k == 0:
            return []
        sc = scores[cand]
        part = np.argpartition(-sc, k - 1)[:k] if k < cand.size else np.arange(cand.size)
        idx = cand[part[np.argsort(-sc[part], kind="stable")]]
        return [{"id": ids[i], "score": round(float(scores[i]), 5)} for i in idx]

    # ---------- istatistik ----------
    def _cached(self, key: str, ttl: float, fn):
        hit = self.stats_cache.get(key)
        if hit and time.time() - hit[0] < ttl:
            return hit[1]
        val = fn()
        self.stats_cache[key] = (time.time(), val)
        return val

    def global_stats(self) -> dict:
        def f():
            m = self.cfg.model_name
            indexed = self.db.query("SELECT COUNT(*) FROM asset_embeddings WHERE model = %s", (m,))[0][0]
            pending = self.db.query(
                "SELECT COUNT(*) FROM assets a LEFT JOIN asset_embeddings e ON e.asset_id = a.id AND e.model = %s "
                "WHERE a.thumb_state = 'ready' AND e.asset_id IS NULL", (m,))[0][0]
            return {"indexed": int(indexed), "pending": int(pending)}
        return self._cached("global", 5.0, f)

    def user_stats(self, user_id: int) -> dict:
        def f():
            m = self.cfg.model_name
            total, indexed = self.db.query(
                "SELECT COUNT(*), COUNT(e.asset_id) FROM assets a "
                "LEFT JOIN asset_embeddings e ON e.asset_id = a.id AND e.model = %s "
                "WHERE a.user_id = %s AND a.thumb_state = 'ready'", (m, user_id))[0]
            return {"indexed": int(indexed or 0), "total": int(total or 0)}
        return self._cached(f"user:{user_id}", 3.0, f)

    # ---------- dizinleyici ----------
    def source_path(self, aid: str) -> str | None:
        root = self.cfg.storage
        found = None
        for variant in VARIANTS:
            for ext in EXTS:
                p = f"{root}/derived/{variant}/{aid[:2]}/{aid}.{ext}"
                if os.path.isfile(p):
                    if variant != "thumb":
                        return p
                    found = p
                    break
            if found:
                # Küçük resim modelin girdisinden (224) küçükse önizlemeyi tercih et.
                try:
                    from PIL import Image

                    with Image.open(found) as im:
                        if min(im.size) >= self.visual.size:
                            return found
                except Exception:  # noqa: BLE001
                    pass
                for ext in EXTS:
                    p = f"{root}/derived/preview/{aid[:2]}/{aid}.{ext}"
                    if os.path.isfile(p):
                        return p
                return found
        return None

    def _defer(self, aid: str) -> None:
        _, n = self.skip.get(aid, (0, 0))
        n += 1
        self.skip[aid] = (time.time() + min(6 * 3600, 300 * 2 ** n), n)

    def index_loop(self) -> None:
        try:
            tid = threading.get_native_id()
            os.setpriority(os.PRIO_PROCESS, tid, min(19, os.getpriority(os.PRIO_PROCESS, tid) + 5))
        except (OSError, AttributeError):
            pass
        try:
            self.load_visual()
        except Exception as e:  # noqa: BLE001
            self.error = f"görsel model yüklenemedi: {e}"
            log.exception("görsel model yüklenemedi; dizinleme kapalı")
            return
        backoff = 5.0
        cursor = ""
        model = self.cfg.model_name
        while not self.stop.is_set():
            try:
                rows = self.db.query(
                    "SELECT a.id, a.user_id FROM assets a "
                    "LEFT JOIN asset_embeddings e ON e.asset_id = a.id AND e.model = %s "
                    "WHERE a.thumb_state = 'ready' AND e.asset_id IS NULL AND a.id > %s "
                    "ORDER BY a.id LIMIT 512", (model, cursor))
                backoff = 5.0
            except Exception as e:  # noqa: BLE001
                log.warning("DB hatası (dizinleyici): %s; %.0f sn sonra yeniden", e, backoff)
                self.db.drop()
                self.stop.wait(backoff)
                backoff = min(60.0, backoff * 2)
                continue
            if not rows:
                cursor = ""
                self.stop.wait(self.cfg.idle)
                continue
            cursor = rows[-1][0]
            now = time.time()
            todo = [(a, u) for a, u in rows if self.skip.get(a, (0, 0))[0] <= now]
            for i in range(0, len(todo), self.cfg.batch):
                if self.stop.is_set():
                    break
                self._index_batch(todo[i:i + self.cfg.batch])

    def _index_batch(self, batch: list[tuple[str, int]]) -> None:
        arrays, meta = [], []
        for aid, uid in batch:
            p = self.source_path(aid)
            if p is None:
                self._defer(aid)
                continue
            try:
                arrays.append(self.visual.load(p))
                meta.append((aid, uid))
            except Exception as e:  # noqa: BLE001
                log.info("görüntü okunamadı %s: %s", aid, e)
                self._defer(aid)
        if not arrays:
            return
        vecs = self.visual.embed_arrays(arrays)
        now = utcnow()
        rows = [(aid, uid, self.cfg.model_name, vecs[k].astype("<f4").tobytes(), now)
                for k, (aid, uid) in enumerate(meta)]
        for attempt in range(5):
            try:
                self.db.execute_many(
                    "INSERT INTO asset_embeddings (asset_id, user_id, model, vec, created_at) "
                    "VALUES (%s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), "
                    "model = VALUES(model), vec = VALUES(vec), created_at = VALUES(created_at)", rows)
                break
            except Exception as e:  # noqa: BLE001
                log.warning("DB yazma hatası: %s", e)
                self.db.drop()
                if self.stop.wait(min(60, 5 * 2 ** attempt)):
                    return
        else:
            return
        for aid, _ in meta:
            self.skip.pop(aid, None)
        self.indexed_session += len(rows)
        if self.indexed_session % 500 < len(rows):
            log.info("dizinlenen (bu oturum): %d", self.indexed_session)


def make_handler(engine: Engine):
    class Handler(BaseHTTPRequestHandler):
        server_version = "ownmemories-ml/1.0"
        protocol_version = "HTTP/1.1"

        def log_message(self, fmt, *args):  # erişim günlüğü yok (sorgular kişisel veri)
            return

        def _send(self, code: int, obj: dict) -> None:
            body = json.dumps(obj, ensure_ascii=False).encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            u = urlparse(self.path)
            try:
                if u.path == "/health":
                    st = {"indexed": 0, "pending": 0}
                    db_ok = True
                    try:
                        st = engine.global_stats()
                    except Exception as e:  # noqa: BLE001
                        db_ok = False
                        log.warning("DB hatası (/health): %s", e)
                        engine.db.drop()
                    out = {"ok": True, "model": engine.cfg.model_name, "dim": engine.dim, **st,
                           "ready": engine.ready and db_ok, "indexer": engine.visual_ready.is_set()}
                    if engine.error:
                        out["error"] = engine.error
                    if not db_ok:
                        out["error"] = "veritabanına bağlanılamadı"
                    return self._send(200, out)
                if u.path == "/status":
                    qs = parse_qs(u.query)
                    try:
                        uid = int(qs.get("user_id", ["0"])[0])
                    except ValueError:
                        return self._send(400, {"error": "user_id gerekli"})
                    if uid <= 0:
                        return self._send(400, {"error": "user_id gerekli"})
                    st = engine.user_stats(uid)
                    return self._send(200, {
                        "indexed": st["indexed"], "total": st["total"],
                        "pending": max(0, st["total"] - st["indexed"]), "model": engine.cfg.model_name,
                        "ready": engine.ready and st["indexed"] > 0})
                return self._send(404, {"error": "bulunamadı"})
            except Exception as e:  # noqa: BLE001
                log.exception("GET %s", u.path)
                engine.db.drop()
                return self._send(500, {"error": str(e)})

        def do_POST(self):
            u = urlparse(self.path)
            if u.path != "/search":
                return self._send(404, {"error": "bulunamadı"})
            try:
                n = int(self.headers.get("Content-Length") or 0)
            except ValueError:
                n = 0
            if n <= 0 or n > 65536:
                return self._send(400, {"error": "geçersiz gövde"})
            try:
                req = json.loads(self.rfile.read(n).decode("utf-8"))
                uid = int(req.get("user_id") or 0)
                text = str(req.get("text") or "").strip()[:500]
                limit = max(1, min(MAX_LIMIT, int(req.get("limit") or 100)))
                ms = req.get("min_score")
                min_score = float(ms) if ms is not None else None
            except (ValueError, TypeError, AttributeError):
                return self._send(400, {"error": "geçersiz istek"})
            if uid <= 0 or not text:
                return self._send(400, {"error": "user_id ve text gerekli"})
            if not engine.ready:
                return self._send(503, {"error": "model hazır değil", "results": [], "model": engine.cfg.model_name})
            try:
                t0 = time.perf_counter()
                res = engine.search(uid, text, limit, min_score)
                return self._send(200, {"results": res, "model": engine.cfg.model_name,
                                        "took_ms": round((time.perf_counter() - t0) * 1000, 1)})
            except Exception as e:  # noqa: BLE001
                log.exception("arama hatası")
                engine.db.drop()
                return self._send(500, {"error": str(e), "results": [], "model": engine.cfg.model_name})

    return Handler


class Server(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def serve(cfg: Config) -> int:
    logging.basicConfig(level=logging.INFO, stream=sys.stderr, format="%(levelname)s %(message)s")
    if not os.path.isdir(cfg.model_dir):
        log.error("model dizini yok: %s (python -m ownmemories_ml download)", cfg.model_dir)
        return 2
    engine = Engine(cfg)
    httpd = Server(("127.0.0.1", cfg.port), make_handler(engine))
    log.info("dinleniyor: 127.0.0.1:%d model=%s threads=%d storage=%s", cfg.port, cfg.model_name, cfg.threads,
             cfg.storage)
    threading.Thread(target=engine.load_text, name="text-load", daemon=True).start()
    if cfg.index:
        threading.Thread(target=engine.index_loop, name="indexer", daemon=True).start()

    def _stop(*_):
        engine.stop.set()
        threading.Thread(target=httpd.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)
    try:
        httpd.serve_forever(poll_interval=0.5)
    finally:
        httpd.server_close()
    return 0
