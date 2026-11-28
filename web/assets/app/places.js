// OwnMemories — Yerler: fotoğrafların çekildiği şehirler (ülkelere göre gruplu kartlar) ve yer sayfası.
// Yer adları sunucuda çevrimdışı bulunur (GeoNames); bu modül yalnızca /v1/places sonuçlarını gösterir.
import { api, previewUrl, thumbUrl } from './api.js';
import { h, t, icon, OM, formatDate } from './util.js';

const nf = () => new Intl.NumberFormat(OM.locale === 'en' ? 'en-GB' : 'tr-TR');
let cache = null; // {at, data} — Yerler ↔ yer sayfası arasında gidip gelirken yeniden sorgulamamak için (30 sn)

export function invalidatePlaces() { cache = null; }

async function loadPlaces() {
  if (cache && Date.now() - cache.at < 30000) return cache.data;
  const data = await api('GET', 'places');
  cache = { at: Date.now(), data };
  return data;
}

/** Harita | Yerler geçişi (iOS'teki bölümlü denetimin web karşılığı). */
export function viewSwitch(active) {
  const item = (key, href, ic) => h('a', {
    class: 'seg-item' + (active === key ? ' on' : ''), href, 'aria-current': active === key ? 'page' : null,
  }, icon(ic), h('span', {}, t(key === 'map' ? 'map_view' : key === 'trips' ? 'trips_view' : 'places_view')));
  return h('nav', { class: 'view-seg', 'aria-label': t('view_switch') }, item('map', '#/map', 'map'), item('places', '#/places', 'pin'), item('trips', '#/trips', 'suitcase'));
}

/** Ülkelere göre grupla: en çok öğeli ülke önce, ülke içinde yerler öğe sayısına göre. */
function byCountry(places) {
  const groups = new Map();
  for (const p of places) {
    const k = p.country_code || '??';
    if (!groups.has(k)) groups.set(k, { code: k, name: p.country || k, places: [], count: 0 });
    const g = groups.get(k);
    g.places.push(p);
    g.count += p.count;
  }
  return [...groups.values()].sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, 'tr'));
}

function placeCard(p, idx) {
  const img = h('img', { src: idx < 8 ? previewUrl(p.cover_asset_id) : thumbUrl(p.cover_asset_id), alt: '', loading: idx < 8 ? 'eager' : 'lazy', decoding: 'async' });
  img.addEventListener('load', () => img.classList.add('in'), { once: true });
  const sub = [p.admin1 && p.admin1 !== p.name ? p.admin1 : null].filter(Boolean).join(' · ');
  return h('a', {
    class: 'place-card', href: `#/place/${p.id}`, dataset: { id: p.id, country: p.country_code || '' },
    'aria-label': `${p.name}${sub ? ', ' + sub : ''}, ${t('place_photos', { n: nf().format(p.count) })}`,
  },
  h('span', { class: 'place-cover' }, img, h('span', { class: 'place-shade', 'aria-hidden': 'true' }),
    p.region && p.region !== p.name ? h('span', { class: 'place-region' }, p.region) : null),
  h('span', { class: 'place-text' },
    h('strong', { class: 'place-name' }, p.name),
    h('small', { class: 'place-meta' },
      sub ? h('span', { class: 'place-admin' }, sub) : null,
      h('span', { class: 'place-count' }, t('place_photos', { n: nf().format(p.count) })))));
}

/** #/places */
export async function renderPlaces(root, { setSub } = {}) {
  const head = h('div', { class: 'page-head places-head' },
    h('div', {}, h('h1', {}, t('places')), h('p', { class: 'places-sub muted' }, ' ')),
    viewSwitch('places'));
  const body = h('div', { class: 'places-body', 'aria-busy': 'true' },
    h('div', { class: 'places-grid is-skeleton', 'aria-hidden': 'true' }, ...Array.from({ length: 8 }, () => h('span', { class: 'place-card is-ghost' }, h('span', { class: 'place-cover' })))));
  root.replaceChildren(head, body);
  let data;
  try {
    data = await loadPlaces();
  } catch {
    body.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
    return;
  }
  if (!root.isConnected) return;
  body.removeAttribute('aria-busy');
  const places = data.places || [];
  const groups = byCountry(places);
  const subText = places.length ? t('places_sub', { n: nf().format(places.length), c: nf().format(groups.length) }) : '';
  head.querySelector('.places-sub').textContent = subText;
  setSub?.(subText);
  const status = data.pending > 0 && data.ready
    ? h('div', { class: 'places-status', role: 'status' }, h('span', { class: 'places-status-dot', 'aria-hidden': 'true' }), t('places_pending', { n: nf().format(data.pending) }))
    : null;
  if (!places.length) {
    body.replaceChildren(status || h('span'), h('div', { class: 'empty places-empty' },
      h('div', { class: 'places-empty-art', 'aria-hidden': 'true' }, icon('pin')),
      h('h2', {}, t('places_empty_title')),
      h('p', {}, data.ready ? t('places_empty') : t('places_no_data'))));
    return;
  }
  const sections = groups.map((g) => h('section', { class: 'places-section', dataset: { country: g.code }, 'aria-labelledby': `pc-${g.code}` },
    h('div', { class: 'places-section-head' },
      h('h2', { id: `pc-${g.code}` }, g.name, ' ', h('span', { class: 'places-section-count' }, t('place_country_count', { n: nf().format(g.places.length) })))),
    h('div', { class: 'places-grid' }, ...g.places.map((p, i) => placeCard(p, i)))));
  body.replaceChildren(...[status, ...sections, h('p', { class: 'places-attrib' }, t('places_attribution'))].filter(Boolean));
}

/** Yer sayfası başlığı (zaman çizelgesinin üstü). */
export function placeHeader(p) {
  const when = p.first_at && p.last_at
    ? (p.first_at.slice(0, 10) === p.last_at.slice(0, 10) ? formatDate(p.first_at) : t('place_range', { from: formatDate(p.first_at), to: formatDate(p.last_at) }))
    : '';
  const where = [p.admin1 && p.admin1 !== p.name ? p.admin1 : null, p.country].filter(Boolean).join(', ');
  return h('div', { class: 'place-head' },
    h('a', { class: 'place-back', href: '#/places' }, icon('left'), h('span', {}, t('places_all'))),
    h('div', { class: 'place-head-text' },
      p.region && p.region !== p.name ? h('span', { class: 'place-head-region' }, p.region) : null,
      h('h1', { class: 'place-title' }, p.name),
      h('p', { class: 'place-head-meta' },
        where ? h('span', { class: 'place-head-where' }, icon('pin'), where) : null,
        h('span', { class: 'place-head-count' }, t('place_photos', { n: nf().format(p.count) })),
        when ? h('span', { class: 'place-head-when' }, when) : null)));
}
