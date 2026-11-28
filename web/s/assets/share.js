// OwnMemories — herkese açık paylaşım sayfası: iki yana yaslı ızgara ve görüntüleyici (klavye, kaydırma, video).
// Bağımlılık yok; harici istek yok. Veri: <script type="application/json" id="share-data">.
(() => {
  'use strict';
  const dataEl = document.getElementById('share-data');
  const grid = document.getElementById('grid');
  if (!dataEl || !grid) return;
  const D = JSON.parse(dataEl.textContent);
  const items = D.items || [];
  const tiles = [...grid.querySelectorAll('.tile')];
  const url = (it, v) => `${D.base}/m/${it.k}/${v}`;

  /* ---------------------- büyük paylaşımlar: kalan karolar kaydırdıkça eklenir */
  // Sunucu ilk karoları HTML olarak verir; gerisi aynı biçimde burada üretilir (binlerce öğede sayfa hafif kalsın).
  const playIc = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5Z"/></svg>';
  function mkTile(it, i) {
    const a = document.createElement('a');
    a.className = it.t === 'v' ? 'tile is-video' : 'tile';
    a.href = url(it, 'p');
    a.dataset.i = String(i);
    a.setAttribute('aria-label', `${it.t === 'v' ? 'Video' : 'Fotoğraf'} ${i + 1}${it.d ? ', ' + it.d : ''}`);
    const img = document.createElement('img');
    const long = Math.max(it.w, it.h, 1);
    const tw = Math.max(1, Math.round(Math.min(D.te || 360, long) * it.w / long));
    const pw = Math.max(1, Math.round(Math.min(D.pe || 2048, long) * it.w / long));
    if (pw > tw * 1.2) img.srcset = `${url(it, 't')} ${tw}w, ${url(it, 'p')} ${pw}w`;
    img.src = url(it, 't');
    img.width = it.w;
    img.height = it.h;
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    a.append(img);
    if (it.t === 'v') {
      const b = document.createElement('span');
      b.className = 'tile-badge';
      b.innerHTML = playIc;
      if (it.dur != null) b.append(`${Math.floor(it.dur / 60)}:${String(it.dur % 60).padStart(2, '0')}`);
      a.append(b);
    }
    return a;
  }
  function more(upTo) {
    const end = Math.min(items.length, Math.max(upTo + 1, tiles.length + 300));
    if (tiles.length >= end) return false;
    const frag = document.createDocumentFragment();
    for (let i = tiles.length; i < end; i++) {
      const t = mkTile(items[i], i);
      tiles.push(t);
      frag.append(t);
    }
    grid.append(frag);
    return true;
  }
  if (tiles.length < items.length) {
    const sentinel = document.createElement('div');
    sentinel.className = 'grid-more';
    sentinel.setAttribute('aria-hidden', 'true');
    grid.after(sentinel);
    const io = new IntersectionObserver((es) => {
      if (!es.some((e) => e.isIntersecting)) return;
      if (more(0)) requestAnimationFrame(() => layout());
      if (tiles.length >= items.length) { io.disconnect(); sentinel.remove(); }
    }, { rootMargin: '1600px 0px' });
    io.observe(sentinel);
  }

  /* ------------------------------------------------------------ ızgara */
  const GAP = () => (matchMedia('(max-width: 720px)').matches ? 3 : 6);
  const target = () => {
    const w = grid.clientWidth;
    if (w < 480) return 118;
    if (w < 900) return 170;
    return 238;
  };
  function layout() {
    const width = grid.clientWidth;
    if (!width) return;
    const gap = GAP();
    const th = target();
    let row = [];
    let sum = 0;
    const flush = (last) => {
      const gaps = gap * (row.length - 1);
      let hgt = (width - gaps) / sum;
      if (last && hgt > th * 1.25) hgt = th; // son satır gerilmesin
      let used = 0;
      row.forEach(({ el, ar }, i) => {
        let w = Math.floor(ar * hgt);
        if (!last && i === row.length - 1) w = Math.max(1, Math.floor(width - gaps - used)); // kenara tam otur
        used += w;
        el.style.width = `${w}px`;
        el.style.height = `${Math.floor(hgt)}px`;
        const img = el.querySelector('img');
        if (img && img.sizes !== `${w}px`) img.sizes = `${w}px`;
      });
      row = [];
      sum = 0;
    };
    tiles.forEach((el, i) => {
      const it = items[i] || { w: 1, h: 1 };
      const ar = Math.min(2.6, Math.max(0.5, it.w / it.h));
      row.push({ el, ar });
      sum += ar;
      if (sum * th + gap * (row.length - 1) >= width) flush(false);
    });
    if (row.length) flush(true);
    grid.classList.add('is-laid');
  }
  let raf = 0;
  new ResizeObserver(() => { cancelAnimationFrame(raf); raf = requestAnimationFrame(layout); }).observe(grid);
  layout();

  /* ------------------------------------------------------- görüntüleyici */
  const ic = {
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>',
    left: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 6-6 6 6 6"/></svg>',
    right: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 6 6 6-6 6"/></svg>',
    down: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19h14"/></svg>',
  };
  const el = (tag, attrs = {}, html = '') => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined && v !== false) n.setAttribute(k, v === true ? '' : v);
    if (html) n.innerHTML = html;
    return n;
  };
  let lb = null;
  let idx = -1;
  let opener = null;
  const preloaded = new Map();

  function preload(i) {
    const it = items[i];
    if (!it || it.t !== 'p' || preloaded.has(i)) return;
    const im = new Image();
    im.decoding = 'async';
    im.src = url(it, 'p');
    preloaded.set(i, im);
    if (preloaded.size > 12) preloaded.delete(preloaded.keys().next().value);
  }

  function build() {
    lb = el('div', { class: 'lb', role: 'dialog', 'aria-modal': 'true', 'aria-label': D.title || 'Fotoğraflar' });
    const top = el('div', { class: 'lb-top' });
    lb.count = el('span', { class: 'lb-count', 'aria-live': 'polite' });
    top.append(lb.count, el('span', { class: 'lb-spacer' }));
    if (D.dl) {
      lb.dl = el('a', { class: 'lb-btn lb-download', download: '', 'aria-label': 'Orijinali indir' }, `${ic.down}<span class="lb-label">İndir</span>`);
      top.append(lb.dl);
    }
    lb.closeBtn = el('button', { class: 'lb-btn lb-close', type: 'button', 'aria-label': 'Kapat' }, ic.close);
    top.append(lb.closeBtn);
    lb.stage = el('div', { class: 'lb-stage' });
    lb.track = el('div', { class: 'lb-track' });
    lb.prev = el('button', { class: 'lb-nav lb-prev', type: 'button', 'aria-label': 'Önceki' }, ic.left);
    lb.next = el('button', { class: 'lb-nav lb-next', type: 'button', 'aria-label': 'Sonraki' }, ic.right);
    lb.stage.append(lb.track, lb.prev, lb.next);
    const bottom = el('div', { class: 'lb-bottom' });
    lb.date = el('div', { class: 'lb-date' });
    lb.sub = el('div', { class: 'lb-sub' });
    bottom.append(lb.date, lb.sub);
    lb.append(top, lb.stage, bottom);
    lb.closeBtn.addEventListener('click', () => close());
    lb.prev.addEventListener('click', () => go(idx - 1));
    lb.next.addEventListener('click', () => go(idx + 1));
    lb.stage.addEventListener('click', (e) => { if (e.target === lb.stage || e.target === lb.track) close(); });
    lb.addEventListener('keydown', onKey);
    swipe(lb.stage);
  }

  function show(i, dir = 0) {
    idx = i;
    const it = items[i];
    lb.track.querySelector('video')?.pause();
    lb.track.replaceChildren();
    lb.track.style.transform = dir ? `translateX(${dir * 40}px)` : '';
    lb.track.style.opacity = dir ? '0' : '1';
    let media;
    if (it.t === 'v') {
      media = el('video', { class: 'lb-media', src: url(it, 'v'), poster: url(it, 'p'), controls: true, playsinline: true, preload: 'metadata', 'aria-label': `Video ${i + 1}` });
      media.autoplay = true;
    } else {
      const spin = el('span', { class: 'lb-spin', 'aria-hidden': 'true' });
      media = el('img', { class: 'lb-media', alt: `Fotoğraf ${i + 1} / ${items.length}${it.d ? ', ' + it.d : ''}`, decoding: 'async' });
      media.addEventListener('load', () => { media.classList.add('ready'); spin.remove(); }, { once: true });
      media.addEventListener('error', () => spin.remove(), { once: true });
      media.src = url(it, 'p');
      if (media.complete) { media.classList.add('ready'); } else { lb.track.append(spin); }
    }
    lb.track.prepend(media);
    requestAnimationFrame(() => { lb.track.style.transform = ''; lb.track.style.opacity = '1'; });
    lb.count.textContent = `${i + 1} / ${items.length}`;
    lb.date.textContent = it.d || '';
    lb.sub.textContent = [it.tm && it.d ? it.tm : '', it.pl || ''].filter(Boolean).join(' · ');
    if (lb.dl) lb.dl.href = url(it, 'o');
    lb.prev.disabled = i === 0;
    lb.next.disabled = i === items.length - 1;
    preload(i + 1);
    preload(i - 1);
    if (history.replaceState) history.replaceState({ lb: true }, '', `#${i + 1}`);
  }

  function go(i) {
    if (i < 0 || i >= items.length || i === idx) return;
    show(i, i > idx ? 1 : -1);
  }

  function open(i) {
    if (!lb) build();
    opener = document.activeElement;
    document.body.append(lb);
    document.body.classList.add('lb-open');
    if (history.pushState && !(history.state && history.state.lb)) history.pushState({ lb: true }, '', `#${i + 1}`);
    show(i);
    requestAnimationFrame(() => lb.classList.add('in'));
    lb.closeBtn.focus({ preventScroll: true });
  }

  function close(fromHistory = false) {
    // idx < 0: zaten kapanıyor (history.back() sonrası gelen popstate odağı yeniden açana taşımasın)
    if (!lb || !lb.isConnected || idx < 0) return;
    lb.track.querySelector('video')?.pause();
    lb.classList.remove('in');
    document.body.classList.remove('lb-open');
    const node = lb;
    setTimeout(() => { node.remove(); node.track.replaceChildren(); }, 220);
    if (!fromHistory && history.state && history.state.lb) history.back();
    else if (history.replaceState && location.hash) history.replaceState(null, '', location.pathname);
    if (idx >= tiles.length && more(idx)) layout();
    const t = tiles[idx];
    (t || opener)?.focus({ preventScroll: false });
    idx = -1;
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.target && e.target.tagName === 'VIDEO' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); go(idx + 1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); go(idx - 1); }
    else if (e.key === 'Home') { e.preventDefault(); go(0); }
    else if (e.key === 'End') { e.preventDefault(); go(items.length - 1); }
    else if (e.key === 'Tab') {
      // odak görüntüleyicide kalsın
      const f = [...lb.querySelectorAll('button:not([disabled]), a[href], video')];
      if (!f.length) return;
      const first = f[0];
      const last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function swipe(stage) {
    let x0 = 0;
    let y0 = 0;
    let id = null;
    let dx = 0;
    let dy = 0;
    stage.addEventListener('pointerdown', (e) => {
      if (e.pointerType === 'mouse' || e.target.tagName === 'VIDEO' || e.isPrimary === false) return;
      id = e.pointerId; x0 = e.clientX; y0 = e.clientY; dx = dy = 0;
      lb.track.classList.add('dragging');
    });
    stage.addEventListener('pointermove', (e) => {
      if (e.pointerId !== id) return;
      dx = e.clientX - x0;
      dy = e.clientY - y0;
      if (Math.abs(dx) > Math.abs(dy)) lb.track.style.transform = `translateX(${dx}px)`;
      else if (dy > 0) { lb.track.style.transform = `translateY(${dy}px)`; lb.track.style.opacity = String(Math.max(0.3, 1 - dy / 400)); }
    });
    const end = (e) => {
      if (e.pointerId !== id) return;
      id = null;
      lb.track.classList.remove('dragging');
      if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy)) {
        const n = dx < 0 ? idx + 1 : idx - 1;
        if (n >= 0 && n < items.length) { go(n); return; }
      } else if (dy > 110 && Math.abs(dy) > Math.abs(dx)) { close(); return; }
      lb.track.style.transform = '';
      lb.track.style.opacity = '1';
    };
    stage.addEventListener('pointerup', end);
    stage.addEventListener('pointercancel', end);
  }

  grid.addEventListener('click', (e) => {
    const t = e.target.closest('.tile');
    if (!t || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    open(Number(t.dataset.i));
  });
  window.addEventListener('popstate', () => { if (lb && lb.isConnected) close(true); });

  // Doğrudan "#5" ile açılan adres: o öğeyi göster.
  const m = /^#(\d+)$/.exec(location.hash);
  if (m && Number(m[1]) >= 1 && Number(m[1]) <= items.length) {
    history.replaceState(null, '', location.pathname);
    open(Number(m[1]) - 1);
  }
})();
