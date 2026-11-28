"""Yüz tanıma ölçümü (LFW): algılama, aynı/farklı kişi uzaklıkları, kümeleme saflığı, gecikme, RAM.

    python -I ml/tests/face_eval.py --model-dir <models>/buffalo_l --lfw <lfw kökü> [--out <klasör>] [--threads 4]

LFW: http://vis-www.cs.umass.edu/lfw/ (scikit-learn aynası: https://ndownloader.figshare.com/files/5976018, lfw.tgz).
--out verilirse algılama kutuları/noktaları çizilmiş örnekler oraya yazılır (gözle denetim için).
Kümeleme Immich mantığıyla (clustering.run_pass) DB'siz, bellekte; atama/oluşturma geri çağrıları sahte.
"""
from __future__ import annotations

import argparse
import os
import random
import resource
import sys
import time

import numpy as np
from PIL import Image, ImageDraw

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, ".."))

from ownmemories_ml import clustering as C  # noqa: E402
from ownmemories_ml import faces as F  # noqa: E402


def rss() -> int:
    with open("/proc/self/status") as f:
        for line in f:
            if line.startswith("VmRSS"):
                return int(line.split()[1]) // 1024
    return 0


def main_face(fl, size) -> int | None:
    """LFW'de kimlik yüzü görüntünün ortasındakidir; diğerleri arka plan."""
    if not fl:
        return None
    cx, cy = size[0] / 2, size[1] / 2
    return min(range(len(fl)), key=lambda i: ((fl[i].box[0] + fl[i].box[2]) / 2 - cx) ** 2
               + ((fl[i].box[1] + fl[i].box[3]) / 2 - cy) ** 2)


def cluster_eval(name, P, L, idents) -> None:
    clusters = sorted(set(P[P > 0]))
    tot = maj = 0
    for c in clusters:
        _, cnt = np.unique(L[P == c], return_counts=True)
        tot += cnt.sum()
        maj += cnt.max()
    comp, split, unas, idn = [], 0, 0, 0
    for p in idents:
        pc = P[L == p]
        idn += len(pc)
        vals, cnt = np.unique(pc[pc > 0], return_counts=True)
        comp.append((cnt.max() if len(cnt) else 0) / max(1, len(pc)))
        split += max(0, len(vals) - 1)
        unas += int((pc == 0).sum())
    noise = np.array([str(x).startswith(("distractor", "bg")) for x in L])
    print(f"  [{name}] küme {len(clusters)} | saflık {maj / max(1, tot) * 100:.1f}% | kimlik yüzlerinin kendi "
          f"kümesindeki oranı {np.mean(comp) * 100:.1f}% | fazladan bölünme {split} | atanmamış kimlik yüzü "
          f"{unas}/{idn} | kümelenen çeldirici/arka plan {(P[noise] > 0).sum()}/{noise.sum()}")


