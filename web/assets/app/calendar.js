// OwnMemories — tarih takvimi: yıl rayındaki ay/yıl etiketine tıklayınca açılır.
// Ayın fotoğraflı günleri (GET assets/days, yerel gün) sayı rozetiyle gösterilir; güne tıklayınca
// zaman çizelgesi o güne gider. Masaüstünde raya bağlı açılır kutu, mobilde (≤ 860px) alt sayfa.
import { api, qs, thumbUrl } from './api.js';
import { h, t, icon, monthParts, formatDate } from './util.js';

const cache = new Map(); // sorgu+ay → Promise<days[]>
let current = null;      // açık takvim (aynı anda tek)

/** Sunucudaki içerik değişince (yükleme, silme…) önbelleği boşaltır. */
export function clearCalendarCache() { cache.clear(); }

export function calendarOpen() { return current !== null; }

export function closeCalendar(restoreFocus = true) { current?.close(restoreFocus); }

function fetchDays(query, month) {
  const key = JSON.stringify(query) + '|' + month;
  if (!cache.has(key)) {
    const p = api('GET', 'assets/days' + qs({ ...query, month })).then((r) => r.days);
    p.catch(() => cache.delete(key));
    cache.set(key, p);
  }
  return cache.get(key);
}

const pad = (n) => String(n).padStart(2, '0');
const todayKey = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };
const daysIn = (y, m) => new Date(Date.UTC(y, m, 0)).getUTCDate();
/** Pazartesi = 0 … Pazar = 6 */
const mondayIndex = (y, m) => (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7;
const fmtCount = (n) => (n > 999 ? '999+' : String(n));

/**
 * Takvimi açar.
 * @param {{
 *   anchor: HTMLElement,          tıklanan öğe (kapanınca odak buraya döner, masaüstünde hizalama)
 *   rail?: HTMLElement,           yıl rayı (masaüstünde kutunun soluna yerleştiği öğe)
 *   query: object,                zaman çizelgesi süzgeçleri (favoriler, albüm, arşiv…)
 *   months: string[],             fotoğraflı aylar, yeniden eskiye ('YYYY-MM')
 *   counts: Map<string, number>,  ay → öğe sayısı
 *   month: string,                açılış ayı
 *   day?: string,                 görünür gün (odak için)
 *   onPick: (d: {day, count, cover_asset_id, before}) => void,
 *   onMonth?: (month: string) => void,
 * }} o
 */
export function openCalendar(o) {
  current?.close(false);
  const months = o.months.slice();
  let month = o.month;
  let days = new Map();
  let focusDay = o.day && o.day.startsWith(month) ? o.day : null;
  let req = 0;
  const mobile = () => matchMedia('(max-width: 860px)').matches;

  const titleId = 'calTitle' + Math.random().toString(36).slice(2, 7);
  const prevBtn = h('button', { class: 'cal-nav cal-prev', type: 'button', 'aria-label': t('cal_prev_month'), onclick: () => step(1) }, icon('left'));
  const nextBtn = h('button', { class: 'cal-nav cal-next', type: 'button', 'aria-label': t('cal_next_month'), onclick: () => step(-1) }, icon('right'));
  const closeBtn = h('button', { class: 'cal-close', type: 'button', 'aria-label': t('close'), onclick: () => close() }, icon('close'));
  const mName = h('span', { class: 'cal-month' });
  const mYear = h('span', { class: 'cal-year' });
  const sub = h('span', { class: 'cal-sub' });
  const title = h('div', { class: 'cal-title', id: titleId, 'aria-live': 'polite' }, h('span', { class: 'cal-title-main' }, mName, ' ', mYear), sub);
  const week = h('div', { class: 'cal-week', 'aria-hidden': 'true' },
    ...t('cal_weekdays').split(',').map((d, i) => h('span', { class: i >= 5 ? 'cal-wd is-weekend' : 'cal-wd' }, d)));
  const grid = h('div', { class: 'cal-grid', role: 'grid', 'aria-labelledby': titleId });
  const jump = h('button', { class: 'cal-jump', type: 'button', onclick: () => { close(false); o.onMonth?.(month); } }, t('cal_go_month'));
  const foot = h('div', { class: 'cal-foot' }, h('span', { class: 'cal-hint' }, t('cal_hint')), jump);
  const dialog = h('section', { class: 'cal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1' },
    h('div', { class: 'cal-grabber', 'aria-hidden': 'true' }),
    h('header', { class: 'cal-head' }, prevBtn, title, nextBtn, closeBtn),
    week, grid, foot);
  // Dışarı tıklama kapatır; tıklanan yer yıl rayındaki başka bir düğmeyse takvim o yılda yeniden açılır.
  const backdrop = h('div', {
    class: 'cal-backdrop',
    onclick: (e) => {
      const under = document.elementsFromPoint(e.clientX, e.clientY).find((el) => el.matches?.('.scrubber button') && el !== o.anchor);
      close(!under);
      under?.click();
    },
  });
  const layer = h('div', { class: 'cal-layer' }, backdrop, dialog);

  /** Fotoğraflı aylar arasında gezinme: dir=1 daha eski, dir=-1 daha yeni. */
  function neighbour(dir) {
    if (dir > 0) return months.find((m) => m < month) || null;
    for (let i = months.length - 1; i >= 0; i--) if (months[i] > month) return months[i];
    return null;
  }

  function step(dir, keepFocusOnDays = false) {
    const m = neighbour(dir);
    if (!m) return;
    month = m;
    focusDay = null;
    render(keepFocusOnDays);
  }

  function dayButtons() { return [...grid.querySelectorAll('.cal-day')]; }

  function render(focusGrid = false) {
    const [y, m] = month.split('-').map(Number);
    const mp = monthParts(month);
    mName.textContent = mp.name;
    mYear.textContent = mp.year;
    const n = o.counts.get(month);
    sub.textContent = n ? t('items', { n }) : '';
    prevBtn.disabled = !neighbour(1);
    nextBtn.disabled = !neighbour(-1);
    const today = todayKey();
    const total = daysIn(y, m);
    const lead = mondayIndex(y, m);
    const cells = [];
    for (let i = 0; i < lead; i++) cells.push(h('span', { class: 'cal-pad', role: 'gridcell', 'aria-hidden': 'true' }));
    for (let d = 1; d <= total; d++) {
      const key = `${month}-${pad(d)}`;
      const btn = h('button', {
        class: 'cal-day' + (key === today ? ' is-today' : '') + (((lead + d - 1) % 7) >= 5 ? ' is-weekend' : ''),
        type: 'button', tabindex: '-1', dataset: { day: key }, 'aria-disabled': 'true',
        onclick: () => pick(key),
      }, h('span', { class: 'cal-num' }, d));
      cells.push(h('span', { class: 'cal-cell', role: 'gridcell' }, btn));
    }
    while (cells.length % 7) cells.push(h('span', { class: 'cal-pad', role: 'gridcell', 'aria-hidden': 'true' }));
    const rows = [];
    for (let i = 0; i < cells.length; i += 7) rows.push(h('div', { class: 'cal-row', role: 'row' }, ...cells.slice(i, i + 7)));
    grid.replaceChildren(...rows);
    grid.dataset.rows = String(rows.length);
    days = new Map();
    label();
    setFocusable(focusDay || `${month}-01`, false);
    dialog.classList.add('is-loading');
    const my = ++req;
    fetchDays(o.query, month).then((list) => {
      if (my !== req || !layer.isConnected) return;
      dialog.classList.remove('is-loading');
      days = new Map(list.map((x) => [x.day, x]));
      const max = Math.max(1, ...list.map((x) => x.count));
      for (const btn of dayButtons()) {
        const d = days.get(btn.dataset.day);
        if (!d) continue;
        btn.classList.add('has');
        btn.removeAttribute('aria-disabled');
        // 1–4: yoğunluk kademesi (temalar rozet/arka plan tonunda kullanabilir)
        btn.dataset.level = String(Math.min(4, 1 + Math.floor((d.count / max) * 3.999)));
        const img = h('img', { class: 'cal-cover', src: thumbUrl(d.cover_asset_id), alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' });
        img.addEventListener('load', () => img.classList.add('in'), { once: true });
        img.addEventListener('error', () => img.remove(), { once: true });
        btn.prepend(img);
        btn.append(h('span', { class: 'cal-count', 'aria-hidden': 'true' }, fmtCount(d.count)));
      }
      label();
      sub.textContent = [n ? t('items', { n }) : '', list.length ? t('cal_days', { n: list.length }) : ''].filter(Boolean).join(' · ');
      // Odak: görünür gün > ayın en yeni fotoğraflı günü > 1'i
      const target = (focusDay && days.has(focusDay)) ? focusDay : (list[0]?.day || `${month}-01`);
      setFocusable(target, focusGrid || grid.contains(document.activeElement));
    }).catch(() => {
      if (my !== req) return;
      dialog.classList.remove('is-loading');
      sub.textContent = t('load_failed');
    });
    if (focusGrid) grid.querySelector('.cal-day[tabindex="0"]')?.focus();
  }

  function label() {
    for (const btn of dayButtons()) {
      const d = days.get(btn.dataset.day);
      const parts = [formatDate(btn.dataset.day, true)];
      if (btn.classList.contains('is-today')) parts.push(t('today'));
      parts.push(d ? t('items', { n: d.count }) : t('cal_no_photos'));
      btn.setAttribute('aria-label', parts.join(', '));
    }
  }

  function setFocusable(key, focus) {
    let target = null;
    for (const btn of dayButtons()) {
      const on = btn.dataset.day === key;
      btn.tabIndex = on ? 0 : -1;
      if (on) target = btn;
    }
    if (!target) { target = dayButtons()[0]; if (target) target.tabIndex = 0; }
    if (focus) target?.focus();
  }

  function pick(key) {
    const d = days.get(key);
    if (!d) return;
    close(false);
    o.onPick(d);
  }

  function moveFocus(delta) {
    const btns = dayButtons();
    const i = btns.indexOf(document.activeElement);
    if (i < 0) return;
    const j = i + delta;
    if (j < 0 || j >= btns.length) {
      // Ayın dışına taşınca bir sonraki/önceki fotoğraflı aya geç.
      const m = neighbour(j < 0 ? 1 : -1);
      if (!m) return;
      month = m;
      const [y, mm] = m.split('-').map(Number);
      const total = daysIn(y, mm);
      focusDay = j < 0 ? `${m}-${pad(Math.max(1, total + j + 1))}` : `${m}-${pad(Math.min(total, j - btns.length + 1))}`;
      render(true);
      return;
    }
    focusDay = btns[j].dataset.day;
    setFocusable(focusDay, true);
  }

  function onKey(e) {
    // Kalıcı (modal) iletişim kutusu: genel kısayollar ("/" arama, Escape ile seçimi temizleme) çalışmasın.
    e.stopPropagation();
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
    if (e.key === 'Tab') {
      const f = [...dialog.querySelectorAll('button:not([disabled])')].filter((b) => b.tabIndex >= 0 && b.offsetParent !== null);
      if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      return;
    }
    // APG tarih seçici: PageUp önceki (daha eski), PageDown sonraki (daha yeni) ay.
    if (e.key === 'PageUp' || e.key === 'PageDown') { e.preventDefault(); step(e.key === 'PageUp' ? 1 : -1, grid.contains(document.activeElement)); return; }
    if (!grid.contains(document.activeElement)) return;
    const btn = document.activeElement;
    const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    if (e.key in moves) { e.preventDefault(); moveFocus(moves[e.key]); return; }
    if (e.key === 'Home' || e.key === 'End') {
      e.preventDefault();
      const btns = dayButtons();
      const i = btns.indexOf(btn);
      const col = (mondayIndex(...month.split('-').map(Number)) + i) % 7;
      const j = e.key === 'Home' ? Math.max(0, i - col) : Math.min(btns.length - 1, i + (6 - col));
      focusDay = btns[j].dataset.day;
      setFocusable(focusDay, true);
      return;
    }
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(btn.dataset.day); }
  }

  function position() {
    if (mobile()) { dialog.style.removeProperty('top'); dialog.style.removeProperty('right'); return; }
    const rail = (o.rail || o.anchor).getBoundingClientRect();
    const a = o.anchor.getBoundingClientRect();
    const hgt = dialog.offsetHeight;
    const right = Math.max(12, innerWidth - rail.left + 14);
    const top = Math.min(Math.max(12, a.top - 18), Math.max(12, innerHeight - hgt - 16));
    dialog.style.right = right + 'px';
    dialog.style.top = top + 'px';
  }

  const onResize = () => position();
  // Odak kutunun dışındayken de Escape kapatır (yakalama evresi: genel Escape işleyicisi seçimi temizlemesin).
  const onDocKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } };

  function close(restoreFocus = true) {
    if (current !== api_) return;
    current = null;
    o.anchor.setAttribute('aria-expanded', 'false');
    window.removeEventListener('resize', onResize);
    document.removeEventListener('keydown', onDocKey, true);
    layer.classList.remove('in');
    layer.classList.add('out');
    const done = () => layer.remove();
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) done();
    else setTimeout(done, 200);
    if (restoreFocus) {
      // Ray yeniden kurulduysa (ör. yükleme sonrası) aynı türdeki yeni düğmeye dön.
      const back = o.anchor.isConnected ? o.anchor : document.querySelector(o.anchor.classList.contains('cal-fab') ? '.cal-fab' : '.scrub-now');
      back?.focus({ preventScroll: true });
    }
  }

  const api_ = { close, dialog };
  current = api_;
  dialog.addEventListener('keydown', onKey);
  document.addEventListener('keydown', onDocKey, true);
  window.addEventListener('resize', onResize);
  o.anchor.setAttribute('aria-expanded', 'true');
  layer.classList.toggle('is-sheet', mobile());
  document.body.append(layer);
  render();
  position();
  requestAnimationFrame(() => {
    layer.classList.add('in');
    (grid.querySelector('.cal-day[tabindex="0"]') || dialog).focus({ preventScroll: true });
  });
  return api_;
}
