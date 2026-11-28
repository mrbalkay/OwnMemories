"""Varsayılan ilgi eşiği (service.relevance_cut) ve yapılandırması için hızlı birim testleri (DB/model gerekmez).

    python -I ml/tests/test_cutoff.py
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

import numpy as np  # noqa: E402

from ownmemories_ml import config  # noqa: E402
from ownmemories_ml.service import MIN_N_FOR_CUT, relevance_cut  # noqa: E402

FAILS = []


def check(cond: bool, msg: str) -> None:
    print(("  ok   " if cond else "  HATA ") + msg)
    if not cond:
        FAILS.append(msg)


def main() -> int:
    rng = np.random.default_rng(1)
    bulk = rng.normal(0.05, 0.01, 1000).astype(np.float32)
    s = bulk.copy()
    s[:5] = [0.14, 0.13, 0.12, 0.09, 0.085]  # ilgili birkaç fotoğraf
    keep = relevance_cut(s, 2.0, None)
    check(keep[:5].all(), "belirgin şekilde yüksek puanlar tutuluyor")
    check(keep.sum() < 60, f"yığının çoğu atılıyor ({keep.sum()} / 1000)")
    # sorguyla eşleşen fotoğraflar kütüphanenin yarısıysa z düşük kalır; puan eşiği bunları kurtarır
    half = np.concatenate([rng.normal(0.12, 0.01, 500), rng.normal(0.04, 0.01, 500)]).astype(np.float32)
    check(relevance_cut(half, 2.0, None)[:500].mean() < 0.1, "yalnız z: geniş eşleşmede çoğu kaybolur")
    check(relevance_cut(half, 2.0, 0.10)[:500].mean() > 0.9, "z VEYA puan eşiği: geniş eşleşme korunur")
    small = rng.normal(0.05, 0.01, MIN_N_FOR_CUT - 1).astype(np.float32)
    check(relevance_cut(small, 2.0, 0.10).all(), f"{MIN_N_FOR_CUT} öğeden küçük kütüphanede kesme yok")
    check(relevance_cut(s, 0.0, None).all(), "min_z=0 eşiği kapatır")
    check(relevance_cut(np.full(100, 0.05, np.float32), 2.0, None).all(), "sabit puanlar (std=0) güvenli")
    none = rng.normal(0.05, 0.01, 1000).astype(np.float32)
    check(relevance_cut(none, 4.0, 0.2).sum() <= 2, "ilgili bir şey yoksa (neredeyse) boş döner")

    c = config.load({"OM_ML_MODEL": "immich-app/ViT-B-16-SigLIP2__webli", "OM_DB_NAME": "x", "OM_STORAGE": "/x"})
    check((c.min_z, c.min_score) == config.CUTOFF["ViT-B-16-SigLIP2__webli"], f"model varsayılanları: {c.min_z}, {c.min_score}")
    c = config.load({"OM_ML_MODEL": "immich-app/bilinmeyen", "OM_DB_NAME": "x", "OM_STORAGE": "/x"})
    check(c.min_z == 2.0 and c.min_score is None, "bilinmeyen model: z=2, puan eşiği yok")
    c = config.load({"OM_ML_MIN_Z": "0", "OM_ML_MIN_SCORE": "0.2", "OM_DB_NAME": "x", "OM_STORAGE": "/x"})
    check(c.min_z == 0.0 and c.min_score == 0.2, "OM_ML_MIN_Z / OM_ML_MIN_SCORE ortamdan")
    print("SONUÇ:", "BAŞARILI" if not FAILS else f"{len(FAILS)} HATA")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
