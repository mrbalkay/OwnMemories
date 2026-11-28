"""Temizlik: görüntü kalite ölçümleri ve benzer/yinelenen fotoğraf grupları.

Ölçümler (dizinleyici, türetilmiş önizleme/küçük resimden; görüntü başına ~10-30 ms):
  phash       64 bit DCT algısal özet (32×32 gri → DCT → sol üst 8×8, ortancaya göre bit). Aynı görüntünün
              yeniden kodlanmış / küçültülmüş / WhatsApp'tan geçmiş kopyalarında Hamming uzaklığı ≤ ~6.
  sharpness   Laplace varyansı: gri görüntü en çok 1024 px uzun kenara ölçeklenir, 4×4 karoya bölünür, en net
              ikinci karonun varyansı alınır (arka planı bulanık portrelerde net konu fotoğrafı "bulanık" saymaz).
  brightness  ortalama parlaklık 0..255.
  color       4×4 ortalama RGB imzası (48 bayt). pHash gri tonludur; aynı düzende ama farklı renkteki kareleri
              (gün batımı / öğle, renk varyasyonlu grafikler) ayırmak için phash ve küresel kenarlarda denetlenir.
  is_screenshot  dosya adı / biçim / ekran boyutu kuralı (PHP'deki cleanup_is_screenshot ile aynı).

Gruplama (group_assets): CLIP vektörleri + çekim zamanı + phash.
  1) Zaman komşuları: çekim zamanları en çok BURST_SECONDS arayla ve kosinüs ≥ T_BURST (≤ BURST_TIGHT sn ise
     ≥ T_BURST_TIGHT) — seri çekimler, aynı anın düzenlenmiş kopyaları. Yalnızca çekim zamanı güvenilir olanlar
     (date_source exif/takeout/filename/user; "file" = dosya tarihi, toplu kopyalamada hepsi aynı olabilir).
  2) phash: Hamming ≤ H_COPY ve kosinüs ≥ T_COPY_CLIP (zamandan bağımsız birebir görsel kopyalar; düz/boş
     karelerin yanlış eşleşmesini CLIP denetimi önler). Aday çiftler 4×16 bitlik bantlarla bulunur.
  3) Küresel CLIP: kosinüs ≥ T_GLOBAL (kırpılmış kopya, EXIF'i silinmiş yeniden kayıt); yalnızca çekim zamanı
     güvenilmez fotoğraflardan tüm kütüphaneye (O(m·n)); m·n > GLOBAL_MAX² ise atlanır.
  2 ve 3'te ayrıca renk imzası uzaklığı ≤ C_COPY ve en-boy oranı farkı ≤ AR_COPY olmalı (zamandan bağımsız
  eşleşmede düşük dokulu, aynı düzenli ama farklı fotoğrafların birleşmesini önler).
  Kenarlar güçlüden zayıfa birleştirilir; iki küme ancak tüm üye çiftleri kosinüs ≥ T_LINK ise ve toplam
  boyut MAX_GROUP'u aşmıyorsa birleşir (zincirleme ile alakasız fotoğrafların aynı gruba düşmesini önler).
  Yazı ağırlıklı kareler (belge sayfaları, slaytlar, fişler, ekran görüntüleri; texty): farklı sayfalar CLIP'te
  birbirine çok benzer (~0.90-0.96) ve seri çekim gibi saniyeler arayla çekilir. Bunlarda yalnızca neredeyse birebir
  kopyalar (Hamming ≤ H_TEXT ve kosinüs ≥ T_TEXT_COPY) gruplanır; yoksa "Önerileri uygula" farklı sayfaları çöpe atardı.
  Yazı ağırlığı CLIP metin istemiyle ölçülür (texty_mask) ve ekran görüntüsü kuralıyla birleştirilir.
  Grup nedeni: tüm çiftler Hamming ≤ H_DUP, kosinüs ≥ T_DUP ve netlik/parlaklık yakınsa "duplicate" (görsel olarak
  aynı kare: yeniden kaydedilmiş, küçültülmüş, sıkıştırılmış kopyalar), yoksa "similar".
"""
from __future__ import annotations

import math
import re

import numpy as np
from PIL import Image, ImageOps

ALGO = 1  # asset_quality.algo: ölçüm yöntemi değişirse artırılır (eski satırlar yeniden hesaplanır)

