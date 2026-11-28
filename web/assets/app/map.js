// OwnMemories — harita görünümü (MapLibre GL + OpenFreeMap vektör altlıkları, fotoğraf kümeleri).
// OpenStreetMap'in gönüllü karo sunucuları uygulamalardan doğrudan kullanımı engelliyor (403 "Access blocked"),
// CARTO artık anahtar istiyor. OpenFreeMap ücretsiz, anahtarsız ve sınırsızdır; stil temaya göre seçilir.
// Kümeler Apple Fotoğraflar gibi: kapak küçük resmi + öğe sayısı. Kümeye dokununca yakınlaşır; en yakın
// düzeyde hâlâ kümeyse içindeki fotoğraflar görüntüleyicide açılır.
import { api, thumbUrl } from './api.js';
import { h, t } from './util.js';
import { currentTheme } from './theme.js';

const CDN = 'https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/5.6.0';
const STYLES = 'https://tiles.openfreemap.org/styles';
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

function maplibre() {
  loading ??= (async () => {
    loadCss(`${CDN}/maplibre-gl.min.css`);
    await loadScript(`${CDN}/maplibre-gl.min.js`);
  })();
  return loading;
}

const darkQuery = () => window.matchMedia?.('(prefers-color-scheme: dark)');

/** Temaya göre stil: Sinema koyu, Galeri sade açık, Yerel renkli (sistem koyu moddaysa koyu). */
function styleUrl() {
  const theme = currentTheme();
  if (theme === 'sinema') return `${STYLES}/dark`;
  if (theme === 'galeri') return `${STYLES}/positron`;
  return darkQuery()?.matches ? `${STYLES}/dark` : `${STYLES}/liberty`;
}

function countLabel(n) {
  return n >= 1000 ? `${Math.floor(n / 100) / 10}B` : String(n);
}

