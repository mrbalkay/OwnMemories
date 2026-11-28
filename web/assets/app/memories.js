// OwnMemories — anılar şeridi ve hikâye tarzı anı görüntüleyicisi.
import { api, thumbUrl, previewUrl, videoUrl } from './api.js';
import { h, t, icon, formatDateTime, formatDate, localDate } from './util.js';
import { setAmbientFrom, currentTheme } from './theme.js';
import { openViewer } from './viewer.js';
import { store } from './store.js';

const STEP_MS = 5000;

/** Bir öğenin ay etiketi: "Kasım 2023". */
function monthOf(a) {
  return new Intl.DateTimeFormat(document.documentElement.lang === 'en' ? 'en-GB' : 'tr-TR', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(localDate(a));
}

/** Anının tarih etiketi: "Kasım 2023" (ilk öğenin yerel ayı). */
function memoryMonth(m) {
  return m.assets[0] ? monthOf(m.assets[0]) : formatDate(m.date);
}

/** Anının tarih aralığı: "24–29 Kasım 2023" ya da tek günse "28 Kasım 2023". */
function memoryRange(m) {
  const keys = m.assets.map((a) => (a.taken_local || a.taken_at).slice(0, 10)).sort();
  if (!keys.length) return formatDate(m.date);
  const a = keys[0], b = keys[keys.length - 1];
  if (a === b) return formatDate(a);
  if (a.slice(0, 7) === b.slice(0, 7)) return `${Number(a.slice(8, 10))}–${formatDate(b)}`;
  return `${formatDate(a)} – ${formatDate(b)}`;
}

/** "2016–2024" (favoriler gibi çok yıllı anılar için). */
function memoryYears(m) {
  const ys = m.assets.map((a) => (a.taken_local || a.taken_at).slice(0, 4)).sort();
  if (!ys.length) return m.title;
  return ys[0] === ys[ys.length - 1] ? ys[0] : `${ys[0]}–${ys[ys.length - 1]}`;
}

const kindAttr = (m) => ({ kind: m.kind, years: m.years_ago ?? '' });

/** Üst satır ve başlık: gezilerde "3 yıl önce" + "Bozcaada'da bir hafta"; diğerlerinde anı adı + ay. */
const memEyebrow = (m) => (m.kind === 'trip' ? t('trip_years_ago', { n: m.years_ago }) : m.title);
const memHeading = (m) => (m.kind === 'trip' ? m.title : (m.kind === 'favorites' ? memoryYears(m) : memoryMonth(m)));

/** Günün anısı için büyük afiş (.mem-hero). Varsayılan CSS'te gizli; temalar açar. */
function memoryHero(m, viewerCtx) {
  const img = h('img', { src: previewUrl(m.cover_asset_id), alt: '', decoding: 'async' });
  img.addEventListener('load', () => img.classList.add('in'), { once: true });
  setAmbientFrom(img);
  const play = () => openStory(m, viewerCtx);
  return h('section', { class: 'mem-hero', dataset: kindAttr(m), 'aria-label': m.title },
    h('div', { class: 'mem-hero-media', onclick: play }, img),
    h('div', { class: 'mem-hero-shade', 'aria-hidden': 'true' }),
    h('div', { class: 'mem-hero-text' },
      h('div', { class: 'mem-hero-eyebrow' }, h('i', { class: 'mem-hero-dot', 'aria-hidden': 'true' }), memEyebrow(m)),
      h('h2', { class: 'mem-hero-title' }, memHeading(m)),
      h('p', { class: 'mem-hero-meta' },
        h('span', { class: 'mem-hero-date' }, memoryRange(m)), ' · ',
        h('span', { class: 'mem-hero-count' }, t('items', { n: m.count }))),
      h('div', { class: 'mem-hero-actions' },
        h('button', { class: 'btn mem-hero-play', type: 'button', onclick: play }, icon('play'), h('span', {}, t('play_memory'))))));
}

/**
 * Zaman çizelgesinin üstündeki anılar bölümü (.mem-block > .mem-hero + .mem-strip); anı yoksa null.
 * @param {() => object} viewerCtx  zaman çizelgesinin görüntüleyici bağlamı (değişiklikler ızgaraya yansısın)
 */
export async function memoriesStrip(viewerCtx = () => ({})) {
  let data;
  try {
    data = await api('GET', 'memories');
  } catch {
    return null;
  }
  if (!data.memories.length) return featuredBlock(viewerCtx);
  const row = h('div', { class: 'mem-row' }, ...data.memories.map((m, idx) => h('button', {
    class: 'mem-card' + (idx === 0 ? ' is-first' : ''), dataset: { ...kindAttr(m), index: idx },
    onclick: () => openStory(m, viewerCtx),
    'aria-label': `${m.title}, ${t('items', { n: m.count })}`,
  },
    h('img', { src: previewUrl(m.cover_asset_id), alt: '', loading: 'lazy' }),
    m.kind === 'favorites' && m.assets.length >= 3
      ? h('span', { class: 'mem-mosaic', 'aria-hidden': 'true' }, ...pickMosaic(m).map((id) => h('img', { src: thumbUrl(id), alt: '', loading: 'lazy' })))
      : null,
    h('span', { class: 'mem-shade' }),
    h('span', { class: 'mem-play', 'aria-hidden': 'true' }, icon('play')),
    h('span', { class: 'mem-text' },
      h('small', { class: 'mem-eyebrow' }, memEyebrow(m)),
      h('strong', {}, memHeading(m)),
      h('small', { class: 'mem-count' }, t('items', { n: m.count })),
      h('span', { class: 'mem-date' }, memoryRange(m))))));
  const scrollBy = (dir) => row.scrollBy({ left: dir * row.clientWidth * 0.8, behavior: 'smooth' });
  const strip = h('section', { class: 'mem-strip', 'aria-label': t('memories'), dataset: { count: data.memories.length } },
    h('div', { class: 'mem-head-row' },
      h('h2', { class: 'mem-head' }, t('memories')),
      h('div', { class: 'mem-nav' },
        h('button', { class: 'icon-btn mem-prev', type: 'button', 'aria-label': t('memories_prev'), onclick: () => scrollBy(-1) }, icon('left')),
        h('button', { class: 'icon-btn mem-next', type: 'button', 'aria-label': t('memories_next'), onclick: () => scrollBy(1) }, icon('right')))),
    h('div', { class: 'mem-row-wrap' }, row, h('div', { class: 'mem-fade', 'aria-hidden': 'true' })));
  return h('div', { class: 'mem-block has-hero' }, memoryHero(data.memories[0], viewerCtx), strip);
}

/**
 * Henüz anı yokken (ör. geçmiş yılların fotoğrafları daha yüklenmemişken) Sinema'nın üst afişi boş kalmasın:
 * kitaplıktan rastgele bir fotoğraf "Kitaplığınızdan" afişi olarak gösterilir; dokununca görüntüleyicide açılır.
 * Diğer temalarda afiş kullanılmadığı için bu durumda bölüm gösterilmez.
 */
async function featuredBlock(viewerCtx) {
  if (currentTheme() !== 'sinema') return null;
  let assets;
  try {
    assets = (await api('GET', 'assets/random?count=12')).assets;
  } catch {
    return null;
  }
  if (!assets?.length) return null;
  const a = assets[0];
  const img = h('img', { src: previewUrl(a.id), alt: '', decoding: 'async' });
  img.addEventListener('load', () => img.classList.add('in'), { once: true });
  setAmbientFrom(img);
  const open = () => {
    const savedList = store.list;
    const savedMore = store.loadMore;
    store.list = assets.slice();
    store.loadMore = null;
    openViewer(a.id, { ...viewerCtx(), onClose: () => { store.list = savedList; store.loadMore = savedMore; } });
  };
  const day = (a.taken_local || a.taken_at).slice(0, 10);
  const hero = h('section', { class: 'mem-hero is-featured', dataset: { kind: 'featured' }, 'aria-label': t('from_library') },
    h('div', { class: 'mem-hero-media', onclick: open }, img),
    h('div', { class: 'mem-hero-shade', 'aria-hidden': 'true' }),
    h('div', { class: 'mem-hero-text' },
      h('div', { class: 'mem-hero-eyebrow' }, h('i', { class: 'mem-hero-dot', 'aria-hidden': 'true' }), t('from_library')),
      h('h2', { class: 'mem-hero-title' }, monthOf(a)),
      h('p', { class: 'mem-hero-meta' }, h('span', { class: 'mem-hero-date' }, formatDate(day))),
      h('div', { class: 'mem-hero-actions' },
        h('button', { class: 'btn mem-hero-play', type: 'button', onclick: open }, icon('photos'), h('span', {}, t('open_photo'))))));
  return h('div', { class: 'mem-block has-hero is-featured' }, hero);
}

/** Favori anı kartı için üç küçük görsel (kapak + iki tane daha). */
function pickMosaic(m) {
  const photos = m.assets.filter((a) => a.type === 'photo').map((a) => a.id);
  const rest = photos.filter((id) => id !== m.cover_asset_id);
  return [m.cover_asset_id, rest[0], rest[Math.floor(rest.length / 2)] ?? rest[1]].filter(Boolean).slice(0, 3);
}

/** Tam ekran anı gösterisi: üstte ilerleme çubukları, dokununca ileri/geri, otomatik ilerler, Boşluk ile duraklar. */
export function openStory(memory, viewerCtx = () => ({})) {
  const assets = memory.assets;
  let i = 0;
  let timer = null;
  let token = 0;            // her slayt için yeni değer; eski medya olayları yok sayılır
  let closed = false;
  let paused = false;
  let stepMs = STEP_MS;
  let startedAt = 0;
  let remaining = STEP_MS;
  let video = null;
  const prevFocus = document.activeElement;
  const bars = assets.map(() => h('span', { class: 'sb' }, h('i')));
  const media = h('div', { class: 'story-media' });
  const bg = h('div', { class: 'story-bg' });
  const caption = h('div', { class: 'story-caption' });
  const heading = memory.kind === 'trip' ? memoryRange(memory) : (memory.kind === 'favorites' ? memoryYears(memory) : memoryMonth(memory));
  // Tema kancaları (varsayılan CSS'te gizli): yan bilgi paneli, alt başlık, sayaç, denetimler, film şeridi.
  const capSub = h('small', { class: 'story-cap-sub' });
  const capTitle = h('strong', { class: 'story-cap-title' });
  const counter = h('span', { class: 'story-count' });
  const pauseBtn = h('button', { class: 'icon-btn story-pause', type: 'button', 'aria-label': t('pause'), title: t('pause'), onclick: () => togglePause() }, icon('pause'), h('span', { class: 'story-pause-text' }, t('pause')));
  const film = assets.map((a, k) => h('button', { type: 'button', class: 'story-thumb', 'aria-label': `${k + 1} / ${assets.length}`, onclick: () => go(k) },
    h('img', { src: thumbUrl(a.id), alt: '', loading: 'lazy' })));
  const el = h('div', { class: 'story', role: 'dialog', 'aria-modal': 'true', 'aria-label': memory.title, tabindex: '-1', dataset: { kind: memory.kind, count: assets.length } },
    bg,
    h('div', { class: 'story-top' },
      h('div', { class: 'story-bars' }, ...bars),
      h('div', { class: 'story-title' }, h('strong', { class: 'story-name' }, memory.title), h('span', { class: 'story-heading' }, heading), caption),
      h('div', { class: 'story-actions' },
        h('button', { class: 'icon-btn', 'aria-label': t('open_photo'), title: t('open_photo'), onclick: () => openCurrent() }, icon('photos')),
        h('button', { class: 'icon-btn', 'aria-label': t('close'), title: t('close'), onclick: () => close() }, icon('close')))),
    h('aside', { class: 'story-side' },
      h('span', { class: 'story-kicker' }, t('memories')),
      h('h2', { class: 'story-side-title' }, memory.title),
      h('div', { class: 'story-fact', dataset: { fact: 'when' } }, h('b', {}, t('when')), h('span', {}, memoryRange(memory))),
      h('div', { class: 'story-fact', dataset: { fact: 'count' } }, h('b', {}, t('in_this_memory')), h('span', {}, t('items', { n: memory.count })))),
    media,
    h('button', { class: 'story-tap prev', 'aria-label': t('prev'), onclick: () => go(i - 1) }),
    h('button', { class: 'story-tap next', 'aria-label': t('next'), onclick: () => go(i + 1) }),
    h('div', { class: 'story-bottom' },
      h('div', { class: 'story-cap' }, capSub, capTitle),
      h('div', { class: 'story-film' }, ...film),
      h('div', { class: 'story-ctl' },
        counter,
        h('button', { class: 'icon-btn story-prev', type: 'button', 'aria-label': t('prev'), onclick: () => go(i - 1) }, icon('left')),
        pauseBtn,
        h('button', { class: 'icon-btn story-next', type: 'button', 'aria-label': t('next'), onclick: () => go(i + 1) }, icon('right')))));
  document.body.append(el);
  document.body.classList.add('no-scroll');
  el.focus();

  // Geri tuşu gösteriyi kapatsın
  history.pushState({ story: true }, '', location.hash);
  const onPop = () => close(true);
  window.addEventListener('popstate', onPop);

  const onKey = (e) => {
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowRight') go(i + 1);
    else if (e.key === 'ArrowLeft') go(i - 1);
    else if (e.key === ' ') { e.preventDefault(); togglePause(); }
    else if (e.key === 'Tab') { // odak gösterinin içinde kalsın
      const f = [...el.querySelectorAll('button')].filter((b) => b.getClientRects().length);
      const k = f.indexOf(document.activeElement);
      e.preventDefault();
      f[(k + (e.shiftKey ? -1 : 1) + f.length) % f.length]?.focus();
    }
  };
  document.addEventListener('keydown', onKey);

  function schedule(ms) {
    clearTimeout(timer);
    stepMs = ms;
    remaining = ms;
    startedAt = performance.now();
    bars[i].firstChild.style.animationDuration = `${ms}ms`;
    if (!paused) timer = setTimeout(() => go(i + 1), ms);
  }

  function togglePause() {
    paused = !paused;
    el.classList.toggle('paused', paused);
    const label = t(paused ? 'play' : 'pause');
    pauseBtn.replaceChildren(icon(paused ? 'play' : 'pause'), h('span', { class: 'story-pause-text' }, label));
    pauseBtn.setAttribute('aria-label', label);
    pauseBtn.title = label;
    if (paused) {
      clearTimeout(timer);
      remaining = Math.max(0, remaining - (performance.now() - startedAt));
      video?.pause();
    } else {
      startedAt = performance.now();
      timer = setTimeout(() => go(i + 1), remaining);
      video?.play().catch(() => {});
    }
  }

  function stopVideo() {
    if (video) {
      video.pause();
      video.removeAttribute('src');
      video.load();
      video = null;
    }
  }

  function go(n) {
    if (closed) return;
    if (n >= assets.length) { close(); return; }
    i = Math.max(0, n);
    const my = ++token;
    stopVideo();
    bars.forEach((b, k) => { b.className = 'sb' + (k < i ? ' done' : k === i ? ' now' : ''); });
    const a = assets[i];
    caption.textContent = formatDateTime(a);
    el.dataset.index = i;
    el.classList.toggle('is-video', a.type === 'video');
    capSub.textContent = monthOf(a);
    capTitle.textContent = formatDate(a.taken_local || a.taken_at, true);
    counter.textContent = t('photo_n_of', { i: i + 1, n: assets.length });
    film.forEach((b, k) => b.classList.toggle('on', k === i));
    const strip = film[i].parentElement;
    if (strip && strip.scrollWidth > strip.clientWidth) {
      const s = strip.getBoundingClientRect(), b = film[i].getBoundingClientRect();
      strip.scrollLeft += b.left - s.left - (s.width - b.width) / 2;
    }
    bg.style.backgroundImage = `url("${thumbUrl(a.id)}")`;
    if (a.type === 'video') {
      const v = h('video', { src: videoUrl(a.id), autoplay: !paused, muted: true, playsinline: true, poster: previewUrl(a.id) });
      video = v;
      v.addEventListener('loadedmetadata', () => {
        if (closed || my !== token) return;
        schedule(Math.min(15000, Math.max(3000, (v.duration || 5) * 1000)));
      });
      v.addEventListener('error', () => { if (!closed && my === token) schedule(STEP_MS); });
      media.replaceChildren(v);
      schedule(15000);
    } else {
      const img = h('img', { src: previewUrl(a.id), alt: a.filename });
      img.addEventListener('load', () => img.classList.add('in'), { once: true });
      media.replaceChildren(img);
      schedule(STEP_MS);
      const nx = assets[i + 1];
      if (nx && nx.type !== 'video') { const pre = new Image(); pre.src = previewUrl(nx.id); }
    }
  }

  function openCurrent() {
    const id = assets[i].id;
    // Geçmiş girdisini geri almadan kapat (history.back() eşzamansızdır ve görüntüleyicinin girdisini silerdi).
    close(true);
    if (history.state?.story) history.replaceState(null, '', location.hash);
    // Zaman çizelgesinin listesini koru; görüntüleyici kapanınca geri yükle.
    const savedList = store.list;
    const savedMore = store.loadMore;
    store.list = assets.slice();
    store.loadMore = null;
    const base = viewerCtx() || {};
    openViewer(id, {
      ...base,
      onClose: () => {
        store.list = savedList;
        store.loadMore = savedMore;
        base.onClose?.();
      },
    });
  }

  function close(fromPop = false) {
    if (closed) return;
    closed = true;
    token++;
    clearTimeout(timer);
    stopVideo();
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('popstate', onPop);
    el.remove();
    document.body.classList.remove('no-scroll');
    if (!fromPop && history.state?.story) history.back();
    prevFocus?.focus?.();
  }

  go(0);
}
