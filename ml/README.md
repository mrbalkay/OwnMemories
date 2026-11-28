# ownmemories-ml — akıllı arama + yüz tanıma servisi

Hazırlayan: Mehmet BALKAY

OwnMemories için doğal dil ile fotoğraf arama ("kumsalda köpek", "karlı dağ"). Immich'in ONNX'e aktardığı
CLIP/SigLIP modelleriyle görüntü vektörlerini üretir (`asset_embeddings` tablosu) ve sorgu metnini aynı uzaya
kodlayıp kullanıcının fotoğraflarını benzerliğe göre sıralar. Python 3.10+, web çatısı yok (stdlib
`ThreadingHTTPServer`), yalnızca `127.0.0.1:8765` dinler. Aynı süreç Kişiler için yüz algılama + tanıma +
kümeleme yapar (Immich `buffalo_l` ONNX; `faces.py`, `clustering.py`; `faces`/`face_scans`/`people`/`asset_person`
tabloları). buffalo_l ağırlıkları yalnızca ticari olmayan kullanım içindir. Ayrıntılı sözleşme: `docs/ARCHITECTURE.md` §7,
kurulum: `docs/INSTALL.md` G.

## Üretim

`sudo bash server/install-ml.sh` (update.sh/install-ubuntu.sh kendiliğinden çağırır): sanal ortam
`/opt/ownmemories-ml`, modeller `/var/lib/ownmemories/models/<model>`, ayarlar `/etc/ownmemories/ml.env`,
systemd birimi `ownmemories-ml.service` (www-data, Nice=10, MemoryHigh=3000M).

## Geliştirme

```bash
python3 -m venv /tmp/mlvenv
/tmp/mlvenv/bin/pip install -r ml/requirements.txt -r ml/requirements-quantize.txt
cd ml
/tmp/mlvenv/bin/python -m ownmemories_ml download --dest /tmp/models            # ~1.5 GB
/tmp/mlvenv/bin/python -m ownmemories_ml quantize --model-dir /tmp/models/ViT-B-16-SigLIP2__webli
/tmp/mlvenv/bin/python -m ownmemories_ml download --model immich-app/buffalo_l --dest /tmp/models  # yüz, ~190 MB
OM_DB_HOST=127.0.0.1 OM_DB_NAME=om_dev OM_DB_USER=om OM_DB_PASS=om OM_STORAGE=/srv/omdevstore \
OM_ML_MODEL_DIR=/tmp/models OM_ML_THREADS=4 /tmp/mlvenv/bin/python -m ownmemories_ml serve
```

```bash
curl -s 127.0.0.1:8765/health
curl -s 127.0.0.1:8765/search -d '{"user_id":1,"text":"kumsalda köpek","limit":20}'
curl -s '127.0.0.1:8765/status?user_id=1'
curl -s '127.0.0.1:8765/faces/status?user_id=1'
curl -s 127.0.0.1:8765/faces/recluster -d '{"user_id":1}'
curl -s 127.0.0.1:8765/cleanup/groups -d '{"user_id":1}'      # Temizlik: benzer/yinelenen gruplar
```

## Testler

```bash
# Model kalitesi / gecikme / RAM (örnek fotoğraflar p1..p18.jpg; --quant ile int8 karşılaştırması)
python -I ml/tests/eval_samples.py --model-dir /tmp/models/ViT-B-16-SigLIP2__webli --images <klasör> \
  --quant /tmp/models/ViT-B-16-SigLIP2__webli/textual/model.int8.onnx
# Uçtan uca: geçici veritabanı (om_mltest_<pid>) + geçici depolama, dizinleyici ve tüm uçlar
OM_TEST_DB_HOST=127.0.0.1 OM_TEST_DB_USER=om OM_TEST_DB_PASS=om \
python -I ml/tests/test_service.py --model-dir /tmp/models/ViT-B-16-SigLIP2__webli --images <klasör>
# Varsayılan ilgi eşiği (DB/model gerekmez, saniyeler sürer)
python -I ml/tests/test_cutoff.py
# Türkçe kalite karşılaştırması: Crossmodal-3600 (indirme komutları dosyanın başında)
python -I ml/tests/xm3600_bench.py --data <xm3600 klasörü> --model-dir /tmp/models/ViT-B-16-SigLIP2__webli
# Temizlik: pHash/netlik/renk imzası/ekran görüntüsü kuralı/gruplama (model/DB gerekmez; --images ile gerçek fotoğraflar)
python -I ml/tests/test_cleanup.py --images <p1..p18.jpg klasörü>
# Yüz: birim testleri (NMS, Umeyama, hizalama, kümeleme; model/DB gerekmez)
python -I ml/tests/test_face_ops.py
# Yüz: uçtan uca (geçici DB om_facetest_<pid>, LFW görselleri, CLIP modeli olmadan)
#   LFW: curl -L -o lfw.tgz https://ndownloader.figshare.com/files/5976018 && tar xzf lfw.tgz
OM_TEST_DB_HOST=127.0.0.1 OM_TEST_DB_USER=om OM_TEST_DB_PASS=om \
python -I ml/tests/test_faces.py --face-model-dir /tmp/models/buffalo_l --lfw <lfw>
# Yüz: LFW ölçümü (uzaklıklar, kümeleme saflığı, gecikme, RAM; --out ile çizilmiş kutular)
python -I ml/tests/face_eval.py --model-dir /tmp/models/buffalo_l --lfw <lfw> --out /tmp/face-eval
```

Sonuç sayısı: istekte `min_score` yoksa servis yalnızca sorguyla belirgin şekilde eşleşenleri döner
(z ≥ `OM_ML_MIN_Z` veya puan ≥ `OM_ML_MIN_SCORE`; modele göre varsayılanlar `config.CUTOFF`).
