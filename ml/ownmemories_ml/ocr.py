"""Fotoğraftaki yazıyı okuma (OCR): PP-OCRv6 (PaddleOCR) çok dilli algılama + tanıma modelleri, onnxruntime ile.

Yalnızca onnxruntime + numpy + Pillow (servisin zaten kullandıkları); OpenCV/shapely/pyclipper gerekmez:
DB son işlemesi (ikili harita → bağlı bileşenler → en küçük döndürülmüş dikdörtgen → genişletme) burada
numpy ile yazıldı ve RapidOCR'ın (cv2) sonuçlarıyla karşılaştırılarak doğrulandı (ml/tests/ocr_bench.py).

Modeller (python -m ownmemories_ml download --model ocr): <models>/ppocr-v6/{det.onnx, rec.onnx} — PP-OCRv6 "tiny"
algılama (1.8 MB) + "small" tanıma (21 MB); ölçüm: tiny algılama small kadar isabetli, 3-4 kat ucuz (ml/tests/ocr_bench.py).
Karakter tablosu tanıma modelinin meta verisinde ("character"); Türkçe harfler (ç ğ ı İ ö ş ü) dahil.

    from ownmemories_ml.ocr import OcrModel
    m = OcrModel("/var/lib/ownmemories/models/ppocr-v6", threads=2)
    r = m.analyze_file("foto.webp")   # {"text", "lines": [(metin, güven)], "words", "lang", "ms"}

Gürültü süzgeci: güveni min_conf'tan düşük satırlar, harf/rakam içermeyen ya da tek karakterlik satırlar atılır;
kalan yazının toplam harf/rakam sayısı MIN_CHARS'tan azsa görüntü "yazısız" sayılır (text = "").
"""
from __future__ import annotations

import math
import os
import re
import time

import numpy as np
from PIL import Image, ImageDraw

MODEL_NAME = "ppocr-v6"  # <models>/ppocr-v6/{det.onnx (PP-OCRv6 det tiny), rec.onnx (PP-OCRv6 rec small)}
ENGINE = "ppocr-v6/1"  # asset_text.engine: model + son işleme sürümü (değişince yeniden dizinlenir)
DET_THRESH = 0.3
BOX_THRESH = 0.5
UNCLIP = 1.6
MIN_SIZE = 3
MAX_CANDIDATES = 600
REC_H = 48
REC_BATCH = 8
REC_MAX_COLS = 8 * 960  # toplu iş başına toplam tanıma genişliği (px, REC_H yüksekliğinde)
DET_MAX_SIDE = 1280  # algılama girdisinin uzun kenarı (32'nin katı); tanıma tam çözünürlükten kırpar
DET_MIN_SIDE = 640   # küçük görüntüler (küçük resimler) en az bu kısa kenara büyütülür
MIN_CONF = 0.80
MIN_CHARS = 3
MAX_LINES = 400
MAX_TEXT = 20000

TR_CHARS = set("çğıİöşüÇĞÖŞÜ")
# Tanımada izin verilen karakterler (Türkçe + İngilizce + yaygın Batı Avrupa harfleri, rakam, noktalama).
# Model 18 bin sınıflı (CJK, Kiril…); Türkçe tabelalarda Ğ→Č, İ→Í, Ö→Õ karışmasını önlemek için kod çözme
# yalnızca bu kümede yapılır (kalan olasılık en yakın Türkçe harfe gider).
ALLOWED = set(
    "".join(chr(c) for c in range(0x20, 0x7F))
    + "çğıİöşüÇĞÖŞÜâîûÂÎÛ"
    + "äëïÄËÏßéèêàáóòúùñÉÈÀÁÓÚÑ"
    + "₺€£$°§©®™«»–—‘’“”•…·×→←↔↑↓"
)
# Model Türkçe harfleri sık sık benzer yabancı harflerle okur (tabela yazı tiplerinde Ğ → Č/Ć, İ → Í, Ö → Õ,
# Ş → Š/Ș). Bunlar kod çözmede serbest bırakılıp Türkçe karşılıklarına çevrilir (ölçüm: ml/tests/ocr_bench.py).
CONFUSABLE = {"Č": "Ğ", "č": "ğ", "Ć": "Ğ", "ć": "ğ", "Ǧ": "Ğ", "ǧ": "ğ", "Ĝ": "Ğ", "ĝ": "ğ", "Ğ": "Ğ",
              "Í": "İ", "Ì": "İ", "Î": "Î", "í": "i", "ì": "i", "Õ": "Ö", "õ": "ö", "Ő": "Ö", "ő": "ö",
              "Š": "Ş", "š": "ş", "Ș": "Ş", "ș": "ş", "Ś": "Ş", "ś": "ş", "Ű": "Ü", "ű": "ü", "Ů": "Ü",
              "ů": "ü"}
