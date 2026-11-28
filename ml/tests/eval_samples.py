"""Örnek fotoğraflarla model doğrulaması: Türkçe/İngilizce sorguların doğru görselleri bulduğunu,
gecikmeyi ve RAM'i ölçer; isteğe bağlı int8 metin modelini fp32 ile karşılaştırır.

    python -I ml/tests/eval_samples.py --model-dir <models>/ViT-B-16-SigLIP2__webli --images <klasör> [--threads 4]

Beklenen eşleşmeler EXPECT içinde (görsel dosya adı -> sorgular). Çıkış kodu: tüm beklenen görseller
ilk 3 içinde ise 0.
"""
from __future__ import annotations

import argparse
import os
import resource
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

import numpy as np  # noqa: E402

from ownmemories_ml.model import TextEncoder, VisualEncoder  # noqa: E402

# Örnek görseller (tests: /tmp/.../img/p1..p18.jpg) için sorgu -> doğru görsel(ler)
EXPECT = {
    "denizanası": ["p5"], "jellyfish": ["p5"],
    "şato": ["p2"], "kale": ["p2"], "castle": ["p2"],
    "orman": ["p7", "p13"], "ormanda patika": ["p7", "p13"], "forest": ["p7", "p13"],
    "asma kilitler": ["p10"], "padlocks": ["p10"],
    "pazar": ["p6"], "yüzen pazar": ["p6"], "floating market": ["p6"],
    "buğday tarlası": ["p3", "p16"], "wheat field": ["p3", "p16"],
    "deniz": ["p11", "p15", "p17"], "denizde gemiler": ["p11"], "ships at sea": ["p11"],
    "kahve fincanı": ["p9"], "coffee cup": ["p9"],
    "klavye ve gözlük": ["p18"], "keyboard": ["p18"],
    "tren istasyonu": ["p4"], "train station": ["p4"],
    "iskele": ["p14"], "pier": ["p14"],
    "sisli ağaçlar": ["p12"], "foggy trees": ["p12"],
    "masada telefon": ["p1"], "eski makine": ["p8"],
    "kumsal": ["p15", "p11", "p17"], "kayalık sahil": ["p15"],
}


def rss_mb() -> float:
    try:
        with open("/proc/self/status") as f:
            for line in f:
                if line.startswith("VmRSS:"):
                    return int(line.split()[1]) / 1024
    except OSError:
        pass
    return resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024


def evaluate(names, img, txt, label):
    ok = 0
    t_total = 0.0
    lines = []
    for q, want in EXPECT.items():
        t0 = time.perf_counter()
        v = txt.embed(q)
        t_total += time.perf_counter() - t0
        s = img @ v
        order = np.argsort(-s)
        top = [names[i] for i in order[:3]]
        hit = any(w in top for w in want) and names[order[0]] in want
        hit3 = any(w in top for w in want)
        ok += hit3
        mark = "OK " if hit else ("ok3" if hit3 else "XX ")
        lines.append(f"  {mark} {q:20s} -> " + ", ".join(f"{names[i]}:{s[i]:.3f}" for i in order[:3]))
    print(f"[{label}] ilk-3 isabet: {ok}/{len(EXPECT)}  ortalama sorgu süresi: {t_total / len(EXPECT) * 1000:.1f} ms")
    print("\n".join(lines))
    return ok


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--model-dir", required=True)
    p.add_argument("--images", required=True)
    p.add_argument("--threads", type=int, default=4)
    p.add_argument("--quant", default="", help="karşılaştırılacak int8 metin modeli (textual/model.int8.onnx)")
    p.add_argument("--quant-only", action="store_true", help="fp32 metin modelini hiç yükleme (RAM ölçümü)")
    a = p.parse_args()
    names = sorted((f[:-4] for f in os.listdir(a.images) if f.endswith(".jpg")), key=lambda n: int(n[1:]))
    print(f"başlangıç RSS: {rss_mb():.0f} MB")
    t0 = time.perf_counter()
    vis = VisualEncoder(a.model_dir, a.threads)
    print(f"görsel model yüklendi: {time.perf_counter() - t0:.1f} sn, RSS {rss_mb():.0f} MB")
    paths = [os.path.join(a.images, n + ".jpg") for n in names]
    vis.embed_files(paths[:1])  # ısınma
    t0 = time.perf_counter()
    arrays = [vis.load(x) for x in paths]
    t_dec = time.perf_counter() - t0
    t0 = time.perf_counter()
    img = vis.embed_arrays(arrays)
    t_inf = time.perf_counter() - t0
    print(f"görsel: {len(paths)} görüntü, çözme {t_dec / len(paths) * 1000:.1f} ms/görüntü, "
          f"çıkarım {t_inf / len(paths) * 1000:.1f} ms/görüntü, RSS {rss_mb():.0f} MB")
    ok = 0
    if not a.quant_only:
        t0 = time.perf_counter()
        txt = TextEncoder(a.model_dir, a.threads)
        print(f"metin modeli (fp32) yüklendi: {time.perf_counter() - t0:.1f} sn, RSS {rss_mb():.0f} MB")
        txt.embed("ısınma")
        ok = evaluate(names, img, txt, "fp32")
        fp = {q: txt.embed(q) for q in EXPECT}
    if a.quant:
        before = rss_mb()
        t0 = time.perf_counter()
        qt = TextEncoder(a.model_dir, a.threads, onnx_file=a.quant)
        print(f"metin modeli (int8) yüklendi: {time.perf_counter() - t0:.1f} sn, +{rss_mb() - before:.0f} MB RSS")
        qt.embed("ısınma")
        okq = evaluate(names, img, qt, "int8")
        if not a.quant_only:
            cos = [float(fp[q] @ qt.embed(q)) for q in EXPECT]
            print(f"fp32-int8 metin vektörü kosinüs: min {min(cos):.4f} ort {np.mean(cos):.4f}")
        ok = ok or okq
    print(f"son RSS: {rss_mb():.0f} MB, tepe: {resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024:.0f} MB")
    return 0 if ok == len(EXPECT) else 1


if __name__ == "__main__":
    sys.exit(main())
