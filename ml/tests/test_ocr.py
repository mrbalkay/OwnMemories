"""Fotoğraftaki yazı (OCR) testleri:  python -I ml/tests/test_ocr.py [--model-dir <models>/ppocr-v6]

Model gerekmeyenler: arama katlaması (PHP ocr_fold() ile ortak örnekler tests/fixtures/ocr/fold.json), bağlı
bileşenler (kaba kuvvetle karşılaştırma), en küçük döndürülmüş dikdörtgen, Türkçe harf düzeltmeleri, gürültü süzgeci.
--model-dir verilirse: sentetik Türkçe tabela/fiş/belge görüntülerinde (ocr_synth.py) sözcük bulma oranı ≥ %95,
aranan sözcükler ("eczane", "kadıköy", "istanbul", "çiğköfte", "ödeme", "fatura", "pasaport", "menü") bulunur ve
yazısız sahnelerde (düz renk, gürültü, doku) hiç yazı çıkmaz.
"""
from __future__ import annotations

import json
import math
import os
import sys
import tempfile

import numpy as np
from PIL import Image, ImageDraw

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))
sys.path.insert(0, HERE)

from ownmemories_ml import ocr as O  # noqa: E402

FAILS: list[str] = []


def check(cond, msg: str) -> None:
    print(("  ok   " if cond else "  FAIL ") + msg)
    if not cond:
        FAILS.append(msg)


def test_fold_fixture() -> None:
    path = os.path.join(HERE, "..", "..", "tests", "fixtures", "ocr", "fold.json")
    with open(path, encoding="utf-8") as fh:
        cases = json.load(fh)
    bad = [(k, v, O.fold(k)) for k, v in cases.items() if O.fold(k) != v]
    check(not bad, f"katlama PHP ile aynı ({len(cases)} örnek) {bad[:3]}")
    check(O.count_words("ELEKTRİK FATURASI\nÖdenecek tutar 812,40 TL") == 7, "sözcük sayısı katlanmış sözcükler")
    check(O.count_words("") == 0, "boş metin 0 sözcük")


def brute_components(mask: np.ndarray) -> list[set]:
    h, w = mask.shape
    seen = np.zeros_like(mask, dtype=bool)
    comps = []
    for y in range(h):
        for x in range(w):
            if mask[y, x] and not seen[y, x]:
                st, comp = [(y, x)], set()
                seen[y, x] = True
                while st:
                    cy, cx = st.pop()
                    comp.add((cy, cx))
                    for dy in (-1, 0, 1):
                        for dx in (-1, 0, 1):
                            ny, nx = cy + dy, cx + dx
                            if 0 <= ny < h and 0 <= nx < w and mask[ny, nx] and not seen[ny, nx]:
                                seen[ny, nx] = True
                                st.append((ny, nx))
                comps.append(comp)
    return comps


def test_components() -> None:
    rng = np.random.default_rng(3)
    ok = True
    for _ in range(30):
        m = rng.random((23, 31)) < rng.uniform(0.2, 0.55)
        ours = O.label_components(m)
        ref = brute_components(m)
        if len(ours) != len(ref):
            ok = False
            break
        # her bileşenin satır/sütun sınırları kaba kuvvetinkiyle aynı
        def bounds(pts):
            a = np.array(sorted(pts))
            return (a[:, 0].min(), a[:, 0].max(), a[:, 1].min(), a[:, 1].max())
        rb = sorted(bounds(c) for c in ref)
        ob = sorted((int(p[:, 1].min()), int(p[:, 1].max()), int(p[:, 0].min()), int(p[:, 0].max())) for p in ours)
        if rb != ob:
            ok = False
            break
    check(ok, "bağlı bileşenler (8-komşu) kaba kuvvetle aynı (30 rastgele maske)")
    check(O.label_components(np.zeros((5, 5), bool)) == [], "boş maske")


def test_min_area_rect() -> None:
    im = Image.new("L", (400, 300), 0)
    ang = 17.0
    cx, cy, w, h = 200, 150, 220, 40
    a = math.radians(ang)
    ux, uy = (math.cos(a) * w / 2, math.sin(a) * w / 2)
    vx, vy = (-math.sin(a) * h / 2, math.cos(a) * h / 2)
    poly = [(cx - ux - vx, cy - uy - vy), (cx + ux - vx, cy + uy - vy), (cx + ux + vx, cy + uy + vy),
            (cx - ux + vx, cy - uy + vy)]
    ImageDraw.Draw(im).polygon(poly, fill=255)
    comps = O.label_components(np.asarray(im) > 0)
    check(len(comps) == 1, "döndürülmüş dikdörtgen tek bileşen")
    c, rw, rh, ra = O.min_area_rect(comps[0])
    long_, short = max(rw, rh), min(rw, rh)
    check(abs(c[0] - cx) < 2 and abs(c[1] - cy) < 2, f"merkez ({c[0]:.1f}, {c[1]:.1f})")
    check(abs(long_ - w) < 4 and abs(short - h) < 4, f"boyut {long_:.1f}×{short:.1f}")
    box = O.rect_points(c, rw, rh, ra)
    top = box[1] - box[0]
    check(abs(math.degrees(math.atan2(top[1], top[0])) - ang) < 1.5, "üst kenar açısı ~17°")
    check(box[0][0] < box[1][0] and box[3][1] > box[0][1], "köşe sırası: sol-üst, sağ-üst, sağ-alt, sol-alt")


