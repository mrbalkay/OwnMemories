"""Türkçe arama kalitesi ölçümü: Crossmodal-3600 (XM3600, 3600 görüntü, 36 dilde açıklama).

Model seçimi ve varsayılan ilgi eşiği (config.CUTOFF) bununla ölçüldü. İki ölçüm yapar:
  1) Açıklama → görüntü: her görüntünün Türkçe (ve İngilizce) açıklamalarıyla R@1/5/10.
  2) Kısa Türkçe anahtar sorgular ("köpek", "kumsal", "karlı dağ"…): İngilizce açıklamalardaki kelimelerle
     doğruluk etiketi; P@10, mAP ve varsayılan eşiğin (z / puan) tuttuğu sonuçların kesinlik/duyarlılığı.

Veri (ücretsiz, ~330 MB):
  curl -LO https://google.github.io/crossmodal-3600/web-data/captions.zip && unzip captions.zip
  curl -LO https://open-images-dataset.s3.amazonaws.com/crossmodal-3600/images.tgz && mkdir imgs && tar xzf images.tgz -C imgs

    python -I ml/tests/xm3600_bench.py --data <klasör> --model-dir <models>/ViT-B-16-SigLIP2__webli [--n 1000]

Görüntüler uygulamanın küçük resimleri gibi kısa kenarı 360 px'e küçültülüp önbelleğe alınır; görüntü
vektörleri <data>/img_<model>_<n>.npy dosyasında saklanır (ikinci çalıştırma yalnızca metni kodlar).
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

import numpy as np  # noqa: E402
from PIL import Image  # noqa: E402

from ownmemories_ml.config import CUTOFF  # noqa: E402
from ownmemories_ml.model import TextEncoder, VisualEncoder, text_model_path  # noqa: E402
from ownmemories_ml.service import relevance_cut  # noqa: E402

# Türkçe sorgu -> İngilizce açıklamalarda "doğru" sayılacak kalıp
KEYWORDS = {
    "köpek": r"\bdogs?\b|puppy|puppies", "kedi": r"\bcats?\b|kitten", "araba": r"\bcars?\b",
    "kumsal": r"beach|shore|sand", "deniz": r"\bsea\b|ocean|beach|\bwaves?\b", "dağ": r"mountain|\bhills?\b",
    "kar": r"\bsnow", "çiçek": r"flower|blossom", "yemek": r"\bfood|dish|meal|plate of|pizza|soup|rice|salad",
    "çocuk": r"\bchild|\bkids?\b|\bboy\b|\bgirl\b|toddler|baby", "gece": r"\bnight|dark sky",
    "kuş": r"\bbirds?\b|parrot|pigeon|duck|chicken|rooster|hen\b", "at": r"\bhorses?\b",
    "bisiklet": r"bicycle|\bbikes?\b", "tekne": r"\bboats?\b|ship|canoe|kayak", "orman": r"forest|woods|jungle",
    "göl": r"\blake\b|pond", "kilise": r"church|cathedral|temple|mosque", "pasta": r"\bcake",
    "uçak": r"airplane|aircraft|plane\b|jet\b", "kitap": r"\bbooks?\b", "gün batımı": r"sunset|sunrise|dusk",
    "sokak": r"\bstreet|\broad\b", "inek": r"\bcows?\b|cattle|\bbull", "bebek": r"\bbaby|infant|toddler",
    "kumsalda köpek": r"(dog.*(beach|sand|shore))|((beach|sand|shore).*dog)",
    "karlı dağ": r"(snow.*mountain)|(mountain.*snow)", "doğum günü pastası": r"birthday|\bcake",
    "kırmızı araba": r"red car|red (sports |old |vintage )?car", "insan kalabalığı": r"crowd|group of people|many people",
    "şehir manzarası": r"city|skyline|buildings", "masa": r"\btable|desk",
}


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--data", required=True)
    p.add_argument("--model-dir", required=True)
    p.add_argument("--n", type=int, default=1000)
    p.add_argument("--threads", type=int, default=4)
    p.add_argument("--fp32", action="store_true", help="int8 metin modeli varsa bile fp32 kullan")
    a = p.parse_args()
    caps = {}
    with open(os.path.join(a.data, "captions.jsonl"), encoding="utf-8") as f:
        for line in f:
            d = json.loads(line)
            caps[d["image/key"]] = {lang: d.get(lang, {}).get("caption", []) for lang in ("tr", "en")}
    keys = sorted(caps)[:a.n]
    name = os.path.basename(os.path.normpath(a.model_dir))
    th = os.path.join(a.data, "thumbs")
    os.makedirs(th, exist_ok=True)
    for k in keys:
        dst = os.path.join(th, k + ".jpg")
        if not os.path.exists(dst):
            with Image.open(os.path.join(a.data, "imgs", k + ".jpg")) as im:
                im = im.convert("RGB")
                s = 360 / min(im.size)
                im.resize((round(im.width * s), round(im.height * s)), Image.LANCZOS).save(dst, quality=82)
    cache = os.path.join(a.data, f"img_{name}_{a.n}.npy")
    if os.path.exists(cache):
        img = np.load(cache)
    else:
        vis = VisualEncoder(a.model_dir, a.threads)
        t0 = time.perf_counter()
        img = vis.embed_files([os.path.join(th, k + ".jpg") for k in keys])
        print(f"görsel: {(time.perf_counter() - t0) / len(keys) * 1000:.0f} ms/görüntü")
        np.save(cache, img)
        del vis
    txt = TextEncoder(a.model_dir, a.threads, onnx_file=text_model_path(a.model_dir, not a.fp32))
    print(f"model: {name}  metin: {os.path.basename(txt.onnx_path)}  görüntü: {len(keys)}")

    for lang in ("tr", "en"):
        r = np.zeros(3)
        n = 0
        for i, k in enumerate(keys):
            for c in caps[k][lang]:
                s = img @ txt.embed(c)
                rank = int((s > s[i]).sum())
                r += [rank < 1, rank < 5, rank < 10]
                n += 1
        r = r / max(1, n) * 100
        print(f"açıklama→görüntü [{lang}] R@1 {r[0]:.1f}  R@5 {r[1]:.1f}  R@10 {r[2]:.1f}  (n={n})")

    min_z, abs_score = CUTOFF.get(name, (2.0, None))
    en = [" ".join(caps[k]["en"]).lower() for k in keys]
    rows = []
    for q, rx in KEYWORDS.items():
        gt = np.array([bool(re.search(rx, c)) for c in en])
        if not gt.any():
            continue
        s = img @ txt.embed(q)
        o = np.argsort(-s)
        hits = np.cumsum(gt[o])
        ap = float((hits / np.arange(1, len(o) + 1))[gt[o]].mean())
        keep = relevance_cut(s, min_z, abs_score)
        kept = int(keep.sum())
        prec = float(gt[keep].mean()) if kept else 0.0
        rec = float(gt[keep].sum() / gt.sum())
        rows.append((gt[o[:10]].mean(), ap, kept, prec, rec))
        print(f"  {q:20s} doğru={gt.sum():3d}  P@10={gt[o[:10]].mean():.1f}  AP={ap:.2f}  eşik: {kept:3d} sonuç, "
              f"kesinlik {prec:.2f}, duyarlılık {rec:.2f}")
    m = np.array(rows).mean(axis=0)
    print(f"anahtar sorgular ({len(rows)}): P@10 {m[0]:.3f}  mAP {m[1]:.3f}  | eşik (z>={min_z}, puan>={abs_score}): "
          f"ort. {m[2]:.0f}/{len(keys)} sonuç, kesinlik {m[3]:.2f}, duyarlılık {m[4]:.2f}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
