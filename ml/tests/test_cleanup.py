"""Temizlik birim testleri (model ve DB gerekmez):  python -I ml/tests/test_cleanup.py [--images <p*.jpg klasörü>]

pHash'in yeniden kodlama/küçültme/bulanıklaştırmaya dayanıklılığı, farklı görüntülerde uzaklığı; Laplace netliğinin
bulanıklıkla düşmesi; parlaklık; ekran görüntüsü kuralı; gruplama (seri çekim yalnızca güvenilir zamanla, phash kopyası
zamandan bağımsız, CLIP doğrulaması, tam bağlantı ile zincirleme engeli, grup boyu sınırı, "duplicate"/"similar").
--images verilirse gerçek fotoğraflarla da pHash/netlik denetlenir.
"""
from __future__ import annotations

import io
import os
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))

from ownmemories_ml import cleanup as C  # noqa: E402

FAILS: list[str] = []


def check(cond, msg: str) -> None:
    print(("  ok   " if cond else "  FAIL ") + msg)
    if not cond:
        FAILS.append(msg)


def scene(seed: int, size=(800, 600)) -> Image.Image:
    """Ayrıntılı yapay sahne (rastgele şekiller + doku)."""
    rng = np.random.default_rng(seed)
    im = Image.new("RGB", size, tuple(int(x) for x in rng.integers(0, 255, 3)))
    d = ImageDraw.Draw(im)
    for _ in range(60):
        x, y = int(rng.integers(0, size[0])), int(rng.integers(0, size[1]))
        r = int(rng.integers(10, 120))
        col = tuple(int(c) for c in rng.integers(0, 255, 3))
        if rng.random() < 0.5:
            d.ellipse((x - r, y - r, x + r, y + r), fill=col)
        else:
            d.rectangle((x, y, x + r, y + r // 2), fill=col)
    noise = rng.integers(-12, 12, (size[1], size[0], 3))
    return Image.fromarray(np.clip(np.asarray(im, dtype=np.int16) + noise, 0, 255).astype(np.uint8))


def jpeg(im: Image.Image, q: int) -> Image.Image:
    b = io.BytesIO()
    im.save(b, "JPEG", quality=q)
    b.seek(0)
    return Image.open(b).convert("RGB")


def gray(im: Image.Image) -> Image.Image:
    return im.convert("L")


def test_phash() -> None:
    a = scene(1)
    h0 = C.phash_gray(gray(a))
    check(0 <= h0 < 2 ** 64, "pHash 64 bit işaretsiz")
    small = a.resize((400, 300), Image.Resampling.LANCZOS)
    check(C.hamming(h0, C.phash_gray(gray(jpeg(small, 35)))) <= 4, "pHash: küçültme + düşük kalite JPEG ≤ 4")
    check(C.hamming(h0, C.phash_gray(gray(a.filter(ImageFilter.GaussianBlur(3))))) <= 6, "pHash: bulanıklaştırma ≤ 6")
    others = [C.hamming(h0, C.phash_gray(gray(scene(s)))) for s in range(2, 12)]
    check(min(others) > C.H_COPY, f"pHash: farklı sahneler > H_COPY ({min(others)})")


def test_sharpness_brightness() -> None:
    a = scene(3)
    s0 = C.sharpness_gray(gray(a))
    s1 = C.sharpness_gray(gray(a.filter(ImageFilter.GaussianBlur(3))))
    check(s1 < s0 / 10, f"netlik bulanıklıkla düşer ({s0:.0f} → {s1:.1f})")
    # Arka planı bulanık, ortası net: en net ikinci karo net kalır
    bg = a.filter(ImageFilter.GaussianBlur(6))
    bg.paste(a.crop((250, 150, 550, 450)), (250, 150))
    check(C.sharpness_gray(gray(bg)) > 10 * s1, "netlik: net konu + bulanık arka plan bulanık sayılmaz")
    check(C.sharpness_gray(Image.new("L", (5, 5))) == 0.0, "netlik: çok küçük görüntü 0")
    check(C.brightness_gray(Image.new("L", (100, 100), 10)) == 10.0, "parlaklık: düz 10")
    check(abs(C.brightness_gray(gray(a)) - float(np.asarray(gray(a)).mean())) < 3, "parlaklık ≈ ortalama")


def test_analyze_file(tmp: str) -> None:
    p = os.path.join(tmp, "x.webp")
    scene(4).save(p, "WEBP", quality=80)
    r = C.analyze_file(p)
    check(set(r) == {"phash", "sharpness", "brightness", "color"} and r["sharpness"] > 50 and len(r["color"]) == 48,
          f"analyze_file: {r['sharpness']}")


def test_screenshot() -> None:
    S = C.is_screenshot
    check(S("Screenshot 2024-01-02 at 10.00.00.png", "image/png", None, 2880, 1800), "ad: Screenshot")
    check(S("Ekran Resmi 2024-01-02 10.00.00.png", "image/png", None, 100, 100), "ad: Ekran Resmi")
    check(S("Ekran görüntüsü 2024.jpg", "image/jpeg", None, 100, 100), "ad: Ekran görüntüsü")
    check(S("IMG_0001.PNG", "image/png", None, 1170, 2532), "iPhone PNG, kamera yok")
    check(S("IMG_0002.HEIC", "image/heic", None, 2532, 1170), "ekran boyutu (yatay), kamera yok")
    check(not S("IMG_0003.HEIC", "image/heic", "Apple", 1170, 2532), "kamera bilgisi olan fotoğraf değil")
    check(not S("IMG_0004.JPG", "image/jpeg", None, 4032, 3024), "kamera yok ama ekran boyutu değil")
    check(not S("p1.jpg", "image/jpeg", None, None, None), "boyut yok")


def vec(seed: int, dim: int = 32) -> np.ndarray:
    v = np.random.default_rng(seed).normal(size=dim)
    return v / np.linalg.norm(v)


def near(v: np.ndarray, sim: float, seed: int) -> np.ndarray:
    """v ile kosinüsü tam `sim` olan birim vektör."""
    r = np.random.default_rng(seed).normal(size=v.shape)
    r -= (r @ v) * v
    r /= np.linalg.norm(r)
    return sim * v + np.sqrt(1 - sim * sim) * r


def test_grouping() -> None:
    a, b, c = vec(1), vec(2), vec(3)
    ids = ["a1", "a2", "a3", "b1", "b2", "c1", "c2"]
    V = np.vstack([a, near(a, 0.97, 10), near(a, 0.965, 11), b, near(b, 0.95, 12), c, near(c, 0.90, 13)])
    taken = np.array([1000, 1003, 1060, 5000, 5004, 9000, 900000], dtype=float)
    ph = [0x0F0F0F0F0F0F0F0F, 0xFFFF00000000FFFF, 0x1234567812345678, None, None,
          0xAAAAAAAAAAAAAAAA, 0xAAAAAAAAAAAAAAAB]
    g = C.group_assets(ids, taken, V, ph)
    sets = sorted(sorted(x["assets"]) for x in g)
    check(["a1", "a2", "a3"] in sets, f"seri çekim: 3 sn ve 60 sn içindeki benzerler bir grup {sets}")
    check(["b1", "b2"] in sets, "seri çekim: 4 sn, kosinüs 0.95 (≥ T_BURST_TIGHT)")
    check(["c1", "c2"] in sets, "phash kopyası: zaman farkı büyük olsa da (Hamming 1, kosinüs 0.90)")
    gc = next(x for x in g if "c1" in x["assets"])
    check(gc["reason"] == "duplicate" and gc["max_hamming"] == 1, f"birebir kopya: duplicate {gc}")
    ga = next(x for x in g if "a1" in x["assets"])
    check(ga["reason"] == "similar", "seri çekim: similar")
    # Zaman güvenilmezse seri çekim kenarı yok
    g2 = C.group_assets(ids, taken, V, ph, reliable=[False] * 7)
    check(sorted(sorted(x["assets"]) for x in g2) == [["c1", "c2"]], "güvenilmez zaman: yalnız phash kopyası")
    # 60 sn arayla ama kosinüs 0.94 (< T_BURST): ayrı
    V3 = np.vstack([a, near(a, 0.94, 20)])
    check(C.group_assets(["x", "y"], np.array([0, 60.0]), V3, [None, None]) == [], "60 sn, 0.94: grup değil")
    check(len(C.group_assets(["x", "y"], np.array([0, 5.0]), V3, [None, None])) == 1, "5 sn, 0.94: grup")
    # phash yakın ama CLIP farklı (düz kareler): grup değil
    V4 = np.vstack([a, b])
    check(C.group_assets(["x", "y"], np.array([0, 1e6]), V4, [0, 1]) == [], "phash yakın, CLIP farklı: grup değil")
    # Netlik farkı büyükse "duplicate" değil
    g5 = C.group_assets(["s", "t"], np.array([0, 1e6]), np.vstack([c, near(c, 0.95, 30)]), [5, 5],
                        sharp=[900.0, 3.0])
    check(g5 and g5[0]["reason"] == "similar", "bulanık kopya: similar")


def test_color_and_aspect_guard() -> None:
    a = scene(5)
    sig = C.color_sig(a)
    check(len(sig) == 48 and C.color_dist(sig, C.color_sig(jpeg(a, 40))) < 3, "renk imzası: JPEG kopyası yakın")
    tinted = Image.merge("RGB", (a.split()[2], a.split()[0], a.split()[1]))  # aynı düzen, farklı renk
    check(C.color_dist(sig, C.color_sig(tinted)) > C.C_COPY, "renk imzası: renkleri değişmiş kare uzak")
    check(C.color_dist(None, sig) == 0.0, "renk imzası yoksa 0")
    v = vec(40)
    V = np.vstack([v, near(v, 0.97, 41)])
    far = np.array([0, 1e7])
    g = C.group_assets(["x", "y"], far, V, [7, 7], color=[sig, C.color_sig(tinted)])
    check(g == [], "phash/CLIP eşleşse de renk farklıysa zamandan bağımsız grup yok")
    g = C.group_assets(["x", "y"], far, V, [7, 7], aspect=[0.5625, 1.333])
    check(g == [], "en-boy oranı çok farklıysa zamandan bağımsız grup yok")
    g = C.group_assets(["x", "y"], np.array([0, 3.0]), V, [7, 7], color=[sig, C.color_sig(tinted)])
    check(len(g) == 1, "seri çekimde renk denetimi yok (aynı an, pozlama değişebilir)")
    V2 = np.vstack([v, near(v, 0.985, 42)])
    check(len(C.group_assets(["x", "y"], far, V2, [None, None], color=[sig, sig])) == 1, "küresel CLIP ≥ T_GLOBAL")


def test_chain_and_cap() -> None:
    # Zincir: her komşu 0.97 benzer ama uçlar birbirine uzak → tam bağlantı tek dev grubu engeller
    base = vec(7, 64)
    seq = [base]
    for k in range(12):
        seq.append(near(seq[-1], 0.97, 100 + k))
    V = np.vstack(seq)
    ids = [f"z{k}" for k in range(len(seq))]
    g = C.group_assets(ids, np.arange(len(seq)) * 2.0, V, [None] * len(seq))
    for x in g:
        sm = V[[ids.index(i) for i in x["assets"]]] @ V[[ids.index(i) for i in x["assets"]]].T
        if sm.min() < C.T_LINK - 1e-6:
            check(False, "zincir: grup içi her çift ≥ T_LINK")
            break
    else:
        check(len(g) >= 2, f"zincir: birden çok küçük grup ({[len(x['assets']) for x in g]})")
    # Aynı kareden 40 kopya: MAX_GROUP sınırı
    V2 = np.vstack([near(base, 0.995, 300 + k) for k in range(40)])
    g2 = C.group_assets([f"m{k}" for k in range(40)], np.zeros(40), V2, [None] * 40)
    check(max(len(x["assets"]) for x in g2) <= C.MAX_GROUP, "grup boyu ≤ MAX_GROUP")
    check(C.group_assets(["tek"], np.zeros(1), V2[:1], [None]) == [], "tek öğe: grup yok")


def test_text_guard() -> None:
    """Belge/ekran sayfaları: CLIP'te birbirine çok benzer (farklı sayfalar ~0.93) ama içerik farklı.
    Yazı ağırlıklı karelerde yalnızca neredeyse birebir kopyalar gruplanır (seri çekim/küresel yol kapalı)."""
    p = vec(60)
    V = np.vstack([p, near(p, 0.95, 61), near(p, 0.995, 62)])
    t = np.array([0, 4.0, 1e6])
    ph = [0x0F0F0F0F0F0F0F0F, 0x0F0F0F0F0F0FFFFF, 0x0F0F0F0F0F0F0F0E]
    g = C.group_assets(["s1", "s2", "s1kopya"], t, V, ph)
    check(len(g) == 1 and len(g[0]["assets"]) == 3, f"yazı bilgisi yokken sayfalar birleşir {g}")
    g = C.group_assets(["s1", "s2", "s1kopya"], t, V, ph, texty=[True, True, True])
    check([sorted(x["assets"]) for x in g] == [["s1", "s1kopya"]],
          f"yazı ağırlıklı: farklı sayfalar ayrı, birebir kopya grup {g}")
    # Kopya ama CLIP < T_TEXT_COPY: yazıda gruplanmaz
    V2 = np.vstack([p, near(p, 0.95, 63)])
    check(C.group_assets(["a", "b"], np.array([0, 1.0]), V2, [5, 5], texty=[True, False]) == [],
          "yazı ağırlıklı: kosinüs 0.95 yetmez")
    # Sıfır/negatif kenar: texty_mask metin/fotoğraf istemlerine göre
    tv, pv = vec(70), vec(71)
    docs = np.vstack([near(tv, 0.3, 72), near(pv, 0.3, 73)])
    m = C.texty_mask(docs, tv, pv)
    check(m.tolist() == [True, False], f"texty_mask: metne yakın vektör yazı sayılır {m}")


def test_popcount() -> None:
    x = np.array([0, 1, 0xFFFFFFFFFFFFFFFF, 0x8000000000000001], dtype=np.uint64)
    check(list(C.popcount64(x)) == [0, 1, 64, 2], "popcount64")


def test_real(folder: str) -> None:
    import glob

    files = sorted(glob.glob(os.path.join(folder, "p*.jpg")))
    if not files:
        print("  skip gerçek görüntüler yok")
        return
    hs, worst = [], 0
    for f in files:
        im = Image.open(f).convert("RGB")
        h0 = C.phash_gray(gray(im))
        hs.append(h0)
        w, h = im.size
        cp = jpeg(im.resize((w * 3 // 4, h * 3 // 4), Image.Resampling.LANCZOS), 38)
        worst = max(worst, C.hamming(h0, C.phash_gray(gray(cp))))
        sb = C.sharpness_gray(gray(im.filter(ImageFilter.GaussianBlur(3))))
        if sb >= 15:
            check(False, f"gerçek: bulanık kopya netliği < 15 ({os.path.basename(f)}: {sb})")
    check(worst <= 4, f"gerçek: WhatsApp benzeri kopya Hamming ≤ 4 (en kötü {worst})")
    mind = min(C.hamming(hs[i], hs[j]) for i in range(len(hs)) for j in range(i + 1, len(hs)))
    check(mind > C.H_COPY, f"gerçek: farklı fotoğraflar arası en küçük Hamming > H_COPY ({mind})")


def main() -> int:
    import tempfile

    test_phash()
    test_sharpness_brightness()
    with tempfile.TemporaryDirectory() as t:
        test_analyze_file(t)
    test_screenshot()
    test_popcount()
    test_grouping()
    test_chain_and_cap()
    test_color_and_aspect_guard()
    test_text_guard()
    if "--images" in sys.argv:
        test_real(sys.argv[sys.argv.index("--images") + 1])
    print(f"\n{'BAŞARISIZ: ' + str(len(FAILS)) if FAILS else 'tümü geçti'}")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
