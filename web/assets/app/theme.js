// OwnMemories — görünüm teması (sinema | galeri | yerel). Tema hesapta saklanır (PATCH /v1/me);
// <html data-skin> ve yalnızca etkin temanın stil dosyası açık tutulur. Temalar app.css'teki
// değişkenleri ve kanca sınıflarını geçersiz kılar; işaretleme tüm temalarda aynıdır.
import { api } from './api.js';
import { OM } from './util.js';

export const THEMES = ['sinema', 'galeri', 'yerel'];
export const DEFAULT_THEME = 'sinema';
const THEME_COLOR = { sinema: '#0b0b0e', galeri: '#f6f4f0', yerel: '#f2f2f7' };

export const currentTheme = () => (THEMES.includes(OM.user?.theme) ? OM.user.theme : DEFAULT_THEME);

/** Temayı sayfaya uygular (sunucuya yazmadan). */
export function applyTheme(name) {
  if (!THEMES.includes(name)) name = DEFAULT_THEME;
  for (const th of THEMES) {
    const link = document.getElementById('skin-' + th);
    if (link) link.disabled = th !== name;
  }
  document.documentElement.dataset.skin = name;
  document.getElementById('themeColor')?.setAttribute('content', THEME_COLOR[name]);
  document.dispatchEvent(new CustomEvent('om:theme', { detail: name }));
}

/** Temayı seçer: önce uygular, sonra hesaba kaydeder; hata olursa eskisine döner. */
export async function setTheme(name) {
  const prev = currentTheme();
  applyTheme(name);
  try {
    OM.user = (await api('PATCH', 'me', { theme: name })).user;
  } catch (e) {
    applyTheme(prev);
    throw e;
  }
}

/**
 * Ortam rengi: verilen görselin ortalama rengini --ambient (ve --ambient-rgb) olarak köke yazar.
 * Sinema teması arka plan parıltısında kullanır; diğer temalar yok sayabilir.
 */
export function setAmbientFrom(img) {
  const run = () => {
    try {
      const c = document.createElement('canvas');
      c.width = c.height = 8;
      const g = c.getContext('2d', { willReadFrequently: true });
      g.drawImage(img, 0, 0, 8, 8);
      const d = g.getImageData(0, 0, 8, 8).data;
      let r = 0, gg = 0, b = 0;
      for (let i = 0; i < d.length; i += 4) { r += d[i]; gg += d[i + 1]; b += d[i + 2]; }
      const n = d.length / 4;
      const rgb = [r / n, gg / n, b / n].map((v) => Math.round(v));
      const root = document.documentElement.style;
      root.setProperty('--ambient-rgb', rgb.join(', '));
      root.setProperty('--ambient', `rgb(${rgb.join(', ')})`);
    } catch { /* çapraz kaynak ya da bozuk görsel: yok say */ }
  };
  if (img.complete && img.naturalWidth) run();
  else img.addEventListener('load', run, { once: true });
}
