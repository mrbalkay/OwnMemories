// OwnMemories — bağlantıyla paylaşım: oluşturma/düzenleme penceresi (QR kodlu) ve "Paylaşılan bağlantılar" sayfası.
// API: GET/POST /v1/shares, PATCH/DELETE /v1/shares/{id}, POST /v1/shares/{id}/revoke|rotate
import { api, thumbUrl } from './api.js';
import { h, t, icon, toast, confirmDialog, OM } from './util.js';
import { qrSvg } from './qr.js';

const EXPIRY = ['1d', '1w', '1m', 'never'];
const loc = () => (OM.locale === 'en' ? 'en-GB' : 'tr-TR');
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(loc(), { day: 'numeric', month: 'long', year: 'numeric' }) : '');

/** Açık/kapalı anahtarı (erişilebilir: role=switch). */
function toggle(label, sub, checked, name) {
  const input = h('input', { type: 'checkbox', role: 'switch', name, class: 'sl-switch-input' });
  input.checked = !!checked;
  return {
    input,
    el: h('label', { class: 'sl-toggle' },
      h('span', { class: 'sl-toggle-text' }, h('strong', {}, label), sub ? h('small', {}, sub) : null),
      input, h('span', { class: 'sl-switch', 'aria-hidden': 'true' })),
  };
}

/** Pencere kabuğu: dialog.modal (temalar biçimler). Kapatınca kaldırılır. */
function openDialog(cls) {
  const d = h('dialog', { class: `modal sl-dialog ${cls}` });
  d.addEventListener('close', () => d.remove());
  d.addEventListener('click', (e) => { if (e.target === d) d.close(); });
  document.body.append(d);
  return d;
}

/** Ayar formu (oluşturma ve düzenleme ortak). */
function settingsForm(init, { editing = false, kind = 'selection' } = {}) {
  const title = h('input', { type: 'text', maxlength: 255, value: init.title || '', placeholder: t('sl_name_ph'), autocomplete: 'off' });
  const exp = editing ? 'keep' : (init.expires || '1m');
  const expiry = h('div', { class: 'sl-seg', role: 'radiogroup', 'aria-label': t('sl_expiry') },
    ...(editing ? ['keep', ...EXPIRY] : EXPIRY).map((v) => h('label', { class: 'sl-seg-opt' },
      h('input', { type: 'radio', name: 'sl-exp', value: v, checked: v === exp }),
      h('span', {}, v === 'keep' ? t('sl_expiry_keep') : t('sl_' + v)))));
  const pw = h('input', { type: 'password', maxlength: 200, autocomplete: 'new-password', placeholder: editing && init.has_password ? t('sl_password_keep') : t('sl_password_ph') });
  const showPw = h('button', { type: 'button', class: 'linklike sl-pw-show', 'aria-pressed': 'false', onclick: () => {
    const on = pw.type === 'password';
    pw.type = on ? 'text' : 'password';
    showPw.setAttribute('aria-pressed', String(on));
    showPw.textContent = on ? t('sl_hide_pw') : t('sl_show_pw');
  } }, t('sl_show_pw'));
  const removePw = editing && init.has_password ? toggle(t('sl_remove_pw'), null, false, 'rmpw') : null;
  const dl = toggle(t('sl_allow_dl'), t('sl_allow_dl_sub'), init.allow_download ?? true, 'dl');
  const lc = toggle(t('sl_location'), t('sl_location_sub'), init.show_location ?? false, 'loc');
  const el = h('div', { class: 'sl-form' },
    h('label', { class: 'field' }, h('span', {}, t('sl_name')), title),
    h('div', { class: 'field' }, h('span', { id: 'sl-exp-l' }, t('sl_expiry')), expiry),
    h('label', { class: 'field sl-pw' }, h('span', {}, t('sl_password'), showPw), pw),
    removePw?.el, dl.el, lc.el,
    kind === 'album' ? h('p', { class: 'sl-note' }, icon('album'), t('sl_album_note')) : null);
  return {
    el,
    focus: () => title.focus(),
    values() {
      const v = { title: title.value.trim(), allow_download: dl.input.checked, show_location: lc.input.checked };
      const e = expiry.querySelector('input:checked')?.value;
      if (e && e !== 'keep') v.expires = e;
      if (removePw?.input.checked) v.password = null;
      else if (pw.value) v.password = pw.value;
      return v;
    },
  };
}

