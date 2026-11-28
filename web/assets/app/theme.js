// OwnMemories — görünüm teması (sinema | galeri | yerel). Tema hesapta saklanır (PATCH /v1/me);
// <html data-skin> ve yalnızca etkin temanın stil dosyası açık tutulur. Temalar app.css'teki
// değişkenleri ve kanca sınıflarını geçersiz kılar; işaretleme tüm temalarda aynıdır.
/*
 * TEMA KANCA SÖZLEŞMESİ (theme-*.css yalnızca bunlara ve mevcut sınıflara dayanır; işaretleme tüm temalarda aynı).
 * Kurallar :root[data-skin="X"] altında yazılır. Yeni kanca öğeleri app.css'te gizli/nötrdür; tema açar.
 * S = sinema, G = galeri, Y = yerel (beklenen kullanım).
 *
 * Kabuk (web/app/index.php)
 *   #app.app[data-route="photos|favorites|people|person|albums|album|shared|map|archive|trash|search|settings"]  her rotada güncellenir
 *   .ambient#ambient          #app'in ilk çocuğu, tam ekran katman (gizli). --ambient / --ambient-rgb ile boya   S
 *   .sidebar .logo .nav .nav-item .nav-settings .me .me-name .me-usage .me-links   (mevcut)
 *   .me-avatar                .me-name içinde baş harf (gizli)   Y
 *   .me-name-text             kullanıcı adı
 *   .me-bar[data-quota="0|1"] > i   kota çubuğu, i genişliği calc(var(--used)*100%) (gizli; --used 0..1, kota yoksa 0)   S G Y
 *   .topbar                   cam üst çubuk; .search .search input  (mevcut)
 *   .page-title > h1#pageTitleText + p#pageSub   büyük sayfa başlığı + "N öğe" alt yazısı (gizli; topbar'ın ilk öğesi
 *                             menü düğmesinden sonra)   Y
 *   .search-kbd               arama kutusunda "/" ipucu (gizli; "/" tuşu aramaya odaklanır)   S
 *   .top-spacer               esnek boşluk (gizli)   S G
 *   .top-avatar               üst çubukta baş harf avatarı (gizli)   S G
 *   .upload-btn .btn .btn-primary  Yükle düğmesi (mevcut)
 *   .selbar, .v-bar, .story-top    diğer cam yüzeyler (mevcut)
 *
 * Menü (#nav, main.js renderNav) — düz liste, sırası: nav-h[library], photos, favorites, people, nav-h[albums], albums, shared,
 *   map, .nav-sep, nav-h[other], archive, trash
 *   .nav-item[data-nav="photos|favorites|people|albums|shared|map|archive|trash"][data-group="library|albums|other"] > .ic + .nav-label
 *   .nav-h[data-group="library|albums|other"]   grup başlığı "Kitaplık/Albümler/Diğer" (gizli)   Y
 *       Y, Harita'yı Kitaplık grubuna almak için #nav{display:flex} içinde CSS `order` kullanır.
 *   .nav-sep                  Arşiv'den önce ayraç (gizli)   S G
 *
 * Anılar (memories.js) — fotoğraflar sayfasında zaman çizelgesinin üstünde, anı varsa:
 *   .mem-block.has-hero > .mem-hero + .mem-strip
 *   .mem-hero[data-kind="on_this_day|this_week|favorites"][data-years]   ilk anının büyük afişi (gizli)   S (Y isteğe bağlı)
 *     .mem-hero-media > img (kapak önizlemesi, yüklenince img.in; tıklayınca gösteri) | .mem-hero-shade
 *     .mem-hero-text > .mem-hero-eyebrow (i.mem-hero-dot + "N yıl önce bugün") | h2.mem-hero-title ("Kasım 2023")
 *                     | p.mem-hero-meta > .mem-hero-date ("24–29 Kasım 2023") · .mem-hero-count ("12 öğe")
 *                     | .mem-hero-actions > button.btn.mem-hero-play (.ic oynat + "Anıyı oynat")
 *     setAmbientFrom() bu kapakla çağrılır (afiş gizli olsa da --ambient ayarlanır).
 *   .mem-strip[data-count] > .mem-head-row > h2.mem-head + .mem-nav > button.icon-btn.mem-prev / .mem-next (gizli)   S Y
 *                          > .mem-row-wrap > .mem-row + .mem-fade (sağ kenar solması, gizli)   Y
 *   .mem-card[data-kind][data-years][data-index] (.is-first ilk kart: G "lead" büyük eser, Y "wide" geniş kart)
 *       > img (kapak) | .mem-mosaic > img×3 (yalnızca favoriler, gizli; Y) | .mem-shade | .mem-play > .ic (gizli; Y)
 *       | .mem-text > small.mem-eyebrow ("1 yıl önce bugün") + strong ("Kasım 2025" / favorilerde "2019–2025")
 *                    + small.mem-count ("4 öğe") + .mem-date ("27 Kasım 2025", gizli; G etiket satırı, Y alt satır)
 *     DİKKAT: .mem-card.is-first'i GİZLEMEYİN (e2e testi ilk .mem-card'a tıklar); S afişle aynı anıyı tekrar gösterebilir.
 *
 * Zaman çizelgesi (timeline.js)
 *   .timeline > section.month-group[data-month="YYYY-MM"][data-year] (.is-current: görünür ay)
 *     h2.month > .month-name ("Kasım") + .month-year ("2026") + .month-count ("214 öğe", özet gelince; gizli)   S G Y
 *       (S: buzlu camda sticky ay başlığı önerilir; month-group bir section olduğundan sticky onun içinde kalır)
 *     section.day-group[data-day] (.all-selected: günün tümü seçili)
 *       h3.day > .day-label ("Bugün" / "26 Kasım") + .day-sub (hafta günü) + button.day-sel (gizli)   S G Y
 *         button.day-sel > .day-sel-check (.ic onay) + .day-sel-text ("Seç") — günü topluca seçer/bırakır.
 *         S: daire (.day-sel-check), G/Y: "Seç" metni.
 *       .grid > .tile (genişlik/yükseklik JS ile; boşluk .grid column-gap'ten okunur) > img + .tile-badges > .badge(.fav) + .tile-check
 *   nav.scrubber (yıl rayı, ≥3 ay varsa, sağda sabit) > button.scrub-now (.scrub-month + .scrub-year, görünür ay; takvimi açar)   S G Y
 *                                                     + button[data-year] (.on: görünür ayın yılı; o yılın takvimini açar)
 *     Yıl düğmelerini `.scrubber button[data-year]` ile seçin (.scrub-now da bir düğmedir).
 *   button.cal-fab (mobil ≤ 860px yüzen ay düğmesi; .cal-fab-ic + .cal-fab-text) — takvimi alt sayfa olarak açar
 *   section.day-group.day-flash   takvimden gidilen gün (~2 sn vurgu animasyonu)
 *
 * Takvim (calendar.js) — body'ye eklenir: .cal-layer(.in|.out|.is-sheet) > .cal-backdrop + section.cal[role=dialog](.is-loading)
 *   > .cal-grabber (mobil tutamak) + header.cal-head > button.cal-nav.cal-prev + .cal-title > .cal-title-main (.cal-month + .cal-year)
 *       + .cal-sub ("34 öğe · 5 gün") + button.cal-nav.cal-next + button.cal-close
 *   > .cal-week > .cal-wd(.is-weekend)×7 (Pzt…Paz)
 *   > .cal-grid[role=grid] > .cal-row > .cal-pad | .cal-cell > button.cal-day[data-day](.has .is-today .is-weekend)[data-level=1–4]
 *       > img.cal-cover(.in; günün kapağı, yalnızca .has) + .cal-num + .cal-count (yalnızca .has; sayı rozeti)
 *       Fotoğrafsız günler aria-disabled="true".
 *   > .cal-foot > .cal-hint + button.cal-jump ("Bu ayın başına git")
 *   Ortam rengi: afiş yoksa ilk karo; kaydırınca görünür ayın ilk karosu ile setAmbientFrom() çağrılır.
 *
 * Anı gösterisi (.story, memories.js openStory)
 *   .story[data-kind][data-count][data-index] (.paused, .is-video)
 *     .story-bg (bulanık mevcut kare) | .story-media > img(.in) | video | .story-tap.prev/.next (görünmez dokunma alanları)
 *     .story-top > .story-bars > .sb(.done|.now) > i (ilerleme; animation-duration JS)
 *                > .story-title > strong.story-name ("3 yıl önce bugün") + .story-heading ("Kasım 2023", gizli) + .story-caption (tam tarih-saat)
 *                > .story-actions > button "Fotoğrafı aç" (ilk) + button "Kapat"
 *     aside.story-side (gizli; S sol bilgi sütunu, G sergi panosu) > .story-kicker ("Anılar") + h2.story-side-title
 *                + .story-fact[data-fact="when"] > b + span ("24–29 Kasım 2023") + .story-fact[data-fact="count"] > b ("Bu anıda") + span
 *     .story-bottom (gizli) > .story-cap > small.story-cap-sub ("Kasım 2023") + strong.story-cap-title ("25 Kasım 2023 Cumartesi")   S Y
 *                           > .story-film > button.story-thumb(.on) > img   (film şeridi, tıklayınca o kareye gider)   S Y
 *                           > .story-ctl > .story-count ("1 / 18") + .story-prev + .story-pause (.ic + .story-pause-text
 *                             "Duraklat"/"Oynat") + .story-next   G (sayaç/oklar) S Y (duraklat)
 *
 * Görüntüleyici (.viewer .v-bar .v-stage .v-img .v-nav .v-info), albümler (.album-card ...), ayarlar (.settings .theme-card)
 *   mevcut sınıflarıyla biçimlenir.
 *
 * Kişiler (people.js) — #app[data-route="people|person"]; menüde .nav-item[data-nav="people"][data-group="library"]
 *   (favorites'ten sonra; Y `order` ile Harita'dan önce tutar).
 *   .p-ava(.is-empty | .is-photo) > img(.in) | .p-ini (baş harf) | .ic   yuvarlak avatar; boyut --ava (G: kare)   S G Y
 *   .pp (kişi seçici combobox; .pp-compact, .is-busy) > .pp-ic + input.pp-input + ul.pp-list > li.pp-opt(.pp-create)[aria-selected]
 *       > .p-ava + .pp-name + .pp-count
 *   Kişiler sayfası: .page-head.people-head > div > h1 + p.people-sub | button.people-hidden-toggle[aria-pressed]
 *     .people-status (yüz tarama sürüyorsa) > .people-status-dot + .people-status-text + .people-status-bar(--p 0..1) > i
 *     .people-body > section.people-section > .people-grid(--face) > a.person-card(.is-hidden)[data-id]
 *                                                > .person-face > .p-ava (+ .person-hidden-badge) | .person-name | .person-count
 *                  > section.people-section.people-unnamed > .people-section-head > h2 (+ .people-section-count) + p
 *                      > .people-grid.is-unnamed > div.person-card.is-cluster > a.person-face + .person-count
 *                          + .who-slot > button.who-btn ("Bu kim?") | .pp
 *     .empty.people-empty > .people-empty-art > span×3 | h2 | p
 *   Kişi sayfası (zaman çizelgesi başlığı): .person-head(.is-hidden) > a.person-back | .person-hero-face > .p-ava
 *     | .person-info > .person-name-box > h1.person-title > button.person-title-btn > .person-title-text(.is-unnamed) + .person-title-edit
 *                    (düzenlerken .pp.person-title-picker) | p.person-meta (+ .person-hidden-pill) | .person-actions > .btn…
 *     Y: başlık ortalı, üst çubuktaki .page-title bu rotada gizlenir.
 *   Bilgi paneli: .i-row.i-row-people > section.i-people > .i-people-head > h3 + button.i-faces-toggle(.on)
 *     > .p-chips > .p-chip(.is-unnamed)[data-person][data-source] > a|button.p-chip-main > .p-ava + .p-chip-name | button.p-chip-x
 *     > .p-add-slot > button.p-add | .pp   > .f-loose-head + .f-loose > .f-item[data-face] > .p-ava.f-ava + button.f-name-btn | .pp
 *   Yüz kutuları: .v-stage > .v-faces(.has-hot) > button.v-face(.is-named|.is-cluster|.is-unknown|.is-hot) > .v-face-label
 */
