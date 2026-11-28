// OwnMemories — harita görünümü (MapLibre GL + OpenFreeMap vektör altlıkları, fotoğraf kümeleri).
// OpenStreetMap'in gönüllü karo sunucuları uygulamalardan doğrudan kullanımı engelliyor (403 "Access blocked"),
// CARTO artık anahtar istiyor. OpenFreeMap ücretsiz, anahtarsız ve sınırsızdır; stil temaya göre seçilir.
// Kümeler Apple Fotoğraflar gibi: kapak küçük resmi + öğe sayısı. Kümeye dokununca yakınlaşır; en yakın
// düzeyde hâlâ kümeyse içindeki fotoğraflar görüntüleyicide açılır.
import { api, thumbUrl } from './api.js';
import { h, t } from './util.js';
import { currentTheme } from './theme.js';
import { viewSwitch } from './places.js';

const CDN = 'https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/5.6.0';
const STYLES = 'https://tiles.openfreemap.org/styles';
const MAX_ZOOM = 19;
// İşaretçiler ~60 px; yarıçap bundan küçük olursa komşu kümeler üst üste biner.
const CLUSTER_RADIUS = 80;
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
  })().catch((e) => {
    // Başarısız yükleme kalıcı olmasın: sonraki ziyaret yeniden dener.
    document.querySelector(`script[src="${CDN}/maplibre-gl.min.js"]`)?.remove();
    loading = null;
    throw e;
  });
  return loading;
}

const darkQuery = () => window.matchMedia?.('(prefers-color-scheme: dark)');

/** Temaya göre stil: Sinema koyu, Galeri sade açık, Yerel renkli (sistem koyu moddaysa koyu).
 *  `om:theme` olayı OM.user güncellenmeden önce gelir; bu yüzden tema olaydan da verilebilir. */
function styleUrl(theme = currentTheme()) {
  if (theme === 'sinema') return `${STYLES}/dark`;
  if (theme === 'galeri') return `${STYLES}/positron`;
  return darkQuery()?.matches ? `${STYLES}/dark` : `${STYLES}/liberty`;
}

function countLabel(n) {
  return n >= 1000 ? `${Math.floor(n / 100) / 10}B` : String(n);
}