ALLOWED |= set(CONFUSABLE)
_CONF_TR = str.maketrans(CONFUSABLE)
ALNUM = re.compile(r"[^\W_]", re.UNICODE)


def available(model_dir: str) -> bool:
    return all(os.path.isfile(os.path.join(model_dir, f)) for f in ("det.onnx", "rec.onnx"))


# ---------- geometri (numpy; cv2 yerine) ----------

def label_components(mask: np.ndarray, max_components: int = 100000) -> list[np.ndarray]:
    """8-komşulu bağlı bileşenler (satır koşuları + birleşim-bul). Her bileşen için (N, 2) [x, y] sınır
    noktaları döner (koşuların uçları: dışbükey zarf için yeterli)."""
    h, w = mask.shape
    m = mask.astype(np.int8)
    d = np.diff(np.pad(m, ((0, 0), (1, 1))), axis=1)
    rs, cs = np.nonzero(d == 1)
    re_, ce = np.nonzero(d == -1)
    # her satırda başlangıç/bitiş sırası aynı (soldan sağa)
    starts = np.stack([rs, cs], 1)
    ends = ce - 1
    n = starts.shape[0]
    if n == 0:
        return []
    parent = np.arange(n)

    def find(a: int) -> int:
        root = a
        while parent[root] != root:
            root = parent[root]
        while parent[a] != root:
            parent[a], a = root, parent[a]
        return root

    rows = starts[:, 0]
    # satır başına koşu dilimleri
    bounds = np.searchsorted(rows, np.arange(h + 1))
    for y in range(1, h):
        a0, a1 = bounds[y - 1], bounds[y]
        b0, b1 = bounds[y], bounds[y + 1]
        if a0 == a1 or b0 == b1:
            continue
        i, j = a0, b0
        while i < a1 and j < b1:
            # 8-komşuluk: [s-1, e+1] örtüşmesi
            if starts[i, 1] <= ends[j] + 1 and starts[j, 1] <= ends[i] + 1:
                ri, rj = find(i), find(j)
                if ri != rj:
                    parent[max(ri, rj)] = min(ri, rj)
            if ends[i] < ends[j]:
                i += 1
            else:
                j += 1
    roots = np.array([find(k) for k in range(n)])
    order = np.argsort(roots, kind="stable")
    rr = roots[order]
    cut = np.flatnonzero(np.diff(rr)) + 1
    out = []
    for grp in np.split(order, cut):
        ys = rows[grp]
        pts = np.concatenate([np.stack([starts[grp, 1], ys], 1), np.stack([ends[grp], ys], 1)])
        out.append(pts)
        if len(out) >= max_components:
            break
    return out


def convex_hull(pts: np.ndarray) -> np.ndarray:
    """Andrew'un monoton zinciri; (K, 2) float."""
    p = np.unique(pts.astype(np.float64), axis=0)
    if p.shape[0] <= 2:
        return p
    p = p[np.lexsort((p[:, 1], p[:, 0]))]

    def half(seq):
        h: list = []
        for q in seq:
            while len(h) >= 2 and ((h[-1][0] - h[-2][0]) * (q[1] - h[-2][1])
                                   - (h[-1][1] - h[-2][1]) * (q[0] - h[-2][0])) <= 0:
                h.pop()
            h.append(q)
        return h

    lower = half(p)
    upper = half(p[::-1])
    return np.array(lower[:-1] + upper[:-1])


