// OwnMemories — arama sonuçları (GET /v1/search): ilgi sırasına göre tek parça, oranları koruyan ızgara.
// Akıllı arama (yerel ML hizmeti) yoksa sunucu metin aramasına düşer (mode "text").
// Zaman çizelgesinin karo, seçim ve satır yerleşimi davranışını devralır; gün/ay gruplaması ve kaydırıcı yoktur.
import { api, qs } from './api.js';
import { h, t, icon } from './util.js';
import { Timeline, clearAmbient } from './timeline.js';
import { setAmbientFrom } from './theme.js';
import { store } from './store.js';

const PAGE = 60;
const fmt = (n) => Number(n || 0).toLocaleString('tr-TR');

/** #/search bağlantısı (boş değerler atlanır). */
export const searchHref = (p) => '#/search' + qs(p);

/** Öneri yongaları: button.chip.search-chip[data-q]. */
function suggestionChips() {
  return h('div', { class: 'search-chips', role: 'group', 'aria-label': t('search_suggestions') }, ...t('search_suggest').split(',').map((s) => h('a', {
    class: 'chip search-chip', href: searchHref({ q: s.toLocaleLowerCase('tr-TR') }), dataset: { q: s },
  }, icon('search'), h('span', {}, s))));
}

/**
 * Kanca sınıfları (tema CSS'i için):
 *   .search-view(.is-empty-query)
 *     .page-head.search-head > .search-head-text > h1.search-title (span.search-q + metin) + p.search-mode[data-mode="smart|text"] (.ic + metin)
 *                            > .search-tools > .seg.search-sort[role=group] > button.seg-btn(.on)[data-sort="relevance|date"]
 *                                            + .filters (Başlangıç / Bitiş / Süz)
 *     p.search-note (dizin hazırlanırken: "Akıllı arama hazırlanıyor: 12.400 / 30.000"; .search-note-bar > i  ilerleme)
 *     .timeline.search-results > section.search-group > .grid > .tile …
 *     .search-start (boş sorgu) > .search-start-ic + h2 + p + .search-chips > a.chip.search-chip
 */
export class SearchView extends Timeline {
  build() {
    const p = this.opts.params || {};
    this.params = p;
    this.offset = 0;
    this.root.replaceChildren();
    this.box = h('div', { class: 'search-view' });
    this.root.append(this.box);
    this.wrap = h('div', { class: 'timeline search-results' });
    if (!p.q) {
      store.loadMore = null;
      this.done = true;
      clearAmbient();
      this.box.classList.add('is-empty-query');
      this.box.append(h('div', { class: 'search-start' },
        h('div', { class: 'search-start-ic', 'aria-hidden': 'true' }, icon('sparkle')),
        h('h2', {}, t('search_empty_title')),
        h('p', { class: 'muted' }, t('search_empty_hint')),
        suggestionChips()));
      return;
    }
    this.head = this.buildHead();
    this.note = h('p', { class: 'search-note', hidden: true });
    const grid = h('div', { class: 'grid', role: 'list' });
    const section = h('section', { class: 'search-group' }, grid);
    this.group = { section, grid, assets: [] };
    this.groups.set('all', this.group);
    this.wrap.append(section);
    this.status = h('div', { class: 'tl-status' });
    this.sentinel = h('div', { class: 'sentinel', 'aria-hidden': 'true' });
    this.box.append(this.head, this.note, this.wrap, this.status, this.sentinel);
    this.lastWidth = 0;
    this.ro = new ResizeObserver(() => {
      const w = this.wrap.clientWidth;
      if (w && Math.abs(w - this.lastWidth) > 1) { this.lastWidth = w; this.onResize(); }
    });
    this.ro.observe(this.wrap);
    this.onTheme = () => requestAnimationFrame(() => { this.lastWidth = this.wrap.clientWidth; this.onResize(); });
    document.addEventListener('om:theme', this.onTheme);
    this.io = new IntersectionObserver((es) => { if (!this.paused && es.some((e) => e.isIntersecting)) this.load(); }, { rootMargin: '1200px' });
    this.io.observe(this.sentinel);
    this.load();
  }

