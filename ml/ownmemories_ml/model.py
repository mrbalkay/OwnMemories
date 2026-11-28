"""CLIP/SigLIP ONNX modeli: görsel ve metin kodlayıcılar.

Immich'in machine-learning (immich_ml/models/clip/{visual,textual}.py, models/transforms.py)
uygulamasını birebir izler:

Görsel: visual/preprocess_cfg.json → size, mean, std, interpolation, resize_mode.
  - resize_mode "squash"  : doğrudan size×size'a yeniden boyutlandır (SigLIP/SigLIP2).
  - resize_mode "shortest": kısa kenarı size yap, ortadan kırp (eski OpenCLIP).
  - [0,1]'e ölçekle, (x - mean) / std, NCHW float32. Girdi uint8 ise (normalizes_input) ham RGB NHWC.
Metin: config.json text_cfg.context_length (SigLIP2: 64), tokenizer_config.json pad_token;
  tokenizer sabit uzunluğa pad + truncation; metin clean_text ile boşlukları sadeleştirilir,
  tokenizer_kwargs.clean == "canonicalize" ise noktalama atılır ve küçük harfe çevrilir.
Çıktılar L2-normalize edilir (kosinüs benzerliği = iç çarpım).
"""
from __future__ import annotations

import json
import os
import string
import threading
from typing import Any

import numpy as np
from PIL import Image, ImageOps

_PUNCT = str.maketrans("", "", string.punctuation)
_RESAMPLE = {r.name.lower(): r for r in Image.Resampling}


def clean_text(text: str, canonicalize: bool = False) -> str:
    text = " ".join(text.split())
    if canonicalize:
        # Türkçe "İ".lower() -> "i̇" (i + birleşen nokta) üretir; tokenizer için düz "i" daha doğru.
        text = text.replace("İ", "i").translate(_PUNCT).lower()
    return text


def l2n(x: np.ndarray) -> np.ndarray:
    x = x.astype(np.float32, copy=False)
    n = np.linalg.norm(x, axis=-1, keepdims=True)
    n[n == 0] = 1.0
    return x / n


def _session(path: str, threads: int):
    import onnxruntime as ort

    so = ort.SessionOptions()
    so.intra_op_num_threads = max(1, threads)
    so.inter_op_num_threads = 1
    so.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
    so.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
    # Bellek arenası büyük toplu işlerde şişebilir; tepe RAM'i düşük tutmak için kapalı.
    so.enable_cpu_mem_arena = False
    return ort.InferenceSession(path, sess_options=so, providers=["CPUExecutionProvider"])


class VisualEncoder:
    def __init__(self, model_dir: str, threads: int = 2):
        vdir = os.path.join(model_dir, "visual")
        with open(os.path.join(vdir, "preprocess_cfg.json"), encoding="utf-8") as f:
            cfg = json.load(f)
        size = cfg["size"]
        self.size = int(size[0] if isinstance(size, list) else size)
        self.resample = _RESAMPLE[str(cfg.get("interpolation", "bicubic")).lower()]
        self.mean = np.array(cfg.get("mean", [0.5, 0.5, 0.5]), dtype=np.float32)
        self.std = np.array(cfg.get("std", [0.5, 0.5, 0.5]), dtype=np.float32)
        self.resize_mode = cfg.get("resize_mode", "shortest")
        if self.resize_mode not in ("squash", "shortest"):
            raise ValueError(f"desteklenmeyen resize_mode: {self.resize_mode}")
        self.sess = _session(os.path.join(vdir, "model.onnx"), threads)
        inp = self.sess.get_inputs()[0]
        self.input_name = inp.name
        self.uint8_input = inp.type == "tensor(uint8)"
        # Toplu iş boyutu sabitse (ör. 1) tek tek çalıştır.
        b = inp.shape[0] if inp.shape else None
        self.fixed_batch = b if isinstance(b, int) else None
        self.dim = int(self.sess.get_outputs()[0].shape[-1])
        self.lock = threading.Lock()

    def _resize(self, img: Image.Image) -> Image.Image:
        s = self.size
        if self.resize_mode == "squash":
            return img.resize((s, s), resample=self.resample)
        if img.width < img.height:
            img = img.resize((s, int(img.height / img.width * s)), resample=self.resample)
        else:
            img = img.resize((int(img.width / img.height * s), s), resample=self.resample)
        left = int(img.size[0] / 2 - s / 2)
        top = int(img.size[1] / 2 - s / 2)
        return img.crop((left, top, left + s, top + s))

    def load(self, path: str) -> np.ndarray:
        """Dosyayı açıp modele hazır diziye çevirir (tek görüntü, toplu boyut olmadan)."""
        with Image.open(path) as im:
            im.draft("RGB", (self.size * 2, self.size * 2))  # büyük JPEG'lerde hızlı ölçekli çözme
            im = ImageOps.exif_transpose(im)
            im = im.convert("RGB")
            im = self._resize(im)
        rgb = np.asarray(im)
        if self.uint8_input:
            return rgb
        x = rgb.astype(np.float32) / 255.0
        x = (x - self.mean) / self.std
        return x.transpose(2, 0, 1).astype(np.float32, copy=False)

    def embed_arrays(self, arrays: list[np.ndarray]) -> np.ndarray:
        if not arrays:
            return np.zeros((0, self.dim), dtype=np.float32)
        with self.lock:
            if self.fixed_batch == 1:
                outs = [self.sess.run(None, {self.input_name: a[None]})[0] for a in arrays]
                out = np.concatenate(outs, axis=0)
            else:
                out = self.sess.run(None, {self.input_name: np.stack(arrays)})[0]
        return l2n(out.reshape(len(arrays), -1))

    def embed_files(self, paths: list[str]) -> np.ndarray:
        return self.embed_arrays([self.load(p) for p in paths])


