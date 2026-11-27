// OwnMemories — web uygulaması girişi: yönlendirme, menü, seçim çubuğu, arama, sürükle-bırak yükleme.
import { api, qs } from './api.js';
import { h, t, icon, toast, confirmDialog, formatBytes, OM } from './util.js';
import { store } from './store.js';
import { Timeline } from './timeline.js';
import { openViewer, closeViewer } from './viewer.js';
import { enqueue, setUploadHandler } from './upload.js';

const view = document.getElementById('view');
const nav = document.getElementById('nav');
const selbar = document.getElementById('selbar');
let timeline = null;
let route = { name: 'photos' };

const NAV = [
  ['photos', 'photos', '#/photos'],
  ['favorites', 'heart', '#/favorites'],
  ['albums', 'album', '#/albums'],
  ['shared', 'people', '#/shared'],
  ['map', 'map', '#/map'],
  ['archive', 'archive', '#/archive'],
  ['trash', 'trash', '#/trash'],
];

function renderNav() {
  nav.replaceChildren(...NAV.map(([key, ic, href]) => h('a', {
    href, class: 'nav-item' + (route.name === key || (key === 'albums' && route.name === 'album' && !route.shared) ? ' active' : ''),
    'aria-current': route.name === key ? 'page' : null,
  }, icon(ic), h('span', {}, t(key)))));
}

function renderUsage() {
  const u = OM.user;
  document.getElementById('usage').textContent = u.quota_bytes
    ? t('used_quota', { used: formatBytes(u.used_bytes), quota: formatBytes(u.quota_bytes) })
    : t('used', { used: formatBytes(u.used_bytes) });
}

function parseRoute() {
  const raw = location.hash.replace(/^#\/?/, '') || 'photos';
  const [path, query = ''] = raw.split('?');
  const parts = path.split('/');
  const params = Object.fromEntries(new URLSearchParams(query));
  if (parts[0] === 'album' && parts[1]) return { name: 'album', id: parts[1] };
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
  };
}

function showTimeline(query, empty, header) {
  timeline = new Timeline(view, { query, empty, header, onOpen: (id) => openViewer(id, viewerCtx()) });
}

async function render() {
  closeViewer();
  timeline?.destroy();
  timeline = null;
  store.clear();
  route = parseRoute();
  renderNav();
  document.getElementById('app').classList.remove('nav-open');
  view.scrollTop = 0;
  const title = (s) => h('div', { class: 'page-head' }, h('h1', {}, s));
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
      const from = h('input', { type: 'date', value: p.from || '', 'aria-label': t('from') });
      const to = h('input', { type: 'date', value: p.to || '', 'aria-label': t('to') });
      const go = () => { location.hash = '#/search' + qs({ q: p.q, from: from.value, to: to.value }); };
      const head = h('div', { class: 'page-head' }, h('h1', {}, t('search_results')),
        h('div', { class: 'filters' }, h('label', {}, t('from'), from), h('label', {}, t('to'), to), h('button', { class: 'btn btn-soft', onclick: go }, t('filter'))));
      showTimeline({ q: p.q, from: p.from ? p.from + 'T00:00:00Z' : '', to: p.to ? p.to + 'T23:59:59Z' : '' }, t('empty_search'), head);
      break;
    }
    case 'albums':
      import('./albums.js').then((m) => m.renderAlbums(view));
      break;
    case 'shared':
      import('./albums.js').then((m) => m.renderAlbums(view, { sharedOnly: true }));
      break;
    case 'album': {
      const m = await import('./albums.js');
      try {
        const album = (await api('GET', `albums/${route.id}`)).album;
        route.album = album;
        route.shared = album.role !== 'owner';
        renderNav();
        showTimeline({ album: album.id }, t('empty_album'), m.albumHeader(album, { onChanged: render }));
      } catch {
        view.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
      }
      break;
    }
    case 'map':
      import('./map.js').then((m) => m.renderMap(view, (id, points) => {
        store.list = points.map((p) => ({ id: p.id, type: 'photo' }));
        store.loadMore = null;
        openViewer(id, {});
      }));
      break;
    default: {
      route.name = 'photos';
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
  location.hash = q ? '#/search' + qs({ q }) : '#/photos';
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
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && store.selection.size && !document.querySelector('.viewer, dialog[open]')) store.clear();
});

renderUsage();
render();