def min_area_rect(pts: np.ndarray) -> tuple[np.ndarray, float, float, float]:
    """En küçük alanlı döndürülmüş dikdörtgen: (merkez(2), genişlik, yükseklik, açı rad). Piksel merkezleri
    yerine piksel kareleri kapsansın diye noktalar ±0.5 genişletilir (cv2.minAreaRect'in kontur davranışına yakın)."""
    hull = convex_hull(pts)
    if hull.shape[0] < 3:
        c = hull.mean(0) if hull.shape[0] else np.zeros(2)
        span = np.ptp(hull, 0) if hull.shape[0] else np.zeros(2)
        return c, float(span[0]) + 1.0, float(span[1]) + 1.0, 0.0
    edges = np.roll(hull, -1, 0) - hull
    ang = np.arctan2(edges[:, 1], edges[:, 0])
    ang = np.unique(np.mod(ang, np.pi / 2))
    best = None
    for a in ang:
        ca, sa = math.cos(a), math.sin(a)
        rot = hull @ np.array([[ca, -sa], [sa, ca]])
        mn, mx = rot.min(0), rot.max(0)
        area = (mx[0] - mn[0] + 1) * (mx[1] - mn[1] + 1)
        if best is None or area < best[0]:
            best = (area, a, mn, mx)
    _, a, mn, mx = best
    ca, sa = math.cos(a), math.sin(a)
    cr = (mn + mx) / 2
    center = np.array([cr[0] * ca - cr[1] * sa, cr[0] * sa + cr[1] * ca])
    return center, float(mx[0] - mn[0] + 1), float(mx[1] - mn[1] + 1), float(a)


def rect_points(center: np.ndarray, w: float, h: float, a: float) -> np.ndarray:
    """Dikdörtgen köşeleri: sol-üst, sağ-üst, sağ-alt, sol-alt (görüntü koordinatlarında)."""
    if w < h:  # uzun kenar yatay olsun (yazı satırı)
        w, h, a = h, w, a + math.pi / 2
    ca, sa = math.cos(a), math.sin(a)
    ux, uy = np.array([ca, sa]) * w / 2, np.array([-sa, ca]) * h / 2
    pts = np.array([center - ux - uy, center + ux - uy, center + ux + uy, center - ux + uy])
    return order_clockwise(pts)


def order_clockwise(pts: np.ndarray) -> np.ndarray:
    xs = pts[np.argsort(pts[:, 0], kind="stable")]
    left, right = xs[:2], xs[2:]
    tl, bl = left[np.argsort(left[:, 1], kind="stable")]
    tr, br = right[np.argsort(right[:, 1], kind="stable")]
    return np.array([tl, tr, br, bl], dtype=np.float32)


def box_score(pred: np.ndarray, box: np.ndarray) -> float:
    h, w = pred.shape
    x0 = int(np.clip(np.floor(box[:, 0].min()), 0, w - 1))
    x1 = int(np.clip(np.ceil(box[:, 0].max()), 0, w - 1))
    y0 = int(np.clip(np.floor(box[:, 1].min()), 0, h - 1))
    y1 = int(np.clip(np.ceil(box[:, 1].max()), 0, h - 1))
    m = Image.new("L", (x1 - x0 + 1, y1 - y0 + 1), 0)
    ImageDraw.Draw(m).polygon([(float(x - x0), float(y - y0)) for x, y in box], fill=1)
    mk = np.asarray(m, dtype=bool)
    sub = pred[y0:y1 + 1, x0:x1 + 1]
    return float(sub[mk].mean()) if mk.any() else 0.0


# ---------- model ----------

