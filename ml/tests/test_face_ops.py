"""Yüz işlemleri birim testleri (model ve DB gerekmez):  python -I ml/tests/test_face_ops.py

NMS, Umeyama benzerlik dönüşümü, PIL affine hizalama (OpenCV piksel kuralı), SCRFD çözümü, letterbox ve
kümeleme geçişinin (çekirdek/erteleme, ret, kullanıcı ataması, kişi oluşturma) davranışı.
"""
from __future__ import annotations

import os
import sys

import numpy as np
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))

from ownmemories_ml import clustering as C  # noqa: E402
from ownmemories_ml import faces as F  # noqa: E402

FAILS: list[str] = []


def check(cond, msg: str) -> None:
    print(("  ok   " if cond else "  FAIL ") + msg)
    if not cond:
        FAILS.append(msg)


def test_nms() -> None:
    boxes = np.array([[0, 0, 10, 10], [1, 1, 11, 11], [20, 20, 30, 30], [0, 0, 10, 10.5]], dtype=np.float32)
    scores = np.array([0.8, 0.9, 0.7, 0.85], dtype=np.float32)
    keep = F.nms(boxes, scores)
    check(list(keep) == [1, 2], f"NMS: çakışanlar bastırıldı, skora göre sıralı {list(keep)}")
    check(F.nms(np.zeros((0, 4), np.float32), np.zeros(0, np.float32)).size == 0, "NMS: boş girdi")
    # IoU tam 0.4 olan kutu tutulur (cv2: > eşik bastırılır)
    a = np.array([[0, 0, 10, 10], [0, 0, 10, 4]], dtype=np.float32)
    check(len(F.nms(a, np.array([0.9, 0.8], np.float32))) == 2, "NMS: IoU = 0.4 bastırılmaz")


def test_umeyama() -> None:
    rng = np.random.default_rng(1)
    src = rng.uniform(0, 100, (5, 2))
    ang, sc, t = 0.3, 1.7, np.array([12.0, -5.0])
    r = np.array([[np.cos(ang), -np.sin(ang)], [np.sin(ang), np.cos(ang)]])
    dst = (sc * (r @ src.T)).T + t
    m = F.umeyama(src, dst)
    want = np.hstack([sc * r, t[:, None]])
    check(np.allclose(m, want, atol=1e-3), "Umeyama: bilinen benzerlik dönüşümünü bulur")
    m2 = F.umeyama(F.ARCFACE_DST, F.ARCFACE_DST)
    check(np.allclose(m2, [[1, 0, 0], [0, 1, 0]], atol=1e-5), "Umeyama: şablon -> şablon birim dönüşüm")


def test_warp() -> None:
    rng = np.random.default_rng(2)
    arr = rng.integers(0, 255, (200, 300, 3), dtype=np.uint8)
    img = Image.fromarray(arr)
    m = np.array([[1, 0, -40], [0, 1, -25]], dtype=np.float32)  # kaynak (40,25) -> hedef (0,0)
    out = F.warp_affine(img, m, 112)
    check(np.array_equal(out, arr[25:137, 40:152]), "warp_affine: tam sayı öteleme piksel kaydırması (OpenCV kuralı)")
    m = np.array([[2, 0, 0], [0, 2, 0]], dtype=np.float32)  # 2x büyütme: hedef (2k) = kaynak (k)
    out = F.warp_affine(img, m, 112).astype(int)
    check(np.abs(out[0:100:2, 0:100:2] - arr[0:50, 0:50].astype(int)).max() <= 1,
          "warp_affine: ölçekte piksel merkezleri OpenCV ile hizalı")
    check(F.warp_affine(img, np.array([[1, 0, 500], [0, 1, 0]], np.float32), 16).max() == 0,
          "warp_affine: görüntü dışı siyah")


