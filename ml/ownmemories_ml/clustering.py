"""Yüz kümeleme (Immich'in "recognizeFaces" işini izler; server/src/services/person.service.ts).

Her atanmamış yüz için (kullanıcı başına, bellekteki 512 boyutlu vektörlerle, kosinüs uzaklığı = 1 - iç çarpım):
  - eşleşmeler = uzaklığı < max_distance olan en yakın min_faces yüz (yüzün kendisi dahil; Immich numResults).
  - Yalnızca kendisiyle eşleştiyse (min_faces > 1) atla.
  - "çekirdek" = eşleşme sayısı >= min_faces. Çekirdek olmayanlar geçişin sonuna ertelenir (Immich: deferred).
  - Kişi = eşleşmelerdeki kişilerin en sık olanı (eşitlikte en yakın; Immich en yakın kişiliyi alır); yoksa
    max_distance içindeki en yakın kişili yüz (Immich hasPerson araması). Yüzün reddettiği kişi
    (rejected_person_id) hiçbir zaman seçilmez.
  - Kişi yoksa ve yüz çekirdekse ve eşleşmelerin en az min_faces'i atanmamışsa: adsız yeni kişi (people.name NULL)
    oluşturulur, kapak = en yüksek skorlu yüz, atanmamış eşleşmelerin hepsi bu kişiye atanır.
  - Atama: faces.person_id, assigned_by = 'auto'; asset_person'a source 'face' satırı (varsa dokunulmaz).
  - Kullanıcının atadığı (assigned_by = 'user') ya da zaten kişisi olan yüzler hiçbir zaman taşınmaz.

Artımlı mod: yeni yüzler + onların max_distance içindeki atanmamış komşuları işlenir (yeni yüz, önceden çekirdek
olmayan komşuları çekirdek yapabilir). Tam geçiş: kullanıcının tüm atanmamış yüzleri (POST /faces/recluster,
tarama kuyruğu boşaldığında ve açılışta).
"""
from __future__ import annotations

import logging
import time
from collections import Counter
from typing import Callable, Iterable

import numpy as np

log = logging.getLogger("ownmemories-ml")

NEIGHBORS = 64  # en yakın kişili yüz araması için tutulan komşu sayısı
CHUNK_ELEMS = 8_000_000  # benzerlik matrisi parçası (float32 ~32 MB)


class FaceSet:
    """Bir kullanıcının yüzleri (id'ye göre artan sırada)."""

    def __init__(self, dim: int = 512):
        self.ids = np.zeros(0, dtype=np.int64)
        self.mat = np.zeros((0, dim), dtype=np.float32)
        self.score = np.zeros(0, dtype=np.float32)
        self.person = np.zeros(0, dtype=np.int64)  # 0 = yok, -1 = bilinmiyor (atama yarışı)
        self.rejected = np.zeros(0, dtype=np.int64)
        self.user = np.zeros(0, dtype=bool)
        self.checked = 0.0

    @property
    def n(self) -> int:
        return int(self.ids.shape[0])

    @property
    def max_id(self) -> int:
        return int(self.ids[-1]) if self.n else 0

    def append(self, ids, mat, score) -> None:
        k = len(ids)
        self.ids = np.concatenate([self.ids, np.asarray(ids, dtype=np.int64)])
        self.mat = np.vstack([self.mat, np.asarray(mat, dtype=np.float32).reshape(k, -1)])
        self.score = np.concatenate([self.score, np.asarray(score, dtype=np.float32)])
        self.person = np.concatenate([self.person, np.zeros(k, dtype=np.int64)])
        self.rejected = np.concatenate([self.rejected, np.zeros(k, dtype=np.int64)])
        self.user = np.concatenate([self.user, np.zeros(k, dtype=bool)])

    def index_of(self, face_ids: Iterable[int]) -> np.ndarray:
        fid = np.asarray(list(face_ids), dtype=np.int64)
        if fid.size == 0 or self.n == 0:
            return np.zeros(0, dtype=np.intp)
        pos = np.searchsorted(self.ids, fid)
        pos = np.clip(pos, 0, self.n - 1)
        return pos[self.ids[pos] == fid].astype(np.intp)