/** Sonuç görünümü: adres + kopyala + QR + (varsa) sistem paylaşımı. */
function resultView(d, res, heading) {
  const url = res.url;
  const input = h('input', { type: 'text', readonly: true, value: url, class: 'sl-url', 'aria-label': t('sl_url_label'), onfocus: (e) => e.target.select() });
  const copy = h('button', { type: 'button', class: 'btn btn-primary sl-copy', onclick: async () => {
    try { await navigator.clipboard.writeText(url); } catch { input.select(); document.execCommand?.('copy'); }
    copy.classList.add('done');
    copy.replaceChildren(icon('check'), h('span', {}, t('sl_copied_short')));
    toast(t('sl_copied'));
    setTimeout(() => { copy.classList.remove('done'); copy.replaceChildren(icon('copy'), h('span', {}, t('sl_copy'))); }, 2200);
  } }, icon('copy'), h('span', {}, t('sl_copy')));
  const qr = h('div', { class: 'sl-qr', html: qrSvg(url, { label: t('sl_qr_label') }) });
  const actions = h('div', { class: 'modal-actions' });
  if (navigator.share) {
    actions.append(h('button', { type: 'button', class: 'btn', onclick: () => navigator.share({ title: res.link?.title || '', url }).catch(() => {}) }, icon('share'), t('sl_share_native')));
  }
  actions.append(
    h('a', { class: 'btn btn-ghost', href: '#/links', onclick: () => d.close() }, t('sl_manage')),
    h('button', { type: 'button', class: 'btn btn-primary', onclick: () => d.close() }, t('sl_done')));
  const meta = res.link ? [
    res.link.expires_at ? t('sl_until', { d: fmtDate(res.link.expires_at) }) : t('sl_no_expiry'),
    res.link.has_password ? t('sl_flag_pw') : null,
    res.link.allow_download ? t('sl_flag_dl') : null,
    res.link.show_location ? t('sl_flag_loc') : t('sl_flag_noloc'),
  ].filter(Boolean).join(' · ') : '';
  d.replaceChildren(h('div', { class: 'sl-result' },
    h('div', { class: 'sl-result-head' }, h('span', { class: 'sl-ok', 'aria-hidden': 'true' }, icon('check')), h('div', {}, h('h2', {}, heading), h('p', { class: 'muted' }, meta))),
    h('div', { class: 'sl-url-row' }, input, copy),
    h('div', { class: 'sl-qr-wrap' }, qr, h('p', { class: 'sl-qr-hint' }, t('sl_qr_hint'))),
    h('p', { class: 'sl-once' }, icon('lock'), t('sl_once')),
    res.funnel === false ? h('p', { class: 'sl-warn', role: 'note' }, icon('info'), h('span', {}, t('sl_tailnet_only'), ' ', h('code', {}, 'sudo bash server/setup-share.sh'))) : null,
    actions));
  copy.focus();
}

/**
 * Yeni bağlantı penceresi. opts: { kind: 'album'|'selection', albumId, ids, title }
 * Döner: oluşturulan bağlantı (veya null).
 */
export function linkDialog(opts, onDone) {
  const d = openDialog('sl-create');
  const kind = opts.kind;
  const n = opts.ids?.length || 0;
  const form = settingsForm({ title: opts.title || '' }, { kind });
  const err = h('p', { class: 'sl-err', role: 'alert', hidden: true });
  const submit = h('button', { type: 'submit', class: 'btn btn-primary' }, icon('link'), h('span', {}, t('sl_create')));
  const f = h('form', { method: 'dialog', class: 'sl-body' },
    h('h2', {}, kind === 'album' ? t('sl_title_album') : t('sl_title_sel', { n })),
    h('p', { class: 'muted sl-lead' }, t('sl_lead')),
    form.el, err,
    h('div', { class: 'modal-actions' }, h('button', { type: 'button', class: 'btn btn-ghost', onclick: () => d.close() }, t('cancel')), submit));
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    submit.disabled = true;
    err.hidden = true;
    try {
      const body = { kind, ...form.values() };
      if (kind === 'album') body.album_id = opts.albumId; else body.ids = opts.ids;
      const res = await api('POST', 'shares', body);
      resultView(d, res, t('sl_ready'));
      onDone?.(res);
    } catch (ex) {
      err.textContent = ex.message || t('load_failed');
      err.hidden = false;
      submit.disabled = false;
    }
  });
  d.append(f);
  d.showModal();
  form.focus();
}

function editDialog(link, onSaved) {
  const d = openDialog('sl-edit');
  const form = settingsForm(link, { editing: true, kind: link.kind });
  const err = h('p', { class: 'sl-err', role: 'alert', hidden: true });
  const f = h('form', { method: 'dialog', class: 'sl-body' }, h('h2', {}, t('sl_edit_title')), form.el, err,
    h('div', { class: 'modal-actions' }, h('button', { type: 'button', class: 'btn btn-ghost', onclick: () => d.close() }, t('cancel')),
      h('button', { type: 'submit', class: 'btn btn-primary' }, t('save'))));
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const v = form.values();
      if (!v.title) delete v.title;
      await api('PATCH', `shares/${link.id}`, v);
      toast(t('sl_saved'));
      d.close();
      onSaved();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  });
  d.append(f);
  d.showModal();
  form.focus();
}

