// OwnMemories — Temizlik: benzer/yinelenen kareler, bulanık ve karanlık fotoğraflar, ekran görüntüleri, kısa videolar.
// Sunucu: GET /v1/cleanup, POST /v1/cleanup/resolve|dismiss|reset. Hiçbir şey kalıcı silinmez; seçilenler Çöp
// kutusuna taşınır (30 gün). Rota: #/cleanup?tab=similar|blurry|dark|screenshots|videos
import { api, thumbUrl, previewUrl } from './api.js';
import { h, t, icon, toast, confirmDialog, formatBytes, formatDate, OM } from './util.js';
import { store } from './store.js';
import { openViewer } from './viewer.js';

const TABS = [
  // [anahtar, sunucudaki liste, simge]
  ['similar', 'groups', 'layers'],
  ['blurry', 'blurry', 'blur'],
  ['dark', 'dark', 'sun'],
  ['screenshots', 'screenshots', 'phone'],
  ['videos', 'short_videos', 'video'],
];
const DISMISS_KIND = { blurry: 'blurry', dark: 'dark', screenshots: 'screenshot', videos: 'video' };
const PRESELECT = { blurry: true, dark: true, screenshots: false, videos: true };
const MAX_IDS_PER_CALL = 900;

const loc = () => (OM.locale === 'en' ? 'en-GB' : 'tr-TR');
const num = (n) => Number(n || 0).toLocaleString(loc());
const sharpLabel = (s) => (s === null || s === undefined ? null : s < 25 ? 'blur' : s < 80 ? 'soft' : 'good');

let state = null; // {data, tab, keep: Map<gid,id>, trash: Map<gid,Set>, pick: Map<tab,Set>, box, setSub}

/** Temizlik sayfasını kutuya çizer. */
export async function renderCleanup(box, { tab = 'similar', setSub = () => {} } = {}) {
  if (!TABS.some(([k]) => k === tab)) tab = 'similar';
  state = { data: null, tab, keep: new Map(), trash: new Map(), pick: new Map(), box, setSub, focus: 0 };
  box.replaceChildren(h('div', { class: 'cleanup is-loading', 'aria-busy': 'true' },
    head(),
    h('div', { class: 'cl-loading' }, h('span', { class: 'cl-spinner', 'aria-hidden': 'true' }), h('span', {}, t('cl_loading')))));
  await reload();
}

async function reload() {
  const st = state;
  try {
    const data = await api('GET', 'cleanup');
    if (st !== state || !st.box.isConnected) return;
    st.data = data;
    // Grup seçimlerini koru (yeniden yüklemede kaybolmasın), yeni gruplar varsayılanla başlar.
    for (const g of data.groups) {
      if (!st.keep.has(g.id)) st.keep.set(g.id, g.keep_id);
      // Varsayılan: en iyisi dışındakiler çöpe — favoriler hariç (onları kullanıcı kendisi işaretler).
      if (!st.trash.has(g.id)) st.trash.set(g.id, new Set(g.assets.filter((a) => a.id !== st.keep.get(g.id) && !a.is_favorite).map((a) => a.id)));
    }
    for (const [k, list] of TABS) {
      if (k === 'similar') continue;
      const ids = new Set(data[list].map((a) => a.id));
      const prev = st.pick.get(k);
      st.pick.set(k, prev ? new Set([...prev].filter((id) => ids.has(id))) : new Set(PRESELECT[k] ? ids : []));
    }
    draw();
  } catch (e) {
    if (st !== state) return;
    st.box.replaceChildren(h('div', { class: 'cleanup' }, head(), h('div', { class: 'empty' }, h('p', {}, e.message || t('load_failed')))));
  }
}

function head() {
  return h('div', { class: 'page-head cl-head' }, h('div', {}, h('h1', {}, t('cleanup')), h('p', { class: 'muted cl-lead' }, t('cleanup_lead'))));
}

/* ----------------------------------------------------------- çizim */

