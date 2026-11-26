// OwnMemories — küçük yardımcılar.
export const OM = window.OM;

export function t(key, vars = {}) {
  let s = OM.t[key] ?? key;
  for (const [k, v] of Object.entries(vars)) s = s.replaceAll(`{${k}}`, String(v));
  return s;
}

/** Element oluşturur: h('div', {class: 'x', onclick}, ...çocuklar) */
export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'html') el.innerHTML = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export const icons = {
  heart: '<svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/></svg>',
  heartFill: '<svg viewBox="0 0 24 24" class="fill"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/></svg>',
  trash: '<svg viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12"/></svg>',
  restore: '<svg viewBox="0 0 24 24"><path d="M5 12a7 7 0 1 0 2-4.9M5 4v4h4"/></svg>',
  album: '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="m4 16 4.5-4 3.5 3 3-2.5 5 4.5"/></svg>',
  archive: '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="4" rx="1"/><path d="M6 9v10h12V9M10 13h4"/></svg>',
  download: '<svg viewBox="0 0 24 24"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19h14"/></svg>',
  info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 11v5M12 8v.5"/></svg>',
  close: '<svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg>',
  left: '<svg viewBox="0 0 24 24"><path d="m14.5 6-6 6 6 6"/></svg>',
  right: '<svg viewBox="0 0 24 24"><path d="m9.5 6 6 6-6 6"/></svg>',
  check: '<svg viewBox="0 0 24 24"><path d="m6 12.5 4 4 8-9"/></svg>',
  play: '<svg viewBox="0 0 24 24" class="fill"><path d="M8 5.5v13l10.5-6.5Z"/></svg>',
  live: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="6" stroke-dasharray="2 2"/><circle cx="12" cy="12" r="9"/></svg>',
  share: '<svg viewBox="0 0 24 24"><circle cx="17" cy="6" r="2.5"/><circle cx="7" cy="12" r="2.5"/><circle cx="17" cy="18" r="2.5"/><path d="m9.2 10.8 5.6-3.4M9.2 13.2l5.6 3.4"/></svg>',
  plus: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
  photos: '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><circle cx="9.5" cy="9.5" r="1.8"/><path d="m4.5 17 4.5-4.5 3.5 3.5 2.5-2.5 4.5 4.5"/></svg>',
  people: '<svg viewBox="0 0 24 24"><circle cx="9" cy="9" r="3"/><circle cx="17" cy="10" r="2.3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M14.5 18.5a4 4 0 0 1 6.5-3"/></svg>',
  map: '<svg viewBox="0 0 24 24"><path d="M12 21s-6-5.6-6-11a6 6 0 0 1 12 0c0 5.4-6 11-6 11Z"/><circle cx="12" cy="10" r="2.2"/></svg>',
};

export function icon(name) {
  const s = document.createElement('span');
  s.className = 'ic';
  s.innerHTML = icons[name] || '';
  return s;
}

const loc = () => (OM.locale === 'en' ? 'en-GB' : 'tr-TR');

/** Asset'in yerel zamanı (çekildiği yerin saatiyle). */
export function localDate(a) {
  const src = a.taken_local || a.taken_at;
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/.exec(src);
  return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6])) : new Date(a.taken_at);
}

export function dayKey(a) {
  return (a.taken_local || a.taken_at).slice(0, 10);
}

export function formatDay(key) {
  const [y, m, d] = key.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  if (key === today) return t('today');
  const yest = new Date(now.getTime() - 86400000);
  const ykey = `${yest.getFullYear()}-${String(yest.getMonth() + 1).padStart(2, '0')}-${String(yest.getDate()).padStart(2, '0')}`;
  if (key === ykey) return t('yesterday');
  const opts = { day: 'numeric', month: 'long', weekday: 'long', timeZone: 'UTC' };
  if (y !== now.getFullYear()) opts.year = 'numeric';
  return new Intl.DateTimeFormat(loc(), opts).format(dt);
}

export function formatDateTime(a) {
  const dt = localDate(a);
  const s = new Intl.DateTimeFormat(loc(), { dateStyle: 'full', timeStyle: 'short', timeZone: 'UTC' }).format(dt);
  if (a.tz_offset === null || a.tz_offset === undefined) return s;
  const o = a.tz_offset, sign = o < 0 ? '−' : '+', ab = Math.abs(o);
  return `${s} (UTC${sign}${Math.floor(ab / 60)}${ab % 60 ? ':' + String(ab % 60).padStart(2, '0') : ''})`;
}

export function formatMonth(ym) {
  const [y, m] = ym.split('-').map(Number);
  return new Intl.DateTimeFormat(loc(), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, 1)));
}

export function formatBytes(n) {
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${i === 0 ? n : n.toLocaleString(loc(), { maximumFractionDigits: 1 })} ${u[i]}`;
}

export function formatDuration(ms) {
  const s = Math.round(ms / 1000);
  const m = Math.floor(s / 60);
  return `${m}:${String(s % 60).padStart(2, '0')}`;
}

export function toast(msg) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { el.hidden = true; }, 3200);
}

/** <dialog> tabanlı basit pencere. body: Node; buttons: [{label, value, primary, danger}] → Promise<value|null> */
export function modal(title, body, buttons) {
  return new Promise((resolve) => {
    const d = h('dialog', { class: 'modal' });
    const form = h('form', { method: 'dialog' },
      h('h2', {}, title), body,
      h('div', { class: 'modal-actions' }, ...buttons.map((b) => h('button', {
        value: b.value, class: `btn ${b.primary ? 'btn-primary' : ''} ${b.danger ? 'btn-danger' : ''}`, type: 'submit',
      }, b.label))));
    d.append(form);
    d.addEventListener('close', () => { resolve(d.returnValue && d.returnValue !== 'cancel' ? d.returnValue : null); d.remove(); });
    document.body.append(d);
    d.showModal();
    const first = d.querySelector('input, select, textarea');
    if (first) first.focus();
  });
}

export async function confirmDialog(title, text, okLabel, danger = false) {
  const r = await modal(title, h('p', {}, text), [{ label: t('cancel'), value: 'cancel' }, { label: okLabel, value: 'ok', primary: !danger, danger }]);
  return r === 'ok';
}

export async function promptDialog(title, label, value = '', okLabel = t('save')) {
  const input = h('input', { type: 'text', value, 'aria-label': label, required: true, maxlength: 255 });
  const r = await modal(title, h('label', { class: 'field' }, h('span', {}, label), input), [{ label: t('cancel'), value: 'cancel' }, { label: okLabel, value: 'ok', primary: true }]);
  return r === 'ok' && input.value.trim() ? input.value.trim() : null;
}
