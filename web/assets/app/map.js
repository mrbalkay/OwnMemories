// OwnMemories — harita görünümü (Leaflet + OpenStreetMap, kümeleme).
import { api } from './api.js';
import { h, t } from './util.js';

const CDN = 'https://cdnjs.cloudflare.com/ajax/libs';
let loading = null;

function loadCss(href) {
  if (document.querySelector(`link[href="${href}"]`)) return;
  document.head.append(h('link', { rel: 'stylesheet', href }));
}

function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) { resolve(); return; }
    const s = h('script', { src });
    s.onload = resolve;
    s.onerror = reject;
    document.head.append(s);
  });
}

function leaflet() {
  loading ??= (async () => {
    loadCss(`${CDN}/leaflet/1.9.4/leaflet.min.css`);
    loadCss(`${CDN}/leaflet.markercluster/1.5.3/MarkerCluster.min.css`);
    loadCss(`${CDN}/leaflet.markercluster/1.5.3/MarkerCluster.Default.min.css`);
    await loadScript(`${CDN}/leaflet/1.9.4/leaflet.min.js`);
    await loadScript(`${CDN}/leaflet.markercluster/1.5.3/leaflet.markercluster.min.js`);
  })();
  return loading;
}

export async function renderMap(root, onOpen) {
  const box = h('div', { class: 'map' });
  root.replaceChildren(h('div', { class: 'page-head' }, h('h1', {}, t('map'))), box);
  let points;
  try {
    [points] = await Promise.all([api('GET', 'assets/map').then((r) => r.points), leaflet()]);
  } catch {
    box.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
    return;
  }
  if (!points.length) {
    box.replaceWith(h('div', { class: 'empty' }, h('p', {}, t('empty_map'))));
    return;
  }
  const L = window.L;
  const map = L.map(box, { worldCopyJump: true });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
  const cluster = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50 });
  for (const p of points) {
    const icon = L.divIcon({ className: 'map-thumb', html: `<img src="/api/v1/assets/${p.id}/thumb" alt="" loading="lazy">`, iconSize: [52, 52] });
    const m = L.marker([p.lat, p.lng], { icon });
    m.on('click', () => onOpen(p.id, points));
    cluster.addLayer(m);
  }
  map.addLayer(cluster);
  map.fitBounds(cluster.getBounds(), { padding: [30, 30], maxZoom: 14 });
}
