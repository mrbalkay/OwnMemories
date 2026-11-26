// OwnMemories — albüm listesi, albüm sayfası, albüme ekleme ve paylaşma pencereleri.
import { api, thumbUrl } from './api.js';
import { h, t, icon, toast, modal, confirmDialog, promptDialog, OM } from './util.js';

export async function renderAlbums(root, { sharedOnly = false } = {}) {
  root.replaceChildren(h('div', { class: 'tl-status' }, h('span', { class: 'spinner' }), t('loading')));
  let albums;
  try {
    albums = (await api('GET', 'albums')).albums;
  } catch {
    root.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
    return;
  }
  if (sharedOnly) albums = albums.filter((a) => a.shared);
  const head = h('div', { class: 'page-head' }, h('h1', {}, sharedOnly ? t('shared') : t('albums')));
  if (!sharedOnly) {
    head.append(h('button', { class: 'btn btn-soft', onclick: async () => {
      const title = await promptDialog(t('new_album'), t('album_title'), '', t('create'));
      if (!title) return;
      const r = await api('POST', 'albums', { title });
      location.hash = `#/album/${r.album.id}`;
    } }, icon('plus'), t('new_album')));
  }
  const grid = h('div', { class: 'album-grid' });
  for (const a of albums) {
    const cover = a.cover_asset_id ? h('img', { src: thumbUrl(a.cover_asset_id), alt: '', loading: 'lazy' }) : h('div', { class: 'album-empty' }, icon('album'));
    grid.append(h('a', { class: 'album-card', href: `#/album/${a.id}` },
      h('div', { class: 'album-cover' }, cover, a.shared ? h('span', { class: 'badge shared' }, icon('people')) : null),
      h('div', { class: 'album-title' }, a.title),
      h('div', { class: 'album-meta' }, a.role === 'owner' ? t('items', { n: a.count }) : t('shared_by', { name: a.owner_name }))));
  }
  root.replaceChildren(head, albums.length ? grid : h('div', { class: 'empty' }, h('p', {}, sharedOnly ? t('empty_shared') : t('empty_albums'))));
}

/** Albüm başlığı + araçlar (zaman çizelgesi üstüne konur). */
export function albumHeader(album, { onChanged }) {
  const isOwner = album.role === 'owner';
  const tools = h('div', { class: 'page-tools' });
  if (isOwner) {
    tools.append(
      h('button', { class: 'btn btn-soft', onclick: () => shareDialog(album, onChanged) }, icon('share'), t('share')),
      h('button', { class: 'btn btn-ghost', onclick: async () => {
        const title = await promptDialog(t('rename'), t('album_title'), album.title);
        if (!title) return;
        await api('PATCH', `albums/${album.id}`, { title });
        onChanged();
      } }, t('rename')),
      h('button', { class: 'btn btn-ghost danger', onclick: async () => {
        if (!(await confirmDialog(t('delete_album'), t('confirm_delete_album', { t: album.title }), t('delete_album'), true))) return;
        await api('DELETE', `albums/${album.id}`);
        toast(t('album_deleted'));
        location.hash = '#/albums';
      } }, t('delete_album')));
  } else {
    tools.append(h('button', { class: 'btn btn-ghost', onclick: async () => {
      if (!(await confirmDialog(t('leave_album'), album.title, t('leave_album'), true))) return;
      await api('DELETE', `albums/${album.id}/members/${OM.user.id}`);
      location.hash = '#/shared';
    } }, t('leave_album')));
  }
  const members = album.members?.length
    ? h('div', { class: 'members' }, icon('people'), album.members.map((m) => m.name).join(', '))
    : null;
  return h('div', { class: 'page-head album-head' },
    h('div', {},
      h('h1', {}, album.title),
      h('div', { class: 'muted' }, isOwner ? t('items', { n: album.count }) : t('shared_by', { name: album.owner_name })),
      members),
    tools);
}

export async function shareDialog(album, onChanged) {
  const email = h('input', { type: 'email', required: true, autocomplete: 'off' });
  const role = h('select', {}, h('option', { value: 'viewer' }, t('role_viewer')), h('option', { value: 'contributor' }, t('role_contributor')));
  const list = h('ul', { class: 'member-list' });
  const renderMembers = (members) => {
    list.replaceChildren(...members.map((m) => h('li', {},
      h('span', {}, m.name, ' ', h('span', { class: 'muted' }, m.email)),
      h('span', { class: 'muted' }, t('role_' + m.role)),
      h('button', { type: 'button', class: 'linklike danger', onclick: async () => {
        await api('DELETE', `albums/${album.id}/members/${m.user_id}`);
        const r = await api('GET', `albums/${album.id}`);
        renderMembers(r.album.members);
      } }, t('remove')))));
  };
  renderMembers(album.members || []);
  const body = h('div', {},
    h('label', { class: 'field' }, h('span', {}, t('share_email')), email),
    h('label', { class: 'field' }, h('span', {}, ''), role),
    album.members?.length ? h('h3', {}, t('shared_with')) : null, list);
  const r = await modal(t('share_title'), body, [{ label: t('cancel'), value: 'cancel' }, { label: t('share'), value: 'ok', primary: true }]);
  if (r !== 'ok' || !email.value.trim()) { onChanged(); return; }
  try {
    await api('POST', `albums/${album.id}/members`, { email: email.value.trim(), role: role.value });
    toast(t('saved'));
  } catch (e) {
    toast(e.message);
  }
  onChanged();
}

/** Seçili asset'leri bir albüme ekler (veya yeni albüm oluşturur). */
export async function addToAlbumDialog(ids, done) {
  const albums = (await api('GET', 'albums')).albums.filter((a) => a.role === 'owner' || a.role === 'contributor');
  const select = h('select', {}, h('option', { value: '' }, t('choose_album')), ...albums.map((a) => h('option', { value: a.id }, a.title)));
  const title = h('input', { type: 'text', placeholder: t('album_title'), maxlength: 255 });
  const body = h('div', {}, h('label', { class: 'field' }, h('span', {}, t('choose_album')), select),
    h('label', { class: 'field' }, h('span', {}, t('or_new_album')), title));
  const r = await modal(t('add_to_album'), body, [{ label: t('cancel'), value: 'cancel' }, { label: t('add_to_album'), value: 'ok', primary: true }]);
  if (r !== 'ok') return;
  let albumId = select.value;
  if (!albumId && title.value.trim()) albumId = (await api('POST', 'albums', { title: title.value.trim() })).album.id;
  if (!albumId) return;
  const res = await api('POST', `albums/${albumId}/assets`, { ids });
  toast(t('added_to_album', { n: res.added }));
  done?.();
}