function draw() {
  const { data } = state;
  const s = data.stats;
  const extra = data.groups.reduce((n, g) => n + g.assets.length - 1, 0);
  state.setSub(s.potential_bytes ? `${formatBytes(s.potential_bytes)} ${t('cl_reclaimable')}` : '');
  const root = h('div', { class: 'cleanup' }, head());

  if (!data.ml.available) {
    root.append(h('div', { class: 'cl-note', role: 'note' }, icon('info'), h('p', {}, t('cl_ml_off'))));
  }
  const an = data.analysis;
  if (an.total > 0 && an.analyzed < an.total) {
    root.append(h('div', { class: 'cl-progress', role: 'status' },
      h('div', { class: 'cl-progress-text' }, h('strong', {}, t('cl_analyzing')), h('span', { class: 'muted' }, t('cl_analyzing_sub', { done: num(an.analyzed), total: num(an.total) }))),
      h('div', { class: 'cl-progress-bar', style: `--p:${(an.analyzed / an.total).toFixed(3)}`, role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(an.total), 'aria-valuenow': String(an.analyzed) }, h('i'))));
  }

  // Kahraman: kazanılabilir alan + tek tık uygula
  const total = s.groups + s.blurry + s.dark + s.screenshots + s.short_videos;
  if (s.groups) {
    root.append(h('section', { class: 'cl-hero', 'aria-label': t('cl_reclaimable') },
      h('div', { class: 'cl-hero-text' },
        h('div', { class: 'cl-hero-num' }, h('span', { class: 'cl-hero-bytes' }, formatBytes(s.potential_bytes)), h('span', { class: 'cl-hero-unit' }, t('cl_reclaimable'))),
        h('p', { class: 'cl-hero-sub' }, t('cl_reclaim_sub', { n: num(extra), groups: num(s.groups) }))),
      h('div', { class: 'cl-hero-art', 'aria-hidden': 'true' }, ...data.groups.slice(0, 3).map((g) => h('img', { src: thumbUrl(g.keep_id), alt: '', loading: 'lazy', decoding: 'async' }))),
      h('button', { class: 'btn btn-primary cl-apply-all', onclick: applyAll }, icon('sparkle'), h('span', {}, t('cl_apply_all')))));
  } else if (!total) {
    root.append(h('section', { class: 'cl-hero is-clean' },
      h('div', { class: 'cl-hero-text' }, h('div', { class: 'cl-hero-num' }, h('span', { class: 'cl-hero-check' }, icon('check')), h('span', { class: 'cl-hero-title' }, t('cl_all_clean'))),
        h('p', { class: 'cl-hero-sub' }, t('cl_all_clean_sub')))));
  }

  // Özet kartları = sekmeler
  const cards = h('div', { class: 'cl-cards', role: 'tablist', 'aria-label': t('cleanup') });
  for (const [k, list, ic] of TABS) {
    const n = k === 'similar' ? s.groups : k === 'videos' ? s.short_videos : s[k];
    const bytes = k === 'similar' ? s.potential_bytes : k === 'videos' ? s.short_videos_bytes : s[`${k}_bytes`];
    const label = n ? t('cl_card_' + k, { n: num(n) }) : t('cl_tab_' + k);
    const sub = !n ? t('cl_card_clean') : k === 'similar' ? t('cl_card_similar_sub', { bytes: formatBytes(bytes) }) : k === 'videos' ? t('cl_card_videos_sub') : formatBytes(bytes);
    const items = k === 'similar' ? data.groups.map((g) => ({ id: g.keep_id })) : data[list];
    const sel = state.tab === k;
    const card = h('button', {
      class: 'cl-card' + (n ? '' : ' is-zero'), role: 'tab', id: 'cl-tab-' + k, 'aria-selected': sel ? 'true' : 'false', 'aria-controls': 'cl-panel',
      tabindex: sel ? '0' : '-1', dataset: { tab: k },
      onclick: () => selectTab(k),
    },
      h('span', { class: 'cl-card-ic' }, icon(ic)),
      h('span', { class: 'cl-card-text' }, h('strong', { class: 'cl-card-label' }, label), h('span', { class: 'cl-card-sub' }, sub)),
      h('span', { class: 'cl-card-thumbs', 'aria-hidden': 'true' }, ...items.slice(0, 3).map((a) => h('img', { src: thumbUrl(a.id), alt: '', loading: 'lazy', decoding: 'async' }))));
    cards.append(card);
  }
  cards.addEventListener('keydown', (e) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    const keys = TABS.map(([k]) => k);
    let i = keys.indexOf(state.tab);
    i = e.key === 'Home' ? 0 : e.key === 'End' ? keys.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + keys.length) % keys.length;
    e.preventDefault();
    selectTab(keys[i], true);
  });
  root.append(cards);

  const panel = h('section', { class: 'cl-panel', id: 'cl-panel', role: 'tabpanel', 'aria-labelledby': 'cl-tab-' + state.tab, dataset: { tab: state.tab } });
  if (state.tab === 'similar') drawGroups(panel);
  else drawList(panel, state.tab);
  root.append(panel);

  root.append(h('footer', { class: 'cl-foot' },
    h('button', { class: 'linklike', onclick: resetDismissed }, t('cl_restore_dismissed'))));
  state.box.replaceChildren(root);
}

