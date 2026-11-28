// OwnMemories — Kişiler: kişi listesi, kişi sayfası, fotoğrafa kişi ekleme (elle) ve tanınan yüzleri adlandırma.
// Sunucu: /v1/people, /v1/assets/{id}/people, /v1/faces/{id}. Görüntüleyici bilgi paneli ve seçim çubuğu buradaki
// yardımcıları kullanır.
import { api, thumbUrl, ApiError } from './api.js';
import { h, t, icon, toast, modal, confirmDialog, OM } from './util.js';
import { setAmbientFrom } from './theme.js';

const loc = () => (OM.locale === 'en' ? 'en-GB' : 'tr-TR');
const num = (n) => Number(n || 0).toLocaleString(loc());
const fold = (s) => (s || '').toLocaleLowerCase(loc()).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ı/g, 'i');

export const faceThumbUrl = (id) => `/api/v1/faces/${id}/thumb`;

/* ------------------------------------------------------------ önbellek */

let cache = null; // Promise<people[]> (gizliler dahil)
/** Kişi listesi (gizliler dahil; isimli + isimsiz kümeler). Değişiklik sonrası invalidatePeople() çağrılır. */
export function loadPeople(force = false) {
  if (!cache || force) {
    cache = api('GET', 'people?include_hidden=1').then((r) => r.people).catch((e) => { cache = null; throw e; });
  }
  return cache;
}
export function invalidatePeople() { cache = null; }

/** Kişinin görünen adı. */
export const personName = (p) => (p?.name ? p.name : t('unnamed_person'));

/* ------------------------------------------------------------- avatar */

