"""Model indirici: Hugging Face'teki Immich ONNX dışa aktarımını indirir.

    python -m ownmemories_ml download --model immich-app/ViT-B-16-SigLIP2__webli --dest /var/lib/ownmemories/models
    python -m ownmemories_ml download --model immich-app/buffalo_l --dest /var/lib/ownmemories/models   (yüz modeli)

- Yalnızca gereken dosyalar (CLIP: config.json ve visual/, textual/ altındaki ONNX + harici ağırlık, tokenizer,
  preprocess_cfg; yüz modeli: detection/model.onnx + recognition/model.onnx) indirilir; rknpu, fp16, armnn gibi
  donanıma özel dosyalar atlanır.
- Yarım kalan indirme ".part" dosyasından HTTP Range ile sürdürülür.
- Boyut ve (LFS dosyalarında) SHA-256, Hugging Face API'sinin verdiği değerlerle doğrulanır.
- Bitince <dest>/<model adı>/.complete yazılır; tekrar çalıştırmak doğrulanmış dosyaları atlar.
"""
from __future__ import annotations

import hashlib
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

HF = os.environ.get("HF_ENDPOINT", "https://huggingface.co").rstrip("/")
NEEDED = (
    "config.json",
    "visual/model.onnx",
    "visual/preprocess_cfg.json",
    "textual/model.onnx",
    "textual/tokenizer.json",
    "textual/tokenizer_config.json",
    "textual/special_tokens_map.json",
)
FACE_NEEDED = ("detection/model.onnx", "recognition/model.onnx")
OPTIONAL = {"textual/special_tokens_map.json"}
UA = "ownmemories-ml/1.0"


def is_face_model(model: str) -> bool:
    """Immich'in yüz tanıma paketleri (buffalo_l/buffalo_s/antelopev2…): detection/ + recognition/."""
    n = model_dir_name(model).lower()
    return n.startswith(("buffalo_", "antelope"))


def needed(model: str) -> tuple[str, ...]:
    return FACE_NEEDED if is_face_model(model) else NEEDED


def wanted(files: dict[str, dict], face: bool = False) -> list[str]:
    """CLIP: config.json + visual/ ve textual/ doğrudan altındaki dosyalar (harici ağırlık dosyaları dahil).
    Yüz: detection/ ve recognition/ doğrudan altındaki dosyalar. Donanıma özel alt klasörler (rknpu/, fp16/…)
    ve .rknn/.armnn dosyaları atlanır."""
    dirs = ("detection", "recognition") if face else ("visual", "textual")
    out = []
    for name in sorted(files):
        parts = name.split("/")
        if (name == "config.json" and not face) or (len(parts) == 2 and parts[0] in dirs):
            if not name.endswith((".rknn", ".armnn")):
                out.append(name)
    return out


def model_dir_name(model: str) -> str:
    """'immich-app/ViT-B-16-SigLIP2__webli' -> 'ViT-B-16-SigLIP2__webli' (dizin adı)."""
    return model.split("/")[-1]


def repo_id(model: str) -> str:
    return model if "/" in model else "immich-app/" + model


def _get(url: str, headers: dict | None = None, timeout: int = 60):
    req = urllib.request.Request(url, headers={"User-Agent": UA, **(headers or {})})
    return urllib.request.urlopen(req, timeout=timeout)


def list_files(model: str, revision: str = "main") -> dict[str, dict]:
    url = f"{HF}/api/models/{repo_id(model)}/tree/{urllib.parse.quote(revision)}?recursive=true"
    with _get(url) as r:
        items = json.load(r)
    out = {}
    for it in items:
        if it.get("type") != "file":
            continue
        lfs = it.get("lfs") or {}
        out[it["path"]] = {"size": int(lfs.get("size") or it.get("size") or 0), "sha256": lfs.get("oid")}
    return out