function selectTab(k, focus = false) {
  state.tab = k;
  history.replaceState(history.state, '', '#/cleanup' + (k === 'similar' ? '' : '?tab=' + k));
  draw();
  if (focus) state.box.querySelector(`#cl-tab-${k}`)?.focus();
}

/* -------------------------------------------------------- benzerler */

function groupTrash(g) { return [...(state.trash.get(g.id) || [])]; }
function groupKeep(g) { const tr = state.trash.get(g.id) || new Set(); return g.assets.map((a) => a.id).filter((id) => !tr.has(id)); }

function drawGroups(panel) {
  const { groups } = state.data;
  if (!groups.length) {
    panel.append(h('div', { class: 'cl-empty' }, icon('check'), h('p', {}, t('cl_empty_similar'))));
    return;
  }
  panel.append(h('p', { class: 'cl-kbd muted', 'aria-hidden': 'true' }, t('cl_list_kbd')));
  const list = h('div', { class: 'cl-groups' });
  groups.forEach((g, i) => list.append(groupCard(g, i)));
  list.addEventListener('keydown', (e) => {
    if (e.target.closest('button') && e.key === 'Enter') return;
    const cardsEls = [...list.querySelectorAll('.cl-group')];
    const cur = e.target.closest('.cl-group');
    const i = cardsEls.indexOf(cur);
    const k = e.key.toLowerCase();
    if (k === 'j' || e.key === 'ArrowDown') { e.preventDefault(); cardsEls[Math.min(cardsEls.length - 1, i + 1)]?.focus(); }
    else if (k === 'k' || e.key === 'ArrowUp') { e.preventDefault(); cardsEls[Math.max(0, i - 1)]?.focus(); }
    else if (cur && e.key === 'Enter') { e.preventDefault(); openCompare(groups[i]); }
    else if (cur && k === 'a') { e.preventDefault(); applyGroup(groups[i]); }
    else if (cur && k === 'f') { e.preventDefault(); dismissGroup(groups[i]); }
  });
  panel.append(list);
  const focusIdx = Math.min(state.focus, groups.length - 1);
  if (state.focusAfter) {
    state.focusAfter = false;
    requestAnimationFrame(() => list.querySelectorAll('.cl-group')[focusIdx]?.focus());
  }
}