SHARP_EDGE = 1024
TILES = 4

# Gruplama eşikleri (SigLIP2 ViT-B-16 ile gerçek kopyalar ve "benzer ama farklı" çiftlerle ayarlandı;
# bkz. ml/tests/test_cleanup.py ve docs/ARCHITECTURE.md §Temizlik).
BURST_SECONDS = 120     # bu kadar saniye içindeki komşular seri çekim adayı
BURST_NEIGHBORS = 40
T_BURST = 0.955         # ... ve kosinüs en az bu
BURST_TIGHT = 10        # çok yakın (≤ 10 sn, aynı an dahil: düzenlenmiş kopya) kareler için
T_BURST_TIGHT = 0.93    # ... daha gevşek eşik
H_COPY = 8              # phash Hamming ≤ bu: aynı görüntü (yeniden kodlama/küçültme/bulanıklaştırma)
T_COPY_CLIP = 0.86      # ... ve CLIP doğrulaması
T_GLOBAL = 0.98         # zamandan bağımsız: yalnızca neredeyse aynı içerik (aynı kişinin farklı pozları ~0.97)
C_COPY = 16.0           # renk imzası ortalama mutlak farkı (0..255): kopyalar ≤ ~8, düzenleme ~10-27, farklı ≥ ~25
AR_COPY = 1.25          # en-boy oranları arası en büyük kat
T_LINK = 0.84           # grup içindeki her çift en az bu benzerlikte olmalı
H_DUP = 6               # "duplicate": tüm çiftler Hamming ≤ H_DUP, kosinüs ≥ T_DUP, netlik oranı ≤ DUP_SHARP_RATIO
T_DUP = 0.88
DUP_SHARP_RATIO = 3.0
DUP_BRIGHT_DIFF = 25.0
MAX_GROUP = 24
TEXT_PROMPT = "a page of printed text"  # yazı ağırlığı istemi (SigLIP2: belge ≥ ~0.09, fotoğraf ≤ ~0.05)
PHOTO_PROMPT = "a photo"
TEXT_MIN = 0.06         # metin istemi benzerliği en az bu
TEXT_MARGIN = 0.005     # ... ve "a photo" isteminden en az bu kadar yüksek (belge ≥ ~0.027, fotoğraf ≤ ~-0.017)
H_TEXT = 6              # yazı ağırlıklı karelerde kopya: Hamming ≤ bu
T_TEXT_COPY = 0.975     # ... ve kosinüs ≥ bu (aynı sayfanın kopyaları ≥ ~0.99, farklı sayfalar ≤ ~0.96)
MIN_EDGE = 64           # daha küçük görüntüler (simgeler, 1×1 test dosyaları) gruplanmaz
GLOBAL_MAX = 40000
GLOBAL_BLOCK = 2048

# ---------------------------------------------------------------- ölçümler

_DCT_CACHE: dict[int, np.ndarray] = {}


def _dct_matrix(n: int) -> np.ndarray:
    m = _DCT_CACHE.get(n)
    if m is None:
        k = np.arange(n)[:, None]
        i = np.arange(n)[None, :]
        m = np.cos(np.pi * (2 * i + 1) * k / (2 * n)) * math.sqrt(2.0 / n)
        m[0] /= math.sqrt(2.0)
        _DCT_CACHE[n] = m = m.astype(np.float64)
    return m


def phash_gray(gray: Image.Image) -> int:
    """64 bit pHash (işaretsiz tamsayı)."""
    small = np.asarray(gray.resize((32, 32), Image.Resampling.LANCZOS), dtype=np.float64)
    c = _dct_matrix(32)
    d = c @ small @ c.T
    low = d[:8, :8].flatten()
    med = np.median(low[1:])
    bits = low > med
    v = 0
    for b in bits:
        v = (v << 1) | int(b)
    return v


