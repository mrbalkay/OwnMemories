// OwnMemories — zaman çizelgesi: güne göre gruplu ızgara, kaydırdıkça yükleme, ay/yıl kaydırıcısı.
import { api, qs, thumbUrl } from './api.js';
import { h, t, icon, dayKey, dayParts, monthParts, formatMonth, formatDuration } from './util.js';
import { setAmbientFrom } from './theme.js';
import { store } from './store.js';

const PAGE = 240;

/** Ortam rengini temanın varsayılanına döndürür (zaman çizelgesi olmayan ya da boş görünümler için). */
export function clearAmbient() {
  const s = document.documentElement.style;
  s.removeProperty('--ambient');
  s.removeProperty('--ambient-rgb');
}

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
    this.monthCounts = new Map(); // 'YYYY-MM' → öğe sayısı (assets/summary)
    this.curMonth = null;
    this.ambientSet = false;
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
    this.lastWidth = 0;
    this.ro = new ResizeObserver(() => {
      const w = this.wrap.clientWidth;
      if (w && Math.abs(w - this.lastWidth) > 1) { this.lastWidth = w; this.onResize(); }
    });
    this.ro.observe(this.wrap);
    // Tema değişince boşluk/kenar değerleri değişir: satırları yeniden diz.
    this.onTheme = () => requestAnimationFrame(() => { this.lastWidth = this.wrap.clientWidth; this.onResize(); });
    document.addEventListener('om:theme', this.onTheme);
    this.io = new IntersectionObserver((es) => { if (!this.paused && es.some((e) => e.isIntersecting)) this.load(); }, { rootMargin: '1200px' });
    this.io.observe(this.sentinel);
    this.buildScrubber();
    this.scroller = document.getElementById('view');
    this.onScroll = () => {
      if (this._sr) return;
      this._sr = requestAnimationFrame(() => { this._sr = 0; this.trackMonth(); });
    };
    this.scroller.addEventListener('scroll', this.onScroll, { passive: true });
    this.load();
  }

  /** Görünür ayı izler: yıl rayının .scrub-now etiketi, düğmelerin .on durumu, .month-group.is-current ve ortam rengi. */
  trackMonth() {
    const top = this.scroller.getBoundingClientRect().top + 90;
    let cur = null;
    for (const sec of this.wrap.children) {
      if (!sec.classList.contains('month-group')) continue;
      if (sec.getBoundingClientRect().bottom > top) { cur = sec; break; }
    }
    if (!cur || cur.dataset.month === this.curMonth) return;
    const first = this.curMonth === null;
    this.curMonth = cur.dataset.month;
    for (const sec of this.wrap.querySelectorAll('.month-group.is-current')) sec.classList.remove('is-current');
    cur.classList.add('is-current');
    this.updateScrubNow();
    const hero = document.querySelector('.mem-hero img');
    if (hero && this.scroller.scrollTop < 240) { if (!first) setAmbientFrom(hero); }
    else {
      const img = cur.querySelector('.tile img');
      if (img) { setAmbientFrom(img); this.ambientSet = true; }
    }
  }

  updateScrubNow() {
    if (!this.scrub || !this.curMonth) return;
    const { name, year } = monthParts(this.curMonth);
    this.scrubNow.replaceChildren(h('span', { class: 'scrub-month' }, name), h('span', { class: 'scrub-year' }, year));
    for (const b of this.scrub.querySelectorAll('button[data-year]')) b.classList.toggle('on', b.dataset.year === year);
  }

  destroy() {
    this.destroyed = true;
    document.removeEventListener('om:theme', this.onTheme);
    window.removeEventListener('resize', this.onResize);
    this.ro?.disconnect();
    this.io?.disconnect();
    this.unsub?.();
    this.scroller?.removeEventListener('scroll', this.onScroll);
    cancelAnimationFrame(this._sr);
    this.scrub?.remove();
  }

  reset(extra = {}) {
    this.query = { ...this.opts.query, ...extra };
    this.next = extra.before ? { before: extra.before } : null;
    this.done = false;
    this.paused = false;
    this.groups.clear();
    this.lastMonth = null;
    this.monthEl = null;
    this.curMonth = null;
    store.list = [];
    this.wrap.replaceChildren();
    this.jumpBack.hidden = !extra.before;
    this.root.scrollTop = 0;
    document.getElementById('view').scrollTop = 0;
    // Tam yenileme (ör. yükleme sonrası): ay sayılarını, toplamı ve yıl rayını da tazele.
    if (!extra.before) this.buildScrubber();
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
      this.trackMonth();
      if (!this.ambientSet && !document.querySelector('.mem-hero img')) {
        const img = this.wrap.querySelector('.tile img');
        if (img) { setAmbientFrom(img); this.ambientSet = true; }
      }
      if (this.done && store.list.length === 0) {
        if (!document.querySelector('.mem-hero img')) clearAmbient();
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
        if (month !== this.lastMonth || !this.monthEl) {
          this.lastMonth = month;
          this.monthEl = h('section', { class: 'month-group', dataset: { month, year: month.slice(0, 4) } }, this.monthHead(month));
          this.wrap.append(this.monthEl);
        }
        const grid = h('div', { class: 'grid', role: 'list' });
        const dp = dayParts(key);
        const g2 = { assets: [] };
        const selBtn = h('button', {
          class: 'day-sel', type: 'button', 'aria-label': `${t('select')}: ${dp.label}`,
          onclick: () => { const ids = g2.assets.map((x) => x.id); store.setMany(ids, !ids.every((id) => store.selection.has(id))); },
        }, h('span', { class: 'day-sel-check' }, icon('check')), h('span', { class: 'day-sel-text' }, t('select_day')));
        const head = h('h3', { class: 'day' },
          h('span', { class: 'day-label' }, dp.label), ' ', h('span', { class: 'day-sub' }, dp.weekday), selBtn);
        const section = h('section', { class: 'day-group', dataset: { day: key } }, head, grid);
        g = Object.assign(g2, { section, grid });
        this.groups.set(key, g);
        this.monthEl.append(section);
      }
      g.assets.push(a);
      g.grid.append(this.tile(a));
      touched.add(g);
    }
    for (const g of touched) this.layout(g);
    // Seçim sürerken yeni sayfa bir günün devamını getirdiyse o günün .all-selected durumu değişir.
    if (store.selection.size) this.syncSelection();
  }

  /** Ay başlığı: h2.month > .month-name + .month-year + .month-count (sayı özet gelince dolar). */
  monthHead(month) {
    const { name, year } = monthParts(month);
    const n = this.monthCounts.get(month);
    return h('h2', { class: 'month', 'aria-label': formatMonth(month) },
      h('span', { class: 'month-name' }, name), ' ', h('span', { class: 'month-year' }, year),
      h('span', { class: 'month-count' }, n ? t('items', { n }) : ''));
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
    for (const g of this.groups.values()) g.section.classList.toggle('all-selected', g.assets.length > 0 && g.assets.every((a) => store.selection.has(a.id)));
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
        this.countRemoved(sec.dataset.day.slice(0, 7));
        if (!grid.children.length) {
          this.groups.delete(sec.dataset.day);
          const monthSec = sec.parentElement;
          sec.remove();
          if (monthSec?.classList.contains('month-group') && !monthSec.querySelector('.day-group')) {
            if (monthSec === this.monthEl) this.monthEl = null;
            monthSec.remove();
          }
        } else {
          this.layout(g);
        }
      }
    }
    if (store.list.length === 0 && this.done) {
      if (!document.querySelector('.mem-hero img')) clearAmbient();
      this.status.replaceChildren(h('div', { class: 'empty' }, h('p', {}, this.opts.empty || t('empty_photos'))));
    }
  }

  /** Kaldırılan bir öğeyi ay sayısından ve toplamdan düşer (.month-count, #pageSub). */
  countRemoved(month) {
    const n = this.monthCounts.get(month);
    if (!n) return;
    this.monthCounts.set(month, n - 1);
    this.total = Math.max(0, (this.total || 0) - 1);
    const el = this.wrap.querySelector(`.month-group[data-month="${month}"] .month-count`);
    if (el) el.textContent = n - 1 ? t('items', { n: n - 1 }) : '';
    this.renderTotal();
  }

  renderTotal() {
    const sub = document.getElementById('pageSub');
    if (sub) sub.textContent = this.total ? t('items', { n: this.total }) : '';
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

  /** assets/summary: ay sayıları (.month-count), toplam (#pageSub) ve yıl rayı (.scrubber). */
  async buildScrubber() {
    try {
      const r = await api('GET', 'assets/summary' + qs(this.opts.query || {}));
      if (this.destroyed || !this.wrap.isConnected) return;
      let total = 0;
      this.monthCounts.clear();
      for (const m of r.months) { this.monthCounts.set(m.month, m.count); total += m.count; }
      for (const sec of this.wrap.querySelectorAll('.month-group')) {
        const n = this.monthCounts.get(sec.dataset.month);
        const el = sec.querySelector('.month-count');
        if (el) el.textContent = n ? t('items', { n }) : '';
      }
      this.total = total;
      this.renderTotal();
      // Yeniden çağrılabilir (reset sonrası): eski rayı kaldır.
      this.scrub?.remove();
      this.scrub = null;
      if (this.opts.scrubber === false || r.months.length < 3) return;
      const years = new Map();
      for (const m of r.months) {
        const y = m.month.slice(0, 4);
        if (!years.has(y)) years.set(y, m.month);
      }
      this.scrubNow = h('div', { class: 'scrub-now', 'aria-hidden': 'true' });
      this.scrub = h('nav', { class: 'scrubber', 'aria-label': t('jump_to_year') }, this.scrubNow,
        ...[...years.entries()].map(([y, month]) => h('button', {
          title: formatMonth(month), dataset: { year: y },
          onclick: () => {
            // Ayın sonundan önceki her şey: o yılın ilk (en yeni) ayına atla.
            const [yy, mm] = month.split('-').map(Number);
            const end = new Date(Date.UTC(yy, mm, 1)).toISOString().replace('.000', '');
            this.reset({ before: end });
          },
        }, y)));
      this.root.append(this.scrub);
      this.updateScrubNow();
    } catch { /* kaydırıcı isteğe bağlı */ }
  }
}