function groupCard(g, index) {
  const keepId = state.keep.get(g.id);
  const tr = state.trash.get(g.id);
  const strip = h('div', { class: 'cl-strip', role: 'group', 'aria-label': t('cl_group_meta', { n: g.assets.length, date: formatDate(g.taken_at.slice(0, 10)) }) });
  const ordered = [g.assets.find((a) => a.id === keepId), ...g.assets.filter((a) => a.id !== keepId)].filter(Boolean);
  for (const a of ordered) {
    const best = a.id === keepId;
    const del = tr.has(a.id);
    const shot = h('button', {
      class: 'cl-shot' + (best ? ' is-best' : '') + (del ? ' is-trash' : ' is-keep'),
      'aria-pressed': best ? null : (del ? 'true' : 'false'),
      'aria-label': `${a.filename} — ${best ? t('cl_best') : del ? t('cl_trash_mark') : t('cl_kept')}`,
      title: best ? t('cl_review') : (del ? t('cl_keep') : t('cl_trash_mark')),
      onclick: () => {
        if (best) { openCompare(g, a.id); return; }
        if (del) tr.delete(a.id); else tr.add(a.id);
        state.focus = index;
        redrawGroup(g);
      },
    },
      h('img', { src: thumbUrl(a.id), alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' }),
      best ? h('span', { class: 'cl-best-badge' }, icon('check'), h('span', {}, t('cl_best'))) : h('span', { class: 'cl-mark', 'aria-hidden': 'true' }, icon(del ? 'trash' : 'check')),
      h('span', { class: 'cl-shot-info' }, `${a.width || '?'}×${a.height || '?'} · ${formatBytes(a.size)}`),
      sharpLabel(a.quality?.sharpness) === 'blur' ? h('span', { class: 'cl-shot-tag' }, t('cl_blur_tag')) : null);
    strip.append(shot);
  }
  const nTrash = tr.size;
  const bytes = g.assets.filter((a) => tr.has(a.id)).reduce((n, a) => n + a.size, 0);
  const why = keepId === g.keep_id && g.keep_reasons.length ? g.keep_reasons.map((r) => t('cl_why_' + r)).join(' · ') : null;
  return h('article', { class: 'cl-group', tabindex: '0', dataset: { id: g.id, reason: g.reason }, 'aria-label': `${t('cl_reason_' + g.reason)} — ${t('cl_group_meta', { n: g.assets.length, date: formatDate(g.taken_at.slice(0, 10)) })}` },
    h('header', { class: 'cl-group-head' },
      h('div', { class: 'cl-group-title' },
        h('span', { class: 'cl-chip', dataset: { reason: g.reason } }, t('cl_reason_' + g.reason)),
        h('span', { class: 'cl-group-meta' }, t('cl_group_meta', { n: num(g.assets.length), date: formatDate(g.taken_at.slice(0, 10)) })),
        nTrash ? h('span', { class: 'cl-group-save' }, t('cl_group_save', { bytes: formatBytes(bytes) })) : null),
      h('div', { class: 'cl-group-actions' },
        h('button', { class: 'btn btn-ghost', onclick: () => openCompare(g) }, icon('eye'), h('span', {}, t('cl_review'))),
        h('button', { class: 'btn btn-ghost', onclick: () => dismissGroup(g) }, h('span', {}, t('cl_different'))),
        h('button', { class: 'btn btn-soft cl-apply', disabled: nTrash ? null : true, onclick: () => applyGroup(g) }, icon('trash'), h('span', {}, t('cl_apply_group'))))),
    strip,
    why ? h('p', { class: 'cl-why' }, h('span', { class: 'cl-why-k' }, t('cl_best') + ':'), ' ', why) : null);
}

function redrawGroup(g) {
  const el = state.box.querySelector(`.cl-group[data-id="${g.id}"]`);
  if (!el) return draw();
  const idx = state.data.groups.indexOf(g);
  const fresh = groupCard(g, idx);
  const hadFocus = el.contains(document.activeElement);
  const focusedLabel = document.activeElement?.getAttribute?.('aria-label')?.split(' — ')[0];
  el.replaceWith(fresh);
  if (hadFocus) {
    const again = [...fresh.querySelectorAll('.cl-shot')].find((b) => b.getAttribute('aria-label').startsWith(focusedLabel + ' —'));
    (again || fresh).focus();
  }
}

async function applyGroup(g) {
  const trash = groupTrash(g);
  if (!trash.length) { toast(t('cl_nothing_marked')); return; }
  await resolve([{ keep: groupKeep(g), trash }], trash, g.assets.filter((a) => trash.includes(a.id)).reduce((n, a) => n + a.size, 0));
}

async function dismissGroup(g) {
  try {
    await api('POST', 'cleanup/dismiss', { kind: 'group', ids: g.assets.map((a) => a.id) });
    toast(t('cl_dismissed'));
    removeGroup(g);
  } catch (e) { toast(e.message); }
}

function removeGroup(g) {
  const i = state.data.groups.indexOf(g);
  state.data.groups.splice(i, 1);
  state.data.stats.groups = state.data.groups.length;
  state.data.stats.potential_bytes = state.data.groups.reduce((n, x) => n + x.bytes, 0);
  state.focus = Math.max(0, i);
  state.focusAfter = true;
  draw();
}

async function applyAll() {
  const groups = state.data.groups.filter((g) => groupTrash(g).length);
  const ids = groups.flatMap(groupTrash);
  if (!ids.length) { toast(t('cl_nothing_marked')); return; }
  const bytes = groups.reduce((n, g) => n + g.assets.filter((a) => state.trash.get(g.id).has(a.id)).reduce((m, a) => m + a.size, 0), 0);
  const ok = await confirmDialog(t('cl_apply_all_title'), t('cl_apply_all_text', { n: num(ids.length), groups: num(groups.length), bytes: formatBytes(bytes) }), t('cl_move_to_trash'), true);
  if (!ok) return;
  await resolve(groups.map((g) => ({ keep: groupKeep(g), trash: groupTrash(g) })), ids, bytes);
}

/** Grupları ≤ MAX_IDS_PER_CALL kimliklik parçalar halinde gönderir. */
async function resolve(parts, ids, bytes) {
  const btns = state.box.querySelectorAll('.cl-apply, .cl-apply-all');
  btns.forEach((b) => { b.disabled = true; });
  let trashed = 0;
  let skipped = 0;
  try {
    let batch = [], size = 0;
    const flush = async () => {
      if (!batch.length) return;
      const r = await api('POST', 'cleanup/resolve', { groups: batch });
      trashed += r.trashed;
      skipped += r.skipped || 0;
      batch = []; size = 0;
    };
    for (const p of parts) {
      const n = p.keep.length + p.trash.length;
      if (size + n > MAX_IDS_PER_CALL) await flush();
      batch.push(p); size += n;
    }
    await flush();
    toast(skipped ? t('cl_skipped', { n: num(skipped) }) : t('cl_moved', { n: num(trashed), bytes: formatBytes(bytes) }));
    store.remove(ids);
  } catch (e) {
    toast(e.message);
  }
  for (const p of parts) {
    for (const id of p.trash) state.trash.forEach((set) => set.delete(id));
  }
  state.focusAfter = true;
  await reload();
}

/* ------------------------------------------------------- karşılaştır */

function facts(a) {
  const s = sharpLabel(a.quality?.sharpness);
  return [
    ['res', `${a.width || '?'} × ${a.height || '?'}`, (a.width || 0) * (a.height || 0)],
    ['size', formatBytes(a.size), a.size],
    ['sharp', s ? t('cl_sharp_' + s) : '—', a.quality?.sharpness ?? -1],
    ['date', a.taken_local ? formatDate(a.taken_local.slice(0, 10)) : formatDate(a.taken_at.slice(0, 10)), null],
    ['place', a.latitude !== null ? t('cl_yes') : t('cl_no'), a.latitude !== null ? 1 : 0],
  ];
}

function factList(a, other) {
  const of = other ? facts(other) : null;
  return h('dl', { class: 'cl-facts' }, ...facts(a).map(([k, v, score], i) => h('div', {
    class: 'cl-fact' + (of && score !== null && of[i][2] !== null && score > of[i][2] ? ' is-better' : ''), dataset: { fact: k },
  }, h('dt', {}, t('cl_' + k)), h('dd', {}, v))));
}

/** Karşılaştırma katmanı: solda "En iyisi", sağda gezilen aday; film şeridi, klavye kısayolları. */
function openCompare(g, startId = null) {
  const prevFocus = document.activeElement;
  const others = () => g.assets.filter((a) => a.id !== state.keep.get(g.id));
  let cur = Math.max(0, others().findIndex((a) => a.id === startId));
  const layer = h('div', { class: 'cl-cmp-layer' });
  const dlg = h('section', { class: 'cl-cmp', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'cl-cmp-title', tabindex: '-1' });
  layer.append(h('div', { class: 'cl-cmp-backdrop', onclick: close }), dlg);
  document.body.append(layer);
  document.body.classList.add('no-scroll');

  function side(a, best) {
    const tr = state.trash.get(g.id);
    const del = !best && tr.has(a.id);
    const img = h('img', { src: previewUrl(a.id), alt: a.filename, decoding: 'async', draggable: 'false' });
    img.addEventListener('load', () => img.classList.add('in'), { once: true });
    const other = best ? others()[cur] : g.assets.find((x) => x.id === state.keep.get(g.id));
    return h('figure', { class: 'cl-cmp-side' + (best ? ' is-best' : '') + (del ? ' is-trash' : ''), dataset: { id: a.id } },
      h('div', { class: 'cl-cmp-media', style: `background-image:url(${thumbUrl(a.id)})` }, img,
        best ? h('span', { class: 'cl-best-badge' }, icon('check'), h('span', {}, t('cl_best'))) : h('span', { class: 'cl-state-pill' + (del ? ' is-trash' : '') }, icon(del ? 'trash' : 'check'), h('span', {}, del ? t('cl_trash_mark') : t('cl_kept')))),
      h('figcaption', {},
        h('div', { class: 'cl-cmp-name', title: a.filename }, a.filename),
        factList(a, other),
        best ? h('div', { class: 'cl-cmp-actions' }, h('span', { class: 'cl-cmp-keepnote' }, icon('check'), t('cl_best_note'))) : h('div', { class: 'cl-cmp-actions' },
          h('button', { class: 'btn' + (del ? '' : ' btn-soft'), 'aria-pressed': del ? 'false' : 'true', onclick: () => setTrash(a.id, false) }, icon('check'), h('span', {}, t('cl_keep'))),
          h('button', { class: 'btn' + (del ? ' btn-danger' : ''), 'aria-pressed': del ? 'true' : 'false', onclick: () => setTrash(a.id, true) }, icon('trash'), h('span', {}, t('cl_trash_mark'))),
          h('button', { class: 'btn btn-ghost', onclick: () => makeBest(a.id) }, h('span', {}, t('cl_make_best'))))));
  }

  function render() {
    const list = others();
    cur = Math.min(cur, list.length - 1);
    const best = g.assets.find((a) => a.id === state.keep.get(g.id));
    const cand = list[cur];
    const tr = state.trash.get(g.id);
    const film = h('div', { class: 'cl-film', role: 'listbox', 'aria-label': t('cl_compare_title') },
      ...list.map((a, i) => h('button', {
        class: 'cl-film-item' + (i === cur ? ' is-on' : '') + (tr.has(a.id) ? ' is-trash' : ''), role: 'option', 'aria-selected': i === cur ? 'true' : 'false',
        'aria-label': `${a.filename} — ${tr.has(a.id) ? t('cl_trash_mark') : t('cl_kept')}`,
        onclick: () => { cur = i; render(); },
      }, h('img', { src: thumbUrl(a.id), alt: '', loading: 'lazy' }), h('span', { class: 'cl-mark', 'aria-hidden': 'true' }, icon(tr.has(a.id) ? 'trash' : 'check')))));
    dlg.replaceChildren(
      h('header', { class: 'cl-cmp-head' },
        h('div', {}, h('h2', { id: 'cl-cmp-title' }, t('cl_compare_title')),
          h('p', { class: 'muted' }, `${t('cl_reason_' + g.reason)} · ${t('cl_group_meta', { n: num(g.assets.length), date: formatDate(g.taken_at.slice(0, 10)) })}`)),
        h('span', { class: 'cl-cmp-count' }, t('cl_compare_of', { i: cur + 1, n: list.length })),
        h('button', { class: 'icon-btn cl-cmp-close', 'aria-label': t('close'), onclick: close }, icon('close'))),
      h('div', { class: 'cl-cmp-stage' }, side(best, true), cand ? side(cand, false) : null),
      film,
      h('footer', { class: 'cl-cmp-foot' },
        h('span', { class: 'cl-kbd muted', 'aria-hidden': 'true' }, t('cl_kbd_hint')),
        h('button', { class: 'btn btn-ghost', onclick: () => { tr.clear(); render(); } }, t('cl_keep_all')),
        h('button', { class: 'btn btn-primary', disabled: tr.size ? null : true, onclick: async () => { close(); await applyGroup(g); } }, icon('trash'), h('span', {}, t('cl_apply_n', { n: tr.size })))));
    dlg.querySelector('.cl-film-item.is-on')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  function setTrash(id, on) {
    const tr = state.trash.get(g.id);
    if (on) tr.add(id); else tr.delete(id);
    render();
  }

  function makeBest(id) {
    const tr = state.trash.get(g.id);
    const old = state.keep.get(g.id);
    state.keep.set(g.id, id);
    tr.delete(id);
    tr.add(old);
    cur = Math.max(0, others().findIndex((a) => a.id === old));
    render();
  }

  function onKey(e) {
    if (e.target.closest?.('input, textarea')) return;
    const k = e.key.toLowerCase();
    const list = others();
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); cur = (cur + 1) % list.length; render(); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); cur = (cur - 1 + list.length) % list.length; render(); }
    else if (k === 't' || k === 'k') { e.preventDefault(); setTrash(list[cur].id, false); }
    else if (k === 's' || k === 'd' || e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); setTrash(list[cur].id, true); }
    else if (k === 'e' || k === 'b') { e.preventDefault(); makeBest(list[cur].id); }
    else if (e.key === 'Enter' && !e.target.closest('button')) { e.preventDefault(); close(); applyGroup(g); }
    else if (e.key === 'Tab') {
      const f = [...dlg.querySelectorAll('button:not([disabled])')];
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  }

  function close() {
    document.removeEventListener('keydown', onKey, true);
    layer.remove();
    document.body.classList.remove('no-scroll');
    redrawGroup(g);
    if (prevFocus?.isConnected) prevFocus.focus();
    else state.box.querySelector(`.cl-group[data-id="${g.id}"]`)?.focus();
  }

  document.addEventListener('keydown', onKey, true);
  render();
  requestAnimationFrame(() => layer.classList.add('in'));
  dlg.focus();
}

