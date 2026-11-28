"""OCR değerlendirme kümesi: sentetik Türkçe yazılı görüntüler (tabela, fiş, belge, ekran görüntüsü, menü).

    python -I ml/tests/ocr_synth.py --out <klasör>

Her görüntü için <ad>.jpg ve tüm kümenin doğru metinleri <klasör>/truth.json ({"ad": "metin", ...}).
Türkçe harfler (ç ğ ı İ ö ş ü) bilerek her örnekte var. Bozulmalar: döndürme (±4–12°), perspektif,
Gauss bulanıklığı, gürültü, düşük JPEG kalitesi, düşük çözünürlük (telefon fotoğrafı benzeri);
ayrıca yazının fotoğrafın küçük bir parçası olduğu "sahne" örnekleri (fotoğraf üstünde tabela).
Yalnızca Pillow + numpy; sistem yazı tipleri (DejaVu, Liberation, Lato, FreeFont) kullanılır.
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import random

import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

FONT_DIRS = ("/usr/share/fonts/truetype", "/usr/share/fonts/opentype")


def font(name: str, size: int) -> ImageFont.FreeTypeFont:
    for d in FONT_DIRS:
        hits = glob.glob(f"{d}/**/{name}", recursive=True)
        if hits:
            return ImageFont.truetype(hits[0], size)
    return ImageFont.truetype("DejaVuSans.ttf", size)


SANS_B = "DejaVuSans-Bold.ttf"
SANS = "DejaVuSans.ttf"
SERIF = "LiberationSerif-Regular.ttf"
MONO = "DejaVuSansMono.ttf"
LATO_B = "Lato-Black.ttf"
FREE_B = "FreeSansBold.ttf"

SIGNS = [
    ("ECZANE", "Nöbetçi Eczane"),
    ("KADIKÖY", "İskele 200 m"),
    ("İSTANBUL", "Hoş geldiniz"),
    ("ÇİĞKÖFTE", "Dürüm 45 TL"),
    ("BÜFE", "Tost · Ayran · Çay"),
    ("KUAFÖR", "Güzellik Salonu"),
    ("ŞEHİR HASTANESİ", "Acil Servis"),
    ("ÜSKÜDAR", "Vapur İskelesi"),
    ("FIRIN", "Taze Ekmek Simit Poğaça"),
    ("MANAV", "Domates Biber Salatalık"),
]

RECEIPTS = [
    ["MİGROS TİCARET A.Ş.", "FİŞ NO: 0042", "SÜT 1 LT        34,90", "EKMEK           12,50",
     "PEYNİR          89,00", "TOPLAM         136,40", "ÖDEME: KREDİ KARTI", "TEŞEKKÜR EDERİZ"],
    ["ŞOK MARKETLER", "TARİH 12.03.2025", "ÇİKOLATA        24,75", "DONDURMA        55,00",
     "TOPLAM          79,75", "NAKİT ÖDEME", "İYİ GÜNLER"],
    ["BOĞAZİÇİ ELEKTRİK", "ELEKTRİK FATURASI", "ABONE NO 556677", "TÜKETİM 245 kWh",
     "ÖDENECEK TUTAR 812,40 TL", "SON ÖDEME 28.11.2025"],
]

DOCS = [
    ["TÜRKİYE CUMHURİYETİ", "PASAPORT / PASSPORT", "Soyadı / Surname: YILDIRIM", "Adı / Given names: ÇAĞLA ŞÜKRAN",
     "Doğum yeri / Place of birth: İZMİR", "Uyruğu / Nationality: TÜR"],
    ["Kira Sözleşmesi", "Kiracı, aylık kira bedelini her ayın", "beşinci gününe kadar ödemekle yükümlüdür.",
     "Gecikme hâlinde günlük faiz uygulanır.", "Taraflar işbu sözleşmeyi okuyup imzalamıştır."],
    ["Değerli Müşterimiz,", "Siparişiniz kargoya verilmiştir.", "Teslimat süresi iki iş günüdür.",
     "Sorularınız için çağrı merkezimizi arayabilirsiniz."],
    ["Invoice / Fatura", "Customer: Öztürk Ltd. Şti.", "Description: Consulting services",
     "Amount due: 1.250,00 EUR", "Thank you for your business"],
]

SCREENS = [
    ["Ayarlar", "Wi-Fi", "Bluetooth", "Bildirimler", "Ekran Süresi", "Gizlilik ve Güvenlik"],
    ["Mesajlar", "Ayşe: Akşam yemeğe geliyor musun?", "Ben: Evet, saat yedide oradayım",
     "Ayşe: Görüşürüz, şimdiden teşekkürler"],
    ["Uçuş Bilgisi", "İstanbul → Ankara", "Kalkış 08:45  Kapı B12", "Biniş kartı hazır"],
]

MENUS = [
    ["MENÜ", "Mercimek Çorbası  60", "İskender Kebap  320", "Lahmacun  90", "Künefe  150", "Türk Kahvesi  70"],
    ["KAHVALTI", "Serpme Kahvaltı  450", "Menemen  120", "Gözleme  110", "Çay  20"],
]


def rnd_color(dark: bool) -> tuple[int, int, int]:
    r = random.Random()
    if dark:
        return tuple(r.randint(10, 90) for _ in range(3))
    return tuple(r.randint(170, 250) for _ in range(3))


def text_block(lines: list[str], fnt: ImageFont.FreeTypeFont, fg, bg, pad: int = 40, spacing: float = 1.45,
               align: str = "left", width: int | None = None) -> Image.Image:
    asc, desc = fnt.getmetrics()
    lh = int((asc + desc) * spacing)
    w = max(int(fnt.getlength(s)) for s in lines) + 2 * pad
    if width:
        w = max(w, width)
    h = lh * len(lines) + 2 * pad
    im = Image.new("RGB", (w, h), bg)
    d = ImageDraw.Draw(im)
    for i, s in enumerate(lines):
        x = pad if align == "left" else (w - int(fnt.getlength(s))) // 2
        d.text((x, pad + i * lh), s, font=fnt, fill=fg)
    return im


def make_sign(title: str, sub: str, rng: random.Random) -> Image.Image:
    bg = rng.choice([(200, 30, 40), (20, 90, 170), (20, 120, 60), (240, 200, 40), (30, 30, 30), (250, 250, 245)])
    fg = (255, 255, 255) if sum(bg) < 450 else (25, 25, 25)
    f1 = font(rng.choice([SANS_B, LATO_B, FREE_B]), rng.randint(90, 130))
    f2 = font(SANS, rng.randint(38, 52))
    w = int(max(f1.getlength(title), f2.getlength(sub))) + 120
    im = Image.new("RGB", (w, 330), bg)
    d = ImageDraw.Draw(im)
    d.rectangle([10, 10, w - 11, 319], outline=fg, width=6)
    d.text(((w - f1.getlength(title)) / 2, 50), title, font=f1, fill=fg)
    d.text(((w - f2.getlength(sub)) / 2, 220), sub, font=f2, fill=fg)
    return im


def on_scene(sign: Image.Image, scene: Image.Image, frac: float, rng: random.Random) -> Image.Image:
    """Tabelayı bir fotoğrafın (sahne) üstüne, kısa kenarın frac'ı kadar genişlikte yapıştırır."""
    sc = scene.copy().convert("RGB")
    W, H = sc.size
    tw = int(W * frac)
    th = int(sign.height * tw / sign.width)
    s = sign.resize((tw, th), Image.LANCZOS).convert("RGBA").rotate(rng.uniform(-6, 6), expand=True,
                                                                    resample=Image.BICUBIC)
    x = rng.randint(0, max(0, W - s.width))
    y = rng.randint(0, max(0, H - s.height))
    sc.paste(s, (x, y), s)
    return sc


