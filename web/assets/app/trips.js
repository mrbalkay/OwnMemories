// OwnMemories — Geziler ve etkinlikler: otomatik gezi/etkinlik albümleri (#/trips), gezi sayfası (#/trip/{id})
// ve "Albüme dönüştür" eylemi (anılardan da kullanılır). Geziler sunucuda bulunur (/v1/trips; trips.php).
import { api, previewUrl, thumbUrl } from './api.js';
import { h, t, icon, toast, modal, OM } from './util.js';
import { viewSwitch } from './places.js';
import { currentTheme } from './theme.js';

const nf = () => new Intl.NumberFormat(OM.locale === 'en' ? 'en-GB' : 'tr-TR');
let cache = null; // {at, key, data}: liste ↔ gezi sayfası arasında gidip gelirken (30 sn)

export function invalidateTrips() { cache = null; }

async function loadTrips(dismissed = false) {
  const key = dismissed ? 'hidden' : 'all';
  if (cache && cache.key === key && Date.now() - cache.at < 30000) return cache.data;
  const data = await api('GET', 'trips' + (dismissed ? '?dismissed=1' : ''));
  cache = { at: Date.now(), key, data };
  return data;
}

/** Kartın/başlığın kısa adı: otomatik adlarda tarihsiz biçim ("Bozcaada gezisi"), kullanıcı adında olduğu gibi. */
const tripName = (tr) => tr.short_title || tr.title;

/** Üst satır türü: "Gezi" · "Etkinlik" · etiketliyse "Doğum günü" / "Günübirlik". */
function kindText(tr) {
  if (tr.kind === 'event') return tr.label_text || t('trip_kind_event');
  return t('trip_kind_trip');
}

function daysText(tr) {
  return tr.days > 1 ? t('trip_days', { n: tr.days }) : t('trip_one_day');
}

/** Yer çipleri (en çok $max; fazlası "+3"). Şehir kimliği varsa yer sayfasına bağlanır. */
function placeChips(tr, { max = 4, links = false } = {}) {
  const list = tr.places || [];
  if (!list.length) return null;
  const shown = list.slice(0, max);
  const chips = shown.map((p) => (links && p.city_id
    ? h('a', { class: 'trip-chip', href: `#/place/${p.city_id}` }, p.name)
    : h('span', { class: 'trip-chip' }, p.name)));
  if (list.length > max) chips.push(h('span', { class: 'trip-chip is-more', title: list.slice(max).map((p) => p.name).join(', ') }, `+${list.length - max}`));
  return h('span', { class: 'trip-chips' }, ...chips);
}

function tripCard(tr, idx, { hidden = false, onRestore, lead = false } = {}) {
  const big = tr.kind === 'trip';
  const img = h('img', { src: idx < 6 || big ? previewUrl(tr.cover_asset_id) : thumbUrl(tr.cover_asset_id), alt: '', loading: idx < 4 ? 'eager' : 'lazy', decoding: 'async' });
  img.addEventListener('load', () => img.classList.add('in'), { once: true });
  const label = `${tr.title}, ${t('items', { n: nf().format(tr.count) })}`;
  const card = h(hidden ? 'div' : 'a', {
    class: 'trip-card' + (big ? '' : ' is-event') + (lead ? ' is-lead' : '') + (hidden ? ' is-hidden' : ''),
    href: hidden ? null : `#/trip/${tr.id}`,
    dataset: { id: tr.id, kind: tr.kind, label: tr.label || '' },
    'aria-label': hidden ? null : label,
  },
  h('span', { class: 'trip-cover' }, img, h('span', { class: 'trip-shade', 'aria-hidden': 'true' }),
    tr.album_id ? h('span', { class: 'trip-badge', title: t('trip_is_album') }, icon('album'), h('span', {}, t('trip_is_album'))) : null),
  h('span', { class: 'trip-text' },
    h('small', { class: 'trip-eyebrow' }, h('span', { class: 'trip-kind' }, kindText(tr)), h('span', { class: 'trip-dot', 'aria-hidden': 'true' }, '·'), h('span', { class: 'trip-range' }, tr.range_text)),
    h('strong', { class: 'trip-name' }, tripName(tr)),
    h('span', { class: 'trip-meta' },
      placeChips(tr, { max: big ? 4 : 2 }),
      h('span', { class: 'trip-count' }, t('items', { n: nf().format(tr.count) })))));
  if (hidden) {
    card.append(h('button', { class: 'btn btn-soft trip-restore', type: 'button', onclick: () => onRestore?.(tr) }, icon('restore'), h('span', {}, t('trip_unhide'))));
  }
  return card;
}

