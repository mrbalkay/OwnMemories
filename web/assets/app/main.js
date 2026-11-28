// OwnMemories — web uygulaması girişi: yönlendirme, menü, seçim çubuğu, arama, sürükle-bırak yükleme.
import { api, qs } from './api.js';
import { h, t, icon, icons, toast, confirmDialog, formatBytes, OM } from './util.js';
import { store } from './store.js';
import { Timeline, clearAmbient } from './timeline.js';
import { SearchView } from './search.js';
import { openViewer, closeViewer } from './viewer.js';
import { enqueue, setUploadHandler } from './upload.js';

const view = document.getElementById('view');
const nav = document.getElementById('nav');
const selbar = document.getElementById('selbar');
let timeline = null;
let route = { name: 'photos' };
let renderSeq = 0; // her render() için artar; geç biten eşzamansız işler eski rotaya yazmasın

const NAV = [
  ['photos', 'photos', '#/photos'],
  ['favorites', 'heart', '#/favorites'],
  ['people', 'person', '#/people'],
  ['albums', 'album', '#/albums'],
  ['shared', 'people', '#/shared'],
  ['map', 'map', '#/map'],
  ['places', 'pin', '#/places'],
  ['archive', 'archive', '#/archive'],
  ['trash', 'trash', '#/trash'],
];

// Menü grupları: temalar başlıkları (.nav-h) gösterebilir ve öğeleri CSS `order` ile yeniden dizebilir.
const NAV_GROUP = { photos: 'library', favorites: 'library', people: 'library', map: 'library', places: 'library', albums: 'albums', shared: 'albums', archive: 'other', trash: 'other' };

function renderNav() {
  document.getElementById('navSettings')?.classList.toggle('active', route.name === 'settings');
  const heading = (g) => h('div', { class: 'nav-h', role: 'presentation', dataset: { group: g } }, t('nav_' + g));
  const items = [];
  for (const [key, ic, href] of NAV) {
    if (key === 'photos') items.push(heading('library'));
    if (key === 'albums') items.push(heading('albums'));
    if (key === 'archive') items.push(h('div', { class: 'nav-sep', role: 'presentation' }), heading('other'));
    items.push(h('a', {
      href, class: 'nav-item' + (route.name === key || (key === 'albums' && route.name === 'album' && !route.shared) || (key === 'people' && route.name === 'person') || (key === 'places' && route.name === 'place') ? ' active' : ''),
      'aria-current': route.name === key ? 'page' : null, dataset: { nav: key, group: NAV_GROUP[key] },
    }, icon(ic), h('span', { class: 'nav-label' }, t(key))));
  }
  nav.replaceChildren(...items);
}

/** Üst çubuktaki büyük sayfa başlığı (#pageTitle; yalnızca bazı temalar gösterir). */
function setPageTitle(text, sub = '') {
  document.getElementById('pageTitleText').textContent = text;
  document.getElementById('pageSub').textContent = sub;
}

function renderUsage() {
  const u = OM.user;
  const bar = document.getElementById('usageBar');
  bar.dataset.quota = u.quota_bytes ? '1' : '0';
  bar.style.setProperty('--used', u.quota_bytes ? String(Math.min(1, u.used_bytes / u.quota_bytes)) : '0');
  document.getElementById('usage').textContent = u.quota_bytes
    ? t('used_quota', { used: formatBytes(u.used_bytes), quota: formatBytes(u.quota_bytes) })
    : t('used', { used: formatBytes(u.used_bytes) });
}

/** Yönetici için sunucu yedeği uyarısı (kurulmamış / başarısız): kenar çubuğunda .me'nin üstünde. */
function renderBackupAlert() {
  document.querySelector('.backup-alert')?.remove();
  const a = OM.backup_alert;
  for (const id of ['navSettings', 'menuBtn']) {
    const el = document.getElementById(id);
    if (!el) continue;
    if (a) el.dataset.alert = a.level; else delete el.dataset.alert;
  }
  if (!a) return;
  const el = h('a', { class: 'backup-alert', href: '/admin/backup.php', dataset: { level: a.level } },
    h('span', { class: 'backup-alert-ic', 'aria-hidden': 'true', html: icons.shield }),
    h('span', { class: 'backup-alert-text' }, h('strong', {}, a.title), h('span', {}, a.text)),
    h('span', { class: 'backup-alert-cta' }, t('backup_open')));
  document.querySelector('.sidebar .me')?.before(el);
}