def simulate(E, S, maxd, minf, order=None, batch=8):
    """Yüzler sırayla (8'erli) gelir: her toplu işte artımlı geçiş, sonda tam geçiş. -> (artımlı, son) kişi dizileri."""
    n = len(E)
    order = np.arange(n) if order is None else order
    fs = C.FaceSet()
    nextp = [0]

    def create(idx):
        nextp[0] += 1
        return nextp[0]

    for s in range(0, n, batch):
        b = order[s:s + batch]
        fs.append(list(range(fs.n + 1, fs.n + 1 + len(b))), E[b], S[b])
        new = np.arange(fs.n - len(b), fs.n)
        seeds, nb = set(int(i) for i in new), {}
        for i, nl in zip(new, C.neighbor_lists(fs, new, maxd)):
            nb[int(i)] = nl
            seeds.update(int(j) for j in nl if fs.person[j] == 0)
        C.run_pass(fs, np.array(sorted(seeds)), maxd, minf, lambda i, p: True, create, nb)
    inc = fs.person.copy()
    t = time.perf_counter()
    C.run_pass(fs, np.flatnonzero(fs.person == 0), maxd, minf, lambda i, p: True, create)
    return inc, fs.person.copy(), time.perf_counter() - t


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--model-dir", required=True)
    ap.add_argument("--lfw", required=True)
    ap.add_argument("--out", default="")
    ap.add_argument("--threads", type=int, default=4)
    a = ap.parse_args()
    lfw = a.lfw
    if a.out:
        os.makedirs(a.out, exist_ok=True)
    r0 = rss()
    t = time.time()
    m = F.FaceModel(a.model_dir, threads=a.threads, min_size=0)
    print(f"model yükleme {time.time() - t:.1f} sn, RSS +{rss() - r0} MB")
    random.seed(7)
    allp = sorted(os.listdir(lfw))
    count = {p: len(os.listdir(os.path.join(lfw, p))) for p in allp}
    people8 = [p for p in allp if count[p] >= 8]

    # 1) uzaklıklar: 40 kişi x 8 foto
    dist_ids = random.sample(people8, 40)
    emb, lab, times, noface = [], [], [], 0
    for p in dist_ids:
        for f in sorted(os.listdir(os.path.join(lfw, p)))[:8]:
            img = F.load_image(os.path.join(lfw, p, f))
            t = time.perf_counter()
            fl = m.analyze(img)
            times.append(time.perf_counter() - t)
            k = main_face(fl, img.size)
            if k is None:
                noface += 1
                continue
            emb.append(fl[k].embedding)
            lab.append(p)
    E = np.stack(emb)
    L = np.array(lab)
    D = 1 - E @ E.T
    iu = np.triu_indices(len(L), 1)
    same = (L[:, None] == L[None, :])[iu]
    d = D[iu]
    print(f"\n1) uzaklıklar: {len(times)} görüntü, {len(L)} kimlik yüzü, yüz bulunamayan {noface}")
    print(f"  aynı kişi ({same.sum()} çift): <0.5 %{np.mean(d[same] < 0.5) * 100:.1f}, medyan {np.median(d[same]):.3f}, "
          f"p95 {np.percentile(d[same], 95):.3f}")
    print(f"  farklı kişi ({(~same).sum()} çift): >0.5 %{np.mean(d[~same] > 0.5) * 100:.2f}, medyan "
          f"{np.median(d[~same]):.3f}, p1 {np.percentile(d[~same], 1):.3f}, en küçük {d[~same].min():.3f}")
    for th in (0.4, 0.5, 0.6):
        print(f"  eşik {th}: doğru kabul %{np.mean(d[same] < th) * 100:.1f}, yanlış kabul %{np.mean(d[~same] < th) * 100:.3f}")
    print(f"  gecikme (250x250 LFW): ortalama {np.mean(times) * 1000:.0f} ms, medyan {np.median(times) * 1000:.0f} ms")

    # 2) kümeleme: 10 kişi x 4-8 foto ve 30 kişi x 4-15 foto + 150 çeldirici (1-2 foto), arka plan yüzleri dahil
    for title, n_id, maxn, n_dis in (("10 kişi x 4-8 foto", 10, 8, 0), ("30 kişi x 4-15 foto + 150 çeldirici", 30, 15, 150)):
        pool = [p for p in allp if count[p] >= (8 if maxn == 8 else 10) and p not in dist_ids]
        idents = random.sample(pool, n_id)
        distr = random.sample([p for p in allp if count[p] <= 3], n_dis)
        E2, L2, S2, drawn = [], [], [], 0
        for p in idents + distr:
            files = sorted(os.listdir(os.path.join(lfw, p)))
            files = random.sample(files, random.randint(4, min(maxn, len(files)))) if p in idents else files[:2]
            for f in files:
                img = F.load_image(os.path.join(lfw, p, f))
                fl = [x for x in m.analyze(img) if min(x.box[2] - x.box[0], x.box[3] - x.box[1]) >= 32]
                k = main_face(fl, img.size)
                for j, fc in enumerate(fl):
                    E2.append(fc.embedding)
                    S2.append(fc.score)
                    L2.append((p if p in idents else "distractor:" + p) if j == k else f"bg:{p}/{f}/{j}")
                if a.out and len(fl) > 1 and drawn < 6:
                    im = img.copy()
                    dr = ImageDraw.Draw(im)
                    for j, fc in enumerate(fl):
                        dr.rectangle(fc.box, outline=(0, 255, 0) if j == k else (255, 0, 0), width=2)
                        for x, y in fc.landmarks:
                            dr.ellipse((x - 2, y - 2, x + 2, y + 2), fill=(255, 255, 0))
                    im.save(os.path.join(a.out, f"det_{n_id}_{drawn}.jpg"))
                    drawn += 1
        E2, S2, L2 = np.stack(E2), np.array(S2, np.float32), np.array(L2)
        nid = sum(1 for x in L2 if not x.startswith(("distractor", "bg")))
        print(f"\n2) kümeleme, {title}: {len(L2)} yüz ({nid} kimlik, "
              f"{sum(x.startswith('distractor') for x in L2)} çeldirici, {sum(x.startswith('bg') for x in L2)} arka plan)")
        for maxd, minf in ((0.5, 3), (0.6, 3), (0.5, 2)):
            order = np.random.RandomState(3).permutation(len(L2))
            inc, fin, tfull = simulate(E2, S2, maxd, minf, order)
            cluster_eval(f"maxd {maxd} minFaces {minf} artımlı", inc, L2[order], idents)
            cluster_eval(f"maxd {maxd} minFaces {minf} artımlı + tam geçiş", fin, L2[order], idents)
            _, one, t1 = simulate(E2, S2, maxd, minf, batch=len(L2))
            cluster_eval(f"maxd {maxd} minFaces {minf} tek tam geçiş ({t1 * 1000:.0f} ms)", one, L2, idents)

    # 3) gecikme: 1440x960, 24 yüz (6x4 LFW karosu) ve tek yüzlü 1440x1440
    tiles = [os.path.join(lfw, p, sorted(os.listdir(os.path.join(lfw, p)))[0]) for p in people8[:24]]
    grid = Image.new("RGB", (1440, 960))
    for i, tp in enumerate(tiles):
        grid.paste(Image.open(tp).convert("RGB").resize((240, 240)), ((i % 6) * 240, (i // 6) * 240))
    lt = []
    for _ in range(4):
        t = time.perf_counter()
        fl = m.analyze(grid)
        lt.append(time.perf_counter() - t)
    t = time.perf_counter()
    m.detect(grid)
    tdet = time.perf_counter() - t
    if a.out:
        im = grid.copy()
        dr = ImageDraw.Draw(im)
        for fc in fl:
            dr.rectangle(fc.box, outline=(0, 255, 0), width=3)
        im.save(os.path.join(a.out, "grid_det.jpg"))
    one = Image.open(tiles[0]).convert("RGB").resize((1440, 1440))
    lt1 = []
    for _ in range(4):
        t = time.perf_counter()
        fl1 = m.analyze(one)
        lt1.append(time.perf_counter() - t)
    print(f"\n3) gecikme: 1440x960 24 yüz -> {len(fl)} bulundu, {np.mean(lt[1:]) * 1000:.0f} ms (yalnız algılama "
          f"{tdet * 1000:.0f} ms); 1440x1440 tek yüz -> {len(fl1)}, {np.mean(lt1[1:]) * 1000:.0f} ms")
    print(f"RSS: başlangıç {r0} MB, şimdi {rss()} MB (+{rss() - r0}), tepe {resource.getrusage(resource.RUSAGE_SELF).ru_maxrss // 1024} MB")
    return 0


if __name__ == "__main__":
    sys.exit(main())
