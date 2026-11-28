"""OCR motoru karşılaştırması: doğruluk (Türkçe) + görüntü başına süre/CPU, yazısız fotoğraflarda gürültü.

    # 1) değerlendirme kümesi: sentetik (ocr_synth.py) + Wikimedia Commons gerçek fotoğrafları (ocr_commons.py)
    python -I ml/tests/ocr_synth.py --out /tmp/ocr/synth --scenes <yazısız fotoğraf klasörü>
    python -I ml/tests/ocr_commons.py --out /tmp/ocr/real
    # 2) ölçüm (motorlar: ppocr = ownmemories_ml.ocr, rapidocr = pip rapidocr, tess = tesseract tur+eng)
    python -I ml/tests/ocr_bench.py --sets /tmp/ocr/synth /tmp/ocr/real --model-dir <models>/ppocr-v6-small \
        --engines ppocr tess [rapidocr] --negatives <yazısız fotoğraf klasörü> --threads 4

Her küme klasöründe truth.json ({"dosya adı (uzantısız)": "doğru metin"}) beklenir.
Ölçütler (Türkçe duyarlı katlama sonrası; bkz. ocr.fold):
  word_recall   doğru metindeki sözcüklerin (≥3 harf ya da sayı) OCR çıktısında bulunma oranı (arama için asıl ölçüt)
  exact_recall  aynısı, katlama yapılmadan (yalnızca Türkçe küçük harf): ç ğ ı İ ö ş ü doğru mu okunuyor
  cer           karakter hata oranı (katlanmış, satırlar boşlukla birleştirilmiş; Levenshtein / doğru uzunluk)
  ms / cpu_ms   görüntü başına duvar saati ve CPU süresi
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import re
import resource
import subprocess
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

from ownmemories_ml.ocr import clean_lines, fold  # noqa: E402


def tr_lower(s: str) -> str:
    return s.replace("İ", "i").replace("I", "ı").lower()


def words(s: str, folded: bool = True) -> list[str]:
    t = fold(s) if folded else re.sub(r"[^\w]+", " ", tr_lower(s))
    return [w for w in t.split() if len(w) >= 3 or w.isdigit()]


def lev(a: str, b: str) -> int:
    if len(a) < len(b):
        a, b = b, a
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        for j, cb in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
        prev = cur
    return prev[-1]


def score(truth: str, out: str) -> dict:
    tw = words(truth)
    ow = set(words(out)) | set(fold(out).replace(" ", "|").split("|"))
    ofold = " " + fold(out) + " "
    hit = sum(1 for w in tw if w in ow or f" {w} " in ofold)
    te = words(truth, False)
    oe = set(words(out, False))
    hit_e = sum(1 for w in te if w in oe)
    ft, fo = fold(truth), fold(out)
    return {"word_recall": hit / max(1, len(tw)), "exact_recall": hit_e / max(1, len(te)),
            "cer": min(1.0, lev(ft, fo) / max(1, len(ft)))}


# ---------- motorlar ----------

class PPOCR:
    name = "ppocr"

    def __init__(self, model_dir: str, threads: int, det_max_side: int):
        from ownmemories_ml.ocr import OcrModel

        self.m = OcrModel(model_dir, threads, det_max_side=det_max_side)
        self.name = f"ppocr@{det_max_side}"

    def __call__(self, path: str) -> str:
        return self.m.analyze_file(path)["text"]


class Rapid:
    name = "rapidocr"

    def __init__(self, threads: int):
        from rapidocr import RapidOCR

        self.e = RapidOCR(params={"Global.log_level": "error", "EngineConfig.onnxruntime.intra_op_num_threads": threads})

    def __call__(self, path: str) -> str:
        r = self.e(path)
        lines = list(zip(r.txts or (), r.scores or ()))
        return "\n".join(t for t, _ in clean_lines(lines))


class Tess:
    def __init__(self, threads: int, psm: int = 3, min_conf: float = 60.0):
        self.env = {**os.environ, "OMP_THREAD_LIMIT": str(threads)}
        self.psm = psm
        self.min_conf = min_conf
        self.name = f"tess-psm{psm}"

    def __call__(self, path: str) -> str:
        r = subprocess.run(["tesseract", path, "stdout", "-l", "tur+eng", "--psm", str(self.psm), "tsv"],
                           capture_output=True, env=self.env, timeout=120)
        lines: dict[tuple, list[str]] = {}
        for row in r.stdout.decode("utf-8", "replace").splitlines()[1:]:
            c = row.split("\t")
            if len(c) < 12 or not c[11].strip():
                continue
            try:
                conf = float(c[10])
            except ValueError:
                continue
            if conf < self.min_conf:
                continue
            lines.setdefault((c[2], c[3], c[4]), []).append(c[11])
        return "\n".join(" ".join(v) for v in lines.values())


def cpu_now() -> float:
    a = resource.getrusage(resource.RUSAGE_SELF)
    b = resource.getrusage(resource.RUSAGE_CHILDREN)
    return a.ru_utime + a.ru_stime + b.ru_utime + b.ru_stime


def run(engine, items: list[tuple[str, str, str]], show: bool) -> dict:
    agg = {"word_recall": 0.0, "exact_recall": 0.0, "cer": 0.0, "ms": 0.0, "cpu_ms": 0.0}
    rows = []
    engine(items[0][1])  # ısınma
    for name, path, truth in items:
        t0, c0 = time.perf_counter(), cpu_now()
        out = engine(path)
        ms, cpu = (time.perf_counter() - t0) * 1000, (cpu_now() - c0) * 1000
        s = score(truth, out)
        s.update(ms=ms, cpu_ms=cpu)
        for k in agg:
            agg[k] += s[k]
        rows.append((name, s, out))
        if show:
            print(f"   {name:16s} wr={s['word_recall']:.2f} er={s['exact_recall']:.2f} cer={s['cer']:.2f} "
                  f"{ms:6.0f} ms | {out[:110]!r}")
    n = max(1, len(items))
    return {k: round(v / n, 3 if k not in ("ms", "cpu_ms") else 0) for k, v in agg.items()} | {"n": len(items),
                                                                                              "rows": rows}


def negatives(engine, paths: list[str]) -> dict:
    hits, total_ms = [], 0.0
    for p in paths:
        t0 = time.perf_counter()
        out = engine(p)
        total_ms += (time.perf_counter() - t0) * 1000
        if out.strip():
            hits.append((os.path.basename(p), out.replace("\n", " | ")[:80]))
    return {"n": len(paths), "with_text": len(hits), "ms": round(total_ms / max(1, len(paths))), "examples": hits}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--sets", nargs="+", required=True)
    ap.add_argument("--model-dir", default="")
    ap.add_argument("--engines", nargs="+", default=["ppocr", "tess"])
    ap.add_argument("--threads", type=int, default=4)
    ap.add_argument("--det-sides", nargs="+", type=int, default=[1280])
    ap.add_argument("--negatives", nargs="*", default=[])
    ap.add_argument("--neg-limit", type=int, default=200)
    ap.add_argument("--show", action="store_true")
    ap.add_argument("--json", default="")
    a = ap.parse_args()
    engines = []
    for e in a.engines:
        if e == "ppocr":
            engines += [PPOCR(a.model_dir, a.threads, s) for s in a.det_sides]
        elif e == "rapidocr":
            engines.append(Rapid(a.threads))
        elif e.startswith("tess"):
            engines.append(Tess(a.threads, int(e[4:] or 3)))
    report = {}
    for d in a.sets:
        with open(os.path.join(d, "truth.json"), encoding="utf-8") as fh:
            truth = json.load(fh)
        items = []
        for k, v in sorted(truth.items()):
            p = next((f for f in glob.glob(os.path.join(d, k + ".*")) if not f.endswith(".json")), None)
            if p:
                items.append((k, p, v))
        for eng in engines:
            print(f"== {os.path.basename(d.rstrip('/'))} / {eng.name}")
            r = run(eng, items, a.show)
            cats: dict[str, list] = {}
            for name, s, _ in r["rows"]:
                cats.setdefault(re.sub(r"\d.*$", "", name), []).append(s["word_recall"])
            print(f"   ORT: word_recall={r['word_recall']} exact_recall={r['exact_recall']} cer={r['cer']} "
                  f"ms={r['ms']} cpu_ms={r['cpu_ms']} (n={r['n']})  tür başına wr: "
                  + ", ".join(f"{c}={sum(v) / len(v):.2f}" for c, v in cats.items()))
            report[f"{d}|{eng.name}"] = {k: v for k, v in r.items() if k != "rows"}
    negs = []
    for d in a.negatives:
        negs += sorted(glob.glob(os.path.join(d, "*.jpg")))
    negs = negs[:a.neg_limit]
    if negs:
        for eng in engines:
            r = negatives(eng, negs)
            print(f"== yazısız/gerçek fotoğraf ({r['n']}) / {eng.name}: yazı bulunan {r['with_text']}, {r['ms']} ms/görüntü")
            for ex in r["examples"][:25]:
                print("     ", ex)
            report[f"negatives|{eng.name}"] = r
    if a.json:
        with open(a.json, "w", encoding="utf-8") as fh:
            json.dump(report, fh, ensure_ascii=False, indent=1)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