def sharpness_gray(gray: Image.Image) -> float:
    """Karolara bölünmüş Laplace varyansı (en net ikinci karo)."""
    g = gray
    if max(g.size) > SHARP_EDGE:
        g = g.copy()
        g.thumbnail((SHARP_EDGE, SHARP_EDGE), Image.Resampling.BILINEAR)
    a = np.asarray(g, dtype=np.float32)
    if a.shape[0] < 8 or a.shape[1] < 8:
        return 0.0
    lap = -4.0 * a[1:-1, 1:-1] + a[:-2, 1:-1] + a[2:, 1:-1] + a[1:-1, :-2] + a[1:-1, 2:]
    h, w = lap.shape
    vals = []
    for ty in range(TILES):
        for tx in range(TILES):
            t = lap[ty * h // TILES:(ty + 1) * h // TILES, tx * w // TILES:(tx + 1) * w // TILES]
            if t.size:
                vals.append(float(t.var()))
    vals.sort(reverse=True)
    return round(vals[1] if len(vals) > 1 else vals[0], 3)


def color_sig(rgb: Image.Image) -> bytes:
    """4×4 ortalama RGB (48 bayt)."""
    return np.asarray(rgb.resize((4, 4), Image.Resampling.BOX), dtype=np.uint8).tobytes()


def color_dist(a: bytes | None, b: bytes | None) -> float:
    if not a or not b or len(a) != len(b):
        return 0.0
    return float(np.abs(np.frombuffer(a, np.uint8).astype(np.int16) - np.frombuffer(b, np.uint8).astype(np.int16)).mean())


def brightness_gray(gray: Image.Image) -> float:
    g = gray.copy()
    g.thumbnail((64, 64), Image.Resampling.BILINEAR)
    return round(float(np.asarray(g, dtype=np.float32).mean()), 2)


def analyze_file(path: str) -> dict:
    """Türetilmiş görüntüden {phash, sharpness, brightness, color}."""
    with Image.open(path) as im:
        im.draft("RGB", (SHARP_EDGE, SHARP_EDGE))
        im = ImageOps.exif_transpose(im)
        rgb = im.convert("RGB")
    gray = rgb.convert("L")
    return {"phash": phash_gray(gray), "sharpness": sharpness_gray(gray), "brightness": brightness_gray(gray),
            "color": color_sig(rgb)}


# iPhone/iPad/yaygın Android ekran boyutları (dikey; yatay hali de sayılır).
SCREEN_SIZES = {
    (640, 1136), (750, 1334), (1242, 2208), (1080, 1920), (1125, 2436), (828, 1792), (1242, 2688),
    (1080, 2340), (1170, 2532), (1284, 2778), (1179, 2556), (1290, 2796), (1206, 2622), (1320, 2868),
    (1536, 2048), (1668, 2224), (1668, 2388), (1620, 2160), (1640, 2360), (2048, 2732), (1488, 2266),
    (1080, 2400), (1440, 3200), (1440, 3120), (1080, 2280), (720, 1600), (1440, 2960), (1080, 2408),
}
SCREENSHOT_NAME = re.compile(
    r"(screenshot|screen[ _-]?shot|ekran[ _-]?(resmi|görüntüsü|goruntusu|kaydı)|^scr_|^screen_|simulator screen)",
    re.IGNORECASE)


def is_screenshot(filename: str | None, mime: str | None, camera_make: str | None, width, height) -> bool:
    name = (filename or "").strip()
    if SCREENSHOT_NAME.search(name):
        return True
    if camera_make:
        return False
    if (mime or "").lower() == "image/png":
        return True
    try:
        w, h = int(width or 0), int(height or 0)
    except (TypeError, ValueError):
        return False
    return (min(w, h), max(w, h)) in SCREEN_SIZES


# ---------------------------------------------------------------- gruplama

def texty_mask(vecs: np.ndarray, text_vec: np.ndarray, photo_vec: np.ndarray) -> np.ndarray:
    """Yazı ağırlıklı (belge/slayt/fiş) kareler: metin istemine yakın ve "fotoğraf" isteminden belirgin yakın."""
    if vecs.shape[0] == 0:
        return np.zeros(0, dtype=bool)
    st = vecs @ np.asarray(text_vec, dtype=np.float32)
    sp = vecs @ np.asarray(photo_vec, dtype=np.float32)
    return (st >= TEXT_MIN) & (st - sp >= TEXT_MARGIN)


def popcount64(x: np.ndarray) -> np.ndarray:
    x = x.astype(np.uint64, copy=False)
    if hasattr(np, "bitwise_count"):
        return np.bitwise_count(x).astype(np.int32)
    b = x.view(np.uint8).reshape(-1, 8)
    return np.unpackbits(b, axis=1).sum(axis=1).astype(np.int32)


def hamming(a: int, b: int) -> int:
    return int((int(a) ^ int(b)).bit_count())


class _Groups:
    """Birleştirme kısıtlı (tam bağlantı: tüm çiftler ≥ T_LINK) ayrık kümeler."""

    def __init__(self, n: int, sim):
        self.parent = list(range(n))
        self.members: dict[int, list[int]] = {}
        self.sim = sim

    def find(self, i: int) -> int:
        while self.parent[i] != i:
            self.parent[i] = self.parent[self.parent[i]]
            i = self.parent[i]
        return i

    def union(self, a: int, b: int, t_link: float, max_group: int) -> bool:
        ra, rb = self.find(a), self.find(b)
        if ra == rb:
            return False
        ma = self.members.get(ra, [ra])
        mb = self.members.get(rb, [rb])
        if len(ma) + len(mb) > max_group:
            return False
        if len(ma) > 1 or len(mb) > 1:
            if float(self.sim(ma, mb).min()) < t_link:
                return False
        if len(ma) < len(mb):
            ra, rb, ma, mb = rb, ra, mb, ma
        self.parent[rb] = ra
        self.members[ra] = ma + mb
        self.members.pop(rb, None)
        return True


def group_assets(ids: list[str], taken: np.ndarray, vecs: np.ndarray, phash: list[int | None], *,
                 reliable: list[bool] | None = None, sharp: list[float | None] | None = None,
                 bright: list[float | None] | None = None, color: list[bytes | None] | None = None,
                 aspect: list[float | None] | None = None, texty: list[bool] | None = None, burst_seconds: float = BURST_SECONDS, t_burst: float = T_BURST,
                 burst_tight: float = BURST_TIGHT, t_burst_tight: float = T_BURST_TIGHT, h_copy: int = H_COPY,
                 t_copy_clip: float = T_COPY_CLIP, t_global: float = T_GLOBAL, t_link: float = T_LINK,
                 h_dup: int = H_DUP, t_dup: float = T_DUP, max_group: int = MAX_GROUP,
                 global_max: int = GLOBAL_MAX) -> list[dict]:
    """Benzer fotoğraf grupları: [{"assets": [id…], "reason": "duplicate"|"similar", "min_sim", "max_hamming"}].

    ids/taken (unix sn)/vecs (L2-normalize, n×d)/phash (None olabilir)/reliable (çekim zamanı güvenilir mi;
    None = hepsi)/sharp/bright/color (renk imzası)/aspect (genişlik/yükseklik)/texty (yazı ağırlıklı mı) aynı
    sıradadır.
    """
    n = len(ids)
    if n < 2:
        return []
    vecs = np.ascontiguousarray(vecs, dtype=np.float32)
    taken = np.asarray(taken, dtype=np.float64)
    edges: dict[tuple[int, int], float] = {}

    def same_look(i: int, j: int) -> bool:
        """Zamandan bağımsız kenarlar için renk ve en-boy oranı denetimi (bilinmiyorsa geçer)."""
        if color is not None and color_dist(color[i], color[j]) > C_COPY:
            return False
        if aspect is not None and aspect[i] and aspect[j]:
            r = aspect[i] / aspect[j]
            if max(r, 1 / r) > AR_COPY:
                return False
        return True

    def add(i: int, j: int, s: float) -> None:
        if texty is not None and (texty[i] or texty[j]):
            # Belge/slayt/ekran: yalnız neredeyse birebir kopya (farklı sayfalar CLIP'te çok benzer).
            if s < T_TEXT_COPY or phash[i] is None or phash[j] is None or hamming(phash[i], phash[j]) > H_TEXT:
                return
        k = (i, j) if i < j else (j, i)
        if s > edges.get(k, -1.0):
            edges[k] = s

    # 1) seri çekim: zamanca komşular
    rel = [k for k in range(n) if reliable is None or reliable[k]]
    order = sorted(rel, key=lambda k: taken[k])
    m = len(order)
    for p in range(m):
        i = order[p]
        q = p + 1
        while q < m and q - p <= BURST_NEIGHBORS and taken[order[q]] - taken[i] <= burst_seconds:
            j = order[q]
            s = float(vecs[i] @ vecs[j])
            if s >= (t_burst_tight if taken[j] - taken[i] <= burst_tight else t_burst):
                add(i, j, s)
            q += 1

    # 2) phash: 4×16 bit bant eşleşmesi → Hamming denetimi
    have = [k for k in range(n) if phash[k] is not None]
    if len(have) > 1:
        hv = np.array([int(phash[k]) for k in have], dtype=np.uint64)
        hidx = np.array(have, dtype=np.int64)
        cand: set[tuple[int, int]] = set()
        for band in range(4):
            key = (hv >> np.uint64(16 * band)) & np.uint64(0xFFFF)
            srt = np.argsort(key, kind="stable")
            ks = key[srt]
            start = 0
            for e in range(1, len(ks) + 1):
                if e == len(ks) or ks[e] != ks[start]:
                    if e - start > 1:
                        blk = srt[start:e]
                        if len(blk) > 200:  # düz/boş görüntülerin dev kovası: yalnızca yakın komşular
                            blk = blk[:200]
                        for x in range(len(blk)):
                            for y in range(x + 1, len(blk)):
                                a, b = int(blk[x]), int(blk[y])
                                cand.add((a, b) if a < b else (b, a))
                    start = e
        if cand:
            pa = np.array([c[0] for c in cand], dtype=np.int64)
            pb = np.array([c[1] for c in cand], dtype=np.int64)
            hd = popcount64(hv[pa] ^ hv[pb])
            ok = hd <= h_copy
            for a, b in zip(pa[ok], pb[ok]):
                i, j = int(hidx[a]), int(hidx[b])
                s = float(vecs[i] @ vecs[j])
                if s >= t_copy_clip and same_look(i, j):
                    add(i, j, s)

    # 3) küresel CLIP (bloklu). Çekim zamanı güvenilir iki fotoğrafın kopyası zaten 1. adımda (aynı an) bulunur;
    # zamandan bağımsız eşleşme yalnızca zamanı güvenilmez olanlar (EXIF'i silinmiş kopyalar) için aranır.
    if t_global < 1.0:
        src = np.arange(n) if reliable is None else np.flatnonzero(~np.asarray(reliable, dtype=bool))
        if 0 < src.size and src.size * n <= global_max * global_max:
            for s0 in range(0, src.size, GLOBAL_BLOCK):
                rows_i = src[s0:s0 + GLOBAL_BLOCK]
                blk = vecs[rows_i] @ vecs.T
                rows, cols = np.nonzero(blk >= t_global)
                for r, c in zip(rows.tolist(), cols.tolist()):
                    i = int(rows_i[r])
                    if c != i and same_look(i, c):
                        add(i, c, float(blk[r, c]))

    if not edges:
        return []

    def sim(ma: list[int], mb: list[int]) -> np.ndarray:
        return vecs[ma] @ vecs[mb].T

    g = _Groups(n, sim)
    for (i, j), _s in sorted(edges.items(), key=lambda kv: -kv[1]):
        g.union(i, j, t_link, max_group)

    out = []
    for root, mem in g.members.items():
        if len(mem) < 2:
            continue
        mem = sorted(mem, key=lambda k: (taken[k], ids[k]))
        sm = vecs[mem] @ vecs[mem].T
        iu = np.triu_indices(len(mem), 1)
        min_sim = float(sm[iu].min())
        hs = [hamming(phash[a], phash[b]) for x, a in enumerate(mem) for b in mem[x + 1:]
              if phash[a] is not None and phash[b] is not None]
        all_h = len(hs) == len(iu[0])
        max_h = max(hs) if hs else None
        dup = all_h and max_h is not None and max_h <= h_dup and min_sim >= t_dup
        if dup and sharp is not None:
            sv = [sharp[k] for k in mem if sharp[k] is not None]
            if len(sv) == len(mem) and max(sv) > DUP_SHARP_RATIO * max(min(sv), 1.0):
                dup = False
        if dup and bright is not None:
            bv = [bright[k] for k in mem if bright[k] is not None]
            if len(bv) == len(mem) and max(bv) - min(bv) > DUP_BRIGHT_DIFF:
                dup = False
        out.append({"assets": [ids[k] for k in mem], "reason": "duplicate" if dup else "similar",
                    "min_sim": round(min_sim, 4), "max_hamming": max_h})
    out.sort(key=lambda gr: gr["assets"][0])
    return out