function yearOf(tr) { return tr.start_day.slice(0, 4); }

/** #/trips */
export async function renderTrips(root, { setSub, showHidden = false } = {}) {
  const sub = h('p', { class: 'trips-sub muted' }, ' ');
  const hiddenBtn = h('button', { class: 'btn btn-ghost trips-hidden-toggle', type: 'button', hidden: true, 'aria-pressed': showHidden ? 'true' : 'false',
    onclick: () => { location.hash = showHidden ? '#/trips' : '#/trips?hidden=1'; } });
  const head = h('div', { class: 'page-head trips-head' },
    h('div', {}, h('h1', {}, t('trips_title')), sub),
    h('div', { class: 'trips-head-tools' }, hiddenBtn, viewSwitch('trips')));
  const body = h('div', { class: 'trips-body', 'aria-busy': 'true' },
    h('div', { class: 'trip-grid is-skeleton', 'aria-hidden': 'true' }, ...Array.from({ length: 4 }, () => h('span', { class: 'trip-card is-ghost' }, h('span', { class: 'trip-cover' })))));
  root.replaceChildren(head, body);
  let data;
  try {
    data = await loadTrips(showHidden);
  } catch {
    body.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
    return;
  }
  if (!root.isConnected) return;
  body.removeAttribute('aria-busy');
  const list = data.trips || [];
  const trips = list.filter((x) => x.kind === 'trip');
  const events = list.filter((x) => x.kind === 'event');
  const subText = showHidden ? t('trips_hidden_sub', { n: nf().format(list.length) })
    : (list.length ? t('trips_sub', { t: nf().format(trips.length), e: nf().format(events.length) }) : '');
  sub.textContent = subText;
  setSub?.(subText);
  if (data.hidden > 0 || showHidden) {
    hiddenBtn.hidden = false;
    hiddenBtn.replaceChildren(icon(showHidden ? 'left' : 'eyeOff'), h('span', {}, showHidden ? t('trips_all') : t('trips_hidden_n', { n: nf().format(data.hidden) })));
  }
  const st = data.status || {};
  const status = st.pending || st.places_pending > 0
    ? h('div', { class: 'places-status trips-status', role: 'status' }, h('span', { class: 'places-status-dot', 'aria-hidden': 'true' }),
      st.places_pending > 0 ? t('trips_places_pending', { n: nf().format(st.places_pending) }) : t('trips_pending'))
    : null;
  if (!list.length) {
    body.replaceChildren(...[status, h('div', { class: 'empty trips-empty' },
      h('div', { class: 'trips-empty-art', 'aria-hidden': 'true' }, icon('suitcase')),
      h('h2', {}, showHidden ? t('trips_hidden_empty') : t('trips_empty_title')),
      h('p', {}, showHidden ? '' : (st.places_ready === false ? t('trips_no_geo') : t('trips_empty'))))].filter(Boolean));
    return;
  }
  const restore = async (tr) => {
    try {
      await api('PATCH', `trips/${tr.id}`, { dismissed: false });
      invalidateTrips();
      toast(t('trip_restored'));
      renderTrips(root, { setSub, showHidden });
    } catch (e) { toast(e.message); }
  };
  const sections = [];
  if (trips.length) {
    // Yıllara göre: en yeni yıl önce
    const years = [...new Set(trips.map(yearOf))];
    sections.push(h('section', { class: 'trips-section', 'aria-labelledby': 'trips-h-trip' },
      h('div', { class: 'trips-section-head' }, h('h2', { id: 'trips-h-trip' }, t('trips_section_trips'), ' ', h('span', { class: 'trips-section-count' }, nf().format(trips.length)))),
      ...years.map((y) => h('div', { class: 'trips-year', dataset: { year: y } },
        h('h3', { class: 'trips-year-label' }, y),
        h('div', { class: 'trip-grid' }, ...trips.filter((x) => yearOf(x) === y).map((x, i) => tripCard(x, i, { hidden: showHidden, onRestore: restore, lead: !showHidden && x === trips[0] && trips.length > 2 })))))));
  }
  if (events.length) {
    sections.push(h('section', { class: 'trips-section trips-events', 'aria-labelledby': 'trips-h-event' },
      h('div', { class: 'trips-section-head' }, h('h2', { id: 'trips-h-event' }, t('trips_section_events'), ' ', h('span', { class: 'trips-section-count' }, nf().format(events.length))),
        h('p', { class: 'muted trips-section-note' }, t('trips_events_note'))),
      h('div', { class: 'trip-grid is-events' }, ...events.map((x, i) => tripCard(x, i, { hidden: showHidden, onRestore: restore })))));
  }
  body.replaceChildren(...[status, ...sections].filter(Boolean));
}