class OcrModel:
    def __init__(self, model_dir: str, threads: int = 2, min_conf: float = MIN_CONF,
                 det_max_side: int = DET_MAX_SIDE):
        import onnxruntime as ort

        so = ort.SessionOptions()
        so.intra_op_num_threads = max(1, threads)
        so.inter_op_num_threads = 1
        so.enable_cpu_mem_arena = False  # değişken girdi boyutları: arena büyüyüp RAM'i tutmasın
        so.log_severity_level = 3
        prov = ["CPUExecutionProvider"]
        self.det = ort.InferenceSession(os.path.join(model_dir, "det.onnx"), so, providers=prov)
        self.rec = ort.InferenceSession(os.path.join(model_dir, "rec.onnx"), so, providers=prov)
        meta = self.rec.get_modelmeta().custom_metadata_map
        chars = meta.get("character")
        if not chars:
            keys = os.path.join(model_dir, "keys.txt")
            with open(keys, encoding="utf-8") as fh:
                chars = fh.read()
        self.chars = ["blank"] + chars.split("\n") + [" "]
        allow = [0] + [i for i, ch in enumerate(self.chars) if i and ch in ALLOWED]
        self.allowed = np.array(sorted(set(allow)), dtype=np.int64)
        self.min_conf = min_conf
        self.det_max_side = det_max_side
        self.det_in = self.det.get_inputs()[0].name
        self.rec_in = self.rec.get_inputs()[0].name

    # --- algılama
    def detect(self, img: Image.Image) -> list[tuple[np.ndarray, float]]:
        """Yazı kutuları: [(4×2 köşe, kaynak görüntü koordinatlarında), puan], yukarıdan aşağı, soldan sağa."""
        W, H = img.size
        s = 1.0
        if max(W, H) > self.det_max_side:
            s = self.det_max_side / max(W, H)
        elif min(W, H) < DET_MIN_SIDE:
            s = min(DET_MIN_SIDE / min(W, H), self.det_max_side / max(W, H))
        rw = max(32, int(round(W * s / 32) * 32))
        rh = max(32, int(round(H * s / 32) * 32))
        x = np.asarray(img.resize((rw, rh), Image.BILINEAR), dtype=np.float32)[:, :, ::-1]  # BGR (Paddle)
        x = (x / 255.0 - 0.5) / 0.5
        x = x.transpose(2, 0, 1)[None].astype(np.float32)
        pred = self.det.run(None, {self.det_in: x})[0][0, 0]
        mask = pred > DET_THRESH
        dil = mask.copy()  # 2×2 genişletme
        dil[:, 1:] |= mask[:, :-1]
        dil[1:, :] |= mask[:-1, :]
        dil[1:, 1:] |= mask[:-1, :-1]
        comps = label_components(dil)
        comps.sort(key=lambda p: -p.shape[0])
        out = []
        for pts in comps[:MAX_CANDIDATES]:
            c, w, h, a = min_area_rect(pts)
            if min(w, h) < MIN_SIZE:
                continue
            box = rect_points(c, w, h, a)
            score = box_score(pred, box)
            if score < BOX_THRESH:
                continue
            # genişletme (pyclipper yuvarlak ofset ≈ her kenardan d kadar)
            dist = (w * h) * UNCLIP / (2 * (w + h))
            w2, h2 = w + 2 * dist, h + 2 * dist
            if min(w2, h2) < MIN_SIZE + 2:
                continue
            box = rect_points(c, w2, h2, a)
            box[:, 0] = np.clip(np.round(box[:, 0] / rw * W), 0, W - 1)
            box[:, 1] = np.clip(np.round(box[:, 1] / rh * H), 0, H - 1)
            bw = np.linalg.norm(box[0] - box[1])
            bh = np.linalg.norm(box[0] - box[3])
            if bw <= 3 or bh <= 3:
                continue
            out.append((box, score))
        out.sort(key=lambda b: (b[0][0, 1], b[0][0, 0]))
        # aynı satırdakiler (y farkı < 10 px) soldan sağa
        for i in range(len(out) - 1):
            for j in range(i, -1, -1):
                if abs(out[j + 1][0][0, 1] - out[j][0][0, 1]) < 10 and out[j + 1][0][0, 0] < out[j][0][0, 0]:
                    out[j], out[j + 1] = out[j + 1], out[j]
                else:
                    break
        return out

    @staticmethod
    def crop(img: Image.Image, box: np.ndarray) -> Image.Image:
        w = int(max(np.linalg.norm(box[0] - box[1]), np.linalg.norm(box[2] - box[3])))
        h = int(max(np.linalg.norm(box[0] - box[3]), np.linalg.norm(box[1] - box[2])))
        w, h = max(w, 1), max(h, 1)
        tl, tr, br, bl = box
        quad = (*tl, *bl, *br, *tr)
        c = img.transform((w, h), Image.QUAD, data=tuple(float(v) for v in quad), resample=Image.BICUBIC)
        if h / w >= 1.5:
            c = c.transpose(Image.ROTATE_90)
        return c

    # --- tanıma
    def recognize(self, crops: list[Image.Image]) -> list[tuple[str, float]]:
        res: list[tuple[str, float]] = [("", 0.0)] * len(crops)
        ratios = [c.width / max(1, c.height) for c in crops]
        idx = [int(i) for i in np.argsort(ratios)]
        # Toplu işler: en çok REC_BATCH satır ve toplam genişlik REC_MAX_COLS (uzun satırlı belgelerde bellek
        # tepe değerini sınırlar: 8 × 5000 px'lik satır ≈ 0.8 GB etkinleşme yerine ≈ 0.2 GB).
        parts: list[list[int]] = []
        for i in idx:
            w = int(REC_H * max(320 / REC_H, ratios[i]))
            if parts and len(parts[-1]) < REC_BATCH and w * (len(parts[-1]) + 1) <= REC_MAX_COLS:
                parts[-1].append(i)
            else:
                parts.append([i])
        for part in parts:
            maxr = max(320 / REC_H, max(ratios[i] for i in part))
            tw = int(REC_H * maxr)
            batch = np.zeros((len(part), 3, REC_H, tw), dtype=np.float32)
            for k, i in enumerate(part):
                c = crops[i]
                rw = min(tw, int(math.ceil(REC_H * ratios[i])))
                a = np.asarray(c.resize((max(1, rw), REC_H), Image.BILINEAR), dtype=np.float32)[:, :, ::-1]
                batch[k, :, :, :rw] = ((a / 255.0 - 0.5) / 0.5).transpose(2, 0, 1)
            pred = self.rec.run(None, {self.rec_in: batch})[0][:, :, self.allowed]
            ids = self.allowed[pred.argmax(2)]
            probs = pred.max(2)
            for k, i in enumerate(part):
                t = ids[k]
                sel = np.ones(t.shape[0], dtype=bool)
                sel[1:] = t[1:] != t[:-1]
                sel &= t != 0
                chars = [self.chars[j] if j < len(self.chars) else "" for j in t[sel]]
                conf = float(probs[k][sel].mean()) if sel.any() else 0.0
                res[i] = (fix_tokens("".join(chars).strip()), conf)
        return res

    # --- uçtan uca
    def analyze(self, img: Image.Image) -> dict:
        t0 = time.perf_counter()
        img = img.convert("RGB")
        boxes = self.detect(img)
        lines: list[tuple[str, float]] = []
        if boxes:
            crops = [self.crop(img, b) for b, _ in boxes[:MAX_LINES]]
            lines = self.recognize(crops)
        kept = clean_lines(lines, self.min_conf)
        text = "\n".join(t for t, _ in kept)[:MAX_TEXT]
        return {"text": text, "lines": kept, "raw": lines, "boxes": len(boxes), "words": count_words(text),
                "lang": guess_lang(text), "ms": round((time.perf_counter() - t0) * 1000, 1)}

    def analyze_file(self, path: str) -> dict:
        with Image.open(path) as im:
            im.draft("RGB", (4096, 4096))
            return self.analyze(im)