# NLLB tabanlı çok dilli modeller (ör. nllb-clip-base-siglip__v1) metnin önüne FLORES-200 dil kodu ister
# (Immich: f"{flores_code}{text}").
FLORES = {"tr": "tur_Latn", "en": "eng_Latn", "de": "deu_Latn", "fr": "fra_Latn", "es": "spa_Latn",
          "it": "ita_Latn", "nl": "nld_Latn", "ru": "rus_Cyrl", "ar": "arb_Arab"}


class TextEncoder:
    def __init__(self, model_dir: str, threads: int = 2, onnx_file: str | None = None, language: str = "tr"):
        from tokenizers import Tokenizer

        tdir = os.path.join(model_dir, "textual")
        with open(os.path.join(model_dir, "config.json"), encoding="utf-8") as f:
            model_cfg = json.load(f)
        with open(os.path.join(tdir, "tokenizer_config.json"), encoding="utf-8") as f:
            tok_cfg = json.load(f)
        text_cfg: dict[str, Any] = model_cfg.get("text_cfg", {})
        self.context_length = int(text_cfg.get("context_length", 77))
        kw = text_cfg.get("tokenizer_kwargs") or {}
        self.canonicalize = kw.get("clean") == "canonicalize"
        pad = tok_cfg["pad_token"]
        if isinstance(pad, dict):
            pad = pad.get("content")
        tok = Tokenizer.from_file(os.path.join(tdir, "tokenizer.json"))
        pad_id = tok.token_to_id(pad)
        if pad_id is None:
            raise ValueError(f"pad token bulunamadı: {pad}")
        tok.enable_padding(length=self.context_length, pad_token=pad, pad_id=pad_id)
        tok.enable_truncation(max_length=self.context_length)
        self.tok = tok
        name = os.path.basename(os.path.normpath(model_dir)).lower()
        self.prefix = FLORES.get(language.split("-")[0].lower(), "eng_Latn") if name.startswith("nllb") else ""
        path = onnx_file or os.path.join(tdir, "model.onnx")
        self.onnx_path = path
        self.sess = _session(path, threads)
        self.inputs = {i.name: i for i in self.sess.get_inputs()}
        self.dim = int(self.sess.get_outputs()[0].shape[-1])
        self.lock = threading.Lock()

    def tokenize(self, text: str) -> dict[str, np.ndarray]:
        enc = self.tok.encode(self.prefix + clean_text(text, self.canonicalize))
        feed = {}
        for name, inp in self.inputs.items():
            dtype = np.int64 if inp.type == "tensor(int64)" else np.int32
            if name in ("text", "input_ids"):
                feed[name] = np.array([enc.ids], dtype=dtype)
            elif name == "attention_mask":
                feed[name] = np.array([enc.attention_mask], dtype=dtype)
            else:
                raise ValueError(f"beklenmeyen metin modeli girdisi: {name}")
        return feed

    def embed(self, text: str) -> np.ndarray:
        feed = self.tokenize(text)
        with self.lock:
            out = self.sess.run(None, feed)[0]
        return l2n(out.reshape(1, -1))[0]


def text_model_path(model_dir: str, prefer_quantized: bool = True) -> str:
    """Kurulumda üretilen int8 metin modeli varsa onu, yoksa orijinali döner."""
    q = os.path.join(model_dir, "textual", "model.int8.onnx")
    if prefer_quantized and os.path.isfile(q):
        return q
    return os.path.join(model_dir, "textual", "model.onnx")