function initials(name) {
  const parts = (name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '';
  return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toLocaleUpperCase(loc());
}

/**
 * Yuvarlak yüz avatarı: yüz kırpması (face id) > kişinin son fotoğrafı > baş harfler / simge.
 * @param {{name?: string|null, cover_face_id?: number|null, cover_asset_id?: string|null}} p
 * @param {{faceId?: number, cls?: string}} o
 */
export function avatar(p, o = {}) {
  const faceId = o.faceId ?? p?.cover_face_id;
  const el = h('span', { class: 'p-ava ' + (o.cls || ''), 'aria-hidden': 'true' });
  const fallback = () => {
    const ini = initials(p?.name);
    el.classList.add('is-empty');
    el.replaceChildren(ini ? h('span', { class: 'p-ini' }, ini) : icon('person'));
  };
  let src = null;
  if (faceId) src = faceThumbUrl(faceId);
  else if (p?.cover_asset_id) { src = thumbUrl(p.cover_asset_id); el.classList.add('is-photo'); }
  if (!src) { fallback(); return el; }
  const img = h('img', { src, alt: '', loading: o.eager ? null : 'lazy', decoding: 'async', draggable: 'false' });
  img.addEventListener('load', () => img.classList.add('in'), { once: true });
  img.addEventListener('error', () => {
    // Yüz kırpması üretilemediyse kişinin fotoğrafına düş.
    if (faceId && p?.cover_asset_id && !el.classList.contains('is-photo')) { el.classList.add('is-photo'); img.src = thumbUrl(p.cover_asset_id); return; }
    fallback();
  });
  el.append(img);
  return el;
}

/* -------------------------------------------------------------- seçici */

let pickerSeq = 0;
/**
 * Kişi seçici (combobox): mevcut adları önerir, yazılan yeni adla kişi oluşturmayı önerir.
 * onPick({person_id, person} | {name}) Promise dönebilir; bitene kadar giriş kilitlenir.
 * @returns {HTMLElement & {focusInput: () => void}}
 */
export function personPicker({ placeholder = t('person_search_ph'), exclude = [], allowCreate = true, onPick, onCancel, initial = '', compact = false, inline = false, createLabel = 'create_person' } = {}) {
  const id = 'pp' + (++pickerSeq);
  const input = h('input', {
    class: 'pp-input', type: 'text', value: initial, placeholder, autocomplete: 'off', spellcheck: 'false', maxlength: 190,
    role: 'combobox', 'aria-autocomplete': 'list', 'aria-expanded': 'false', 'aria-controls': id + '-list', 'aria-label': placeholder,
  });
  const list = h('ul', { class: 'pp-list' + (inline ? ' pp-list-inline' : ''), id: id + '-list', role: 'listbox', hidden: true, 'aria-label': placeholder });
  const wrap = h('div', { class: 'pp' + (compact ? ' pp-compact' : '') }, h('span', { class: 'pp-ic' }, icon('search')), input, inline ? null : list);
  // Pencere içinde liste girişin altında akışta durur (iletişim kutusunun düğmelerini örtmez).
  const host = inline ? h('div', { class: 'pp-host' }, wrap, list) : wrap;
  const avatars = new Map(); // her tuşta resimler yeniden yüklenip yanıp sönmesin
  const avaOf = (p) => { if (!avatars.has(p.id)) avatars.set(p.id, avatar(p, { eager: true })); return avatars.get(p.id); };
  // Açılır listeyi ekrana sığdır: altta yer yoksa yukarı, sağa taşıyorsa sağa hizala.
  const place = () => {
    if (inline || list.hidden) return;
    list.classList.remove('pp-up', 'pp-end');
    const r = list.getBoundingClientRect();
    const w = wrap.getBoundingClientRect();
    const vh = window.visualViewport?.height || window.innerHeight;
    if (r.bottom > vh - 8 && w.top - 8 > vh - w.bottom) list.classList.add('pp-up');
    if (r.right > document.documentElement.clientWidth - 8) list.classList.add('pp-end');
  };
  let people = [];
  let opts = [];
  let active = -1;
  let busy = false;
  const ex = new Set(exclude.map(Number));
  loadPeople().then((ps) => { people = ps.filter((p) => p.name && !ex.has(p.id)); if (document.activeElement === input) refresh(); }).catch(() => {});

  const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
  const setActive = (i) => {
    active = i;
    [...list.children].forEach((li, k) => li.setAttribute('aria-selected', k === i ? 'true' : 'false'));
    if (i >= 0 && list.children[i]) { input.setAttribute('aria-activedescendant', list.children[i].id); list.children[i].scrollIntoView({ block: 'nearest' }); }
    else input.removeAttribute('aria-activedescendant');
  };
  function refresh() {
    const q = input.value.trim();
    const fq = fold(q);
    let matches;
    if (!fq) matches = [...people].sort((a, b) => b.count - a.count).slice(0, 6);
    else {
      const scored = [];
      for (const p of people) {
        const fn = fold(p.name);
        let s = -1;
        if (fn === fq) s = 0;
        else if (fn.startsWith(fq)) s = 1;
        else if (fn.split(/\s+/).some((w) => w.startsWith(fq))) s = 2;
        else if (fn.includes(fq)) s = 3;
        if (s >= 0) scored.push([s, p]);
      }
      scored.sort((a, b) => a[0] - b[0] || b[1].count - a[1].count);
      matches = scored.slice(0, 7).map((x) => x[1]);
    }
    opts = matches.map((p) => ({ person: p }));
    if (allowCreate && q && !people.some((p) => fold(p.name) === fq) && !(initial && fold(initial) === fq)) opts.push({ create: q });
    list.replaceChildren(...opts.map((o, i) => h('li', {
      id: `${id}-o${i}`, role: 'option', class: 'pp-opt' + (o.create ? ' pp-create' : ''), 'aria-selected': 'false',
      onmousedown: (e) => e.preventDefault(), onclick: () => choose(i),
    }, o.create
      ? [h('span', { class: 'p-ava is-empty pp-plus' }, icon(createLabel === 'create_person' ? 'plus' : 'pencil')), h('span', { class: 'pp-name' }, t(createLabel, { name: o.create }))]
      : [avaOf(o.person), h('span', { class: 'pp-name' }, o.person.name), h('span', { class: 'pp-count', 'aria-label': t('person_photos', { n: num(o.person.count) }) }, num(o.person.count))])));
    const open = opts.length > 0;
    list.hidden = !open;
    input.setAttribute('aria-expanded', open ? 'true' : 'false');
    setActive(open && q ? 0 : -1);
    place();
  }
  async function choose(i) {
    if (busy) return;
    let pick;
    const o = opts[i];
    if (o?.person) pick = { person_id: o.person.id, person: o.person };
    else {
      const q = input.value.trim();
      if (!q) return;
      const same = people.find((p) => fold(p.name) === fold(q));
      pick = same ? { person_id: same.id, person: same } : (allowCreate ? { name: q } : null);
    }
    if (!pick) return;
    busy = true;
    wrap.classList.add('is-busy');
    input.disabled = true;
    close();
    try {
      await onPick?.(pick);
    } finally {
      busy = false;
      wrap.classList.remove('is-busy');
      if (wrap.isConnected) { input.disabled = false; input.value = ''; if (inline) input.focus(); }
    }
  }
  input.addEventListener('focus', refresh);
  input.addEventListener('input', refresh);
  input.addEventListener('blur', () => { if (!inline) setTimeout(close, 120); });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) refresh(); else setActive(Math.min(opts.length - 1, active + 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(-1, active - 1)); }
    else if (e.key === 'Enter') { e.preventDefault(); choose(active); }
    else if (e.key === 'Escape') {
      e.stopPropagation();
      if (!list.hidden && !inline) { e.preventDefault(); close(); }
      else if (onCancel) onCancel();
      else e.target.closest('dialog')?.close('cancel');
    }
  });
  host.focusInput = () => input.focus();
  return host;
}