import { api } from './api.js';
import { OM } from './util.js';

export const THEMES = ['sinema', 'galeri', 'yerel'];
export const DEFAULT_THEME = 'sinema';
const THEME_COLOR = { sinema: '#0b0b0e', galeri: '#f6f4f0', yerel: '#f2f2f7' };

export const currentTheme = () => (THEMES.includes(OM.user?.theme) ? OM.user.theme : DEFAULT_THEME);

/** Temayı sayfaya uygular (sunucuya yazmadan). */
export function applyTheme(name) {
  if (!THEMES.includes(name)) name = DEFAULT_THEME;
  for (const th of THEMES) {
    const link = document.getElementById('skin-' + th);
    if (link) link.disabled = th !== name;
  }
  document.documentElement.dataset.skin = name;
  document.getElementById('themeColor')?.setAttribute('content', THEME_COLOR[name]);
  document.dispatchEvent(new CustomEvent('om:theme', { detail: name }));
}

/** Temayı seçer: önce uygular, sonra hesaba kaydeder; hata olursa eskisine döner. */
export async function setTheme(name) {
  const prev = currentTheme();
  applyTheme(name);
  try {
    OM.user = (await api('PATCH', 'me', { theme: name })).user;
  } catch (e) {
    applyTheme(prev);
    throw e;
  }
}

/**
 * Ortam rengi: verilen görselin ortalama rengini --ambient (ve --ambient-rgb) olarak köke yazar.
 * Sinema teması arka plan parıltısında kullanır; diğer temalar yok sayabilir.
 */
export function setAmbientFrom(img) {
  const run = () => {
    try {
      const c = document.createElement('canvas');
      c.width = c.height = 8;
      const g = c.getContext('2d', { willReadFrequently: true });
      g.drawImage(img, 0, 0, 8, 8);
      const d = g.getImageData(0, 0, 8, 8).data;
      let r = 0, gg = 0, b = 0;
      for (let i = 0; i < d.length; i += 4) { r += d[i]; gg += d[i + 1]; b += d[i + 2]; }
      const n = d.length / 4;
      const rgb = [r / n, gg / n, b / n].map((v) => Math.round(v));
      const root = document.documentElement.style;
      root.setProperty('--ambient-rgb', rgb.join(', '));
      root.setProperty('--ambient', `rgb(${rgb.join(', ')})`);
    } catch { /* çapraz kaynak ya da bozuk görsel: yok say */ }
  };
  if (img.complete && img.naturalWidth) run();
  else img.addEventListener('load', run, { once: true });
}
