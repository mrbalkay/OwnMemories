"""OCR değerlendirmesi için gerçek fotoğraflar: Wikimedia Commons'tan Türkçe tabelalar, eczaneler, dükkânlar.

    python -I ml/tests/ocr_commons.py --out /tmp/ocr/real [--neg-out /tmp/ocr/real-neg]

<out>/<ad>.jpg (uzun kenar 1600 px; Special:FilePath küçük resmi) + <out>/truth.json + <out>/ATTRIBUTION.txt.
Yazısız (yemek) fotoğraflar --neg-out klasörüne iner: yazısız fotoğrafta OCR gürültüsü olmamalı.
Var olan dosyalar yeniden indirilmez. Commons istek sınırına (HTTP 429) takılırsa bekleyip yeniden dener.

Doğru metin ("truth") fotoğrafta insan gözüyle rahat okunan yazılardır (tabela, levha); çok küçük/bulanık
yazılar dahil değildir. Fotoğraflar aşağıdaki yazarlara aittir ve belirtilen lisanslarla kullanılmıştır
(yalnızca yerel ölçüm için indirilir, depoya eklenmez):
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
import urllib.parse
import urllib.request

UA = "OwnMemoriesOCREval/1.0 (offline OCR evaluation)"

# (ad, Commons dosya adı, yazar, lisans, doğru metin)
PHOTOS = [
    ("r01", "İstanbul_Güneş_Eczanesi.jpg", "Türkdüzenlemeci28", "CC0", "ECZANE\nİSTANBUL GÜNEŞ\nTATET"),
    ("r02", "2026_yılında_Medine_Eczanesi.jpg", "Türkdüzenlemeci28", "CC0", "ECZANE\nMEDİNE\nMEDİNE ECZANESİ"),
    ("r03", "Hisar_Eczanesi.jpg", "Satirdan kahraman", "CC BY-SA 4.0", "HİSAR ECZANESİ"),
    ("r04", "Sıhhat_Eczanesi.jpg", "Satirdan kahraman", "CC BY-SA 4.0", "ECZANE\nSIHHAT\nsince 1923"),
    ("r05", "Çaylıoğlu_Eczanesi_-_Mart_2014_-_panoramio.jpg", "Ulvi Yelen", "CC BY 3.0", "eczane\nÇAYLIOĞLU"),
    ("r09", "AKÇAY_MAKİNA.jpg", "Sami zb", "CC BY-SA 4.0",
     "AKÇAY MAKİNE\nADLER PFAFF\nHIGHLEAD SEIKO\nTYPICAL brother\nMAKİNE ALIM SATIM\nYEDEK PARÇA İMALAT\nve TAMİR\n"
     "Telfax:546 33 36"),
    ("r10", "Ahmet_Refik_St.,_Kadıköy.jpg", "Adem", "CC BY 4.0",
     "AHMET REFİK\nSOKAĞI 1 29\nGÖZTEPE MAHALLESİ\nKADIKÖY TN: 63"),
    ("r11", "Cevdet_Paşa_street_sign_in_July_2024.jpg", "Huluxsi", "CC BY-SA 4.0", "CEVDETPAŞA\nCADDESİ 1 131\nFATİH"),
    ("r12", "Hacı_Necipusta_street_sign_in_July_2024.jpg", "Kurmanbek", "CC BY-SA 4.0",
     "HACI\nNECİPUSTA\nSOKAĞI\nKADIKÖY"),
    ("r13", "Prof._Zühtü_Müridoğlu_St.,_Kadıköy.jpg", "Adem", "CC BY 4.0",
     "PROF. ZÜHTÜ\nMÜRİDOĞLU ÇIKMAZI\nSOKAĞI 1 13\nGÖZTEPE MAHALLESİ\nKADIKÖY TN: 6"),
    ("r14", "Bağdat_St._Kadıköy_6_July_2024_(2).jpg", "Wiki leylek", "CC BY 4.0", "Bağdat Caddesi"),
    ("r15", "Mehmet_Şengün_St._Kadıköy_6_July_2024_(3).jpg", "Wiki leylek", "CC BY 4.0",
     "MEHMET\nŞENGÜN\nSOKAĞI 1 15\nFENERBAHÇE MAHALLESİ\nKADIKÖY TN: 24"),
    ("r16", "Tütüncü_Mehmetefendi_St._Kadıköy_6_July_2024_(2).jpg", "Wiki leylek", "CC BY 4.0",
     "TÜTÜNCÜ\nMEHMETEFENDİ\nCADDESİ\nKADIKÖY\nİSTANBUL\nOYUNCAK MÜZESİ\nGÖZTEPE\nMAHALLE\nMUHTARLIĞI"),
    ("r17", "Can_plak,_Istanbul.jpg", "Jwslubbock", "CC BY-SA 3.0", "CAN PLAK\nKUAFÖR"),
    ("r22", "EDS_levhası.jpg", "Taras Rynza from Moscow, Russia", "CC BY-SA 2.0", "EDS\nDenetleme Sistemi"),
    ("r23", "Şair_Nefi_st.jpg", "Tarih", "CC0", "ŞAİR NEFİ\nSOKAĞI 1 41\nCAFERAĞA MAHALLESİ\nKADIKÖY TN: 51"),
    ("r24", "Cemal_Süreyye_st.2.jpg", "Tarih", "CC0", "CEMAL\nSÜREYA\nSOKAĞI 48 2\nCAFERAĞA MAHALLESİ\nKADIKÖY TN: 81"),
    ("r25", "Fazıl_Hüsnü_Dağlarca_st.2.jpg", "Tarih", "CC0", "FAZIL HÜSNÜ\nDAĞLARCA\nSOKAĞI\nKADIKÖY"),
    ("r26", "Dr._Şakir_Paşa_st.2.jpg", "Tarih", "CC0", "Dr.Şakir Paşa\nSokak"),
    ("r27", "Aycell_-_Sıla_Izgara.jpg", "Satirdan kahraman", "CC0", "AYCELL\nKESKİNHAN İLETİŞİM\nSILA IZGARA"),
    ("r29", "Cafer_Erol_Candy_Shop_with_new_year_decorations.jpg", "Kurmanbek", "CC BY-SA 4.0",
     "ŞEKERCİ CAFER EROL"),
    ("r30", "Rainbow45_Records.jpg", "Jwslubbock", "CC BY-SA 3.0", "mitokondri"),
    ("r32", "Hastane_-_panoramio_(1).jpg", "aachim3", "CC BY 3.0", "ZANE YÜCEL\nLeylim Cafe"),
]
# yazısız: yemek fotoğrafları (çiğ köfte; "çiğköfte" araması bunları OCR'la değil akıllı aramayla bulmalı)
NEGATIVES = [
    ("n06", "Çiğ_Köfte_Itanbul_Develi_Kalamış_(1).jpg", "Benreis", "CC BY-SA 4.0"),
    ("n07", "Çiğ_köfte_in_Fatih.jpg", "Kadı", "CC BY 4.0"),
    ("n28", "Mezemsi.jpg", "E4024", "CC BY-SA 3.0"),
    ("n31", "Çiğköfte_2013-11-12_03-26.jpg", "User:Yakupismail", "CC BY-SA 3.0"),
]


def fetch(title: str, dest: str, width: int = 1600) -> bool:
    if os.path.isfile(dest) and os.path.getsize(dest) > 10000:
        return True
    url = f"https://commons.wikimedia.org/wiki/Special:FilePath/{urllib.parse.quote(title)}?width={width}"
    for attempt in range(5):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=60) as r:
                data = r.read()
            with open(dest + ".part", "wb") as fh:
                fh.write(data)
            os.replace(dest + ".part", dest)
            time.sleep(1)
            return True
        except Exception as e:  # noqa: BLE001
            print(f"  {title}: {e}; yeniden denenecek", file=sys.stderr)
            time.sleep(10 * (attempt + 1))
    return False


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True)
    ap.add_argument("--neg-out", default="")
    a = ap.parse_args()
    os.makedirs(a.out, exist_ok=True)
    truth, attr = {}, []
    for name, title, author, lic, text in PHOTOS:
        if fetch(title, os.path.join(a.out, name + ".jpg")):
            truth[name] = text
            attr.append(f"{name}.jpg  {title}  —  {author}, {lic}, Wikimedia Commons")
    with open(os.path.join(a.out, "truth.json"), "w", encoding="utf-8") as fh:
        json.dump(truth, fh, ensure_ascii=False, indent=1)
    if a.neg_out:
        os.makedirs(a.neg_out, exist_ok=True)
        for name, title, author, lic in NEGATIVES:
            if fetch(title, os.path.join(a.neg_out, name + ".jpg")):
                attr.append(f"{name}.jpg  {title}  —  {author}, {lic}, Wikimedia Commons")
    with open(os.path.join(a.out, "ATTRIBUTION.txt"), "w", encoding="utf-8") as fh:
        fh.write("\n".join(attr) + "\n")
    print(f"{len(truth)} yazılı fotoğraf: {a.out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
