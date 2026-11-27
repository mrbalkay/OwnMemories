// OwnMemories — zaman çizelgesi: güne göre gruplu ızgara, kaydırdıkça yükleme, ay/yıl kaydırıcısı.
import { api, qs, thumbUrl } from './api.js';
import { h, t, icon, dayKey, formatDay, formatMonth, formatDuration } from './util.js';
import { store } from './store.js';

const PAGE = 240;

export class Timeline {
  /**
   * @param {HTMLElement} root
   * @param {{query?: object, empty?: string, onOpen: (id:string)=>void, scrubber?: boolean, header?: Node}} opts
   */
  constructor(root, opts) {
    this.root = root;
    this.opts = opts;
    this.query = opts.query || {};
    this.next = null;
    this.loading = false;
    this.done = false;
    this.groups = new Map(); // dayKey → {section, grid, assets, tiles}
    this.lastMonth = null;
    store.list = [];
    this.onResize = () => { clearTimeout(this._rt); this._rt = setTimeout(() => this.layoutAll(), 120); };
    window.addEventListener('resize', this.onResize);
    store.loadMore = () => this.load();
    this.build();
    this.unsub = store.onChange(() => this.syncSelection());
  }

  build() {
    this.root.replaceChildren();
    this.wrap = h('div', { class: 'timeline' });
    if (this.opts.header) this.root.append(this.opts.header);
    this.jumpBack = h('button', { class: 'btn btn-soft newer', hidden: true, onclick: () => this.reset() }, t('newer'));
    this.status = h('div', { class: 'tl-status' });
    this.sentinel = h('div', { class: 'sentinel', 'aria-hidden': 'true' });
    this.root.append(this.jumpBack, this.wrap, this.status, this.sentinel);
    this.io = new IntersectionObserver((es) => { if (!this.paused && es.some((e) => e.isIntersecting)) this.load(); }, { rootMargin: '1200px' });
    this.io.observe(this.sentinel);
    if (this.opts.scrubber !== false) this.buildScrubber();
    this.load();
  }

  destroy() {
    window.removeEventListener('resize', this.onResize);
    this.io?.disconnect();
    this.unsub?.();
    this.scrub?.remove();
  }

  reset(extra = {}) {
    this.query = { ...this.opts.query, ...extra };
    this.next = extra.before ? { before: extra.before } : null;
    this.done = false;
    this.paused = false;
    this.groups.clear();
    this.lastMonth = null;
    store.list = [];
    this.wrap.replaceChildren();
    this.jumpBack.hidden = !extra.before;
    this.root.scrollTop = 0;
    document.getElementById('view').scrollTop = 0;
    this.load();
  }

  async load() {
    if (this.loading || this.done) return;
    this.loading = true;
    let failed = false;
    this.status.replaceChildren(h('span', { class: 'spinner' }), t('loading'));
    try {
      const params = { ...this.query, limit: PAGE };
      if (this.next) { params.before = this.next.before; params.before_id = this.next.before_id; }
      const r = await api('GET', 'assets' + qs(params));
      this.next = r.next;
      this.done = r.next === null;
      this.append(r.assets);
      this.status.replaceChildren();
      if (this.done && store.list.length === 0) {
        this.status.append(h('div', { class: 'empty' }, h('p', {}, this.opts.empty || t('empty_photos'))));
      }
    } catch (e) {
      failed = true;
      this.status.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed')), h('button', { class: 'btn', onclick: () => { this.paused = false; this.load(); } }, t('retry'))));
    } finally {
      this.loading = false;
    }
    // Hata olduysa kullanıcı "Tekrar dene" diyene kadar otomatik yükleme durur.
    this.paused = failed;
    // Ekran dolmadıysa devam et
    if (!failed && !this.done && this.sentinel.getBoundingClientRect().top < window.innerHeight + 1200) requestAnimationFrame(() => this.load());
  }

  append(assets) {
    const touched = new Set();
    for (const a of assets) {
      store.list.push(a);
      const key = dayKey(a);
      let g = this.groups.get(key);
      if (!g) {
        const month = key.slice(0, 7);
        if (month !== this.lastMonth) {
          this.lastMonth = month;
          this.wrap.append(h('h2', { class: 'month' }, formatMonth(month)));
        }
        const grid = h('div', { class: 'grid', role: 'list' });
        const head = h('h3', { class: 'day' }, formatDay(key));
        const section = h('section', { class: 'day-group', dataset: { day: key } }, head, grid);
        g = { section, grid, assets: [] };
        this.groups.set(key, g);
        this.wrap.append(section);
      }
      g.assets.push(a);
      g.grid.append(this.tile(a));
      touched.add(g);
    }
    for (const g of touched) this.layout(g);
  }

