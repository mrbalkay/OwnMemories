"""python -m ownmemories_ml <komut>

  serve                          HTTP servisi + dizinleyici (ml.env ortam değişkenleriyle)
  download [--model M --dest D]  modeli Hugging Face'ten indir (sürdürmeli, sha256 doğrulamalı)
  quantize --model-dir DIR       metin modelinin token tablosunu int8'e çevir (RAM tasarrufu)
  embed-text "metin"             (tanı) metin vektörünün ilk değerleri
  ocr resim.jpg [...]            (tanı) fotoğraftaki yazıyı oku (OCR modeli: download --model ocr)
"""
from __future__ import annotations

import sys


def main() -> int:
    args = sys.argv[1:]
    cmd = args[0] if args else "serve"
    rest = args[1:]
    if cmd == "serve":
        from . import config, service

        return service.serve(config.load())
    if cmd == "download":
        from . import download

        return download.main(rest)
    if cmd == "quantize":
        from . import quantize

        return quantize.main(rest)
    if cmd == "embed-text":
        from . import config
        from .model import TextEncoder, text_model_path

        c = config.load()
        t = TextEncoder(c.model_dir, c.threads, onnx_file=text_model_path(c.model_dir, c.text_quant))
        v = t.embed(" ".join(rest))
        print(v.shape[0], " ".join(f"{x:.4f}" for x in v[:8]))
        return 0
    if cmd == "ocr":
        from . import config
        from .ocr import OcrModel

        c = config.load()
        m = OcrModel(c.ocr_model_dir, c.threads, min_conf=c.ocr_min_conf, det_max_side=c.ocr_det_side)
        for path in rest:
            r = m.analyze_file(path)
            print(f"== {path}: {r['words']} sözcük, dil={r['lang']}, {r['ms']} ms")
            for t, conf in r["lines"]:
                print(f"   {conf:.2f}  {t}")
        return 0
    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main())