def test_letterbox_decode() -> None:
    img = Image.new("RGB", (1280, 720), (200, 100, 50))
    canvas, scale = F.letterbox(img, 640)
    check(canvas.shape == (640, 640, 3) and abs(scale - 0.5) < 1e-9 and canvas[359, 639].tolist() == [200, 100, 50]
          and canvas[360, 0].tolist() == [0, 0, 0], "letterbox: sol üst, ölçek 0.5, alt dolgu siyah")
    # SCRFD: adım 8'de tek çapa (hücre (3, 2), çapa 0) skor 0.9, uzaklıklar 1 adım
    heads = []
    for stride in F.DET_STRIDES:
        n = (640 // stride) ** 2 * 2
        heads.append(np.zeros((n, 1), np.float32))
    for stride in F.DET_STRIDES:
        n = (640 // stride) ** 2 * 2
        heads.append(np.zeros((n, 4), np.float32))
    for stride in F.DET_STRIDES:
        n = (640 // stride) ** 2 * 2
        heads.append(np.zeros((n, 10), np.float32))
    cell = 2 * (640 // 8) + 3
    heads[0][cell * 2, 0] = 0.9
    heads[3][cell * 2] = [1, 1, 2, 2]
    heads[6][cell * 2] = [0.5, 0.5] * 5
    s, b, k = F.decode_scrfd(heads)
    i = int(np.argmax(s))
    check(s[i] == np.float32(0.9) and b[i].tolist() == [16, 8, 40, 32] and k[i][:2].tolist() == [28, 20],
          f"SCRFD çözümü: merkez (24,16), kutu {b[i].tolist()}, nokta {k[i][:2].tolist()}")
    s3, b3, _ = F.decode_scrfd([h[None] for h in heads])
    check(np.array_equal(b3, b) and np.array_equal(s3, s), "SCRFD: toplu boyutlu (1, N, k) çıktılar da çözülür")


def unit(v):
    v = np.asarray(v, np.float32)
    return v / np.linalg.norm(v)


def make_set(groups: list[int], noise: int, seed: int = 3) -> tuple[C.FaceSet, np.ndarray]:
    rng = np.random.default_rng(seed)
    vecs, labels = [], []
    for g, n in enumerate(groups):
        center = unit(rng.normal(size=512))
        for _ in range(n):
            vecs.append(unit(center + rng.normal(size=512) * 0.025))  # kosinüs uzaklığı ~0.15-0.3
            labels.append(g)
    for _ in range(noise):
        vecs.append(unit(rng.normal(size=512)))
        labels.append(-1)
    fs = C.FaceSet()
    fs.append(list(range(1, len(vecs) + 1)), np.stack(vecs), [0.8] * len(vecs))
    return fs, np.array(labels)


def callbacks():
    created = []

    def assign(i, p):
        return True

    def create(idx):
        created.append(list(idx))
        return 100 + len(created)

    return assign, create, created


def test_clustering() -> None:
    fs, lab = make_set([6, 4, 2], noise=5)
    nb = C.neighbor_lists(fs, np.arange(fs.n), 0.5)
    check(all(int(nb[i][0]) == i for i in range(fs.n)), "komşu listesi kendisiyle başlar")
    assign, create, created = callbacks()
    st = C.run_pass(fs, np.arange(fs.n), 0.5, 3, assign, create)
    p = fs.person
    check(len(created) == 2 and st["created"] == 2, f"6'lı ve 4'lü gruba kişi, 2'li gruba yok ({st})")
    check(len(set(p[lab == 0])) == 1 and p[lab == 0][0] > 0 and len(set(p[lab == 1])) == 1 and p[lab == 1][0] > 0
          and p[lab == 0][0] != p[lab == 1][0], "gruplar saf ve ayrı")
    check((p[lab == 2] == 0).all() and (p[lab == -1] == 0).all(), "2'li grup ve gürültü atanmadı")

    # yeni yüz: var olan kişiye (çekirdek değil ama kişili komşu) atanır
    fs2, lab2 = make_set([6], noise=0, seed=4)
    fs2.person[:5] = 7
    assign, create, created = callbacks()
    C.run_pass(fs2, np.array([5]), 0.5, 3, assign, create)
    check(fs2.person[5] == 7 and not created, "yeni yüz var olan kişiye atanır")

    # ret: yüz reddettiği kişiye atanmaz; yeni kişi de oluşmaz (atanmamış eşleşme yok)
    fs2.person[5] = 0
    fs2.rejected[5] = 7
    C.run_pass(fs2, np.array([5]), 0.5, 3, assign, create)
    check(fs2.person[5] == 0 and not created, "reddedilen kişiye geri atanmaz")

    # çoğunluk oyu: en yakın 3 eşleşmede iki kişi -> en sık olan
    fs3, _ = make_set([8], noise=0, seed=5)
    fs3.person[:] = 0
    nb3 = C.neighbor_lists(fs3, np.array([0]), 0.5)[0]
    a, b, c = (int(x) for x in nb3[1:4])
    fs3.person[a] = 1
    fs3.person[b] = 2
    fs3.person[c] = 2
    C.run_pass(fs3, np.array([0]), 0.5, 4, lambda i, p: True, lambda idx: None)
    check(fs3.person[0] == 2, f"çoğunluk oyu: {fs3.person[0]}")

    # atama başarısız (PHP kişiyi silmiş): yüz -1 olur, başka kişi denenmez, hata yok
    fs4, _ = make_set([4], noise=0, seed=6)
    fs4.person[:3] = 9
    C.run_pass(fs4, np.array([3]), 0.5, 3, lambda i, p: False, lambda idx: None)
    check(fs4.person[3] == -1, "başarısız atama -1 (bilinmiyor) olarak işaretlenir")

    # kullanıcı ataması ve kişili yüzler aday listesinde olsa bile değişmez
    fs5, lab5 = make_set([5], noise=0, seed=7)
    fs5.person[0] = 3
    fs5.user[0] = True
    assign, create, created = callbacks()
    C.run_pass(fs5, np.arange(fs5.n), 0.5, 3, assign, create)
    check(fs5.person[0] == 3 and (fs5.person[1:] == 3).all(), "kullanıcının kişisi korunur, komşular ona katılır")

    # FaceSet.index_of
    check(list(fs.index_of([1, 3, 999])) == [0, 2], "index_of bilinmeyen id'yi atlar")


def main() -> int:
    for t in (test_nms, test_umeyama, test_warp, test_letterbox_decode, test_clustering):
        print(t.__name__)
        t()
    print("SONUÇ:", "BAŞARILI" if not FAILS else f"{len(FAILS)} HATA")
    return 1 if FAILS else 0


if __name__ == "__main__":
    sys.exit(main())