  /** Oranları koruyan satır yerleşimi (justified): her satır grid genişliğini tam doldurur. */
  layout(g) {
    const width = g.grid.clientWidth || this.wrap.clientWidth;
    if (!width) return;
    const target = window.innerWidth < 600 ? 128 : (window.innerWidth < 1100 ? 190 : 230);
    const tiles = [...g.grid.children];
    const GAP = parseFloat(getComputedStyle(g.grid).columnGap) || 4;
    let row = [];
    let sum = 0;
    const flush = (last) => {
      if (!row.length) return;
      const gaps = GAP * (row.length - 1);
      let hgt = (width - gaps) / sum;
      if (last && hgt > target * 1.25) hgt = target; // son satır gereğinden büyümesin
      row.forEach(([el, ar]) => {
        el.style.width = `${Math.floor(ar * hgt)}px`;
        el.style.height = `${Math.floor(hgt)}px`;
      });
      row = [];
      sum = 0;
    };
    tiles.forEach((el, i) => {
      const a = g.assets[i];
      const ar = a && a.width && a.height ? Math.min(3, Math.max(0.4, a.width / a.height)) : 1;
      row.push([el, ar]);
      sum += ar;
      if (sum * target + GAP * (row.length - 1) >= width) flush(false);
    });
    flush(true);
  }

  layoutAll() {
    for (const g of this.groups.values()) this.layout(g);
  }

  tile(a) {
    const img = h('img', { src: thumbUrl(a.id), alt: a.filename, loading: 'lazy', decoding: 'async', draggable: 'false' });
    img.addEventListener('load', () => img.classList.add('in'), { once: true });
    const sel = h('button', { class: 'tile-check', 'aria-label': t('select'), tabindex: '-1', onclick: (e) => { e.stopPropagation(); store.toggle(a.id); } });
    sel.innerHTML = '<svg viewBox="0 0 24 24"><path d="m6 12.5 4 4 8-9"/></svg>';
    const badges = h('div', { class: 'tile-badges' });
    if (a.type === 'video') badges.append(h('span', { class: 'badge' }, icon('play'), a.duration_ms ? formatDuration(a.duration_ms) : ''));
    if (a.live_pair_id && a.type === 'photo') badges.append(h('span', { class: 'badge' }, icon('live')));
    if (a.is_favorite) badges.append(h('span', { class: 'badge fav' }, icon('heartFill')));
    const tile = h('div', {
      class: 'tile' + (store.selection.has(a.id) ? ' selected' : ''), role: 'listitem', tabindex: '0', dataset: { id: a.id },
      onclick: (e) => {
        if (store.selection.size > 0 || e.shiftKey || e.metaKey || e.ctrlKey) store.toggle(a.id);
        else this.opts.onOpen(a.id);
      },
      onkeydown: (e) => { if (e.key === 'Enter') this.opts.onOpen(a.id); if (e.key === ' ') { e.preventDefault(); store.toggle(a.id); } },
    }, img, badges, sel);
    // Dokunmatik: uzun basış seçer
    let timer;
    tile.addEventListener('touchstart', () => { timer = setTimeout(() => { store.toggle(a.id); timer = null; }, 450); }, { passive: true });
    tile.addEventListener('touchend', (e) => { if (timer === null) e.preventDefault(); clearTimeout(timer); });
    tile.addEventListener('touchmove', () => clearTimeout(timer), { passive: true });
    return tile;
  }

  syncSelection() {
    this.wrap.classList.toggle('selecting', store.selection.size > 0);
    for (const el of this.wrap.querySelectorAll('.tile')) el.classList.toggle('selected', store.selection.has(el.dataset.id));
  }

  /** Bir asset'i listeden kaldırır (çöp, arşiv sonrası). */
  removeIds(ids) {
    store.remove(ids);
    for (const id of ids) {
      const el = this.wrap.querySelector(`.tile[data-id="${id}"]`);
      const grid = el?.parentElement;
      el?.remove();
      const sec = grid?.closest('.day-group');
      const g = sec ? this.groups.get(sec.dataset.day) : null;
      if (g) {
        g.assets = g.assets.filter((x) => x.id !== id);
        if (!grid.children.length) {
          this.groups.delete(sec.dataset.day);
          sec.remove();
        } else {
          this.layout(g);
        }
      }
    }
    if (store.list.length === 0 && this.done) this.status.replaceChildren(h('div', { class: 'empty' }, h('p', {}, this.opts.empty || t('empty_photos'))));
  }

  refreshTile(a) {
    const el = this.wrap.querySelector(`.tile[data-id="${a.id}"]`);
    if (!el) return;
    const fresh = this.tile(a);
    fresh.style.width = el.style.width;
    fresh.style.height = el.style.height;
    el.replaceWith(fresh);
    const g = this.groups.get(dayKey(a));
    if (g) g.assets = g.assets.map((x) => (x.id === a.id ? a : x));
  }

  async buildScrubber() {
    try {
      const r = await api('GET', 'assets/summary' + qs(this.query));
      if (r.months.length < 3) return;
      const years = new Map();
      for (const m of r.months) {
        const y = m.month.slice(0, 4);
        if (!years.has(y)) years.set(y, m.month);
      }
      this.scrub = h('nav', { class: 'scrubber', 'aria-label': 'Yıllar' },
        ...[...years.entries()].map(([y, month]) => h('button', {
          title: formatMonth(month),
          onclick: () => {
            // Ayın sonundan önceki her şey: o yılın ilk (en yeni) ayına atla.
            const [yy, mm] = month.split('-').map(Number);
            const end = new Date(Date.UTC(yy, mm, 1)).toISOString().replace('.000', '');
            this.reset({ before: end });
          },
        }, y)));
      this.root.append(this.scrub);
    } catch { /* kaydırıcı isteğe bağlı */ }
  }
}