/* ---------------------------------------------------------- listeler */

function drawList(panel, k) {
  const list = TABS.find(([x]) => x === k)[1];
  const items = state.data[list];
  const pick = state.pick.get(k);
  const totalCount = k === 'videos' ? state.data.stats.short_videos : state.data.stats[k];
  if (!items.length) {
    panel.append(h('div', { class: 'cl-empty' }, icon('check'), h('p', {}, t('cl_empty_' + k))));
    return;
  }
  const selBytes = items.filter((a) => pick.has(a.id)).reduce((n, a) => n + a.size, 0);
  const all = pick.size === items.length;
  panel.append(h('div', { class: 'cl-toolbar' },
    h('span', { class: 'cl-toolbar-count', role: 'status' }, t('cl_selected_bytes', { n: num(pick.size), bytes: formatBytes(selBytes) })),
    h('button', { class: 'btn btn-ghost', onclick: () => { state.pick.set(k, new Set(all ? [] : items.map((a) => a.id))); draw(); } }, t(all ? 'cl_select_none' : 'cl_select_all')),
    h('button', { class: 'btn btn-primary', disabled: pick.size ? null : true, onclick: () => trashSelected(k, items) }, icon('trash'), h('span', {}, t('cl_trash_selected')))));
  const grid = h('div', { class: 'cl-grid', role: 'group', 'aria-label': t('cl_tab_' + k) });
  for (const a of items) {
    const on = pick.has(a.id);
    let tag = null;
    if (k === 'blurry') tag = t('cl_blur_tag');
    else if (k === 'dark') tag = t(a.why === 'bright' ? 'cl_why_bright' : 'cl_why_dark');
    else if (k === 'videos') tag = `${(a.duration_ms / 1000).toLocaleString(loc(), { maximumFractionDigits: 1 })} sn`;
    const tile = h('div', { class: 'cl-tile' + (on ? ' is-on' : ''), dataset: { id: a.id } },
      h('button', {
        class: 'cl-tile-main', 'aria-pressed': on ? 'true' : 'false', 'aria-label': `${a.filename}${tag ? ' — ' + tag : ''}`,
        onclick: () => { if (on) pick.delete(a.id); else pick.add(a.id); draw(); focusTile(a.id); },
      }, h('img', { src: thumbUrl(a.id), alt: '', loading: 'lazy', decoding: 'async', draggable: 'false' }),
        h('span', { class: 'cl-check', 'aria-hidden': 'true' }, icon('check')),
        tag ? h('span', { class: 'cl-tile-tag' }, tag) : null),
      h('div', { class: 'cl-tile-actions' },
        h('button', { class: 'cl-tile-btn', title: t('cl_zoom'), 'aria-label': `${t('cl_zoom')}: ${a.filename}`, onclick: () => zoom(items, a.id) }, icon('search')),
        h('button', { class: 'cl-tile-btn', title: t('cl_good'), 'aria-label': `${t('cl_good')}: ${a.filename}`, onclick: () => dismissItem(k, a) }, icon('eyeOff'))));
    grid.append(tile);
  }
  panel.append(grid);
  if (totalCount > items.length) panel.append(h('p', { class: 'muted cl-more' }, t('cl_more_hidden', { shown: num(items.length), n: num(totalCount) })));
}

