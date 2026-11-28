"""ownmemories-ml HTTP servisi + arka plan dizinleyici (tek süreç).

Uç noktalar (yalnızca 127.0.0.1):
  GET  /health               {"ok","model","dim","indexed","pending","ready"}
  POST /search               {"user_id","text","limit"<=500,"min_score"} -> {"results":[{"id","score"}],"model"}
  GET  /status?user_id=N     {"indexed","total","pending","model","ready"}
  GET  /faces/status?user_id=N  {"scanned","total","faces","people","ready","available","model"}
  POST /faces/recluster      {"user_id"} -> {"queued":true}  (tam kümeleme geçişi; ör. birleştirme/adlandırma sonrası)
  POST /cleanup/groups       {"user_id"} -> {"groups":[{"assets":[id…],"reason","min_sim","max_hamming"}],
                             "analyzed","total","cached","took_ms"}  (Temizlik: benzer/yinelenen fotoğraf grupları)

Temizlik (cleanup.py): dizinleyici her turda kalite ölçümü olmayan görüntülerin phash/netlik/parlaklık değerlerini
türetilmiş önizlemeden hesaplayıp asset_quality'ye yazar (migration 008 yoksa sessizce bekler). Gruplar istek
anında CLIP vektörleri + çekim zamanı + phash ile hesaplanır ve kütüphane değişene kadar bellekte tutulur.

Fotoğraftaki yazı (ocr.py): CLIP işi bitince her turda OM_OCR_BATCH fotoğrafın önizlemesinden (2048 px) yazı
okunur ve asset_text'e yazılır (yazı yoksa boş satır: tarandı). OCR modeli (<models>/ppocr-v6) ya da tablo
(migration 011) yoksa sessizce kapalı kalır. Model/son işleme sürümü (ocr.ENGINE) değişince yeniden okunur.

Yüz tanıma (faces.py + clustering.py) aynı arka plan döngüsünde CLIP'le dönüşümlü çalışır: her turda bir CLIP
toplu işi, ardından birkaç görüntülük yüz işi (CLIP işi bitince yüz işi büyür). Yüz modeli ya da tabloları
(migration 006) yoksa yüz kısmı sessizce kapalı kalır; CLIP etkilenmez.

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
RECLUSTER_GAP = 30.0  # /faces/recluster: kullanıcı başına tam geçişler arası en az (sn)
DIRTY_GAP = 1800.0  # tarama kuyruğu boşalınca yapılan tam geçişler arası en az (sn)
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
        self.wake = threading.Event()
        # yüz tanıma
        self.faces = None  # faces.FaceModel
        self.face_error: str | None = None
        self.face_skip: dict[str, tuple[float, int]] = {}
        self.face_tables: tuple[float, bool] = (0.0, False)
        self.clusterer = None
        self.recluster: set[int] = set()  # tam geçiş istenen kullanıcılar
        self.recluster_lock = threading.Lock()
        self.face_new: dict[int, list[int]] = {}  # kullanıcı -> kümelenmemiş yeni yüz id'leri
        self.face_dirty: set[int] = set()  # kuyruk boşalınca tam geçiş yapılacak kullanıcılar
        self.face_initial_pass = False
        self.last_full: dict[int, float] = {}  # kullanıcı -> son tam geçiş zamanı
        self.recluster_gap = RECLUSTER_GAP
        self.dirty_gap = DIRTY_GAP
        self.clip_queue: list[tuple[str, int]] = []
        self.clip_cursor = ""
        self.face_queue: list[tuple[str, int]] = []
        self.face_cursor = ""
        self.faces_session = 0
        # temizlik
        self.quality_tables: tuple[float, bool] = (0.0, False)
        self.quality_queue: list[tuple[str, int, str, str, str, int, int]] = []
        self.quality_cursor = ""
        self.quality_skip: dict[str, tuple[float, int]] = {}
        self.quality_session = 0
        self.cleanup_cache: dict[int, tuple[tuple, dict]] = {}
        self.cleanup_locks: dict[int, threading.Lock] = {}
        self.cleanup_lock = threading.Lock()
        # fotoğraftaki yazı (OCR)
        self.ocr = None  # ocr.OcrModel
        self.ocr_error: str | None = None
        self.ocr_tables: tuple[float, bool] = (0.0, False)
        self.ocr_queue: list[tuple[str, int]] = []
        self.ocr_cursor = ""
        self.ocr_skip: dict[str, tuple[float, int]] = {}
        self.ocr_session = 0

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

    def load_faces(self) -> None:
        from . import faces as facemod

        if not self.cfg.face_model:
            self.face_error = "yüz tanıma kapalı (OM_FACE_MODEL)"
            return
        d = self.cfg.face_model_dir
        if not facemod.available(d):
            self.face_error = f"yüz modeli yok: {d} (python -m ownmemories_ml download --model {self.cfg.face_model})"
            log.warning("%s; yüz tanıma kapalı, akıllı arama etkilenmez", self.face_error)
            return
        try:
            t0 = time.time()
            self.faces = facemod.FaceModel(d, self.cfg.threads, min_score=self.cfg.face_min_score,
                                           min_size=self.cfg.face_min_size)
            malloc_trim()
            self.face_error = None
            log.info("yüz modeli yüklendi: %s (%.1f sn)", self.cfg.face_model_name, time.time() - t0)
        except Exception as e:  # noqa: BLE001
            self.faces = None
            self.face_error = f"yüz modeli yüklenemedi: {e}"
            log.exception("yüz modeli yüklenemedi; yüz tanıma kapalı")

    def load_ocr(self) -> None:
        from . import ocr as ocrmod

        if not self.cfg.ocr:
            self.ocr_error = "fotoğraftaki yazı kapalı (OM_OCR=0)"
            return
        d = self.cfg.ocr_model_dir
        if not ocrmod.available(d):
            self.ocr_error = f"OCR modeli yok: {d} (python -m ownmemories_ml download --model ocr)"
            log.warning("%s; fotoğraftaki yazı kapalı, akıllı arama etkilenmez", self.ocr_error)
            return
        try:
            t0 = time.time()
            self.ocr = ocrmod.OcrModel(d, self.cfg.threads, min_conf=self.cfg.ocr_min_conf,
                                       det_max_side=self.cfg.ocr_det_side)
            malloc_trim()
            self.ocr_error = None
            log.info("OCR modeli yüklendi: %s (%.1f sn)", ocrmod.ENGINE, time.time() - t0)
        except Exception as e:  # noqa: BLE001
            self.ocr = None
            self.ocr_error = f"OCR modeli yüklenemedi: {e}"
            log.exception("OCR modeli yüklenemedi; fotoğraftaki yazı kapalı")

    def ocr_tables_ok(self) -> bool:
        """asset_text tablosu (migration 011) var mı? 5 dakikada bir yeniden denetlenir."""
        t, ok = self.ocr_tables
        if ok or time.time() - t < 300:
            return ok
        try:
            ok = bool(self.db.query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() "
                "AND table_name = 'asset_text'"))
        except Exception as e:  # noqa: BLE001
            log.warning("DB hatası (asset_text): %s", e)
            self.db.drop()
            ok = False
        if not ok and t == 0.0:
            log.info("asset_text tablosu henüz yok (migration 011); fotoğraftaki yazı bekliyor")
        self.ocr_tables = (time.time(), ok)
        return ok

    def faces_tables_ok(self) -> bool:
        """Yüz tabloları (migration 006) var mı? 5 dakikada bir yeniden denetlenir."""
        t, ok = self.face_tables
        if ok or time.time() - t < 300:
            return ok
        try:
            have = {r[0] for r in self.db.query(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() "
                "AND table_name IN ('faces', 'face_scans', 'people', 'asset_person')")}
            ok = len(have) == 4
        except Exception as e:  # noqa: BLE001
            log.warning("DB hatası (yüz tabloları): %s", e)
            self.db.drop()
            ok = False
        if not ok and t == 0.0:
            log.info("yüz tabloları henüz yok (migration 006); yüz tanıma bekliyor")
        self.face_tables = (time.time(), ok)
        return ok

    def quality_tables_ok(self) -> bool:
        """asset_quality tablosu (migration 008) var mı? 5 dakikada bir yeniden denetlenir."""
        t, ok = self.quality_tables
        if ok or time.time() - t < 300:
            return ok
        try:
            ok = bool(self.db.query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() "
                "AND table_name = 'asset_quality'"))
        except Exception as e:  # noqa: BLE001
            log.warning("DB hatası (asset_quality): %s", e)
            self.db.drop()
            ok = False
        if not ok and t == 0.0:
            log.info("asset_quality tablosu henüz yok (migration 008); temizlik ölçümleri bekliyor")
        self.quality_tables = (time.time(), ok)
        return ok

    def request_recluster(self, user_id: int) -> None:
        with self.recluster_lock:
            self.recluster.add(user_id)
        self.wake.set()

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

    def face_user_stats(self, user_id: int) -> dict:
        def f():
            m = self.cfg.face_model_name
            total, scanned = self.db.query(
                "SELECT COUNT(*), COUNT(s.asset_id) FROM assets a "
                "LEFT JOIN face_scans s ON s.asset_id = a.id AND s.model = %s "
                "WHERE a.user_id = %s AND a.thumb_state = 'ready' AND a.trashed_at IS NULL "
                "AND NOT (a.type = 'video' AND a.live_pair_id IS NOT NULL)", (m, user_id))[0]
            faces = self.db.query("SELECT COUNT(*) FROM faces WHERE user_id = %s", (user_id,))[0][0]
            people = self.db.query("SELECT COUNT(*) FROM people WHERE user_id = %s AND hidden = 0", (user_id,))[0][0]
            return {"scanned": int(scanned or 0), "total": int(total or 0), "faces": int(faces or 0),
                    "people": int(people or 0)}
        return self._cached(f"faces:{user_id}", 3.0, f)

    # ---------- dizinleyici ----------
    def face_source_path(self, aid: str) -> str | None:
        """Yüz algılama kaynağı: en büyük türetilmiş görüntü (önizleme > video kapağı > küçük resim)."""
        root = self.cfg.storage
        for variant in ("preview", "poster", "thumb"):
            for ext in EXTS:
                p = f"{root}/derived/{variant}/{aid[:2]}/{aid}.{ext}"
                if os.path.isfile(p):
                    return p
        return None

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

    @staticmethod
    def _defer_in(skip: dict, aid: str) -> None:
        _, n = skip.get(aid, (0, 0))
        n += 1
        skip[aid] = (time.time() + min(6 * 3600, 300 * 2 ** n), n)

    def _defer(self, aid: str) -> None:
        self._defer_in(self.skip, aid)

    def index_loop(self) -> None:
        try:
            tid = threading.get_native_id()
            os.setpriority(os.PRIO_PROCESS, tid, min(19, os.getpriority(os.PRIO_PROCESS, tid) + 5))
        except (OSError, AttributeError):
            pass
        try:
            self.load_visual()
        except Exception as e:  # noqa: BLE001
            self.visual = None
            self.error = f"görsel model yüklenemedi: {e}"
            log.exception("görsel model yüklenemedi; CLIP dizinlemesi kapalı")
        self.load_faces()
        self.load_ocr()
        if self.faces is not None:
            from .clustering import Clusterer

            self.clusterer = Clusterer(self.db, self.cfg.face_model_name, self.cfg.face_max_distance,
                                       self.cfg.face_min_faces)
        backoff = 5.0
        while not self.stop.is_set():
            try:
                did = False
                clip_busy = False
                if self.visual is not None:
                    clip_busy = self._clip_step()
                    did = clip_busy
                if self.faces is not None and self.faces_tables_ok():
                    # CLIP işi varken tur başına az görüntü (arama dizini önce dolsun), yoksa tam hız.
                    n = self.cfg.face_batch if clip_busy else max(self.cfg.face_batch, self.cfg.batch)
                    did = self._face_step(n) or did
                    did = self._cluster_step() or did
                if self.quality_tables_ok():
                    # Kalite ölçümü ucuzdur (~50-100 ms/görüntü): CLIP işi varken de tur başına bir toplu iş.
                    did = self._quality_step(self.cfg.batch * (2 if clip_busy else 8)) or did
                    if not did:
                        self._cleanup_warm()
                if self.ocr is not None and not clip_busy and self.ocr_tables_ok():
                    # Düşük öncelik: arama dizini (CLIP) dolunca; tur başına az görüntü (~0.3-1.5 sn CPU/görüntü).
                    did = self._ocr_step(self.cfg.ocr_batch) or did
                backoff = 5.0
            except Exception as e:  # noqa: BLE001
                log.warning("DB hatası (dizinleyici): %s; %.0f sn sonra yeniden", e, backoff)
                self.db.drop()
                self.stop.wait(backoff)
                backoff = min(60.0, backoff * 2)
                continue
            if not did:
                self.wake.wait(self.cfg.idle)
                self.wake.clear()

    def _clip_step(self) -> bool:
        """Bir CLIP toplu işi; True: iş yapıldı (ya da kuyruk ilerledi)."""
        if not self.clip_queue:
            rows = self.db.query(
                "SELECT a.id, a.user_id FROM assets a "
                "LEFT JOIN asset_embeddings e ON e.asset_id = a.id AND e.model = %s "
                "WHERE a.thumb_state = 'ready' AND e.asset_id IS NULL AND a.id > %s "
                "ORDER BY a.id LIMIT 512", (self.cfg.model_name, self.clip_cursor))
            if not rows:
                self.clip_cursor = ""
                return False
            self.clip_cursor = rows[-1][0]
            now = time.time()
            self.clip_queue = [(a, u) for a, u in rows if self.skip.get(a, (0, 0))[0] <= now]
            if not self.clip_queue:
                return True
        batch, self.clip_queue = self.clip_queue[:self.cfg.batch], self.clip_queue[self.cfg.batch:]
        self._index_batch(batch)
        return True

    def _face_step(self, n: int) -> bool:
        """En çok n görüntüde yüz ara; True: iş yapıldı."""
        if not self.face_queue:
            rows = self.db.query(
                "SELECT a.id, a.user_id FROM assets a "
                "LEFT JOIN face_scans s ON s.asset_id = a.id AND s.model = %s "
                "WHERE a.thumb_state = 'ready' AND a.trashed_at IS NULL AND s.asset_id IS NULL "
                "AND NOT (a.type = 'video' AND a.live_pair_id IS NOT NULL) AND a.id > %s "
                "ORDER BY a.id LIMIT 256", (self.cfg.face_model_name, self.face_cursor))
            if not rows:
                self.face_cursor = ""
                return False
            self.face_cursor = rows[-1][0]
            now = time.time()
            self.face_queue = [(a, u) for a, u in rows if self.face_skip.get(a, (0, 0))[0] <= now]
            if not self.face_queue:
                return True
        batch, self.face_queue = self.face_queue[:n], self.face_queue[n:]
        for aid, uid in batch:
            if self.stop.is_set():
                break
            self._scan_faces(aid, int(uid))
        return True

    def _scan_faces(self, aid: str, uid: int) -> None:
        p = self.face_source_path(aid)
        if p is None:
            self._defer_in(self.face_skip, aid)
            return
        try:
            found, _size = self.faces.analyze_file(p)
        except Exception as e:  # noqa: BLE001
            log.info("yüz taraması: görüntü okunamadı %s: %s", aid, e)
            self._defer_in(self.face_skip, aid)
            return
        model = self.cfg.face_model_name
        now = utcnow()
        conn = self.db.conn()
        try:
            conn.begin()
            with conn.cursor() as cur:
                # Önceki (yarım kalmış ya da başka modelle yapılmış) taramanın yüzleri yerine yenileri.
                cur.execute("DELETE FROM faces WHERE asset_id = %s AND (model <> %s OR person_id IS NULL)",
                            (aid, model))
                cur.execute("SELECT COUNT(*) FROM faces WHERE asset_id = %s", (aid,))
                kept = int(cur.fetchone()[0])
                if kept == 0:
                    for fc in found:
                        x1, y1, x2, y2 = fc.norm
                        cur.execute(
                            "INSERT INTO faces (asset_id, user_id, person_id, rejected_person_id, x1, y1, x2, y2, "
                            "score, embedding, model, assigned_by, created_at) "
                            "SELECT id, user_id, NULL, NULL, %s, %s, %s, %s, %s, %s, %s, NULL, %s "
                            "FROM assets WHERE id = %s",
                            (x1, y1, x2, y2, fc.score, fc.embedding.astype("<f4").tobytes(), model, now, aid))
                cur.execute(
                    "INSERT INTO face_scans (asset_id, user_id, model, faces, scanned_at) "
                    "SELECT id, user_id, %s, %s, %s FROM assets WHERE id = %s "
                    "ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), model = VALUES(model), "
                    "faces = VALUES(faces), scanned_at = VALUES(scanned_at)",
                    (model, len(found) if kept == 0 else kept, now, aid))
                cur.execute("SELECT id FROM faces WHERE asset_id = %s AND person_id IS NULL", (aid,))
                new_ids = [int(r[0]) for r in cur.fetchall()]
            conn.commit()
        except Exception:
            try:
                conn.rollback()
            except Exception:  # noqa: BLE001
                pass
            raise
        self.face_skip.pop(aid, None)
        if new_ids:
            self.face_new.setdefault(uid, []).extend(new_ids)
            self.face_dirty.add(uid)
        self.faces_session += 1
        if self.faces_session % 500 == 0:
            log.info("yüz taraması (bu oturum): %d görüntü", self.faces_session)

    def _cluster_step(self) -> bool:
        """Yeni yüzleri artımlı kümele; istenen ya da kuyruğu boşalmış kullanıcılar için tam geçiş."""
        if self.clusterer is None:
            return False
        did = False
        for uid in list(self.face_new):
            ids = self.face_new.pop(uid)
            self.clusterer.run(uid, ids)
            did = True
        now = time.time()
        # Tam geçiş büyük kütüphanede saniyeler sürer (50 bin yüz / 20 bin atanmamış: ~5 sn): istekler
        # kullanıcı başına en çok RECLUSTER_GAP saniyede bir, kuyruk boşalınca yapılan en çok DIRTY_GAP'te bir.
        with self.recluster_lock:
            requested = {u for u in self.recluster if now - self.last_full.get(u, 0.0) >= self.recluster_gap}
            self.recluster -= requested
        users = set(requested)
        if not self.face_queue and not self.face_cursor:
            # tarama kuyruğu boş: yeni yüzü olan kullanıcılar (artımlı geçişin ertelediği yüzler için) ve
            # açılışta bir kez atanmamış yüzü olan herkes
            due = {u for u in self.face_dirty if now - self.last_full.get(u, 0.0) >= self.dirty_gap}
            self.face_dirty -= due
            users |= due
            if not self.face_initial_pass:
                self.face_initial_pass = True
                users |= {int(r[0]) for r in self.db.query(
                    "SELECT DISTINCT user_id FROM faces WHERE person_id IS NULL")}
        for uid in sorted(users):
            if self.stop.is_set():
                break
            self.last_full[uid] = time.time()
            self.clusterer.run(uid, None)
            self.face_dirty.discard(uid)
            did = True
        return did

    def _quality_step(self, n: int) -> bool:
        """En çok n görüntünün kalite ölçümü (asset_quality); True: iş yapıldı."""
        from . import cleanup

        if not self.quality_queue:
            rows = self.db.query(
                "SELECT a.id, a.user_id, a.original_filename, a.mime, a.camera_make, a.width, a.height FROM assets a "
                "LEFT JOIN asset_quality q ON q.asset_id = a.id "
                "WHERE a.thumb_state = 'ready' AND a.trashed_at IS NULL AND (q.asset_id IS NULL OR q.algo < %s) "
                "AND NOT (a.type = 'video' AND a.live_pair_id IS NOT NULL) AND a.id > %s "
                "ORDER BY a.id LIMIT 512", (cleanup.ALGO, self.quality_cursor))
            if not rows:
                self.quality_cursor = ""
                return False
            self.quality_cursor = rows[-1][0]
            now = time.time()
            self.quality_queue = [r for r in rows if self.quality_skip.get(r[0], (0, 0))[0] <= now]
            if not self.quality_queue:
                return True
        batch, self.quality_queue = self.quality_queue[:n], self.quality_queue[n:]
        out = []
        for aid, uid, fname, mime, make, w, h in batch:
            if self.stop.is_set():
                break
            p = self.face_source_path(aid)
            if p is None:
                self._defer_in(self.quality_skip, aid)
                continue
            try:
                m = cleanup.analyze_file(p)
            except Exception as e:  # noqa: BLE001
                log.info("kalite ölçümü: görüntü okunamadı %s: %s", aid, e)
                self._defer_in(self.quality_skip, aid)
                continue
            shot = 1 if cleanup.is_screenshot(fname, mime, make, w, h) else 0
            out.append((aid, int(uid), m["phash"], m["sharpness"], m["brightness"], m["color"], shot, cleanup.ALGO,
                        utcnow()))
        if out:
            self.db.execute_many(
                "INSERT INTO asset_quality (asset_id, user_id, phash, sharpness, brightness, color, is_screenshot, "
                "algo, computed_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE "
                "user_id = VALUES(user_id), phash = VALUES(phash), sharpness = VALUES(sharpness), "
                "brightness = VALUES(brightness), color = VALUES(color), is_screenshot = VALUES(is_screenshot), algo = VALUES(algo), "
                "computed_at = VALUES(computed_at)", out)
            for r in out:
                self.quality_skip.pop(r[0], None)
            self.quality_session += len(out)
            if self.quality_session % 1000 < len(out):
                log.info("kalite ölçümü (bu oturum): %d görüntü", self.quality_session)
        return True

    def _ocr_step(self, n: int) -> bool:
        """En çok n fotoğrafta yazı oku (asset_text); True: iş yapıldı."""
        from . import ocr as ocrmod

        if not self.ocr_queue:
            rows = self.db.query(
                "SELECT a.id, a.user_id FROM assets a LEFT JOIN asset_text t ON t.asset_id = a.id "
                "WHERE a.type = 'photo' AND a.thumb_state = 'ready' AND a.trashed_at IS NULL "
                "AND (t.asset_id IS NULL OR t.engine <> %s) AND a.id > %s ORDER BY a.id LIMIT 256",
                (ocrmod.ENGINE, self.ocr_cursor))
            if not rows:
                self.ocr_cursor = ""
                return False
            self.ocr_cursor = rows[-1][0]
            now = time.time()
            self.ocr_queue = [(a, u) for a, u in rows if self.ocr_skip.get(a, (0, 0))[0] <= now]
            if not self.ocr_queue:
                return False  # sayfanın hepsi ertelenmiş (dosyası yok): boşta dönmeyelim
        batch, self.ocr_queue = self.ocr_queue[:n], self.ocr_queue[n:]
        out = []
        for aid, _uid in batch:
            if self.stop.is_set():
                break
            p = self.face_source_path(aid)
            if p is None:
                self._defer_in(self.ocr_skip, aid)
                continue
            try:
                r = self.ocr.analyze_file(p)
            except Exception as e:  # noqa: BLE001
                log.info("OCR: görüntü okunamadı %s: %s", aid, e)
                self._defer_in(self.ocr_skip, aid)
                continue
            text = r["text"]
            f = ocrmod.fold(text)
            out.append((text, f" {f} " if f else "", r["lang"] if text else None, ocrmod.count_words(text),
                        ocrmod.ENGINE, utcnow(), aid))
        if out:
            # INSERT … SELECT: bu arada kalıcı silinen fotoğrafa satır yazılmaz.
            self.db.execute_many(
                "INSERT INTO asset_text (asset_id, user_id, text, folded, lang, words, engine, computed_at) "
                "SELECT id, user_id, %s, %s, %s, %s, %s, %s FROM assets WHERE id = %s "
                "ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), text = VALUES(text), folded = VALUES(folded), "
                "lang = VALUES(lang), words = VALUES(words), engine = VALUES(engine), computed_at = VALUES(computed_at)",
                out)
            for r in out:
                self.ocr_skip.pop(r[-1], None)
            self.ocr_session += len(out)
            if self.ocr_session % 500 < len(out):
                log.info("fotoğraftaki yazı (bu oturum): %d görüntü", self.ocr_session)
        return True

    # ---------- temizlik: benzer gruplar ----------
    def cleanup_fingerprint(self, user_id: int) -> tuple:
        e = self.db.query("SELECT COUNT(*), MAX(created_at) FROM asset_embeddings WHERE user_id = %s AND model = %s",
                          (user_id, self.cfg.model_name))[0]
        q = self.db.query("SELECT COUNT(*), MAX(computed_at) FROM asset_quality WHERE user_id = %s", (user_id,))[0]
        a = self.db.query("SELECT COUNT(*), MAX(version) FROM assets WHERE user_id = %s", (user_id,))[0]
        return tuple(str(x) for x in (*e, *q, *a, self.text is not None))

    def _cleanup_warm(self) -> None:
        """Boşta: Temizlik'i daha önce açmış kullanıcıların grupları kütüphane değiştiyse arka planda yenilenir
        (büyük kütüphanede hesap saniyeler sürer; sayfa açılışı beklemesin)."""
        now = time.time()
        if now - getattr(self, "_warm_at", 0.0) < 60:
            return
        self._warm_at = now
        for uid in list(self.cleanup_cache):
            if self.stop.is_set():
                return
            try:
                if self.cleanup_cache[uid][0] != self.cleanup_fingerprint(uid):
                    self.cleanup_groups(uid)
            except Exception as e:  # noqa: BLE001
                log.warning("temizlik ön hesaplaması: %s", e)
                self.db.drop()

    def cleanup_groups(self, user_id: int) -> dict:
        from . import cleanup

        with self.cleanup_lock:
            lock = self.cleanup_locks.setdefault(user_id, threading.Lock())
        with lock:
            fp = self.cleanup_fingerprint(user_id)
            hit = self.cleanup_cache.get(user_id)
            if hit and hit[0] == fp:
                return {**hit[1], "cached": True}
            t0 = time.perf_counter()
            rows = self.db.query(
                "SELECT a.id, UNIX_TIMESTAMP(a.taken_at), a.date_source, q.phash, q.sharpness, q.brightness, q.color, "
                "a.width, a.height, q.is_screenshot "
                "FROM assets a LEFT JOIN asset_quality q ON q.asset_id = a.id "
                "WHERE a.user_id = %s AND a.trashed_at IS NULL AND a.is_archived = 0 AND a.type = 'photo' "
                "AND a.thumb_state = 'ready' AND COALESCE(LEAST(a.width, a.height), 9999) >= %s "
                "AND NOT EXISTS (SELECT 1 FROM assets ed WHERE ed.edited_of_id = a.id AND ed.trashed_at IS NULL)",
                (user_id, cleanup.MIN_EDGE))
            ui = self.user_index(user_id)
            with ui.lock:
                pos = {a: i for i, a in enumerate(ui.ids)}
                mat = ui.mat
            ids, taken, rel, ph, sh, br, co, ar, rows_idx, shot = [], [], [], [], [], [], [], [], [], []
            for aid, ts, src, phash, sharp, bright, color, w, h, is_shot in rows:
                i = pos.get(aid)
                if i is None:
                    continue
                ids.append(aid)
                rows_idx.append(i)
                taken.append(float(ts or 0))
                rel.append(src in ("exif", "takeout", "filename", "user"))
                ph.append(int(phash) if phash is not None else None)
                sh.append(float(sharp) if sharp is not None else None)
                br.append(float(bright) if bright is not None else None)
                co.append(bytes(color) if color is not None else None)
                ar.append(float(w) / float(h) if w and h else None)
                shot.append(bool(is_shot))
            vecs = mat[rows_idx] if rows_idx else np.zeros((0, self.dim), dtype=np.float32)
            texty = np.asarray(shot, dtype=bool)
            if self.text is not None and len(ids):
                # Belge/slayt/fiş gibi yazı ağırlıklı kareler: yalnız birebir kopyalar gruplanır (bkz. cleanup.py).
                try:
                    texty |= cleanup.texty_mask(vecs, self.embed_query(cleanup.TEXT_PROMPT),
                                                self.embed_query(cleanup.PHOTO_PROMPT))
                except Exception as e:  # noqa: BLE001
                    log.warning("temizlik: yazı denetimi yapılamadı: %s", e)
            groups = cleanup.group_assets(ids, np.array(taken), vecs, ph, reliable=rel, sharp=sh, bright=br, color=co,
                                          aspect=ar, texty=texty.tolist())
            res = {"groups": groups, "analyzed": sum(1 for x in ph if x is not None), "total": len(rows),
                   "indexed": len(ids), "took_ms": round((time.perf_counter() - t0) * 1000, 1)}
            self.cleanup_cache[user_id] = (fp, res)
            return {**res, "cached": False}

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
                           "ready": engine.ready and db_ok, "indexer": engine.visual_ready.is_set(),
                           "faces": engine.faces is not None, "cleanup": engine.quality_tables_ok(),
                           "ocr": engine.ocr is not None and engine.ocr_tables_ok()}
                    if engine.ocr_error:
                        out["ocr_error"] = engine.ocr_error
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
                if u.path == "/faces/status":
                    qs = parse_qs(u.query)
                    try:
                        uid = int(qs.get("user_id", ["0"])[0])
                    except ValueError:
                        uid = 0
                    if uid <= 0:
                        return self._send(400, {"error": "user_id gerekli"})
                    available = engine.faces is not None
                    out = {"available": available, "model": engine.cfg.face_model_name, "ready": False,
                           "scanned": 0, "total": 0, "faces": 0, "people": 0}
                    if engine.faces_tables_ok():
                        out.update(engine.face_user_stats(uid))
                        out["ready"] = available
                    if engine.face_error:
                        out["error"] = engine.face_error
                    return self._send(200, out)
                return self._send(404, {"error": "bulunamadı"})
            except Exception as e:  # noqa: BLE001
                log.exception("GET %s", u.path)
                engine.db.drop()
                return self._send(500, {"error": str(e)})

        def do_POST(self):
            u = urlparse(self.path)
            if u.path not in ("/search", "/faces/recluster", "/cleanup/groups"):
                return self._send(404, {"error": "bulunamadı"})
            try:
                n = int(self.headers.get("Content-Length") or 0)
            except ValueError:
                n = 0
            if n <= 0 or n > 65536:
                return self._send(400, {"error": "geçersiz gövde"})
            if u.path == "/cleanup/groups":
                try:
                    req = json.loads(self.rfile.read(n).decode("utf-8"))
                    uid = int(req.get("user_id") or 0)
                except (ValueError, TypeError, AttributeError):
                    return self._send(400, {"error": "geçersiz istek"})
                if uid <= 0:
                    return self._send(400, {"error": "user_id gerekli"})
                if not engine.quality_tables_ok():
                    return self._send(503, {"error": "asset_quality tablosu yok (migration 008)", "groups": []})
                try:
                    return self._send(200, engine.cleanup_groups(uid))
                except Exception as e:  # noqa: BLE001
                    log.exception("temizlik grupları")
                    engine.db.drop()
                    return self._send(500, {"error": str(e), "groups": []})
            if u.path == "/faces/recluster":
                try:
                    req = json.loads(self.rfile.read(n).decode("utf-8"))
                    uid = int(req.get("user_id") or 0)
                except (ValueError, TypeError, AttributeError):
                    return self._send(400, {"error": "geçersiz istek"})
                if uid <= 0:
                    return self._send(400, {"error": "user_id gerekli"})
                if engine.clusterer is None:
                    return self._send(200, {"queued": False, "available": False})
                engine.request_recluster(uid)
                return self._send(200, {"queued": True, "available": True})
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
        engine.wake.set()
        threading.Thread(target=httpd.shutdown, daemon=True).start()

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)
    try:
        httpd.serve_forever(poll_interval=0.5)
    finally:
        httpd.server_close()
    return 0