/* ----------------------------------------------------------- işlemler */

/** API hata iletisini gösterir. */
const fail = (e) => toast(e?.message || t('load_failed'));

/**
 * Bir kişiyi (genellikle adsız bir yüz kümesini) seçilen kişiye göre adlandırır:
 * mevcut biri seçildiyse birleştirir, yeni adsa yeniden adlandırır; ad zaten varsa birleştirmeyi önerir.
 * @returns {Promise<object|null>} sonuçtaki kişi
 */
export async function namePerson(person, pick) {
  try {
    if (pick.person_id && pick.person_id !== person.id) {
      // Adı olan bir kişi başka biriyle birleşecekse onay al (adsız kümeyi adlandırmak doğrudan birleştirir).
      if (person.name && !(await confirmDialog(t('merge_title'), t('merge_confirm', { a: person.name, b: pick.person?.name || '' }), t('merge')))) return null;
      const r = await api('POST', `people/${person.id}/merge`, { into: pick.person_id });
      invalidatePeople();
      toast(t('merged'));
      return r.person;
    }
    const r = await api('PATCH', `people/${person.id}`, { name: pick.name ?? pick.person?.name });
    invalidatePeople();
    toast(t('saved'));
    return r.person;
  } catch (e) {
    if (e instanceof ApiError && e.code === 'name_exists') {
      const other = e.extra?.person;
      const otherId = e.extra?.person_id ?? other?.id;
      const ok = await confirmDialog(t('merge_q_title'), t('merge_q', { name: other?.name || pick.name }), t('merge'));
      if (!ok || !otherId) return null;
      return mergePeople(person.id, otherId);
    }
    fail(e);
    return null;
  }
}

export async function mergePeople(fromId, intoId) {
  try {
    const r = await api('POST', `people/${fromId}/merge`, { into: intoId });
    invalidatePeople();
    toast(t('merged'));
    return r.person;
  } catch (e) { fail(e); return null; }
}

/** Seçili fotoğraflara kişi ekler (seçim çubuğu). */
export async function addPersonDialog(ids, done) {
  let pick = null;
  let dlg;
  const hint = h('p', { class: 'muted small pp-dialog-hint' }, t('add_person_hint', { n: num(ids.length) }));
  const picker = personPicker({
    inline: true,
    onPick: (p) => { pick = p; dlg?.close('ok'); },
  });
  const body = h('div', { class: 'pp-dialog' }, hint, picker);
  const res = modal(t('add_person'), body, [{ label: t('cancel'), value: 'cancel' }]);
  dlg = body.closest('dialog');
  const r = await res;
  if (r !== 'ok' || !pick) return;
  try {
    const out = await api('POST', 'assets/people', { ids, ...(pick.person_id ? { person_id: pick.person_id } : { name: pick.name }) });
    invalidatePeople();
    toast(t('tagged', { n: num(out.count), name: personName(out.person) }));
    done?.(out);
  } catch (e) { fail(e); }
}

