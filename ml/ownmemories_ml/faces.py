"""Yüz algılama + tanıma (Immich "buffalo_l" ONNX: SCRFD det_10g + ArcFace w600k_r50).

Immich'in machine-learning kodunu (immich_ml/models/facial_recognition/{detection,recognition,_ops}.py ve
models/transforms.py: letterbox, normalize) izler; OpenCV yerine yalnızca numpy + Pillow kullanır:

Algılama (FaceDetector._predict):
  - Görüntü RGB; uzun kenar 640 olacak şekilde ölçeklenip 640×640 siyah tuvalin sol üstüne konur (letterbox),
    ölçek = yeni_yükseklik / yükseklik.
  - (x - 127.5) / 128, NCHW float32.
  - Çıktılar: her FPN adımı (8, 16, 32) için skor, kutu uzaklıkları, 5 nokta; hücre başına 2 çapa
    (decode_scrfd: kutu = merkez -/+ uzaklık·adım, noktalar = merkez + ofset·adım).
  - skor >= min_score (Immich varsayılanı 0.7), kutular/ölçek, NMS (IoU 0.4; cv2.dnn.NMSBoxes gibi açgözlü).
Tanıma (FaceRecognizer._predict):
  - 5 nokta, standart ArcFace şablonuna Umeyama benzerlik dönüşümüyle (en küçük kareler) hizalanır; 112×112
    kesit (cv2.warpAffine yerine PIL affine + aynı piksel merkezi kuralı, çift doğrusal, kenar siyah).
  - (x - 127.5) / 127.5, NCHW float32; 512 boyutlu vektör L2-normalize edilir (kosinüs = iç çarpım).

Not: InsightFace buffalo_l ağırlıkları yalnızca ticari olmayan kullanım içindir (docs/INSTALL.md).
"""
from __future__ import annotations

import os
import threading
from dataclasses import dataclass
from functools import lru_cache

import numpy as np
from PIL import Image, ImageOps

DET_SIZE = 640
ALIGNED_SIZE = 112
DET_STRIDES = (8, 16, 32)
ANCHORS_PER_CELL = 2
NMS_THRESHOLD = 0.4

# 112×112 kesit için kanonik ArcFace 5 nokta şablonu (sol göz, sağ göz, burun, sol ağız, sağ ağız)
ARCFACE_DST = np.array(
    [[38.2946, 51.6963], [73.5318, 51.5014], [56.0252, 71.7366], [41.5493, 92.3655], [70.7299, 92.2041]],
    dtype=np.float32,
)