def neighbor_lists(fs: FaceSet, idx: np.ndarray, max_distance: float, k: int = NEIGHBORS) -> list[np.ndarray]:
    """idx'teki her yüz için uzaklığı < max_distance olan en yakın k yüz (yakından uzağa, kendisi dahil)."""
    out: list[np.ndarray] = []
    n = fs.n
    if n == 0 or len(idx) == 0:
        return [np.zeros(0, dtype=np.intp) for _ in idx]
    min_sim = 1.0 - max_distance
    rows = max(1, CHUNK_ELEMS // max(1, n))
    for s in range(0, len(idx), rows):
        part = idx[s:s + rows]
        sims = fs.mat[part] @ fs.mat.T
        for r, i in enumerate(part):
            row = sims[r]
            cand = np.flatnonzero(row > min_sim)
            if i not in cand:  # sayısal güvenlik: kendisi her zaman eşleşir
                cand = np.append(cand, i)
            if cand.size > k:
                top = np.argpartition(-row[cand], k - 1)[:k]
                cand = cand[top]
            order = np.argsort(-row[cand], kind="stable")
            # kendisini başa al (Immich: eşleşmeler yüzün kendisini içerir, uzaklık 0)
            cand = cand[order]
            if cand[0] != i:
                cand = np.concatenate([[i], cand[cand != i]])
            out.append(cand.astype(np.intp))
    return out


def run_pass(fs: FaceSet, candidates: np.ndarray, max_distance: float, min_faces: int,
             assign: Callable[[int, int], bool], create: Callable[[list[int]], int | None],
             neighbors: dict[int, np.ndarray] | None = None) -> dict:
    """Kümeleme geçişi. assign(face_index, person_id) -> başarılı mı; create(face_indices) -> yeni person_id.

    fs.person, başarılı atamalarda burada güncellenir. Dönen: {"assigned", "created", "skipped"}.
    """
    stats = {"assigned": 0, "created": 0, "skipped": 0}
    cand = np.asarray([i for i in candidates if fs.person[i] == 0], dtype=np.intp)
    if cand.size == 0:
        return stats
    if neighbors is None:
        neighbors = {}
    missing = np.asarray([i for i in cand if int(i) not in neighbors], dtype=np.intp)
    for i, nb in zip(missing, neighbor_lists(fs, missing, max_distance)):
        neighbors[int(i)] = nb
    min_faces = max(1, int(min_faces))

    def do(i: int, deferred: bool) -> bool:
        """True: işlendi (atandı ya da kalıcı atlandı); False: ertele."""
        if fs.person[i] != 0:
            return True
        nb = neighbors[i]
        matches = nb[:min_faces]
        if min_faces > 1 and matches.size <= 1:
            stats["skipped"] += 1
            return True
        is_core = matches.size >= min_faces
        if not is_core and not deferred:
            return False
        rej = int(fs.rejected[i])
        votes = [int(fs.person[j]) for j in matches if fs.person[j] > 0 and fs.person[j] != rej]
        pid = 0
        if votes:
            cnt = Counter(votes)
            best = max(cnt.values())
            pid = next(p for p in votes if cnt[p] == best)  # eşitlikte en yakın
        else:
            for j in nb:
                p = int(fs.person[j])
                if p > 0 and p != rej:
                    pid = p
                    break
        if pid:
            if assign(i, pid):
                fs.person[i] = pid
                stats["assigned"] += 1
            else:
                fs.person[i] = -1
            return True
        if is_core:
            free = [int(j) for j in matches if fs.person[j] == 0]
            if len(free) >= min_faces:
                new = create(free)
                if new:
                    stats["created"] += 1
                    for j in free:
                        if assign(j, new):
                            fs.person[j] = new
                            stats["assigned"] += 1
                        else:
                            fs.person[j] = -1
                    return True
        stats["skipped"] += 1
        return True

    later = [int(i) for i in cand if not do(int(i), False)]
    for i in later:
        do(i, True)
    return stats


class Clusterer:
    """FaceSet önbelleği + DB'ye yazan atama/oluşturma (indexer iş parçacığında çalışır)."""

    def __init__(self, db, model: str, max_distance: float = 0.5, min_faces: int = 3):
        self.db = db
        self.model = model
        self.max_distance = float(max_distance)
        self.min_faces = int(min_faces)
        self.sets: dict[int, FaceSet] = {}

    # ---------- yükleme
    def _load_vectors(self, uid: int, after: int = 0) -> tuple[list[int], np.ndarray, list[float]]:
        rows = self.db.query("SELECT id, embedding, score FROM faces WHERE user_id = %s AND model = %s AND id > %s "
                             "AND LENGTH(embedding) = 2048 ORDER BY id", (uid, self.model, after))
        ids, vecs, scores = [], [], []
        for fid, blob, sc in rows:
            v = np.frombuffer(blob, dtype="<f4")
            if v.shape[0] != 512:
                continue
            ids.append(int(fid))
            vecs.append(v)
            scores.append(float(sc or 0))
        mat = np.vstack(vecs).astype(np.float32) if vecs else np.zeros((0, 512), dtype=np.float32)
        return ids, mat, scores

    def load(self, uid: int) -> FaceSet:
        fs = self.sets.get(uid)
        if fs is None:
            fs = FaceSet()
        ids, mat, scores = self._load_vectors(uid, fs.max_id)
        if ids:
            fs.append(ids, mat, scores)
        sql = ("SELECT id, person_id, rejected_person_id, assigned_by FROM faces "
               "WHERE user_id = %s AND model = %s AND LENGTH(embedding) = 2048 ORDER BY id")
        state = self.db.query(sql, (uid, self.model))
        sid = np.fromiter((r[0] for r in state), dtype=np.int64, count=len(state))
        if sid.shape[0] != fs.n or not np.array_equal(sid, fs.ids):
            # silinen yüzler (varlık kalıcı silindi) ya da okunamayan vektör: baştan yükle
            fs = FaceSet()
            ids, mat, scores = self._load_vectors(uid, 0)
            fs.append(ids, mat, scores)
            state = self.db.query(sql, (uid, self.model))
        if fs.n:
            st = {int(r[0]): r for r in state}
            miss = (None, -1, 0, None)  # arada silinmiş: dokunma
            rows = [st.get(int(f), miss) for f in fs.ids]
            fs.person = np.fromiter((int(r[1] or 0) for r in rows), dtype=np.int64, count=fs.n)
            fs.rejected = np.fromiter((int(r[2] or 0) for r in rows), dtype=np.int64, count=fs.n)
            fs.user = np.fromiter((r[3] == "user" for r in rows), dtype=bool, count=fs.n)
        fs.checked = time.time()
        self.sets[uid] = fs
        return fs

    def forget(self, uid: int) -> None:
        self.sets.pop(uid, None)

    # ---------- DB yazımı
    def _assign(self, uid: int, fs: FaceSet, i: int, pid: int, now) -> bool:
        fid = int(fs.ids[i])
        with self.db.conn().cursor() as cur:
            n = cur.execute(
                "UPDATE faces f JOIN people p ON p.id = %s AND p.user_id = f.user_id "
                "SET f.person_id = p.id, f.assigned_by = 'auto' "
                "WHERE f.id = %s AND f.user_id = %s AND f.person_id IS NULL AND NOT (f.rejected_person_id <=> p.id)",
                (pid, fid, uid))
            if n != 1:
                return False
            cur.execute(
                "INSERT IGNORE INTO asset_person (asset_id, person_id, source, created_at) "
                "SELECT asset_id, person_id, 'face', %s FROM faces WHERE id = %s AND person_id = %s",
                (now, fid, pid))
        return True

    def _create(self, uid: int, fs: FaceSet, idx: list[int], now) -> int | None:
        best = max(idx, key=lambda j: float(fs.score[j]))
        with self.db.conn().cursor() as cur:
            cur.execute("INSERT INTO people (user_id, name, cover_face_id, hidden, created_at, updated_at) "
                        "VALUES (%s, NULL, %s, 0, %s, %s)", (uid, int(fs.ids[best]), now, now))
            return int(cur.lastrowid or 0) or None

    def _fix_covers(self, uid: int) -> None:
        """Kapak yüzü yoksa ya da artık o kişiye ait değilse en yüksek skorlu yüzünü kapak yap."""
        self.db.query(
            "UPDATE people p SET p.cover_face_id = (SELECT f.id FROM faces f WHERE f.person_id = p.id "
            "ORDER BY f.score DESC, f.id LIMIT 1) "
            "WHERE p.user_id = %s AND (p.cover_face_id IS NULL OR NOT EXISTS "
            "(SELECT 1 FROM faces f2 WHERE f2.id = p.cover_face_id AND f2.person_id = p.id)) "
            "AND EXISTS (SELECT 1 FROM faces f3 WHERE f3.person_id = p.id)", (uid,))

    # ---------- geçişler
    def run(self, uid: int, new_face_ids: Iterable[int] | None = None) -> dict:
        """new_face_ids verilirse artımlı (yeni yüzler + atanmamış komşuları), yoksa tam geçiş."""
        t0 = time.time()
        fs = self.load(uid)
        if fs.n == 0:
            return {"assigned": 0, "created": 0, "skipped": 0, "faces": 0}
        neighbors: dict[int, np.ndarray] = {}
        if new_face_ids is None:
            cand = np.flatnonzero(fs.person == 0)
        else:
            new = fs.index_of(new_face_ids)
            new = new[fs.person[new] == 0]
            seeds = set(int(i) for i in new)
            for i, nb in zip(new, neighbor_lists(fs, new, self.max_distance)):
                neighbors[int(i)] = nb
                seeds.update(int(j) for j in nb if fs.person[j] == 0)
            cand = np.asarray(sorted(seeds), dtype=np.intp)
        from .service import utcnow

        now = utcnow()
        stats = run_pass(
            fs, cand, self.max_distance, self.min_faces,
            assign=lambda i, p: self._assign(uid, fs, i, p, now),
            create=lambda idx: self._create(uid, fs, idx, now),
            neighbors=neighbors)
        if stats["assigned"] or stats["created"] or new_face_ids is None:
            self._fix_covers(uid)
        stats["faces"] = fs.n
        stats["candidates"] = int(len(cand))
        stats["took_ms"] = round((time.time() - t0) * 1000)
        if stats["assigned"] or stats["created"]:
            log.info("yüz kümeleme (kullanıcı %d, %s): %s", uid, "artımlı" if new_face_ids is not None else "tam",
                     stats)
        return stats
