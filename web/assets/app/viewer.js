// OwnMemories — tam ekran görüntüleyici: önizleme/video, klavye ve kaydırma ile gezinme, bilgi paneli.
import { api, previewUrl, videoUrl, originalUrl, thumbUrl } from './api.js';
import { h, t, icon, toast, formatDateTime, formatBytes } from './util.js';
import { store } from './store.js';
import { fetchAssetPeople, peopleSection, faceBoxes } from './people.js';
import { infoSection as ocrSection } from './ocr.js';

let current = null;

/**
 * @param {string} id
 * @param {{onChanged?: (a)=>void, onRemoved?: (ids)=>void, inTrash?: boolean, albumId?: string, canRemoveFromAlbum?: boolean}} ctx
 */
export function openViewer(id, ctx = {}) {
  closeViewer();
  current = new Viewer(id, ctx);
}

export function closeViewer() {
  current?.close();
  current = null;
}

class Viewer {
  constructor(id, ctx) {
    this.ctx = ctx;
    this.infoOpen = window.innerWidth > 1100 && localStorage.getItem('om_info') === '1';
    try { this.showFaces = localStorage.getItem('om_faces') === '1'; } catch { this.showFaces = false; }
    this.people = null; // {id, data} — assets/{id}/people
    this.boxes = null;
    this.focusFace = null;
    this.prevFocus = document.activeElement;
    this.el = h('div', { class: 'viewer', role: 'dialog', 'aria-modal': 'true', tabindex: '-1' });
    this.stage = h('div', { class: 'v-stage' });
    this.info = h('aside', { class: 'v-info', 'aria-label': t('details') });
    this.bar = h('div', { class: 'v-bar' });
    this.prevBtn = h('button', { class: 'v-nav v-prev', 'aria-label': t('prev'), onclick: () => this.step(-1) }, icon('left'));
    this.nextBtn = h('button', { class: 'v-nav v-next', 'aria-label': t('next'), onclick: () => this.step(1) }, icon('right'));
    this.el.append(this.bar, this.stage, this.prevBtn, this.nextBtn, this.info);
    document.body.append(this.el);
    document.body.classList.add('no-scroll');
    this.onKey = (e) => this.key(e);
    document.addEventListener('keydown', this.onKey);
    this.touch();
    history.pushState({ viewer: true }, '', location.hash);
    this.onPop = () => this.close(true);
    window.addEventListener('popstate', this.onPop);
    this.show(id);
    this.el.focus();
  }

  close(fromPop = false) {
    if (!this.el.isConnected) return;
    document.removeEventListener('keydown', this.onKey);
    window.removeEventListener('popstate', this.onPop);
    this.el.remove();
    document.body.classList.remove('no-scroll');
    if (!fromPop && history.state?.viewer) history.back();
    this.prevFocus?.focus?.();
    if (current === this) current = null;
    this.ctx.onClose?.();
  }

  key(e) {
    if (e.target.matches('input, textarea')) return;
    if (e.key === 'Escape') this.close();
    else if (e.key === 'ArrowLeft') this.step(-1);
    else if (e.key === 'ArrowRight') this.step(1);
    else if (e.key === 'i') this.toggleInfo();
    else if (e.key === 'f' && this.asset?.access === 'own') this.toggleFav();
  }