export async function renderMap(root, onOpen) {
  const box = h('div', { class: 'map' });
  root.replaceChildren(h('div', { class: 'page-head map-head' }, h('h1', {}, t('map')), viewSwitch('map')), box);
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
    fitBoundsOptions: { padding: 72, maxZoom: 14 },
    attributionControl: { compact: true },
    renderWorldCopies: true,
    // Küme en yakın düzeyde de çözülmüyorsa (aynı yerde çekilmiş fotoğraflar) görüntüleyici açılır.
    maxZoom: MAX_ZOOM,
    dragRotate: false,
    pitchWithRotate: false,
  });
  map.touchZoomRotate.disableRotation();
  map.addControl(new gl.NavigationControl({ showCompass: false }), 'top-left');

  const data = {
    type: 'FeatureCollection',
    features: points.map((p) => ({ type: 'Feature', properties: { id: p.id, city: p.city || '' }, geometry: { type: 'Point', coordinates: [p.lng, p.lat] } })),
  };

  // clusterMaxZoom haritanın en yakın düzeyine eşit: aynı noktadaki fotoğraflar her düzeyde kümede kalır,
  // genişleme düzeyleri MAX_ZOOM'u aşar ve dokunulunca görüntüleyicide açılır (üst üste yığılmaz).
  // GeoJSON kaynağının maxzoom'u clusterMaxZoom'dan büyük olmalı (yoksa MapLibre kümeyi kırpar).
  const SOURCE = { type: 'geojson', data, cluster: true, clusterRadius: CLUSTER_RADIUS, clusterMaxZoom: MAX_ZOOM, maxzoom: MAX_ZOOM + 1 };
  // Görünmez katman: kaynağın döşenip sorgulanabilmesi için gerekli.
  const LAYER = { id: 'photos-hit', type: 'circle', source: 'photos', paint: { 'circle-radius': 1, 'circle-opacity': 0 } };
  function addSource() {
    if (map.getSource('photos')) return;
    map.addSource('photos', SOURCE);
    map.addLayer(LAYER);
  }

  const markers = new Map();   // anahtar → { marker, el }
  let onScreen = new Set();

  function openCluster(clusterId, count) {
    const src = map.getSource('photos');
    src.getClusterExpansionZoom(clusterId).then((zoom) => {
      if (zoom <= map.getMaxZoom() && map.getZoom() < map.getMaxZoom()) {
        const f = markers.get('c' + clusterId);
        if (f) map.easeTo({ center: f.marker.getLngLat(), zoom });
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
    const tip = h('span', { class: 'map-tip', 'aria-hidden': 'true' });
    const el = h('button', {
      type: 'button',
      class: 'map-thumb' + (isCluster ? ' is-cluster' : ''),
      'aria-label': isCluster ? t('map_cluster', { n: props.point_count }) : t('open_photo'),
    }, img, isCluster ? h('span', { class: 'map-count' }, countLabel(props.point_count)) : null, tip);
    // İpucu: yer adı (sunucuda çevrimdışı bulunur). Kümede en çok fotoğrafı olan şehir(ler).
    const setTip = (cities, n) => {
      const names = [...cities.entries()].filter(([c]) => c).sort((a, b) => b[1] - a[1]).map(([c]) => c);
      if (!names.length) return;
      const place = names.length > 2 ? `${names[0]}, ${names[1]} +${names.length - 2}` : names.join(', ');
      const label = n > 1 ? t('map_place', { place, n }) : place;
      tip.textContent = label;
      el.classList.add('has-tip');
      el.setAttribute('aria-label', n > 1 ? `${t('map_cluster', { n })}: ${place}` : `${t('open_photo')}: ${place}`);
    };
    if (isCluster) {
      // Küme kapağı: kümedeki ilk fotoğraf; ipucu için en çok 300 yaprağın şehirleri sayılır.
      map.getSource('photos').getClusterLeaves(props.cluster_id, Math.min(300, props.point_count), 0)
        .then((leaves) => {
          if (leaves[0]) img.src = thumbUrl(leaves[0].properties.id);
          const cities = new Map();
          for (const l of leaves) cities.set(l.properties.city, (cities.get(l.properties.city) || 0) + 1);
          setTip(cities, props.point_count);
        })
        .catch(() => {});
      el.addEventListener('click', (e) => { e.stopPropagation(); openCluster(props.cluster_id, props.point_count); });
    } else {
      img.src = thumbUrl(props.id);
      setTip(new Map([[props.city, 1]]), 1);
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

  map.on('load', () => {
    addSource();
    sync();
    // Dar ekranda atıf kutusu açık başlar ve haritanın altını kaplar: kapalı başlat (ⓘ ile açılır).
    if (box.clientWidth < 640) {
      const attrib = box.querySelector('.maplibregl-ctrl-attrib');
      attrib?.classList.remove('maplibregl-compact-show');
      attrib?.removeAttribute('open');
    }
  });
  map.on('style.load', addSource);   // yedek: kaynak her durumda yeni stilde de bulunsun
  map.on('sourcedata', (e) => { if (e.sourceId === 'photos' && e.isSourceLoaded) sync(); });
  map.on('moveend', sync);
  // Yakınlaşma sonrası eski düzeyin karoları solma süresince sorgu sonucunda kalır; son geçiş tüm
  // karolar oturduğunda yapılır, yoksa eski kümeler ekranda takılı kalır.
  map.on('idle', sync);

  // Tema ya da sistem görünümü değişince stil değişir; fotoğraf kaynağı ve katmanı yeni stile taşınır.
  let styleName = currentTheme();
  const restyle = (e) => {
    if (!box.isConnected) { cleanup(); return; }
    if (e?.type === 'om:theme' && e.detail) styleName = e.detail;
    map.setStyle(styleUrl(styleName), {
      transformStyle: (prev, next) => ({
        ...next,
        sources: { ...next.sources, photos: SOURCE },
        layers: [...next.layers, LAYER],
      }),
    });
  };
  const mq = darkQuery();
  let watch = null;
  let disposed = false;
  // Tek sefer çalışır (gözlemci ve tema olayı aynı anda tetikleyebilir; map.remove() iki kez çağrılmaz).
  function cleanup() {
    if (disposed) return;
    disposed = true;
    watch?.disconnect();
    document.removeEventListener('om:theme', restyle);
    mq?.removeEventListener?.('change', restyle);
    markers.clear();
    map.remove();
  }
  document.addEventListener('om:theme', restyle);
  mq?.addEventListener?.('change', restyle);
  // Sayfadan çıkılınca harita ve dinleyiciler bırakılır.
  const host = document.getElementById('view') || root;
  watch = new MutationObserver(() => { if (!box.isConnected) cleanup(); });
  watch.observe(host, { childList: true, subtree: true });
}