function parseRoute() {
  const raw = location.hash.replace(/^#\/?/, '') || 'photos';
  const [path, query = ''] = raw.split('?');
  const parts = path.split('/');
  const params = Object.fromEntries(new URLSearchParams(query));
  if (parts[0] === 'album' && parts[1]) return { name: 'album', id: parts[1] };
  if (parts[0] === 'person' && /^\d+$/.test(parts[1] || '')) return { name: 'person', id: Number(parts[1]) };
  if (parts[0] === 'place' && /^\d+$/.test(parts[1] || '')) return { name: 'place', id: Number(parts[1]) };
  return { name: parts[0] || 'photos', params };
}

/* ---------------------------------------------------------- selection */

function renderSelbar() {
  const n = store.selection.size;
  selbar.hidden = n === 0;
  if (!n) { selbar.replaceChildren(); return; }
  const ids = () => [...store.selection];
  const btn = (ic, label, fn, cls = '') => h('button', { class: 'btn btn-ghost ' + cls, onclick: fn }, icon(ic), h('span', {}, label));
  const actions = [];
  if (route.name === 'trash') {
    actions.push(btn('restore', t('restore'), () => bulk('restore', 'restored')));
    actions.push(btn('trash', t('delete_forever'), async () => {
      if (await confirmDialog(t('delete_forever'), t('confirm_purge', { n }), t('delete_forever'), true)) bulk('purge', 'purged');
    }, 'danger'));
  } else {
    actions.push(btn('album', t('add_to_album'), () => import('./albums.js').then((m) => m.addToAlbumDialog(ids(), () => store.clear()))));
    if (route.name !== 'album' || route.album?.role === 'owner') {
      actions.push(btn('personAdd', t('add_person'), () => import('./people.js').then((m) => m.addPersonDialog(ids(), () => {
        store.clear();
        if (route.name === 'person') timeline?.reset();
      }))));
    }
    if (route.name === 'person' && route.person) {
      actions.push(btn('close', t('remove_from_person'), async () => {
        const list = ids();
        let n = 0;
        for (const id of list) {
          try { await api('DELETE', `assets/${id}/people/${route.person.id}`); n++; } catch { /* yok say */ }
        }
        import('./people.js').then((m) => m.invalidatePeople());
        toast(t('removed_from_person', { n }));
        timeline?.removeIds(list);
        store.clear();
      }));
    }
    if (route.name === 'album' && route.album && (route.album.role !== 'viewer')) {
      actions.push(btn('close', t('remove_from_album'), async () => {
        try {
          const r = await api('DELETE', `albums/${route.album.id}/assets`, { ids: ids() });
          toast(t('removed_from_album', { n: r.removed }));
          timeline.removeIds(ids());
          store.clear();
        } catch (e) { toast(e.message); }
      }));
    }
    actions.push(btn('heart', t('favorite'), () => setFav(ids(), true)));
    if (route.name === 'archive') actions.push(btn('archive', t('unarchive'), () => setArchived(ids(), false)));
    else if (route.name !== 'album') actions.push(btn('archive', t('archive_action'), () => setArchived(ids(), true)));
    if (route.name !== 'album' || route.album?.role === 'owner') actions.push(btn('trash', t('to_trash'), () => bulk('trash', 'trashed')));
  }
  selbar.replaceChildren(
    h('button', { class: 'icon-btn', 'aria-label': t('clear_selection'), onclick: () => store.clear() }, icon('close')),
    h('strong', { class: 'sel-count' }, t('selected', { n })),
    h('div', { class: 'sel-actions' }, ...actions));
}

async function bulk(action, msg) {
  const ids = [...store.selection];
  const r = await api('POST', `assets/${action}`, { ids });
  toast(t(msg, { n: r.count }));
  timeline?.removeIds(ids);
  store.clear();
}

async function setFav(ids, value) {
  for (const id of ids) {
    const r = await api('PATCH', `assets/${id}`, { is_favorite: value }).catch(() => null);
    if (r) { store.update(r.asset); timeline?.refreshTile(r.asset); }
  }
  toast(t('favorited'));
  store.clear();
}

async function setArchived(ids, value) {
  for (const id of ids) await api('PATCH', `assets/${id}`, { is_archived: value }).catch(() => null);
  toast(t(value ? 'archived' : 'unarchived', { n: ids.length }));
  timeline?.removeIds(ids);
  store.clear();
}

/* --------------------------------------------------------------- views */

function viewerCtx() {
  return {
    onChanged: (a) => {
      timeline?.refreshTile(a);
      if (route.name === 'favorites' && !a.is_favorite) timeline?.removeIds([a.id]);
    },
    onRemoved: (ids) => timeline?.removeIds(ids),
    onPeopleChanged: (id, data) => {
      if (route.name === 'person' && route.person && !(data.people || []).some((p) => p.id === route.person.id)) timeline?.removeIds([id]);
    },
  };
}

function showTimeline(query, empty, header) {
  timeline = new Timeline(view, { query, empty, header, onOpen: (id) => openViewer(id, viewerCtx()) });
}

async function render() {
  const seq = ++renderSeq;
  const current = () => seq === renderSeq;
  closeViewer();
  timeline?.destroy();
  timeline = null;
  store.clear();
  route = parseRoute();
  document.getElementById('app').dataset.route = route.name;
  const TITLES = { people: 'people', person: 'people', favorites: 'favorites', archive: 'archive', trash: 'trash', search: 'search_results', albums: 'albums', shared: 'shared', settings: 'settings', map: 'map', album: 'albums', places: 'places', place: 'places' };
  setPageTitle(t(TITLES[route.name] || 'photos'));
  renderNav();
  document.getElementById('app').classList.remove('nav-open');
  view.scrollTop = 0;
  const title = (s) => h('div', { class: 'page-head' }, h('h1', {}, s));
  // Zaman çizelgesi olmayan görünümler: önceki rotadan kalan ortam rengini sıfırla.
  if (['albums', 'shared', 'settings', 'map', 'people', 'person', 'places'].includes(route.name)) clearAmbient();
  // Kendi içinde eşzamansız yazan görünümler (albüm listesi, harita) ayrı bir kutuya çizilir:
  // daha yeni bir rota #view'i değiştirirse kutu DOM'dan kopar ve geç gelen yazımlar görünmez kalır.
  const box = () => { const b = h('div', { class: 'view-box' }); view.replaceChildren(b); return b; };
  switch (route.name) {
    case 'favorites':
      showTimeline({ favorite: 1 }, t('empty_favorites'), title(t('favorites')));
      break;
    case 'archive':
      showTimeline({ archived: 1 }, t('empty_archive'), title(t('archive')));
      break;
    case 'trash':
      showTimeline({ trashed: 1 }, t('empty_trash'), h('div', { class: 'page-head' }, h('div', {}, h('h1', {}, t('trash')), h('p', { class: 'muted' }, t('trash_note')))));
      break;
    case 'search': {
      const p = route.params || {};
      document.getElementById('searchInput').value = p.q || '';
      setPageTitle(p.q ? `“${p.q}”` : t('search_title'));
      timeline = new SearchView(view, { params: p, onOpen: (id) => openViewer(id, viewerCtx()) });
      break;
    }
    case 'albums':
      import('./albums.js').then((m) => { if (current()) m.renderAlbums(box()); });
      break;
    case 'shared':
      import('./albums.js').then((m) => { if (current()) m.renderAlbums(box(), { sharedOnly: true }); });
      break;
    case 'album': {
      const m = await import('./albums.js');
      try {
        const album = (await api('GET', `albums/${route.id}`)).album;
        if (!current()) return; // bu arada başka bir rotaya geçildi
        route.album = album;
        setPageTitle(album.title || t('albums'));
        route.shared = album.role !== 'owner';
        renderNav();
        showTimeline({ album: album.id }, t('empty_album'), m.albumHeader(album, { onChanged: render }));
      } catch {
        if (!current()) return;
        clearAmbient();
        view.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
      }
      break;
    }
    case 'people':
      import('./people.js').then((m) => { if (current()) m.renderPeople(box(), { setSub: (s) => { if (current()) document.getElementById('pageSub').textContent = s; } }); });
      break;
    case 'person': {
      const m = await import('./people.js');
      try {
        const person = (await api('GET', `people/${route.id}`)).person;
        if (!current()) return;
        route.person = person;
        setPageTitle(m.personName(person), t('person_photos', { n: person.count.toLocaleString(OM.locale === 'en' ? 'en-GB' : 'tr-TR') }));
        showTimeline({ person: person.id }, t('empty_person'), m.personHeader(person, {
          onChanged: render,
          onGone: (into) => { if (into?.id && into.id !== person.id) location.hash = `#/person/${into.id}`; else render(); },
        }));
      } catch {
        if (!current()) return;
        view.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('person_not_found')), h('a', { class: 'btn', href: '#/people' }, t('people'))));
      }
      break;
    }
    case 'places':
      import('./places.js').then((m) => { if (current()) m.renderPlaces(box(), { setSub: (s) => { if (current()) document.getElementById('pageSub').textContent = s; } }); });
      break;
    case 'place': {
      const m = await import('./places.js');
      try {
        const place = (await api('GET', `places/${route.id}`)).place;
        if (!current()) return;
        route.place = place;
        setPageTitle(place.name, t('place_photos', { n: place.count.toLocaleString(OM.locale === 'en' ? 'en-GB' : 'tr-TR') }));
        showTimeline({ place: place.id }, t('empty_photos'), m.placeHeader(place));
      } catch {
        if (!current()) return;
        clearAmbient();
        view.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('place_not_found')), h('a', { class: 'btn', href: '#/places' }, t('places'))));
      }
      break;
    }
    case 'settings':
      import('./settings.js').then((m) => { if (current()) m.renderSettings(view); });
      break;
    case 'map':
      import('./map.js').then((m) => current() && m.renderMap(box(), (id, points) => {
        store.list = points.map((p) => ({ id: p.id, type: 'photo' }));
        store.loadMore = null;
        openViewer(id, {});
      }));
      break;
    default: {
      route.name = 'photos';
      document.getElementById('app').dataset.route = 'photos';
      renderNav();
      const head = h('div', { class: 'mem-slot' });
      showTimeline({}, t('empty_photos'), head);
      import('./memories.js').then(async (m) => {
        const strip = await m.memoriesStrip(viewerCtx);
        if (strip && head.isConnected) head.replaceWith(strip);
      });
    }
  }
  view.focus({ preventScroll: true });
}