/** Kişiyi başka biriyle birleştirme penceresi (kişi sayfası). */
export async function mergeDialog(person) {
  let pick = null;
  let dlg;
  const picker = personPicker({ inline: true, exclude: [person.id], allowCreate: false, placeholder: t('merge_search_ph'), onPick: (p) => { pick = p; dlg?.close('ok'); } });
  const body = h('div', { class: 'pp-dialog' },
    h('div', { class: 'merge-from' }, avatar(person), h('div', {}, h('strong', {}, personName(person)), h('div', { class: 'muted small' }, t('person_photos', { n: num(person.count) })))),
    h('p', { class: 'muted small pp-dialog-hint' }, t('merge_hint')), picker);
  const res = modal(t('merge_title'), body, [{ label: t('cancel'), value: 'cancel' }]);
  dlg = body.closest('dialog');
  if ((await res) !== 'ok' || !pick) return null;
  const ok = await confirmDialog(t('merge_title'), t('merge_confirm', { a: personName(person), b: pick.person.name }), t('merge'));
  if (!ok) return null;
  return mergePeople(person.id, pick.person_id);
}

/* --------------------------------------------------------- Kişiler sayfası */

/** #/people */
export async function renderPeople(root, { setSub } = {}) {
  let showHidden = false;
  try { showHidden = localStorage.getItem('om_people_hidden') === '1'; } catch { /* yok say */ }
  const status = h('div', { class: 'people-status', hidden: true, role: 'status' });
  const toggle = h('button', { class: 'btn btn-ghost people-hidden-toggle', type: 'button', 'aria-pressed': 'false' });
  const head = h('div', { class: 'page-head people-head' }, h('div', {}, h('h1', {}, t('people')), h('p', { class: 'muted people-sub' })), toggle);
  const body = h('div', { class: 'people-body' }, h('div', { class: 'tl-status' }, h('span', { class: 'spinner' }), t('loading')));
  root.replaceChildren(head, status, body);

  const syncToggle = () => {
    toggle.setAttribute('aria-pressed', showHidden ? 'true' : 'false');
    toggle.replaceChildren(icon(showHidden ? 'eyeOff' : 'eye'), h('span', {}, showHidden ? t('hide_hidden') : t('show_hidden')));
    toggle.setAttribute('aria-label', showHidden ? t('hide_hidden') : t('show_hidden'));
    toggle.title = toggle.getAttribute('aria-label');
  };
  toggle.addEventListener('click', () => {
    showHidden = !showHidden;
    try { localStorage.setItem('om_people_hidden', showHidden ? '1' : '0'); } catch { /* yok say */ }
    syncToggle();
    draw();
  });
  syncToggle();

  let people = [];
  let scanning = false; // yüz tarama sürüyor mu (boş durum metni için)
  async function load(force = true) {
    try {
      people = await loadPeople(force);
    } catch {
      if (root.isConnected) body.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed')), h('button', { class: 'btn', onclick: () => load() }, t('retry'))));
      return;
    }
    if (root.isConnected) draw();
  }

  function draw() {
    const visible = people.filter((p) => showHidden || !p.hidden);
    const named = visible.filter((p) => p.name && (p.count > 0 || showHidden));
    const unnamed = visible.filter((p) => !p.name && p.count >= 2);
    const hiddenCount = people.filter((p) => p.hidden).length;
    toggle.hidden = hiddenCount === 0 && !showHidden;
    const sub = named.length ? t('people_n', { n: num(named.length) }) : '';
    head.querySelector('.people-sub').textContent = sub;
    setSub?.(sub);
    if (!named.length && !unnamed.length) {
      body.replaceChildren(h('div', { class: 'empty people-empty' },
        h('div', { class: 'people-empty-art', 'aria-hidden': 'true' }, h('span'), h('span'), h('span')),
        h('h2', {}, scanning ? t('people_empty_scanning_title') : t('people_empty_title')),
        h('p', {}, scanning ? t('people_empty_scanning') : t('people_empty'))));
      return;
    }
    const parts = [];
    if (named.length) {
      parts.push(h('section', { class: 'people-section', 'aria-label': t('people') },
        h('div', { class: 'people-grid', role: 'list' }, ...named.map((p) => personCard(p)))));
    }
    if (unnamed.length) {
      parts.push(h('section', { class: 'people-section people-unnamed', 'aria-labelledby': 'ppUnnamedH' },
        h('div', { class: 'people-section-head' },
          h('h2', { id: 'ppUnnamedH' }, t('unnamed'), h('span', { class: 'people-section-count' }, num(unnamed.length))),
          h('p', { class: 'muted' }, t('unnamed_sub'))),
        h('div', { class: 'people-grid is-unnamed', role: 'list' }, ...unnamed.map((p) => clusterCard(p)))));
    }
    body.replaceChildren(...parts);
  }

  function personCard(p) {
    return h('a', { class: 'person-card' + (p.hidden ? ' is-hidden' : ''), href: `#/person/${p.id}`, role: 'listitem', dataset: { id: p.id },
      'aria-label': `${p.name}, ${t('person_photos', { n: num(p.count) })}${p.hidden ? ', ' + t('hidden_badge') : ''}` },
    h('span', { class: 'person-face' }, avatar(p), p.hidden ? h('span', { class: 'person-hidden-badge' }, icon('eyeOff')) : null),
    h('span', { class: 'person-name', title: p.name }, p.name),
    h('span', { class: 'person-count' }, t('person_photos', { n: num(p.count) })));
  }

  function clusterCard(p) {
    const card = h('div', { class: 'person-card is-cluster' + (p.hidden ? ' is-hidden' : ''), role: 'listitem', dataset: { id: p.id } });
    const face = h('a', { class: 'person-face', href: `#/person/${p.id}`, 'aria-label': `${t('unnamed_person')}, ${t('person_photos', { n: num(p.count) })}` }, avatar(p));
    const count = h('span', { class: 'person-count' }, t('person_photos', { n: num(p.count) }));
    const who = h('button', { class: 'who-btn', type: 'button' }, t('who_is_this'));
    const slot = h('div', { class: 'who-slot' }, who);
    who.addEventListener('click', () => {
      const picker = personPicker({
        placeholder: t('name_ph'), compact: true,
        onPick: async (pick) => {
          const res = await namePerson(p, pick);
          if (res) { card.classList.add('is-named'); await load(); }
        },
        onCancel: () => { slot.replaceChildren(who); who.focus(); },
      });
      picker.addEventListener('focusout', () => setTimeout(() => {
        if (picker.isConnected && !picker.contains(document.activeElement) && !picker.classList.contains('is-busy')) slot.replaceChildren(who);
      }, 150));
      slot.replaceChildren(picker);
      picker.focusInput();
    });
    card.append(face, count, slot);
    return card;
  }

  // Yüz tarama durumu ("Yüzler taranıyor: 1.240 / 6.500"); sürerken ara ara yenilenir.
  let wasScanning = false;
  async function pollStatus() {
    if (!root.isConnected) return;
    let s = null;
    try { s = await api('GET', 'people/status'); } catch { /* yok say */ }
    if (!root.isConnected) return;
    const nowScanning = !!(s && s.available && s.total > 0 && s.scanned < s.total);
    if (nowScanning !== scanning) { scanning = nowScanning; if (body.querySelector('.people-empty')) draw(); }
    status.hidden = !nowScanning;
    if (nowScanning) {
      const pct = Math.max(0, Math.min(1, s.scanned / s.total));
      status.replaceChildren(
        h('span', { class: 'people-status-dot', 'aria-hidden': 'true' }),
        h('span', { class: 'people-status-text' }, t('faces_scanning', { done: num(s.scanned), total: num(s.total) })),
        h('span', { class: 'people-status-bar', 'aria-hidden': 'true', style: `--p:${pct.toFixed(4)}` }, h('i')));
      setTimeout(pollStatus, 15000);
    } else if (wasScanning) {
      load();
    }
    if (nowScanning && wasScanning) load();
    wasScanning = nowScanning;
  }

  await load(true);
  pollStatus();
}