def clean_lines(lines: list[tuple[str, float]], min_conf: float = MIN_CONF) -> list[tuple[str, float]]:
    """Düşük güvenli ve anlamsız satırları at; toplam harf/rakam MIN_CHARS'tan azsa hepsini at."""
    out = []
    for t, c in lines:
        t = re.sub(r"\s+", " ", t).strip()
        n = len(ALNUM.findall(t))
        if c < min_conf or n < 2:
            continue
        # tek harf + noktalama yığınları ("| . -") gibi gürültü
        if n <= 2 and len(t) > 4:
            continue
        out.append((t, round(c, 3)))
    if sum(len(ALNUM.findall(t)) for t, _ in out) < MIN_CHARS:
        return []
    # Yalnız birkaç rakam (harf yok): pencere/dalga desenlerinde görülen tek sanrı türü ("1000"); yazısız say.
    joined = "".join(t for t, _ in out)
    if not re.search(r"[^\W\d_]", joined) and len(re.findall(r"\d", joined)) < 6:
        return []
    return out


_DIGIT_IN_WORD = re.compile(r"\b(?=\w*[A-ZÇĞİÖŞÜ]{2})[A-ZÇĞİÖŞÜ01]{3,}\b")


_TR_UP = {"i": "İ", "ı": "I", "ğ": "Ğ", "ş": "Ş", "ç": "Ç", "ö": "Ö", "ü": "Ü"}