/* ------------------------------------------------------------- wiring */

store.onChange(renderSelbar);
window.addEventListener('hashchange', render);
document.getElementById('menuBtn').addEventListener('click', () => document.getElementById('app').classList.toggle('nav-open'));
document.getElementById('searchForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const q = document.getElementById('searchInput').value.trim();
  location.hash = '#/search' + qs({ q });
});
document.getElementById('fileInput').addEventListener('change', (e) => { enqueue([...e.target.files]); e.target.value = ''; });
setUploadHandler(() => {
  clearTimeout(setUploadHandler._t);
  setUploadHandler._t = setTimeout(async () => {
    try { OM.user = (await api('GET', 'me')).user; renderUsage(); } catch { /* yok say */ }
    if (route.name === 'photos') timeline?.reset();
  }, 1500);
});

const hint = document.getElementById('dropHint');
let dragDepth = 0;
window.addEventListener('dragenter', (e) => { if (e.dataTransfer?.types?.includes('Files')) { dragDepth++; hint.hidden = false; } });
window.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; hint.hidden = true; } });
window.addEventListener('dragover', (e) => e.preventDefault());
window.addEventListener('drop', (e) => {
  e.preventDefault();
  dragDepth = 0;
  hint.hidden = true;
  if (e.dataTransfer?.files?.length) enqueue([...e.dataTransfer.files]);
});
// Dar ekranda uzun yer tutucu kesiliyor; kısa olanı kullan.
{
  const input = document.getElementById('searchInput');
  const mq = matchMedia('(max-width: 600px)');
  const full = input.placeholder;
  const sync = () => { input.placeholder = mq.matches ? t('search_placeholder_short') : full; };
  mq.addEventListener('change', sync);
  sync();
}
document.addEventListener('keydown', (e) => {
  // "/" arama kutusuna odaklanır (.search-kbd ipucu)
  if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.target.closest?.('input, textarea, select, [contenteditable]') && !document.querySelector('.viewer, .story, dialog[open]')) {
    e.preventDefault();
    document.getElementById('searchInput').focus();
    return;
  }
  if (e.key === 'Escape' && store.selection.size && !document.querySelector('.viewer, .story, dialog[open]')) store.clear();
});

renderUsage();
renderBackupAlert();
render();
