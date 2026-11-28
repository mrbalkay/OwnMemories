"""Yapılandırma: ortam değişkenleri (/etc/ownmemories/ml.env, systemd EnvironmentFile ile).

OM_DB_HOST, OM_DB_PORT, OM_DB_NAME, OM_DB_USER, OM_DB_PASS  veritabanı
OM_STORAGE        depolama kökü (derived/<thumb|preview|poster>/...)
OM_ML_MODEL       Immich ONNX modeli (ör. immich-app/ViT-B-16-SigLIP2__webli)
OM_ML_MODEL_DIR   modellerin kök dizini (/var/lib/ownmemories/models); model <kök>/<ad> altında
OM_ML_PORT        dinlenecek port (yalnızca 127.0.0.1), varsayılan 8765
OM_ML_THREADS     ONNX iş parçacığı sayısı (varsayılan: min(4, CPU))
İsteğe bağlı: OM_ML_BATCH (16), OM_ML_IDLE (20 sn), OM_ML_TEXT_QUANT (1: int8 metin modeli varsa kullan),
OM_ML_INDEX (1: dizinleyiciyi çalıştır), OM_ML_LANG (tr; yalnızca NLLB modelleri için),
OM_CONFIG_PHP (DB/depolama değişkenleri yoksa okunacak config.php).
Sonuç eşiği (istekte min_score yoksa; bkz. service.relevance_cut): OM_ML_MIN_Z (0 = kapalı),
OM_ML_MIN_SCORE (bu puanın üstü her zaman tutulur); varsayılanlar modele göre, bkz. CUTOFF.
Yüz tanıma (Kişiler; model yoksa kendiliğinden kapalı, CLIP etkilenmez):
OM_FACE_MODEL (immich-app/buffalo_l; boş ya da "0" = kapalı), OM_FACE_MIN_SCORE (0.7, algılama eşiği),
OM_FACE_MIN_SIZE (32 px, kaynak görüntüde en kısa kenar), OM_FACE_MAX_DISTANCE (0.5, kosinüs uzaklığı),
OM_FACE_MIN_FACES (3, yeni kişi için gereken yüz), OM_FACE_BATCH (4, CLIP işi varken tur başına görüntü).
Fotoğraftaki yazı (OCR; model yoksa kendiliğinden kapalı): OM_OCR (1; "0" = kapalı), OM_OCR_BATCH (8, tur başına
görüntü; CLIP işi bitince çalışır), OM_OCR_MIN_CONF (0.80, satır güven eşiği), OM_OCR_DET_SIDE (1280, algılama çözünürlüğü),
OM_OCR_MODEL_DIR (varsayılan <OM_ML_MODEL_DIR>/ppocr-v6).
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
from dataclasses import dataclass

DEFAULT_MODEL = "immich-app/ViT-B-16-SigLIP2__webli"
DEFAULT_FACE_MODEL = "immich-app/buffalo_l"
DEFAULT_CONFIG_PHP = "/var/www/ownmemories/web/config/config.php"

# Modele göre varsayılan ilgi eşiği (min_z, kesin-ilgili puan); Crossmodal-3600'ün 1000 görüntüsü ve
# Türkçe açıklamalarıyla ölçüldü (ml/tests/xm3600_bench.py). Puan eşiği eşleşmeyen çiftlerin ~%99.5-99.8'i
# üstünde; z eşiği doğru görselin ~%92-99'unu tutar. Bilinmeyen model: (2.0, yok).
CUTOFF = {
    "ViT-B-16-SigLIP2__webli": (2.0, 0.10),
    "nllb-clip-base-siglip__v1": (2.0, 0.07),
}


def model_short(model: str) -> str:
    return model.strip().split("/")[-1]


@dataclass
class Config:
    db_host: str = "127.0.0.1"
    db_port: int = 3306
    db_name: str = "ownmemories"
    db_user: str = "ownmemories"
    db_pass: str = ""
    storage: str = "/srv/ownmemories"
    model: str = DEFAULT_MODEL
    model_root: str = "/var/lib/ownmemories/models"
    port: int = 8765
    threads: int = 2
    batch: int = 16
    idle: float = 20.0
    text_quant: bool = True
    index: bool = True
    language: str = "tr"
    min_z: float = 2.0
    min_score: float | None = None  # "kesin ilgili" puan (OM_ML_MIN_SCORE), bkz. CUTOFF
    face_model: str = DEFAULT_FACE_MODEL  # "" = yüz tanıma kapalı
    face_min_score: float = 0.7
    face_min_size: float = 32.0
    face_max_distance: float = 0.5
    face_min_faces: int = 3
    face_batch: int = 4
    ocr: bool = True
    ocr_batch: int = 8
    ocr_min_conf: float = 0.80
    ocr_det_side: int = 1280
    ocr_dir: str = ""  # boş: <model_root>/ppocr-v6

    @property
    def model_name(self) -> str:
        """DB'deki asset_embeddings.model değeri (kısa ad)."""
        return model_short(self.model)

    @property
    def model_dir(self) -> str:
        return os.path.join(self.model_root, self.model_name)

    @property
    def face_model_name(self) -> str:
        """DB'deki faces.model / face_scans.model değeri (kısa ad); "" = kapalı."""
        return model_short(self.face_model) if self.face_model else ""

    @property
    def face_model_dir(self) -> str:
        return os.path.join(self.model_root, self.face_model_name) if self.face_model else ""

    @property
    def ocr_model_dir(self) -> str:
        from .ocr import MODEL_NAME

        return self.ocr_dir or os.path.join(self.model_root, MODEL_NAME)