function focusTile(id) {
  state.box.querySelector(`.cl-tile[data-id="${id}"] .cl-tile-main`)?.focus();
}

function zoom(items, id) {
  store.list = items.map((a) => ({ ...a }));
  store.loadMore = null;
  openViewer(id, { onRemoved: () => reload(), onChanged: () => {} });
}

async function dismissItem(k, a) {
  try {
    await api('POST', 'cleanup/dismiss', { kind: DISMISS_KIND[k], ids: [a.id] });
    toast(t('cl_good_done'));
    await reload();
  } catch (e) { toast(e.message); }
}

async function trashSelected(k, items) {
  const ids = items.filter((a) => state.pick.get(k).has(a.id)).map((a) => a.id);
  const bytes = items.filter((a) => ids.includes(a.id)).reduce((n, a) => n + a.size, 0);
  if (!ids.length) return;
  if (!await confirmDialog(t('cl_move_to_trash'), t('cl_confirm_selected', { n: num(ids.length), bytes: formatBytes(bytes) }), t('cl_move_to_trash'), true)) return;
  const parts = [];
  for (let i = 0; i < ids.length; i += MAX_IDS_PER_CALL) parts.push({ keep: [], trash: ids.slice(i, i + MAX_IDS_PER_CALL) });
  await resolve(parts, ids, bytes);
}

async function resetDismissed() {
  try {
    const r = await api('POST', 'cleanup/reset');
    toast(t('cl_restored', { n: num(r.restored) }));
    await reload();
  } catch (e) { toast(e.message); }
}