  buildHead() {
    const p = this.params;
    const sort = p.sort === 'date' ? 'date' : 'relevance';
    this.title = h('h1', { class: 'search-title' }, h('span', { class: 'search-q' }, `“${p.q}”`));
    this.mode = h('p', { class: 'search-mode', hidden: true });
    const go = (extra) => { location.hash = searchHref({ q: p.q, from: p.from, to: p.to, sort: p.sort, favorite: p.favorite, ...extra }); };
    this.sortSeg = h('div', { class: 'seg search-sort', role: 'group', 'aria-label': t('search_sort'), hidden: true },
      ...[['relevance', 'search_sort_relevance'], ['date', 'search_sort_date']].map(([k, label]) => h('button', {
        type: 'button', class: 'seg-btn' + (k === sort ? ' on' : ''), 'aria-pressed': k === sort ? 'true' : 'false', dataset: { sort: k },
        onclick: () => { if (k !== sort) go({ sort: k === 'date' ? 'date' : '' }); },
      }, t(label))));
    const from = h('input', { type: 'date', value: p.from || '', 'aria-label': t('from') });
    const to = h('input', { type: 'date', value: p.to || '', 'aria-label': t('to') });
    const apply = () => go({ from: from.value, to: to.value });
    for (const inp of [from, to]) inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
    const filters = h('div', { class: 'filters', id: 'searchDates' }, h('label', {}, t('from'), from), h('label', {}, t('to'), to),
      h('button', { class: 'btn btn-soft', type: 'button', onclick: apply }, t('filter')));
    // Dar ekranda tarih süzgeci bir düğmenin arkasında durur (etkin süzgeç varsa açık başlar).
    const hasDates = !!(p.from || p.to);
    const tools = h('div', { class: 'search-tools' + (hasDates ? ' dates-open' : '') });
    const dateBtn = h('button', {
      type: 'button', class: 'btn btn-soft search-date-btn' + (hasDates ? ' on' : ''), 'aria-expanded': hasDates ? 'true' : 'false', 'aria-controls': 'searchDates',
      onclick: () => { const open = tools.classList.toggle('dates-open'); dateBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); if (open) from.focus(); },
    }, icon('calendar'), h('span', {}, t('search_dates')));
    tools.append(h('div', { class: 'search-tools-row' }, this.sortSeg, dateBtn), filters);
    this.live = h('p', { class: 'visually-hidden', 'aria-live': 'polite', role: 'status' });
    return h('div', { class: 'page-head search-head' },
      h('div', { class: 'search-head-text' }, this.title, this.mode),
      tools, this.live);
  }

  /** Başlık: "“kumsalda köpek” için 48 sonuç", mod satırı ve dizin durumu. */
  renderHead(r) {
    this.total = r.total ?? null;
    this.renderCount();
    const smart = r.mode === 'smart';
    this.mode.hidden = false;
    this.mode.dataset.mode = r.mode;
    // Metin kipinde status yoksa ML hizmetine ulaşılamamıştır: kullanıcıya bunu da söyle.
    const modeKey = smart ? 'search_mode_smart' : (r.status ? 'search_mode_text' : 'search_mode_text_fallback');
    this.mode.replaceChildren(icon(smart ? 'sparkle' : 'search'), h('span', {}, t(modeKey)));
    this.sortSeg.hidden = !smart;
    const st = r.status;
    if (st && st.total > 0 && st.indexed < st.total) {
      this.note.hidden = false;
      this.note.replaceChildren(
        h('span', { class: 'search-note-text' }, t('search_indexing', { done: fmt(st.indexed), total: fmt(st.total) })),
        h('span', { class: 'search-note-bar', 'aria-hidden': 'true', style: `--p:${Math.min(1, st.indexed / st.total).toFixed(3)}` }, h('i')));
    } else {
      this.note.hidden = true;
    }
  }

  renderCount() {
    const q = `“${this.params.q}”`;
    const n = this.total || 0;
    const text = n ? t('search_title_n', { q: '\u0000', n: fmt(n) }) : t('search_title_none', { q: '\u0000' });
    const [before, after] = text.split('\u0000');
    this.title.replaceChildren(before, h('span', { class: 'search-q' }, q), after);
    const sub = document.getElementById('pageSub');
    if (sub) sub.textContent = n ? t('search_count', { n: fmt(n) }) : t('search_count_none');
    if (this.live) this.live.textContent = text.replace('\u0000', q);
  }

  async load() {
    if (this.loading || this.done || !this.params?.q) return;
    this.loading = true;
    const gen = this.gen || 0;
    let failed = false;
    this.status.replaceChildren(h('span', { class: 'spinner' }), t('loading'));
    try {
      const p = this.params;
      const r = await api('GET', 'search' + qs({
        q: p.q, sort: p.sort === 'date' ? 'date' : '', limit: PAGE, offset: this.offset || '',
        from: p.from ? p.from + 'T00:00:00Z' : '', to: p.to ? p.to + 'T23:59:59Z' : '', favorite: p.favorite,
      }));
      if (gen !== (this.gen || 0) || this.destroyed) return;
      if (!this.offset) this.renderHead(r);
      this.offset = r.next_offset;
      this.done = r.next_offset === null;
      this.append(r.assets);
      this.status.replaceChildren();
      if (!this.ambientSet) {
        const img = this.wrap.querySelector('.tile img');
        if (img) { setAmbientFrom(img); this.ambientSet = true; }
      }
      if (this.done && store.list.length === 0) this.showEmpty();
    } catch (e) {
      if (gen !== (this.gen || 0) || this.destroyed) return;
      failed = true;
      this.status.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed')), h('button', { class: 'btn', onclick: () => { this.paused = false; this.load(); } }, t('retry'))));
    } finally {
      if (gen === (this.gen || 0)) this.loading = false;
    }
    if (gen !== (this.gen || 0) || this.destroyed) return;
    this.paused = failed;
    if (!failed && !this.done && this.sentinel.getBoundingClientRect().top < window.innerHeight + 1200) requestAnimationFrame(() => this.load());
  }

  showEmpty() {
    clearAmbient();
    this.status.replaceChildren(h('div', { class: 'empty search-empty' },
      h('p', {}, t('search_no_results')),
      h('p', { class: 'search-try muted' }, t('search_try')),
      suggestionChips()));
  }

  append(assets) {
    const g = this.group;
    for (const a of assets) {
      store.list.push(a);
      g.assets.push(a);
      g.grid.append(this.tile(a));
    }
    this.layout(g);
    if (store.selection.size) this.syncSelection();
  }

  reset() {
    this.gen = (this.gen || 0) + 1;
    this.loading = false;
    this.offset = 0;
    this.done = false;
    this.paused = false;
    store.list = [];
    if (this.group) { this.group.assets = []; this.group.grid.replaceChildren(); }
    return this.load();
  }

  removeIds(ids) {
    store.remove(ids);
    const g = this.group;
    if (!g) return;
    const set = new Set(ids);
    let n = 0;
    for (const id of ids) {
      const el = g.grid.querySelector(`.tile[data-id="${id}"]`);
      if (el) { el.remove(); n++; }
    }
    g.assets = g.assets.filter((x) => !set.has(x.id));
    if (n && this.total) { this.total = Math.max(0, this.total - n); this.renderCount(); }
    this.layout(g);
    if (store.list.length === 0 && this.done) this.showEmpty();
  }

  refreshTile(a) {
    const g = this.group;
    const el = g?.grid.querySelector(`.tile[data-id="${a.id}"]`);
    if (!el) return;
    const fresh = this.tile(a);
    fresh.style.width = el.style.width;
    fresh.style.height = el.style.height;
    el.replaceWith(fresh);
    g.assets = g.assets.map((x) => (x.id === a.id ? a : x));
  }

  buildScrubber() { /* aramada yıl rayı yok */ }
  trackMonth() {}
}