/* ------------------------------------------------------ Paylaşılan bağlantılar */

export async function renderLinks(root, { setSub } = {}) {
  root.replaceChildren(h('div', { class: 'tl-status' }, h('span', { class: 'spinner' }), t('loading')));
  let data;
  try { data = await api('GET', 'shares'); } catch {
    root.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
    return;
  }
  const links = data.links;
  const active = links.filter((l) => l.status === 'active').length;
  setSub?.(t('sl_active_n', { n: active }));
  const head = h('div', { class: 'page-head sl-head' }, h('div', {}, h('h1', {}, t('shared_links')), h('p', { class: 'muted' }, t('sl_page_sub'))));
  if (!data.funnel) {
    head.append(h('p', { class: 'sl-warn sl-warn-page', role: 'note' }, icon('info'), h('span', {}, t('sl_tailnet_only'), ' ', h('code', {}, 'sudo bash server/setup-share.sh'))));
  }
  const reload = () => renderLinks(root, { setSub });
  if (!links.length) {
    root.replaceChildren(head, h('div', { class: 'empty sl-empty' }, h('span', { class: 'sl-empty-ic', 'aria-hidden': 'true' }, icon('link')), h('p', {}, t('sl_empty'))));
    return;
  }
  const list = h('ul', { class: 'sl-list', role: 'list' });
  for (const l of links) {
    const cover = l.cover_asset_id ? h('img', { src: thumbUrl(l.cover_asset_id), alt: '', loading: 'lazy' }) : h('span', { class: 'sl-cover-empty' }, icon(l.kind === 'album' ? 'album' : 'photos'));
    const flags = h('div', { class: 'sl-flags' },
      h('span', { class: 'sl-pill', dataset: { status: l.status } }, t('sl_status_' + l.status)),
      h('span', { class: 'sl-chip' }, t('sl_kind_' + l.kind)),
      l.has_password ? h('span', { class: 'sl-chip' }, icon('lock'), t('sl_flag_pw')) : null,
      l.allow_download ? h('span', { class: 'sl-chip' }, icon('download'), t('sl_flag_dl')) : null,
      l.show_location ? h('span', { class: 'sl-chip' }, icon('pin'), t('sl_flag_loc')) : null);
    const meta = [t('sl_items', { n: l.count }), t('sl_views', { n: l.views }),
      l.status === 'active' ? (l.expires_at ? t('sl_until', { d: fmtDate(l.expires_at) }) : t('sl_no_expiry')) : null,
      t('sl_created', { d: fmtDate(l.created_at) })].filter(Boolean).join(' · ');
    const acts = h('div', { class: 'sl-acts' });
    if (l.status !== 'revoked') {
      acts.append(
        h('button', { type: 'button', class: 'btn btn-ghost', onclick: async () => {
          if (!(await confirmDialog(t('sl_rotate'), t('sl_rotate_confirm'), t('sl_rotate')))) return;
          const res = await api('POST', `shares/${l.id}/rotate`);
          const d = openDialog('sl-create');
          d.showModal();
          resultView(d, { ...res, funnel: data.funnel }, t('sl_new_url'));
          d.addEventListener('close', reload);
        } }, icon('restore'), h('span', {}, t('sl_rotate'))),
        h('button', { type: 'button', class: 'btn btn-ghost', onclick: () => editDialog(l, reload) }, icon('pencil'), h('span', {}, t('sl_edit'))),
        h('button', { type: 'button', class: 'btn btn-ghost danger', onclick: async () => {
          if (!(await confirmDialog(t('sl_revoke_title'), t('sl_revoke_confirm', { t: l.title }), t('sl_revoke'), true))) return;
          await api('POST', `shares/${l.id}/revoke`);
          toast(t('sl_revoked'));
          reload();
        } }, icon('close'), h('span', {}, t('sl_revoke'))));
    }
    if (l.status !== 'active') {
      acts.append(h('button', { type: 'button', class: 'btn btn-ghost danger', onclick: async () => {
        await api('DELETE', `shares/${l.id}`);
        toast(t('sl_deleted'));
        reload();
      } }, icon('trash'), h('span', {}, t('sl_delete'))));
    }
    list.append(h('li', { class: 'sl-item', dataset: { status: l.status, id: String(l.id) } },
      l.kind === 'album' && l.album_id && l.status !== 'revoked' ? h('a', { class: 'sl-cover', href: `#/album/${l.album_id}`, 'aria-label': t('sl_open_album') }, cover) : h('span', { class: 'sl-cover' }, cover),
      h('div', { class: 'sl-main' }, h('h2', { class: 'sl-name' }, l.title), h('p', { class: 'sl-meta' }, meta), flags),
      acts));
  }
  root.replaceChildren(head, list);
}