  /**
   * Dokunma hareketleri (iOS Fotoğraflar mantığı): yatay kaydırma önceki/sonraki öğeye geçer;
   * aşağı ya da yukarı çekince fotoğraf parmağı izler, arka plan solar ve eşik aşılınca kapanır.
   */
  touch() {
    let x0 = null, y0 = null, t0 = 0, axis = null, dy = 0;
    const media = () => this.stage.querySelector('.v-img, video');
    const setDrag = (y, animate) => {
      const m = media();
      const p = Math.min(Math.abs(y) / 320, 1);
      if (m) {
        m.style.transition = animate ? 'transform .25s cubic-bezier(.2,.8,.2,1)' : 'none';
        m.style.transform = y ? `translateY(${y}px) scale(${1 - p * 0.25})` : '';
      }
      this.el.style.transition = animate ? 'background-color .25s ease' : 'none';
      this.el.style.backgroundColor = y ? `rgba(8, 7, 11, ${1 - p * 0.85})` : '';
      this.el.classList.toggle('dragging', !!y);
    };
    this.stage.addEventListener('touchstart', (e) => {
      if (e.touches.length !== 1 || this.stage.classList.contains('zoom')) { x0 = null; return; }
      x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; t0 = performance.now(); axis = null; dy = 0;
    }, { passive: true });
    this.stage.addEventListener('touchmove', (e) => {
      if (x0 === null) return;
      if (e.touches.length !== 1) { x0 = null; setDrag(0, true); return; }
      const mx = e.touches[0].clientX - x0, my = e.touches[0].clientY - y0;
      if (!axis && Math.hypot(mx, my) > 10) axis = Math.abs(my) > Math.abs(mx) ? 'y' : 'x';
      if (axis === 'y') { e.preventDefault(); dy = my; setDrag(dy, false); }
    }, { passive: false });
    const end = (e) => {
      if (x0 === null) return;
      const dx = e.changedTouches[0].clientX - x0;
      const v = Math.abs(dy) / Math.max(1, performance.now() - t0); // px/ms
      x0 = null;
      if (axis === 'x' && Math.abs(dx) > 60) { this.step(dx < 0 ? 1 : -1); return; }
      if (axis !== 'y') return;
      if (Math.abs(dy) > 110 || (Math.abs(dy) > 40 && v > 0.6)) {
        const m = media();
        if (m) { m.style.transition = 'transform .2s ease-out, opacity .2s ease-out'; m.style.transform = `translateY(${dy > 0 ? '100vh' : '-100vh'}) scale(.75)`; m.style.opacity = '0'; }
        this.el.style.transition = 'background-color .2s ease-out';
        this.el.style.backgroundColor = 'rgba(8, 7, 11, 0)';
        setTimeout(() => this.close(), 190);
      } else setDrag(0, true);
    };
    this.stage.addEventListener('touchend', end);
    this.stage.addEventListener('touchcancel', () => { if (x0 !== null) { x0 = null; setDrag(0, true); } });
  }

  async step(dir) {
    const i = store.list.findIndex((a) => a.id === this.id);
    if (dir > 0 && i >= store.list.length - 3 && store.loadMore) await store.loadMore();
    const n = store.list[i + dir];
    if (n) this.show(n.id);
  }

  async show(id) {
    this.id = id;
    this.people = null;
    this.focusFace = null;
    const base = store.byId(id);
    const i = store.list.findIndex((a) => a.id === id);
    this.prevBtn.hidden = i <= 0;
    this.nextBtn.hidden = i < 0 || i >= store.list.length - 1;
    this.renderStage(base || { id, type: 'photo' });
    try {
      const r = await api('GET', `assets/${id}`);
      if (this.id !== id) return;
      this.asset = r.asset;
      this.renderBar();
      this.renderInfo();
      if (base && base.type !== r.asset.type) this.renderStage(r.asset);
      this.loadPeople();
    } catch {
      toast(t('load_failed'));
    }
    // Komşu önizlemeyi önceden yükle
    const nx = store.list[i + 1];
    if (nx && nx.type === 'photo') { const im = new Image(); im.src = previewUrl(nx.id); }
  }

  renderStage(a) {
    this.boxes?.destroy();
    this.boxes = null;
    this.stage.replaceChildren();
    if (a.type === 'video') {
      const v = h('video', { src: videoUrl(a.id), controls: true, autoplay: true, playsinline: true, poster: previewUrl(a.id), preload: 'metadata' });
      v.addEventListener('error', () => {
        this.stage.replaceChildren(h('div', { class: 'v-msg' },
          h('p', {}, t('video_unplayable')),
          h('a', { class: 'btn', href: originalUrl(a.id) }, t('download'))));
      });
      this.stage.append(v);
      return;
    }
    const low = h('img', { class: 'v-low', src: thumbUrl(a.id), alt: '' });
    const img = h('img', { class: 'v-img', src: previewUrl(a.id), alt: a.filename || '' });
    img.addEventListener('load', () => low.remove());
    img.addEventListener('dblclick', () => this.stage.classList.toggle('zoom'));
    this.stage.append(low, img);
    this.drawFaces();
    if (a.live_pair_id) {
      const live = h('button', { class: 'v-live', 'aria-label': t('play_live') }, icon('live'), 'LIVE');
      const play = () => {
        const v = h('video', { class: 'v-livevid', src: videoUrl(a.live_pair_id), autoplay: true, muted: true, playsinline: true });
        v.addEventListener('ended', () => v.remove());
        this.stage.append(v);
      };
      live.addEventListener('mouseenter', play);
      live.addEventListener('click', play);
      this.stage.append(live);
    }
  }