/* ------------------------------------------------------------ eylemler */

/** Geziyi albüme dönüştürür; albüm zaten varsa onu açar. Sonuç: albüm. */
export async function convertToAlbum(trip, { open = true } = {}) {
  try {
    const r = await api('POST', `trips/${trip.id}/album`);
    invalidateTrips();
    toast(t('trip_converted', { t: r.album.title }));
    if (open) location.hash = `#/album/${r.album.id}`;
    return r.album;
  } catch (e) {
    toast(e.message);
    return null;
  }
}

/** Yeniden adlandır: boş bırakılırsa otomatik ada döner. */
async function renameDialog(trip) {
  const input = h('input', { type: 'text', value: trip.title_auto ? tripName(trip) : trip.title, 'aria-label': t('trip_rename_label'), maxlength: 120 });
  const body = h('div', {},
    h('label', { class: 'field' }, h('span', {}, t('trip_rename_label')), input),
    h('p', { class: 'muted small trip-rename-hint' }, t('trip_rename_hint')));
  const r = await modal(t('rename'), body, [{ label: t('cancel'), value: 'cancel' }, { label: t('save'), value: 'ok', primary: true }]);
  if (r !== 'ok') return null;
  return input.value.trim();
}

async function hideDialog() {
  const r = await modal(t('trip_hide_title'), h('p', {}, t('trip_hide_text')), [{ label: t('cancel'), value: 'cancel' }, { label: t('trip_hide'), value: 'ok', primary: true }]);
  return r === 'ok';
}

/* ------------------------------------------------------------ gezi sayfası */

/** Rotanın SVG taslağı (harita yüklenemezse ya da yüklenirken). */
function routeSketch(route, places) {
  const pts = route.length ? route : places.map((p) => ({ lat: p.lat, lng: p.lng }));
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 320 200');
  svg.setAttribute('class', 'trip-sketch');
  svg.setAttribute('aria-hidden', 'true');
  if (!pts.length) return svg;
  const xs = pts.map((p) => p.lng), ys = pts.map((p) => -Math.log(Math.tan(Math.PI / 4 + (p.lat * Math.PI) / 360)));
  const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
  const spanX = Math.max(maxX - minX, 0.02), spanY = Math.max((maxY - minY) * 57.3, 0.02);
  const k = Math.min(260 / spanX, 140 / spanY);
  const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
  const P = (p) => [160 + (p.lng - cx) * k, 100 + (-Math.log(Math.tan(Math.PI / 4 + (p.lat * Math.PI) / 360)) - cy) * 57.3 * k];
  const ns = (tag, attrs) => { const el = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const [a, v] of Object.entries(attrs)) el.setAttribute(a, v); return el; };
  if (pts.length > 1) svg.append(ns('polyline', { points: pts.map((p) => P(p).map((v) => v.toFixed(1)).join(',')).join(' '), class: 'trip-sketch-line' }));
  for (const p of places) { const [x, y] = P(p); svg.append(ns('circle', { cx: x.toFixed(1), cy: y.toFixed(1), r: 5, class: 'trip-sketch-dot' })); }
  return svg;
}