def _upper_word(m: re.Match) -> str:
    """Büyük harfli sözcükte küçük okunmuş Türkçe harfi büyüt (iSTANBUL → İSTANBUL, ÇIğKÖFTE → ÇIĞKÖFTE)."""
    w = m.group(0)
    ups = sum(1 for c in w if c.isupper())
    lows = [c for c in w if c.islower()]
    if ups >= 2 and lows and all(c in _TR_UP for c in lows) and len(lows) <= max(1, ups // 3):
        return "".join(_TR_UP.get(c, c) for c in w)
    return w


def fix_tokens(line: str) -> str:
    """Büyük harfli sözcüklerin içinde I/O yerine okunan 1/0'ı düzelt (SOKAĞ1 → SOKAĞI, KAD1KÖY → KADIKÖY).
    Yalnızca en az iki büyük harf içeren ve başka rakam içermeyen sözcüklerde; plaka/telefon dokunulmaz."""
    line = line.translate(_CONF_TR)
    line = re.sub(r"\$(?=[A-ZÇĞİÖŞÜ]{2})", "Ş", line)  # "$AİR NEFİ" → "ŞAİR NEFİ"
    line = re.sub(r"[^\W\d_]{3,}", _upper_word, line)

    def fix(m: re.Match) -> str:
        w = m.group(0)
        if not re.search(r"[A-ZÇĞİÖŞÜ]", w[:1] + w[-1:]) and w[0] in "01" and w[-1] in "01":
            return w
        return w.replace("1", "I").replace("0", "O")
    return _DIGIT_IN_WORD.sub(fix, line)


def count_words(text: str) -> int:
    """asset_text.words: katlanmış sözcük sayısı (PHP ocr_store() ile aynı)."""
    f = fold(text)
    return len(f.split(" ")) if f else 0


def guess_lang(text: str) -> str | None:
    """Kaba dil tahmini: Türkçe harf ya da sık Türkçe ek/sözcük varsa "tr", Latin harfli ise "en", yoksa None."""
    if not text:
        return None
    if any(ch in TR_CHARS for ch in text):
        return "tr"
    low = text.lower()
    if re.search(r"\b(ve|bir|ile|için|bu|da|de|tl|no|tarih|toplam|fiş)\b", low):
        return "tr"
    if re.search(r"[a-z]", low):
        return "en"
    return None


# PHP places_fold() (web/includes/places.php) ile aynı tablo; ocr_fold() onu kullanır.
_FOLD_MAP = {
    "ı": "i", "ş": "s", "ğ": "g", "ç": "c", "ö": "o", "ü": "u", "â": "a", "î": "i", "û": "u",
    "á": "a", "à": "a", "ä": "a", "ã": "a", "å": "a", "ā": "a", "é": "e", "è": "e", "ê": "e", "ë": "e",
    "í": "i", "ì": "i", "ï": "i", "ó": "o", "ò": "o", "ô": "o", "õ": "o", "ø": "o", "ú": "u", "ù": "u",
    "ñ": "n", "ś": "s", "š": "s", "ž": "z", "ź": "z", "ż": "z", "č": "c", "ć": "c", "ř": "r", "ł": "l",
    "ß": "ss", "œ": "oe", "æ": "ae", "ý": "y", "ț": "t", "ţ": "t", "ș": "s", "ő": "o", "ű": "u", "ę": "e", "ą": "a",
}
_FOLD = str.maketrans(_FOLD_MAP)


def fold(text: str) -> str:
    """Arama için katlanmış metin: Türkçe duyarlı küçük harf + aksansız, yalnız [a-z0-9] sözcükler
    (İSTANBUL, ıstanbul, Istanbul → istanbul). PHP ocr_fold() = places_fold() ile birebir aynı olmalı
    (ortak örnekler: tests/fixtures/ocr/fold.json)."""
    import unicodedata

    s = text.replace("İ", "i").replace("I", "ı").lower().translate(_FOLD)
    if re.search(r"[^\x00-\x7f]", s):
        s = unicodedata.normalize("NFKD", s)
        s = "".join(ch for ch in s if not unicodedata.combining(ch))
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return re.sub(r" +", " ", s).strip()