/* ------------------------------------------------------------ Kişi sayfası */

/**
 * #/person/{id} — zaman çizelgesinin üstüne konan başlık: yüz, düzenlenebilir ad, sayı ve işlemler.
 * @param {object} person
 * @param {{onChanged: () => void, onGone: (into?: object) => void}} cb
 */
export function personHeader(person, { onChanged, onGone }) {
  const face = h('div', { class: 'person-hero-face' }, avatar(person));
  const img = face.querySelector('img');
  if (img) setAmbientFrom(img);
  const nameBox = h('div', { class: 'person-name-box' });
  const drawName = () => {
    const btn = h('button', { class: 'person-title-btn', type: 'button', title: t('rename_person'), 'aria-label': `${t('rename_person')}: ${personName(person)}` },
      h('span', { class: 'person-title-text' + (person.name ? '' : ' is-unnamed') }, person.name || t('add_name')), h('span', { class: 'person-title-edit' }, icon('pencil')));
    btn.addEventListener('click', editName);
    nameBox.replaceChildren(h('h1', { class: 'person-title' }, btn));
  };
  const editName = () => {
    const picker = personPicker({
      placeholder: t('name_ph'), exclude: [person.id], initial: person.name || '', createLabel: person.name ? 'rename_to' : 'create_person',
      onPick: async (pick) => {
        if (!pick.person_id && pick.name === person.name) { drawName(); return; }
        const res = await namePerson(person, pick);
        if (!res) { drawName(); return; }
        if (res.id !== person.id) onGone(res); else onChanged();
      },
      onCancel: () => { drawName(); nameBox.querySelector('button')?.focus(); },
    });
    picker.classList.add('person-title-picker');
    nameBox.replaceChildren(picker);
    picker.focusInput();
    picker.querySelector('input').select();
  };
  drawName();

  const actions = h('div', { class: 'person-actions' },
    h('button', { class: 'btn btn-soft', type: 'button', onclick: async () => { const r = await mergeDialog(person); if (r) onGone(r); } }, icon('merge'), h('span', {}, t('merge'))),
    h('button', { class: 'btn btn-ghost', type: 'button', onclick: async () => {
      try {
        await api('PATCH', `people/${person.id}`, { hidden: !person.hidden });
        invalidatePeople();
        toast(person.hidden ? t('person_shown') : t('person_hidden'));
        onChanged();
      } catch (e) { fail(e); }
    } }, icon(person.hidden ? 'eye' : 'eyeOff'), h('span', {}, person.hidden ? t('show_person') : t('hide_person'))),
    h('button', { class: 'btn btn-ghost danger', type: 'button', onclick: async () => {
      if (!(await confirmDialog(t('delete_person'), t('confirm_delete_person', { name: personName(person) }), t('delete_person'), true))) return;
      try {
        await api('DELETE', `people/${person.id}`);
        invalidatePeople();
        toast(t('person_deleted'));
        location.hash = '#/people';
      } catch (e) { fail(e); }
    } }, icon('trash'), h('span', {}, t('delete_person'))));

  return h('div', { class: 'person-head' + (person.hidden ? ' is-hidden' : '') },
    h('a', { class: 'person-back', href: '#/people' }, icon('left'), h('span', {}, t('people'))),
    face,
    h('div', { class: 'person-info' },
      nameBox,
      h('p', { class: 'person-meta' }, t('person_photos', { n: num(person.count) }), person.hidden ? h('span', { class: 'person-hidden-pill' }, icon('eyeOff'), t('hidden_badge')) : null),
      actions));
}