def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(8 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _verify(path: str, meta: dict, check_hash: bool = True) -> bool:
    if not os.path.isfile(path):
        return False
    if meta.get("size") and os.path.getsize(path) != meta["size"]:
        return False
    if check_hash and meta.get("sha256"):
        return sha256_file(path) == meta["sha256"]
    return True


def _fetch(url: str, dest: str, meta: dict, log) -> None:
    part = dest + ".part"
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    attempts = 0
    while True:
        have = os.path.getsize(part) if os.path.exists(part) else 0
        if meta.get("size") and have > meta["size"]:
            os.remove(part)
            have = 0
        if meta.get("size") and have == meta["size"]:
            break
        headers = {"Range": f"bytes={have}-"} if have else {}
        try:
            with _get(url, headers, timeout=120) as r:
                mode = "ab" if have and r.status == 206 else "wb"
                if mode == "wb":
                    have = 0
                done, last = have, time.time()
                with open(part, mode) as f:
                    while True:
                        buf = r.read(1 << 20)
                        if not buf:
                            break
                        f.write(buf)
                        done += len(buf)
                        if time.time() - last > 10 and meta.get("size"):
                            log(f"    {os.path.basename(os.path.dirname(dest))}/{os.path.basename(dest)}: "
                                f"{done * 100 // meta['size']}% ({done >> 20} / {meta['size'] >> 20} MB)")
                            last = time.time()
            if not meta.get("size"):
                break
        except (urllib.error.URLError, OSError, TimeoutError) as e:
            if isinstance(e, urllib.error.HTTPError) and e.code == 416:
                os.remove(part)
                continue
            attempts += 1
            if attempts > 8:
                raise
            log(f"    bağlantı hatası ({e}); {attempts}. yeniden deneme…")
            time.sleep(min(60, 2 ** attempts))
    if not _verify(part, meta):
        os.remove(part)
        raise RuntimeError(f"doğrulama başarısız (boyut/sha256): {dest}")
    os.replace(part, dest)


def download(model: str, dest_root: str, revision: str = "main", log=print) -> str:
    """Modeli indirir; model dizinini döner. Hata durumunda istisna fırlatır."""
    target = os.path.join(dest_root, model_dir_name(model))
    marker = os.path.join(target, ".complete")
    face = is_face_model(model)
    need = needed(model)
    try:
        files = list_files(model, revision)
    except (urllib.error.URLError, OSError, ValueError) as e:
        # Çevrimdışı güncelleme: daha önce tamamlanmış model varsa onu kullan.
        if os.path.isfile(marker) and all(os.path.isfile(os.path.join(target, n)) for n in need if n not in OPTIONAL):
            log(f"    Hugging Face'e ulaşılamadı ({e}); mevcut model kullanılıyor.")
            return target
        raise
    os.makedirs(target, exist_ok=True)
    manifest = {}
    if os.path.isfile(marker):
        try:
            with open(marker) as f:
                manifest = json.load(f)
        except (OSError, ValueError):
            manifest = {}
    for name in need:
        if name not in files and name not in OPTIONAL:
            raise RuntimeError(f"modelde dosya yok: {name}")
    for name in wanted(files, face):
        meta = files[name]
        path = os.path.join(target, name)
        # Önceki tam doğrulamayla aynı boyut/sha ise yeniden hash hesaplama (hızlı tekrar çalıştırma).
        same = manifest.get(name) == meta and _verify(path, meta, check_hash=False)
        if same or _verify(path, meta):
            log(f"    hazır: {name}")
            continue
        log(f"    indiriliyor: {name} ({meta['size'] >> 20} MB)")
        url = f"{HF}/{repo_id(model)}/resolve/{urllib.parse.quote(revision)}/{name}"
        _fetch(url, path, meta, log)
    with open(marker + ".tmp", "w") as f:
        json.dump({n: files[n] for n in wanted(files, face)}, f)
    os.replace(marker + ".tmp", marker)
    return target


def main(argv: list[str]) -> int:
    import argparse

    p = argparse.ArgumentParser(prog="ownmemories_ml download")
    p.add_argument("--model", default=os.environ.get("OM_ML_MODEL", "immich-app/ViT-B-16-SigLIP2__webli"))
    p.add_argument("--dest", default=os.environ.get("OM_ML_MODEL_DIR", "/var/lib/ownmemories/models"))
    p.add_argument("--revision", default="main")
    a = p.parse_args(argv)
    try:
        path = download(a.model, a.dest, a.revision)
    except Exception as e:  # noqa: BLE001
        print(f"Model indirilemedi: {e}", file=sys.stderr)
        return 1
    print(path)
    return 0