def test_fix_tokens_and_clean() -> None:
    cases = {
        "SOKAČI": "SOKAĞI", "SOKAĞ1": "SOKAĞI", "KAD1KÖY": "KADIKÖY", "MÜRİDOČLU": "MÜRİDOĞLU", "ŞEHÍR": "ŞEHİR",
        "KADIKÕY": "KADIKÖY", "$AİR NEFİ": "ŞAİR NEFİ", "A101": "A101", "34ZK2": "34ZK2", "Tel:325 10 10": "Tel:325 10 10",
        "MUHTARLIĆI": "MUHTARLIĞI", "iSTANBUL GÜNEŞ": "İSTANBUL GÜNEŞ", "ÇIğKÖFTE": "ÇIĞKÖFTE",
        "FENERBAHÇE MAHALLESi": "FENERBAHÇE MAHALLESİ", "Dürüm 45 TL": "Dürüm 45 TL", "iPhone": "iPhone",
        "Nöbetçi Eczane": "Nöbetçi Eczane", "McDONALDS": "McDONALDS",
    }
    bad = [(k, O.fix_tokens(k)) for k, v in cases.items() if O.fix_tokens(k) != v]
    check(not bad, f"Türkçe harf / rakam düzeltmeleri {bad}")
    lines = [("ECZANE", 0.98), ("|", 0.95), ("x", 0.99), ("1D", 0.5), ("Nöbetçi", 0.79), ("- . -", 0.9)]
    check(O.clean_lines(lines) == [("ECZANE", 0.98)], "düşük güven ve anlamsız satırlar atılır")
    check(O.clean_lines([("AB", 0.99)]) == [], "toplam 3 harften az: yazısız sayılır")
    check(O.clean_lines([("1000", 0.99)]) == [] and O.clean_lines([("0212 620 00 33", 0.95)]) != [],
          "yalnız birkaç rakam yazısız; telefon numarası kalır")
    check(O.guess_lang("Nöbetçi Eczane") == "tr" and O.guess_lang("Thank you") == "en" and O.guess_lang("") is None,
          "dil tahmini")


def test_model(model_dir: str) -> None:
    import ocr_synth

    m = O.OcrModel(model_dir, threads=2)
    with tempfile.TemporaryDirectory() as t:
        truth = ocr_synth.build(t, [])
        allw, hit, corpus = 0, 0, {}
        for name, text in truth.items():
            r = m.analyze_file(os.path.join(t, name + ".jpg"))
            corpus[name] = " " + O.fold(r["text"]) + " "
            words = [w for w in O.fold(text).split() if len(w) >= 3]
            allw += len(words)
            hit += sum(1 for w in words if f" {w}" in corpus[name])
        rate = hit / max(1, allw)
        check(rate >= 0.95, f"sentetik küme sözcük bulma oranı %{rate * 100:.1f} ({len(truth)} görüntü)")
        for q in ("eczane", "kadıköy", "İSTANBUL", "çiğköfte", "ödeme", "fatura", "pasaport", "menü"):
            f = O.fold(q)
            found = [n for n, c in corpus.items() if f" {f}" in c]
            check(bool(found), f"'{q}' bulundu: {found[:3]}")
        # yazısız sahneler: düz renk, gürültü, doku, gökyüzü geçişi
        rng = np.random.default_rng(1)
        noise = []
        for k in range(6):
            a = rng.integers(0, 255, (768, 1024, 3), dtype=np.uint8) if k % 3 == 0 else \
                np.full((768, 1024, 3), rng.integers(0, 255, 3), dtype=np.uint8)
            if k % 3 == 2:
                a = (np.linspace(40, 220, 1024)[None, :, None] * np.ones((768, 1, 3))).astype(np.uint8)
            r = m.analyze(Image.fromarray(a))
            if r["text"]:
                noise.append(r["text"])
        check(not noise, f"yazısız sahnelerde yazı yok {noise}")


def main() -> int:
    import argparse

    ap = argparse.ArgumentParser()
    ap.add_argument("--model-dir", default="")
    a = ap.parse_args()
    test_fold_fixture()
    test_components()
    test_min_area_rect()
    test_fix_tokens_and_clean()
    if a.model_dir:
        test_model(a.model_dir)
    print(f"\n{'BAŞARISIZ: ' + str(len(FAILS)) if FAILS else 'hepsi geçti'}")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