def _php_config(path: str) -> dict:
    php = shutil.which("php")
    if not php or not os.path.isfile(path):
        return {}
    code = "echo json_encode(require $argv[1]);"
    try:
        out = subprocess.run([php, "-r", code, path], capture_output=True, timeout=20, check=True).stdout
        return json.loads(out or b"{}") or {}
    except (OSError, subprocess.SubprocessError, ValueError):
        return {}


def load(env: dict | None = None) -> Config:
    e = dict(os.environ if env is None else env)
    c = Config()
    if not e.get("OM_DB_NAME") or not e.get("OM_STORAGE"):
        php = _php_config(e.get("OM_CONFIG_PHP", DEFAULT_CONFIG_PHP))
        db = php.get("db") or {}
        st = php.get("storage") or {}
        fallback = {
            "OM_DB_HOST": db.get("host"), "OM_DB_PORT": db.get("port"), "OM_DB_NAME": db.get("name"),
            "OM_DB_USER": db.get("user"), "OM_DB_PASS": db.get("pass"), "OM_STORAGE": st.get("path"),
        }
        for k, v in fallback.items():
            if v not in (None, "") and not e.get(k):
                e[k] = str(v)
    c.db_host = e.get("OM_DB_HOST", c.db_host) or c.db_host
    c.db_port = int(e.get("OM_DB_PORT") or c.db_port)
    c.db_name = e.get("OM_DB_NAME", c.db_name) or c.db_name
    c.db_user = e.get("OM_DB_USER", c.db_user) or c.db_user
    c.db_pass = e.get("OM_DB_PASS", c.db_pass)
    c.storage = (e.get("OM_STORAGE") or c.storage).rstrip("/")
    c.model = e.get("OM_ML_MODEL") or c.model
    c.model_root = e.get("OM_ML_MODEL_DIR") or c.model_root
    c.port = int(e.get("OM_ML_PORT") or c.port)
    c.threads = int(e.get("OM_ML_THREADS") or min(4, os.cpu_count() or 2))
    c.batch = max(1, min(64, int(e.get("OM_ML_BATCH") or c.batch)))
    c.idle = float(e.get("OM_ML_IDLE") or c.idle)
    c.text_quant = (e.get("OM_ML_TEXT_QUANT", "1") or "1") not in ("0", "false", "no")
    c.index = (e.get("OM_ML_INDEX", "1") or "1") not in ("0", "false", "no")
    c.language = e.get("OM_ML_LANG") or c.language
    dz, ds = CUTOFF.get(c.model_name, (c.min_z, None))
    mz = e.get("OM_ML_MIN_Z")
    c.min_z = max(0.0, float(mz)) if mz not in (None, "") else dz
    ms = e.get("OM_ML_MIN_SCORE")
    c.min_score = float(ms) if ms not in (None, "") else ds
    fm = e.get("OM_FACE_MODEL")
    if fm is not None:
        c.face_model = "" if fm.strip().lower() in ("", "0", "false", "no", "off") else fm.strip()
    c.face_min_score = float(e.get("OM_FACE_MIN_SCORE") or c.face_min_score)
    c.face_min_size = max(0.0, float(e.get("OM_FACE_MIN_SIZE") or c.face_min_size))
    c.face_max_distance = float(e.get("OM_FACE_MAX_DISTANCE") or c.face_max_distance)
    c.face_min_faces = max(1, int(e.get("OM_FACE_MIN_FACES") or c.face_min_faces))
    c.face_batch = max(1, min(64, int(e.get("OM_FACE_BATCH") or c.face_batch)))
    c.ocr = (e.get("OM_OCR", "1") or "1").strip().lower() not in ("0", "false", "no", "off")
    c.ocr_batch = max(1, min(64, int(e.get("OM_OCR_BATCH") or c.ocr_batch)))
    c.ocr_min_conf = min(0.99, max(0.3, float(e.get("OM_OCR_MIN_CONF") or c.ocr_min_conf)))
    c.ocr_det_side = max(640, min(2048, int(e.get("OM_OCR_DET_SIDE") or c.ocr_det_side)) // 32 * 32)
    c.ocr_dir = e.get("OM_OCR_MODEL_DIR") or ""
    return c
