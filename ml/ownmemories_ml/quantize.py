"""Metin modelinin token tablosunu int8'e çevirir: textual/model.onnx -> textual/model.int8.onnx

    python -m ownmemories_ml quantize --model-dir /var/lib/ownmemories/models/ViT-B-16-SigLIP2__webli

SigLIP2 metin modelinin ~%70'i 256k×768'lik token tablosudur (Gather girdisi, ~786 MB float32).
Bu tablo satır başına ölçekle (per-row, simetrik) int8'e çevrilir:
    Gather(W, ids)  ->  Cast(Gather(W_int8, ids), float) * Gather(scale, ids)
Transformer katmanları (MatMul) float32 kalır: onnxruntime quantize_dynamic ile MatMul'ları da
nicemlemek Türkçe sorgu kalitesini belirgin düşürdüğü için (ölçüm: docs/ARCHITECTURE.md) bilerek yapılmaz.
Sonuç: dosya 1.13 GB -> ~0.54 GB, metin modelinin çalışma belleği ~1.4 GB -> ~0.75 GB; fp32 ile
vektör kosinüsü >= 0.99. Görsel model değişmez, mevcut asset_embeddings satırları geçerli kalır.
Gerekli paket: onnx (requirements-quantize.txt). Başarısızlıkta servis orijinal modeli kullanır.
"""
from __future__ import annotations

import os
import sys

MIN_ELEMENTS = 1 << 22  # yalnızca büyük tablolar (≥4M öğe)


def quantize(model_dir: str, force: bool = False, log=print) -> str:
    src = os.path.join(model_dir, "textual", "model.onnx")
    dst = os.path.join(model_dir, "textual", "model.int8.onnx")
    if os.path.isfile(dst) and not force and os.path.getmtime(dst) >= os.path.getmtime(src):
        log(f"    hazır: {dst}")
        return dst
    import numpy as np
    import onnx
    from onnx import TensorProto, helper, numpy_helper

    log("    metin modelinin token tablosu int8'e çevriliyor…")
    m = onnx.load(src)
    g = m.graph
    inits = {t.name: t for t in g.initializer}
    users: dict[str, int] = {}
    for n in g.node:
        for i in n.input:
            users[i] = users.get(i, 0) + 1
    done = 0
    new_nodes = []
    for n in g.node:
        t = inits.get(n.input[0]) if n.op_type == "Gather" and n.input else None
        axis = next((a.i for a in n.attribute if a.name == "axis"), 0)
        if (t is None or axis != 0 or t.data_type != TensorProto.FLOAT or len(t.dims) != 2
                or t.dims[0] * t.dims[1] < MIN_ELEMENTS or users.get(t.name, 0) != 1):
            new_nodes.append(n)
            continue
        w = numpy_helper.to_array(t).astype(np.float32, copy=False)
        scale = np.abs(w).max(axis=1, keepdims=True) / 127.0
        scale[scale == 0] = 1.0
        q = np.clip(np.rint(w / scale), -127, 127).astype(np.int8)
        del w
        base = t.name
        g.initializer.remove(t)
        g.initializer.extend([
            numpy_helper.from_array(q, base + "_int8"),
            numpy_helper.from_array(scale.astype(np.float32), base + "_scale"),
        ])
        del q
        out = n.output[0]
        new_nodes += [
            helper.make_node("Gather", [base + "_int8", n.input[1]], [out + "_q"], axis=0, name=n.name + "_q"),
            helper.make_node("Cast", [out + "_q"], [out + "_f"], to=TensorProto.FLOAT, name=n.name + "_cast"),
            helper.make_node("Gather", [base + "_scale", n.input[1]], [out + "_s"], axis=0, name=n.name + "_s"),
            helper.make_node("Mul", [out + "_f", out + "_s"], [out], name=n.name + "_mul"),
        ]
        done += 1
    if not done:
        raise RuntimeError("nicemlenecek token tablosu bulunamadı")
    del g.node[:]
    g.node.extend(new_nodes)
    tmp = dst + ".tmp"
    onnx.save(m, tmp)
    os.replace(tmp, dst)
    return dst


def main(argv: list[str]) -> int:
    import argparse

    p = argparse.ArgumentParser(prog="ownmemories_ml quantize")
    p.add_argument("--model-dir", required=True)
    p.add_argument("--force", action="store_true")
    a = p.parse_args(argv)
    try:
        print(quantize(a.model_dir, a.force))
    except Exception as e:  # noqa: BLE001
        print(f"Nicemleme başarısız: {e}", file=sys.stderr)
        return 1
    return 0