  renderBar() {
    const a = this.asset;
    const own = a.access === 'own';
    const btn = (name, label, fn, cls = '') => h('button', { class: 'icon-btn ' + cls, 'aria-label': label, title: label, onclick: fn }, icon(name));
    const tools = [];
    if (own && !a.trashed_at) {
      tools.push(btn(a.is_favorite ? 'heartFill' : 'heart', a.is_favorite ? t('unfavorite') : t('favorite'), () => this.toggleFav(), a.is_favorite ? 'on' : ''));
      tools.push(btn('album', t('add_to_album'), () => import('./albums.js').then((m) => m.addToAlbumDialog([a.id]))));
    }
    tools.push(h('a', { class: 'icon-btn', href: originalUrl(a.id), 'aria-label': t('download'), title: t('download') }, icon('download')));
    tools.push(btn('info', t('info'), () => this.toggleInfo(), this.infoOpen ? 'on' : ''));
    if (own && a.trashed_at) {
      tools.push(btn('restore', t('restore'), () => this.bulk('restore', 'restored')));
    } else if (own) {
      tools.push(btn('trash', t('to_trash'), () => this.bulk('trash', 'trashed')));
    }
    this.bar.replaceChildren(
      h('button', { class: 'icon-btn', 'aria-label': t('close'), title: t('close'), onclick: () => this.close() }, icon('close')),
      h('div', { class: 'v-title' }, a.taken_local ? formatDateTime(a) : ''),
      h('div', { class: 'v-tools' }, ...tools),
    );
  }

  toggleInfo() {
    this.infoOpen = !this.infoOpen;
    localStorage.setItem('om_info', this.infoOpen ? '1' : '0');
    if (this.asset) { this.renderBar(); this.renderInfo(); this.loadPeople(); }
  }

  renderInfo() {
    this.el.classList.toggle('with-info', this.infoOpen);
    if (!this.infoOpen) { this.info.replaceChildren(); return; }
    const a = this.asset;
    const own = a.access === 'own';
    const rows = [];
    const row = (label, ...val) => rows.push(h('div', { class: 'i-row' }, h('div', { class: 'i-label' }, label), h('div', { class: 'i-val' }, ...val)));
    const desc = h('textarea', { class: 'i-desc', rows: 2, placeholder: t('description_ph'), readonly: !own, maxlength: 5000 });
    desc.value = a.description || '';
    desc.addEventListener('change', async () => {
      const r = await api('PATCH', `assets/${a.id}`, { description: desc.value });
      this.asset = r.asset;
      toast(t('saved'));
    });
    rows.push(desc);
    // Kişiler (sahibin fotoğrafı): açıklamanın hemen altında, Apple/Google Fotoğraflar'daki gibi üstte.
    if (own && !a.trashed_at) rows.push(this.peopleSlot = h('div', { class: 'i-row i-row-people' }, this.peopleBody()));
    row(t('taken'), formatDateTime(a), h('div', { class: 'muted small' }, t('date_source_' + a.date_source)));
    row(t('file'), a.filename, h('div', { class: 'muted small' }, [a.width && a.height ? `${a.width} × ${a.height}` : null, formatBytes(a.size)].filter(Boolean).join('  ·  ')));
    if (a.camera_model || a.camera_make) row(t('camera'), [a.camera_make, a.camera_model].filter(Boolean).join(' '), a.lens ? h('div', { class: 'muted small' }, a.lens) : null);
    if (a.latitude !== null) {
      const osm = `https://www.openstreetmap.org/?mlat=${a.latitude}&mlon=${a.longitude}#map=15/${a.latitude}/${a.longitude}`;
      const coords = `${a.latitude.toFixed(5)}, ${a.longitude.toFixed(5)}`;
      const p = a.place;
      // Yer adı (sunucuda çevrimdışı bulunur): "Moda, Kadıköy, İstanbul, Türkiye"; koordinatlar küçük ve ikincil.
      const name = p
        ? (own ? h('a', { class: 'i-place', href: `#/place/${p.id}`, title: t('open_place', { name: p.city }) }, p.label) : h('span', { class: 'i-place' }, p.label))
        : null;
      row(t('location'), ...[name, h('div', { class: 'i-coords muted small' }, h('span', { class: 'i-coords-num', 'aria-label': `${t('coords')}: ${coords}` }, coords), ' · ',
        h('a', { href: osm, target: '_blank', rel: 'noopener' }, t('map_open')))].filter(Boolean));
    } else {
      row(t('location'), h('span', { class: 'muted' }, t('no_location')));
    }
    if (!(own && !a.trashed_at) && a.people?.length) row(t('people'), a.people.join(', '));
    if (a.albums?.length) row(t('in_albums'), ...a.albums.map((al) => h('a', { class: 'chip', href: `#/album/${al.id}` }, al.title)));
    if (a.owner_name) row(t('owner'), a.owner_name);
    // Fotoğraftaki yazı (ocr.js): yazı varsa katlanabilir bölüm + kopyala; aramadan gelindiyse sorgu vurgulanır.
    // Satırların sonunda (temaların .i-row gruplamasını bölmesin); yazı yoksa gizli kalır.
    if (a.type === 'photo') rows.push(ocrSection(a.id, store.byId(a.id)?.text_match?.term || ''));
    this.info.replaceChildren(h('h2', {}, t('details')), ...rows);
  }