/** Küçük, etkileşimsiz rota haritası (MapLibre; map.js ile aynı altlıklar). */
function routeMap(trip) {
  const box = h('div', { class: 'trip-map', role: 'img', 'aria-label': t('trip_route_label', { places: (trip.places || []).map((p) => p.name).join(', ') || trip.title }) },
    routeSketch(trip.route || [], trip.places || []));
  const route = trip.route || [];
  if (!route.length) { box.classList.add('is-empty'); return box; }
  import('./map.js').then(async (m) => {
    try { await m.maplibre(); } catch { return; }
    if (!box.isConnected) await new Promise((r) => requestAnimationFrame(r));
    if (!box.isConnected) return;
    const gl = window.maplibregl;
    const bounds = new gl.LngLatBounds();
    for (const p of route) bounds.extend([p.lng, p.lat]);
    const canvasBox = h('div', { class: 'trip-map-gl' });
    box.append(canvasBox);
    const color = getComputedStyle(box).getPropertyValue('--trip-route').trim() || '#6a56d6';
    const map = new gl.Map({
      container: canvasBox, style: m.styleUrl(currentTheme()), bounds, fitBoundsOptions: { padding: 34, maxZoom: 11 },
      interactive: false, attributionControl: { compact: true },
    });
    const line = { type: 'Feature', geometry: { type: 'LineString', coordinates: route.map((p) => [p.lng, p.lat]) } };
    const dots = { type: 'FeatureCollection', features: (trip.places || []).map((p) => ({ type: 'Feature', properties: { name: p.name }, geometry: { type: 'Point', coordinates: [p.lng, p.lat] } })) };
    map.on('load', () => {
      map.addSource('trip-line', { type: 'geojson', data: line });
      map.addSource('trip-dots', { type: 'geojson', data: dots });
      map.addLayer({ id: 'trip-line', type: 'line', source: 'trip-line', layout: { 'line-join': 'round', 'line-cap': 'round' }, paint: { 'line-color': color, 'line-width': 2.5, 'line-opacity': 0.85, 'line-dasharray': [1.5, 1.2] } });
      map.addLayer({ id: 'trip-dots', type: 'circle', source: 'trip-dots', paint: { 'circle-radius': 5.5, 'circle-color': color, 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff' } });
      box.classList.add('is-ready');
    });
    const host = document.getElementById('view');
    const watch = new MutationObserver(() => { if (!box.isConnected) { watch.disconnect(); map.remove(); } });
    watch.observe(host, { childList: true, subtree: true });
  });
  return box;
}

/** Gezi sayfası başlığı (zaman çizelgesinin üstü): başlık, tarih, yerler, rota haritası, eylemler. */
export function tripHeader(trip, { onChanged } = {}) {
  const metaBits = [
    h('span', { class: 'trip-head-range' }, icon('calendar'), trip.range_text),
    h('span', { class: 'trip-head-days' }, daysText(trip)),
    h('span', { class: 'trip-head-count' }, t('items', { n: nf().format(trip.count) })),
  ];
  if (trip.kind === 'trip' && trip.distance_km >= 50) metaBits.push(h('span', { class: 'trip-head-km' }, t('trip_km', { n: nf().format(Math.round(trip.distance_km)) })));
  const albumBtn = trip.album_id
    ? h('a', { class: 'btn btn-soft trip-album-open', href: `#/album/${trip.album_id}` }, icon('album'), h('span', {}, t('trip_open_album')))
    : h('button', { class: 'btn btn-primary trip-album-make', type: 'button', onclick: async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      await convertToAlbum(trip);
      btn.disabled = false;
    } }, icon('album'), h('span', {}, t('trip_to_album')));
  const actions = h('div', { class: 'trip-actions' },
    albumBtn,
    h('button', { class: 'btn btn-ghost trip-rename', type: 'button', onclick: async () => {
      const title = await renameDialog(trip);
      if (title === null) return;
      try {
        await api('PATCH', `trips/${trip.id}`, { title });
        invalidateTrips();
        toast(t('saved'));
        onChanged?.();
      } catch (e) { toast(e.message); }
    } }, icon('pencil'), h('span', {}, t('rename'))),
    h('button', { class: 'btn btn-ghost trip-hide', type: 'button', onclick: async () => {
      if (!(await hideDialog())) return;
      try {
        await api('DELETE', `trips/${trip.id}`);
        invalidateTrips();
        toast(t('trip_hidden_toast'));
        location.hash = '#/trips';
      } catch (e) { toast(e.message); }
    } }, icon('eyeOff'), h('span', {}, t('trip_hide'))));
  const cover = h('img', { src: previewUrl(trip.cover_asset_id), alt: '', decoding: 'async' });
  cover.addEventListener('load', () => cover.classList.add('in'), { once: true });
  return h('div', { class: 'trip-head', dataset: { kind: trip.kind } },
    h('div', { class: 'trip-head-backdrop', 'aria-hidden': 'true' }, cover),
    h('a', { class: 'place-back trip-back', href: '#/trips' }, icon('left'), h('span', {}, t('trips_all'))),
    h('div', { class: 'trip-head-main' },
      h('div', { class: 'trip-head-text' },
        h('span', { class: 'trip-head-kicker' }, kindText(trip)),
        h('h1', { class: 'trip-title' }, trip.title_auto ? tripName(trip) : trip.title),
        h('p', { class: 'trip-head-meta' }, ...metaBits),
        placeChips(trip, { max: 8, links: true }),
        actions),
      routeMap(trip)));
}