/* ------------------------------------------------- görüntüleyici: Kişiler */

/** GET /v1/assets/{id}/people */
export const fetchAssetPeople = (id) => api('GET', `assets/${id}/people`);

/**
 * Bilgi panelindeki "Kişiler" bölümü.
 * @param {object} data   assets/{id}/people yanıtı
 * @param {{assetId: string, showFaces: boolean, onData: (d)=>void, onToggleFaces: ()=>void, onHover: (faceId|null)=>void, focusFaceId?: number|null}} o
 */
export function peopleSection(data, o) {
  const sec = h('section', { class: 'i-people', 'aria-labelledby': 'iPeopleH' });
  const faces = data.faces || [];
  const faceOf = (pid) => faces.find((f) => f.person_id === pid);
  const apply = async (fn) => {
    try {
      const d = await fn();
      invalidatePeople();
      o.onData(d);
    } catch (e) { fail(e); }
  };
  const head = h('div', { class: 'i-people-head' }, h('h3', { id: 'iPeopleH' }, t('people')));
  if (faces.length) {
    head.append(h('button', { class: 'i-faces-toggle' + (o.showFaces ? ' on' : ''), type: 'button', 'aria-pressed': o.showFaces ? 'true' : 'false', onclick: o.onToggleFaces },
      icon('faceScan'), h('span', {}, o.showFaces ? t('hide_faces') : t('show_faces'))));
  }
  sec.append(head);

  const chips = h('div', { class: 'p-chips', role: 'list' });
  // Adlandırılmış ve adsız-küme kişileri
  for (const p of data.people || []) {
    const f = faceOf(p.id);
    const chip = h('span', { class: 'p-chip' + (p.name ? '' : ' is-unnamed'), role: 'listitem', dataset: { person: p.id, source: p.source } });
    if (f) {
      chip.addEventListener('mouseenter', () => o.onHover(f.id));
      chip.addEventListener('mouseleave', () => o.onHover(null));
    }
    if (p.name) {
      chip.append(h('a', { class: 'p-chip-main', href: `#/person/${p.id}`, title: t('open_person', { name: p.name }) }, avatar(p, { faceId: f?.id }), h('span', { class: 'p-chip-name' }, p.name)));
    } else {
      // Adsız yüz kümesi: adlandır
      chip.append(h('button', { class: 'p-chip-main', type: 'button', 'aria-label': `${t('unnamed_person')}: ${t('name_face')}`, title: t('unnamed_person'), onclick: () => {
        const picker = personPicker({
          placeholder: t('name_ph'), compact: true,
          onPick: async (pick) => { const r = await namePerson({ id: p.id, name: null }, pick); if (r) apply(() => fetchAssetPeople(o.assetId)); },
          onCancel: () => { picker.replaceWith(chip); chip.querySelector('.p-chip-main')?.focus(); },
        });
        chip.replaceWith(picker);
        picker.focusInput();
      } }, avatar(p, { faceId: f?.id }), h('span', { class: 'p-chip-name' }, t('name_face'))));
    }
    chip.append(h('button', { class: 'p-chip-x', type: 'button', 'aria-label': t('remove_person_from_photo', { name: personName(p) }), title: t('remove'),
      onclick: () => apply(() => api('DELETE', `assets/${o.assetId}/people/${p.id}`)) }, icon('close')));
    chips.append(chip);
  }
  // Kişi ekle
  const addBtn = h('button', { class: 'p-add', type: 'button' }, icon('plus'), h('span', {}, t('add_person')));
  const addSlot = h('div', { class: 'p-add-slot' }, addBtn);
  addBtn.addEventListener('click', () => {
    const picker = personPicker({
      exclude: (data.people || []).map((p) => p.id),
      onPick: (pick) => apply(() => api('POST', `assets/${o.assetId}/people`, pick.person_id ? { person_id: pick.person_id } : { name: pick.name })),
      onCancel: () => { addSlot.replaceChildren(addBtn); addBtn.focus(); },
    });
    picker.addEventListener('focusout', () => setTimeout(() => {
      if (picker.isConnected && !picker.contains(document.activeElement) && !picker.classList.contains('is-busy')) addSlot.replaceChildren(addBtn);
    }, 150));
    addSlot.replaceChildren(picker);
    picker.focusInput();
  });
  if ((data.people || []).length) sec.append(chips);
  // Bu fotoğrafta yüzü olmayan (elle eklenmiş) kişiler için kişinin kapağını kullan.
  if ((data.people || []).some((p) => !faceOf(p.id))) {
    loadPeople().then((all) => {
      for (const p of data.people) {
        if (faceOf(p.id)) continue;
        const full = all.find((x) => x.id === p.id);
        const old = chips.querySelector(`.p-chip[data-person="${p.id}"] .p-ava`);
        if (full && old && (full.cover_face_id || full.cover_asset_id)) old.replaceWith(avatar(full));
      }
    }).catch(() => {});
  }
  sec.append(addSlot);

  // Kimseye atanmamış yüzler
  const loose = faces.filter((f) => f.person_id === null || f.person_id === undefined);
  if (loose.length) {
    const list = h('div', { class: 'f-loose', role: 'list' });
    for (const f of loose) {
      const item = h('div', { class: 'f-item', role: 'listitem', dataset: { face: f.id } });
      item.addEventListener('mouseenter', () => o.onHover(f.id));
      item.addEventListener('mouseleave', () => o.onHover(null));
      const nameBtn = h('button', { class: 'f-name-btn', type: 'button', 'aria-label': t('name_this_face') }, t('name_face'));
      const open = () => {
        const picker = personPicker({
          placeholder: t('name_ph'), compact: true,
          onPick: (pick) => apply(() => api('PATCH', `faces/${f.id}`, pick.person_id ? { person_id: pick.person_id } : { name: pick.name })),
          onCancel: () => { picker.replaceWith(nameBtn); nameBtn.focus(); },
        });
        nameBtn.replaceWith(picker);
        picker.focusInput();
      };
      nameBtn.addEventListener('click', open);
      item.append(avatar({}, { faceId: f.id, cls: 'f-ava' }), nameBtn);
      item.openNaming = open;
      list.append(item);
    }
    sec.append(h('div', { class: 'f-loose-head muted small' }, t('detected_faces')), list);
    if (o.focusFaceId) {
      const it = [...list.children].find((x) => Number(x.dataset.face) === o.focusFaceId);
      if (it) requestAnimationFrame(() => { it.scrollIntoView({ block: 'nearest' }); it.openNaming(); });
    }
  }
  return sec;
}