  /* ------------------------------------------------ Kişiler ve yüzler */

  /** Sahibin fotoğrafında kişiler/yüzler (bilgi paneli açıksa ya da yüz kutuları gösteriliyorsa). */
  async loadPeople(force = false) {
    const a = this.asset;
    if (!a || a.access !== 'own' || a.trashed_at) return;
    if (!this.infoOpen && !(this.showFaces && a.type === 'photo')) return;
    if (this.people?.id === a.id && !force) return;
    const id = a.id;
    try {
      const data = await fetchAssetPeople(id);
      if (this.id !== id) return;
      this.setPeople(data);
    } catch {
      if (this.id === id && this.peopleSlot) this.peopleSlot.replaceChildren(h('div', { class: 'muted small' }, t('load_failed')));
    }
  }

  setPeople(data) {
    this.people = { id: this.asset.id, data };
    this.asset.people = (data.people || []).filter((p) => p.name).map((p) => p.name);
    if (this.peopleSlot?.isConnected) this.peopleSlot.replaceChildren(this.peopleBody());
    this.drawFaces();
    this.ctx.onPeopleChanged?.(this.asset.id, data);
  }

  peopleBody() {
    if (this.people?.id !== this.asset?.id) return h('div', { class: 'i-people-loading' }, h('span', { class: 'spinner' }));
    const sec = peopleSection(this.people.data, {
      assetId: this.asset.id,
      showFaces: this.showFaces,
      focusFaceId: this.focusFace,
      onData: (d) => this.setPeople(d),
      onToggleFaces: () => this.toggleFaces(),
      onHover: (fid) => this.boxes?.highlight(fid),
    });
    this.focusFace = null;
    return sec;
  }

  toggleFaces() {
    this.showFaces = !this.showFaces;
    try { localStorage.setItem('om_faces', this.showFaces ? '1' : '0'); } catch { /* yok say */ }
    if (this.peopleSlot?.isConnected) this.peopleSlot.replaceChildren(this.peopleBody());
    this.drawFaces();
  }

  drawFaces() {
    this.boxes?.destroy();
    this.boxes = null;
    const img = this.stage.querySelector('.v-img');
    const data = this.people?.id === this.id ? this.people.data : null;
    if (!this.showFaces || !img || !data?.faces?.length) return;
    this.boxes = faceBoxes(img, data, {
      onPick: (f) => {
        if (f.person_id && f.person_name) { location.hash = `#/person/${f.person_id}`; return; }
        this.focusFace = f.id;
        if (!this.infoOpen) this.toggleInfo();
        else if (this.peopleSlot?.isConnected) this.peopleSlot.replaceChildren(this.peopleBody());
      },
    });
    img.after(this.boxes.el);
  }

  async toggleFav() {
    const a = this.asset;
    const r = await api('PATCH', `assets/${a.id}`, { is_favorite: !a.is_favorite });
    this.asset = r.asset;
    store.update(r.asset);
    this.ctx.onChanged?.(r.asset);
    this.renderBar();
    toast(r.asset.is_favorite ? t('favorited') : t('unfavorited'));
  }

  async bulk(action, msg) {
    const id = this.asset.id;
    const i = store.list.findIndex((x) => x.id === id);
    const r = await api('POST', `assets/${action}`, { ids: [id] });
    toast(t(msg, { n: r.count }));
    const next = store.list[i + 1] || store.list[i - 1];
    this.ctx.onRemoved?.([id]);
    if (next && next.id !== id) this.show(next.id); else this.close();
  }
}