export async function renderMap(root, onOpen) {
  const box = h('div', { class: 'map' });
  root.replaceChildren(h('div', { class: 'page-head' }, h('h1', {}, t('map'))), box);
  let points;
  try {
    [points] = await Promise.all([api('GET', 'assets/map').then((r) => r.points), maplibre()]);
  } catch {
    box.replaceChildren(h('div', { class: 'empty' }, h('p', {}, t('load_failed'))));
    return;
  }
  if (!points.length) {
    box.replaceWith(h('div', { class: 'empty' }, h('p', {}, t('empty_map'))));
    return;
  }
  if (!box.isConnected) return;   // bu arada başka sayfaya geçildi

  const gl = window.maplibregl;
  const byId = new Map(points.map((p) => [p.id, p]));
  const bounds = new gl.LngLatBounds();
  for (const p of points) bounds.extend([p.lng, p.lat]);

  const map = new gl.Map({
    container: box,
    style: styleUrl(),
    bounds,
    fitBoundsOptions: { padding: 50, maxZoom: 14 },
    attributionControl: { compact: true },
    renderWorldCopies: true,
    dragRotate: false,
    pitchWithRotate: false,
  });
  map.touchZoomRotate.disableRotation();
  map.addControl(new gl.NavigationControl({ showCompass: false }), 'top-left');

  const data = {
    type: 'FeatureCollection',
    features: points.map((p) => ({ type: 'Feature', properties: { id: p.id }, geometry: { type: 'Point', coordinates: [p.lng, p.lat] } })),
  };

  // Stil değişince (tema) kaynak yeniden eklenir; HTML işaretçiler kalır, yalnızca içerikleri yenilenir.
  function addSource() {
    if (map.getSource('photos')) return;
    map.addSource('photos', { type: 'geojson', data, cluster: true, clusterRadius: 56, clusterMaxZoom: 17 });
    // Görünmez katman: kaynağın döşenip sorgulanabilmesi için gerekli.
    map.addLayer({ id: 'photos-hit', type: 'circle', source: 'photos', paint: { 'circle-radius': 1, 'circle-opacity': 0 } });
  }

  const markers = new Map();   // anahtar → { marker, el }
  let onScreen = new Set();

  function openCluster(clusterId, count) {
    const src = map.getSource('photos');
    src.getClusterExpansionZoom(clusterId).then((zoom) => {
      const maxed = zoom > map.getMaxZoom() || map.getZoom() >= 17;
      if (!maxed) {
        const f = markers.get('c' + clusterId);
        if (f) map.easeTo({ center: f.marker.getLngLat(), zoom: Math.min(zoom, 18) });
        return;
      }
      // Aynı noktada çok fotoğraf: hepsi görüntüleyicide.
      src.getClusterLeaves(clusterId, Math.min(count, 500), 0).then((leaves) => {
        const ids = leaves.map((l) => l.properties.id);
        const list = ids.map((id) => byId.get(id)).filter(Boolean);
        if (list.length) onOpen(list[0].id, list);
      });
    }).catch(() => {});
  }

  function markerFor(key, feature) {
    let entry = markers.get(key);
    if (entry) return entry;
    const props = feature.properties;
    const isCluster = !!props.cluster;
    const img = h('img', { alt: '', loading: 'lazy', decoding: 'async' });
    const el = h('button', {
      type: 'button',
      class: 'map-thumb' + (isCluster ? ' is-cluster' : ''),
      'aria-label': isCluster ? t('map_cluster', { n: props.point_count }) : t('open_photo'),
    }, img, isCluster ? h('span', { class: 'map-count' }, countLabel(props.point_count)) : null);
    if (isCluster) {
      // Küme kapağı: kümedeki ilk fotoğraf.
      map.getSource('photos').getClusterLeaves(props.cluster_id, 1, 0)
        .then((leaves) => { if (leaves[0]) img.src = thumbUrl(leaves[0].properties.id); })
        .catch(() => {});
      el.addEventListener('click', (e) => { e.stopPropagation(); openCluster(props.cluster_id, props.point_count); });
    } else {
      img.src = thumbUrl(props.id);
      el.addEventListener('click', (e) => { e.stopPropagation(); onOpen(props.id, points); });
    }
    const marker = new gl.Marker({ element: el, anchor: 'center' }).setLngLat(feature.geometry.coordinates);
    entry = { marker, el };
    markers.set(key, entry);
    return entry;
  }

  function sync() {
    if (!map.getSource('photos') || !map.isSourceLoaded('photos')) return;
    const next = new Set();
    for (const f of map.querySourceFeatures('photos')) {
      const key = f.properties.cluster ? 'c' + f.properties.cluster_id : 'p' + f.properties.id;
      if (next.has(key)) continue;   // karo sınırlarında aynı nesne birden çok gelebilir
      next.add(key);
      const entry = markerFor(key, f);
      if (!onScreen.has(key)) entry.marker.addTo(map);
    }
    for (const key of onScreen) {
      if (!next.has(key)) markers.get(key)?.marker.remove();
    }
    // Küme kimlikleri yakınlaştırmayla değişir: kullanılmayan kayıtlar atılır (bellek).
    if (markers.size > 2000) for (const [k] of markers) if (!next.has(k)) markers.delete(k);
    onScreen = next;
  }

  map.on('load', () => { addSource(); sync(); });
  map.on('styledata', () => { if (map.isStyleLoaded()) addSource(); });
  map.on('sourcedata', (e) => { if (e.sourceId === 'photos' && e.isSourceLoaded) sync(); });
  map.on('moveend', sync);

  // Tema ya da sistem görünümü değişince stil değişir.
  const restyle = () => {
    if (!box.isConnected) { cleanup(); return; }
    map.setStyle(styleUrl());
  };
  const mq = darkQuery();
  function cleanup() {
    document.removeEventListener('om:theme', restyle);
    mq?.removeEventListener?.('change', restyle);
    map.remove();
  }
  document.addEventListener('om:theme', restyle);
  mq?.addEventListener?.('change', restyle);
  // Sayfadan çıkılınca harita ve dinleyiciler bırakılır.
  const host = document.getElementById('view') || root;
  const watch = new MutationObserver(() => {
    if (!box.isConnected) { watch.disconnect(); cleanup(); }
  });
  watch.observe(host, { childList: true, subtree: true });
}