@lru_cache(maxsize=len(DET_STRIDES))
def _anchor_centers(size: int, stride: int) -> np.ndarray:
    ys, xs = np.mgrid[: size // stride, : size // stride]
    centers = np.stack([xs, ys], axis=-1).astype(np.float32).reshape(-1, 2) * stride
    return np.repeat(centers, ANCHORS_PER_CELL, axis=0)


def _squeeze_batch(a: np.ndarray) -> np.ndarray:
    """(N, k) ya da (1, N, k) -> (N, k)."""
    a = np.asarray(a, dtype=np.float32)
    while a.ndim > 2:
        a = a[0]
    return a


def decode_scrfd(heads: list[np.ndarray], size: int = DET_SIZE) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """SCRFD çıktılarını (9 baş: 3 skor, 3 kutu, 3 nokta) skor (N,), kutu (N,4), nokta (N,10)'a çevirir."""
    n = len(DET_STRIDES)
    scores, boxes, kps = [], [], []
    for level, stride in enumerate(DET_STRIDES):
        centers = _anchor_centers(size, stride)
        sc = _squeeze_batch(heads[level]).reshape(-1)
        distance = _squeeze_batch(heads[level + n]) * stride
        offsets = _squeeze_batch(heads[level + 2 * n]) * stride
        scores.append(sc)
        boxes.append(np.concatenate([centers - distance[:, :2], centers + distance[:, 2:]], axis=1))
        kps.append(np.tile(centers, offsets.shape[1] // 2) + offsets)
    return np.concatenate(scores), np.concatenate(boxes), np.concatenate(kps)


def nms(boxes: np.ndarray, scores: np.ndarray, threshold: float = NMS_THRESHOLD) -> np.ndarray:
    """Açgözlü NMS (cv2.dnn.NMSBoxes ile aynı: skora göre azalan, IoU > eşik olanları bastır)."""
    if boxes.shape[0] == 0:
        return np.zeros(0, dtype=np.intp)
    x1, y1, x2, y2 = boxes[:, 0], boxes[:, 1], boxes[:, 2], boxes[:, 3]
    areas = np.maximum(0.0, x2 - x1) * np.maximum(0.0, y2 - y1)
    order = np.argsort(-scores, kind="stable")
    keep = []
    while order.size:
        i = order[0]
        keep.append(i)
        rest = order[1:]
        w = np.maximum(0.0, np.minimum(x2[i], x2[rest]) - np.maximum(x1[i], x1[rest]))
        h = np.maximum(0.0, np.minimum(y2[i], y2[rest]) - np.maximum(y1[i], y1[rest]))
        inter = w * h
        union = areas[i] + areas[rest] - inter
        iou = np.where(union > 0, inter / np.maximum(union, 1e-12), 0.0)
        order = rest[iou <= threshold]
    return np.asarray(keep, dtype=np.intp)


def umeyama(src: np.ndarray, dst: np.ndarray) -> np.ndarray:
    """src noktalarını dst'ye taşıyan benzerlik dönüşümü (2×3), Umeyama en küçük kareler (ölçek+dönme+öteleme)."""
    src = np.asarray(src, dtype=np.float64)
    dst = np.asarray(dst, dtype=np.float64)
    src_mean, dst_mean = src.mean(0), dst.mean(0)
    src_c, dst_c = src - src_mean, dst - dst_mean
    cov = dst_c.T @ src_c / len(src)
    u, s, vt = np.linalg.svd(cov)
    d = np.sign(np.linalg.det(u @ vt))
    if d == 0:
        d = 1.0
    diag = np.diag([1.0, d])
    rotation = u @ diag @ vt
    var = (src_c ** 2).sum() / len(src)
    scale = np.trace(np.diag(s) @ diag) / var if var > 0 else 1.0
    translation = dst_mean - scale * rotation @ src_mean
    return np.hstack([scale * rotation, translation[:, None]]).astype(np.float32)


def warp_affine(img: Image.Image, m: np.ndarray, size: int) -> np.ndarray:
    """cv2.warpAffine(img, m, (size, size)) karşılığı (çift doğrusal, kenar siyah) — PIL ile.

    m kaynak->hedef dönüşümüdür (OpenCV kuralı: piksel merkezleri tam sayı). PIL hedef->kaynak katsayıları ister
    ve piksel merkezlerini x+0.5'te alır: c' = c + 0.5 - A·(0.5, 0.5).
    """
    a = np.vstack([m.astype(np.float64), [0.0, 0.0, 1.0]])
    inv = np.linalg.inv(a)[:2]
    lin, t = inv[:, :2], inv[:, 2]
    t = t + 0.5 - lin @ np.array([0.5, 0.5])
    coeffs = (lin[0, 0], lin[0, 1], t[0], lin[1, 0], lin[1, 1], t[1])
    out = img.transform((size, size), Image.Transform.AFFINE, coeffs, resample=Image.Resampling.BILINEAR,
                        fillcolor=(0, 0, 0))
    return np.asarray(out, dtype=np.uint8)


def letterbox(img: Image.Image, size: int = DET_SIZE) -> tuple[np.ndarray, float]:
    w, h = img.size
    if h > w:
        nh, nw = size, int(size * w / h)
    else:
        nw, nh = size, int(size * h / w)
    nw, nh = max(1, nw), max(1, nh)
    canvas = np.zeros((size, size, 3), dtype=np.uint8)
    canvas[:nh, :nw] = np.asarray(img.resize((nw, nh), resample=Image.Resampling.BILINEAR), dtype=np.uint8)
    return canvas, nh / h


def l2n(x: np.ndarray) -> np.ndarray:
    x = x.astype(np.float32, copy=False)
    n = np.linalg.norm(x, axis=-1, keepdims=True)
    n[n == 0] = 1.0
    return x / n


@dataclass
class Face:
    box: tuple[float, float, float, float]  # kaynak görüntü pikselleri (x1, y1, x2, y2)
    norm: tuple[float, float, float, float]  # 0..1, görüntülenen (EXIF'e göre döndürülmüş) görüntüye göre
    score: float
    landmarks: np.ndarray  # (5, 2)
    embedding: np.ndarray  # (512,) float32, L2-normalize


def load_image(path: str, max_side: int = 0) -> Image.Image:
    with Image.open(path) as im:
        if max_side:
            im.draft("RGB", (max_side, max_side))
        im = ImageOps.exif_transpose(im)
        im = im.convert("RGB")
        im.load()
    return im


def model_files(model_dir: str) -> tuple[str, str]:
    return (os.path.join(model_dir, "detection", "model.onnx"), os.path.join(model_dir, "recognition", "model.onnx"))


def available(model_dir: str) -> bool:
    return all(os.path.isfile(p) for p in model_files(model_dir))


def _session(path: str, threads: int):
    import onnxruntime as ort

    so = ort.SessionOptions()
    so.intra_op_num_threads = max(1, threads)
    so.inter_op_num_threads = 1
    so.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
    so.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
    so.enable_cpu_mem_arena = False
    # Tanıma modeli çıktısını (1, 512) bildirir ama toplu girdi kabul eder; her çağrıdaki uyarıyı sustur.
    so.log_severity_level = 3
    return ort.InferenceSession(path, sess_options=so, providers=["CPUExecutionProvider"])


class FaceModel:
    def __init__(self, model_dir: str, threads: int = 2, min_score: float = 0.7, min_size: float = 32.0,
                 max_batch: int = 16):
        det, rec = model_files(model_dir)
        self.det = _session(det, threads)
        self.rec = _session(rec, threads)
        self.det_input = self.det.get_inputs()[0].name
        rin = self.rec.get_inputs()[0]
        self.rec_input = rin.name
        b = rin.shape[0] if rin.shape else None
        self.rec_batch = b if isinstance(b, int) else max_batch
        self.dim = int(self.rec.get_outputs()[0].shape[-1] or 512)
        self.min_score = float(min_score)
        self.min_size = float(min_size)
        self.lock = threading.Lock()

    # ---- algılama
    def detect(self, img: Image.Image) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
        """-> kutular (n,4) kaynak pikselleri, skorlar (n,), noktalar (n,5,2)."""
        canvas, scale = letterbox(img, DET_SIZE)
        blob = ((canvas.astype(np.float32) - 127.5) / 128.0).transpose(2, 0, 1)[None]
        with self.lock:
            heads = self.det.run(None, {self.det_input: np.ascontiguousarray(blob)})
        scores, boxes, kps = decode_scrfd(heads, DET_SIZE)
        cand = scores >= self.min_score
        scores, boxes, kps = scores[cand], boxes[cand] / scale, kps[cand] / scale
        keep = nms(boxes, scores)
        boxes, scores, kps = boxes[keep].round(), scores[keep], kps[keep].reshape(-1, 5, 2)
        if self.min_size > 0 and boxes.shape[0]:
            ok = np.minimum(boxes[:, 2] - boxes[:, 0], boxes[:, 3] - boxes[:, 1]) >= self.min_size
            boxes, scores, kps = boxes[ok], scores[ok], kps[ok]
        return boxes, scores, kps

    # ---- tanıma
    def embed(self, img: Image.Image, landmarks: np.ndarray) -> np.ndarray:
        n = len(landmarks)
        if n == 0:
            return np.zeros((0, self.dim), dtype=np.float32)
        crops = np.empty((n, ALIGNED_SIZE, ALIGNED_SIZE, 3), dtype=np.uint8)
        for i, kps in enumerate(landmarks):
            crops[i] = warp_affine(img, umeyama(kps, ARCFACE_DST), ALIGNED_SIZE)
        x = ((crops.astype(np.float32) - 127.5) / 127.5).transpose(0, 3, 1, 2)
        outs = []
        with self.lock:
            for i in range(0, n, self.rec_batch):
                outs.append(self.rec.run(None, {self.rec_input: np.ascontiguousarray(x[i:i + self.rec_batch])})[0])
        return l2n(np.concatenate(outs, axis=0).reshape(n, -1))

    def analyze(self, img: Image.Image) -> list[Face]:
        boxes, scores, kps = self.detect(img)
        if boxes.shape[0] == 0:
            return []
        emb = self.embed(img, kps)
        w, h = img.size
        out = []
        for b, s, k, e in zip(boxes, scores, kps, emb):
            x1, y1, x2, y2 = (float(v) for v in b)
            norm = (min(1.0, max(0.0, x1 / w)), min(1.0, max(0.0, y1 / h)),
                    min(1.0, max(0.0, x2 / w)), min(1.0, max(0.0, y2 / h)))
            out.append(Face(box=(x1, y1, x2, y2), norm=norm, score=float(s), landmarks=k, embedding=e))
        return out

    def analyze_file(self, path: str) -> tuple[list[Face], tuple[int, int]]:
        img = load_image(path)
        return self.analyze(img), img.size