/**
 * Görüntünün üstüne yüz kutuları çizer (img ile aynı ızgara hücresinde, aynı boyutta).
 * @returns {{el: HTMLElement, highlight: (faceId|null)=>void, destroy: ()=>void}}
 */
export function faceBoxes(img, data, { onPick }) {
  const layer = h('div', { class: 'v-faces', role: 'group', 'aria-label': t('faces_on_photo') });
  const size = () => {
    layer.style.width = img.clientWidth + 'px';
    layer.style.height = img.clientHeight + 'px';
  };
  for (const f of data.faces || []) {
    const label = f.person_name || (f.person_id ? t('unnamed_person') : t('unknown_face'));
    const b = h('button', {
      class: 'v-face' + (f.person_id ? (f.person_name ? ' is-named' : ' is-cluster') : ' is-unknown'), type: 'button', dataset: { face: f.id },
      style: `left:${(f.x1 * 100).toFixed(3)}%;top:${(f.y1 * 100).toFixed(3)}%;width:${((f.x2 - f.x1) * 100).toFixed(3)}%;height:${((f.y2 - f.y1) * 100).toFixed(3)}%`,
      'aria-label': f.person_name ? t('face_of', { name: f.person_name }) : t('name_this_face'),
      onclick: (e) => { e.stopPropagation(); onPick(f); },
    }, h('span', { class: 'v-face-label' }, label));
    b.addEventListener('dblclick', (e) => e.stopPropagation());
    layer.append(b);
  }
  const ro = new ResizeObserver(size);
  ro.observe(img);
  if (img.complete) size(); else img.addEventListener('load', size, { once: true });
  return {
    el: layer,
    highlight: (id) => { for (const b of layer.children) b.classList.toggle('is-hot', id !== null && Number(b.dataset.face) === id); layer.classList.toggle('has-hot', id !== null); },
    destroy: () => { ro.disconnect(); layer.remove(); },
  };
}