def perspective(im: Image.Image, rng: random.Random, amount: float = 0.12) -> Image.Image:
    w, h = im.size
    dx, dy = w * amount, h * amount

    def j(v):
        return rng.uniform(0, v)

    src = [(j(dx), j(dy)), (w - j(dx), j(dy)), (w - j(dx), h - j(dy)), (j(dx), h - j(dy))]
    dst = [(0, 0), (w, 0), (w, h), (0, h)]
    A, B = [], []
    for (x, y), (u, v) in zip(dst, src):
        A.append([x, y, 1, 0, 0, 0, -u * x, -u * y]); B.append(u)
        A.append([0, 0, 0, x, y, 1, -v * x, -v * y]); B.append(v)
    coef = np.linalg.solve(np.array(A, dtype=float), np.array(B, dtype=float))
    return im.transform((w, h), Image.PERSPECTIVE, tuple(coef), Image.BICUBIC, fillcolor=(128, 128, 128))


def photoify(im: Image.Image, rng: random.Random, level: int) -> Image.Image:
    """level 0: temiz; 1: hafif döndürme + bulanıklık; 2: perspektif + gürültü + düşük kalite;
    3: güçlü bozulma (dönme ~12°, bulanık, karanlık, küçük)."""
    if level == 0:
        return im
    bg = (int(rng.uniform(60, 160)),) * 3
    pad = Image.new("RGB", (int(im.width * 1.2), int(im.height * 1.25)), bg)
    pad.paste(im, ((pad.width - im.width) // 2, (pad.height - im.height) // 2))
    im = pad
    ang = rng.uniform(3, 6) if level == 1 else rng.uniform(5, 9) if level == 2 else rng.uniform(9, 13)
    im = im.rotate(ang * rng.choice([-1, 1]), expand=True, resample=Image.BICUBIC, fillcolor=bg)
    if level >= 2:
        im = perspective(im, rng, 0.08 if level == 2 else 0.14)
    a = np.asarray(im).astype(np.float32)
    # aydınlatma eğimi
    gy = np.linspace(rng.uniform(0.75, 1.0), rng.uniform(0.95, 1.15), a.shape[0])[:, None, None]
    a = a * gy * (0.75 if level == 3 else 1.0)
    a += np.random.default_rng(rng.randint(0, 1 << 30)).normal(0, 4 + 5 * level, a.shape)
    im = Image.fromarray(np.clip(a, 0, 255).astype(np.uint8))
    im = im.filter(ImageFilter.GaussianBlur(0.6 * level))
    if level == 3:
        im = im.resize((im.width // 2, im.height // 2), Image.BILINEAR)
    return im


def build(out: str, scenes: list[str], seed: int = 7) -> dict[str, str]:
    os.makedirs(out, exist_ok=True)
    rng = random.Random(seed)
    truth: dict[str, str] = {}

    def save(name: str, im: Image.Image, text: str, q: int = 88):
        im.convert("RGB").save(os.path.join(out, name + ".jpg"), quality=q)
        truth[name] = text

    for i, (t, s) in enumerate(SIGNS):
        im = make_sign(t, s, rng)
        lvl = i % 4
        save(f"sign{i:02d}_l{lvl}", photoify(im, rng, lvl), f"{t}\n{s}", q=90 - 15 * (lvl == 3))
    for i, lines in enumerate(RECEIPTS):
        im = text_block(lines, font(MONO, 30), (30, 30, 30), (245, 243, 236), pad=50)
        for lvl in (0, 2):
            save(f"receipt{i}_l{lvl}", photoify(im, rng, lvl), "\n".join(lines))
    for i, lines in enumerate(DOCS):
        im = text_block(lines, font(rng.choice([SERIF, SANS]), 34), (20, 20, 20), (252, 252, 250), pad=70,
                        width=1400)
        for lvl in (0, 1, 3):
            save(f"doc{i}_l{lvl}", photoify(im, rng, lvl), "\n".join(lines))
    for i, lines in enumerate(SCREENS):
        dark = i % 2 == 1
        im = text_block(lines, font("Lato-Medium.ttf", 40), (235, 235, 235) if dark else (20, 20, 20),
                        (28, 28, 30) if dark else (242, 242, 247), pad=60, spacing=2.0, width=1080)
        save(f"screen{i}", im, "\n".join(lines), q=92)
    for i, lines in enumerate(MENUS):
        im = text_block(lines, font("LiberationSerif-Bold.ttf", 46), (240, 225, 190), (45, 30, 20), pad=70,
                        align="center", spacing=1.6)
        for lvl in (1, 2):
            save(f"menu{i}_l{lvl}", photoify(im, rng, lvl), "\n".join(lines))
    # sahne: fotoğrafın içinde (küçük) tabela
    for i, sc in enumerate(scenes[:6]):
        t, s = SIGNS[(i * 3) % len(SIGNS)]
        sign = make_sign(t, s, rng)
        with Image.open(sc) as scene:
            scene = scene.convert("RGB")
            if max(scene.size) < 1200:
                f = 1600 / max(scene.size)
                scene = scene.resize((int(scene.width * f), int(scene.height * f)), Image.LANCZOS)
            im = on_scene(sign, scene, rng.uniform(0.28, 0.42), rng)
        save(f"scene{i}", photoify(im, rng, 1), f"{t}\n{s}")
    with open(os.path.join(out, "truth.json"), "w", encoding="utf-8") as fh:
        json.dump(truth, fh, ensure_ascii=False, indent=1)
    return truth


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True)
    ap.add_argument("--scenes", default="", help="sahne örnekleri için yazısız fotoğraf klasörü")
    a = ap.parse_args()
    scenes = sorted(glob.glob(os.path.join(a.scenes, "*.jpg"))) if a.scenes else []
    t = build(a.out, scenes)
    print(f"{len(t)} görüntü: {a.out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
