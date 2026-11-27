// OwnMemories — anılar şeridi ve hikâye tarzı anı görüntüleyicisi.
import { api, thumbUrl, previewUrl, videoUrl } from './api.js';
import { h, t, icon, formatDateTime } from './util.js';
import { openViewer } from './viewer.js';
import { store } from './store.js';

const STEP_MS = 5000;

/**
 * Zaman çizelgesinin üstündeki anılar şeridi; anı yoksa null.
 * @param {() => object} viewerCtx  zaman çizelgesinin görüntüleyici bağlamı (değişiklikler ızgaraya yansısın)
 */
export async function memoriesStrip(viewerCtx = () => ({})) {
  let data;
  try {
    data = await api('GET', 'memories');
  } catch {
    return null;
  }
  if (!data.memories.length) return null;
  return h('section', { class: 'mem-strip', 'aria-label': t('memories') },
    h('h2', { class: 'mem-head' }, t('memories')),
    h('div', { class: 'mem-row' }, ...data.memories.map((m) => h('button', {
      class: 'mem-card', onclick: () => openStory(m, viewerCtx),
      'aria-label': `${m.title}, ${t('items', { n: m.count })}`,
    },
      h('img', { src: previewUrl(m.cover_asset_id), alt: '', loading: 'lazy' }),
      h('span', { class: 'mem-shade' }),
      h('span', { class: 'mem-text' }, h('strong', {}, m.title), h('small', {}, t('items', { n: m.count })))))));
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
  const el = h('div', { class: 'story', role: 'dialog', 'aria-modal': 'true', 'aria-label': memory.title, tabindex: '-1' },
    bg,
    h('div', { class: 'story-top' },
      h('div', { class: 'story-bars' }, ...bars),
      h('div', { class: 'story-title' }, h('strong', {}, memory.title), caption),
      h('div', { class: 'story-actions' },
        h('button', { class: 'icon-btn', 'aria-label': t('open_photo'), title: t('open_photo'), onclick: () => openCurrent() }, icon('photos')),
        h('button', { class: 'icon-btn', 'aria-label': t('close'), title: t('close'), onclick: () => close() }, icon('close')))),
    media,
    h('button', { class: 'story-tap prev', 'aria-label': t('prev'), onclick: () => go(i - 1) }),
    h('button', { class: 'story-tap next', 'aria-label': t('next'), onclick: () => go(i + 1) }));
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
      const f = [...el.querySelectorAll('button')];
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
