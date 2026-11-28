// OwnMemories — fotoğraftaki yazı (OCR): arama sonucu rozeti ve görüntüleyici bilgi panelindeki "Fotoğraftaki yazı".
// Yazıyı sunucudaki ownmemories-ml okur (asset_text); arama eşleşmeleri GET /v1/search'te asset.text_match olarak gelir:
//   {term: "fatura", snippet: "ELEKTRİK FATURASI", parts: [{t: "ELEKTRİK ", m: false}, {t: "FATURASI", m: true}]}
/*
 * Kanca sınıfları (tema CSS'i için; app.css'te nötr taban, theme-*.css'in sonunda "Fotoğraftaki yazı" bölümü):
 *   Arama karosu:  .tile.has-ocr > .tile-ocr[title] > .tile-ocr-ic (.ic) + .tile-ocr-text > span | mark.tile-ocr-hit
 *   Arama başlığı: p.search-ocr (.ic + "“fatura” yazısı 3 fotoğrafta bulundu")   (.search-head-text içinde, .search-mode'dan sonra)
 *   Bilgi paneli:  section.i-ocr[data-state="done"](.is-open) > .i-ocr-head > button.i-ocr-toggle[aria-expanded]
 *                    (> .i-ocr-ic + .i-ocr-title + .i-ocr-count + .i-ocr-chev) + button.icon-btn.i-ocr-copy
 *                  > .i-ocr-body#… > p.i-ocr-line (> span | mark.i-ocr-hit)…
 */
import { api } from './api.js';
import { h, t, toast } from './util.js';

const TEXT_IC = '<svg viewBox="0 0 24 24"><path d="M4 7V5.5A1.5 1.5 0 0 1 5.5 4H7M17 4h1.5A1.5 1.5 0 0 1 20 5.5V7M20 17v1.5a1.5 1.5 0 0 1-1.5 1.5H17M7 20H5.5A1.5 1.5 0 0 1 4 18.5V17"/><path d="M8.5 8.5h7M12 8.5v7.5"/></svg>';
const COPY_IC = '<svg viewBox="0 0 24 24"><rect x="8.5" y="8.5" width="11" height="11" rx="2.2"/><path d="M15.5 8.5V6.2a1.7 1.7 0 0 0-1.7-1.7H6.2a1.7 1.7 0 0 0-1.7 1.7v7.6a1.7 1.7 0 0 0 1.7 1.7h2.3"/></svg>';
const CHEV_IC = '<svg viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"/></svg>';
const OPEN_KEY = 'om_ocr_open';

const ic = (svg, cls = 'ic') => { const s = document.createElement('span'); s.className = cls; s.setAttribute('aria-hidden', 'true'); s.innerHTML = svg; return s; };

/** Türkçe duyarlı katlama (sunucudaki ocr_fold ile aynı amaç: İ/ı, aksanlar). */
export function fold(s) {
  return String(s || '').replace(/İ/g, 'i').replace(/I/g, 'ı').toLocaleLowerCase('tr-TR')
    .replace(/[ıîìí]/g, 'i').replace(/ş/g, 's').replace(/ğ/g, 'g').replace(/ç/g, 'c').replace(/ö/g, 'o').replace(/ü/g, 'u')
    .normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, ' ').trim();
}

/** Sorgu sözcükleri (katlanmış). */
export const queryTokens = (q) => [...new Set(fold(q).split(' ').filter(Boolean))];

/** Bir özgün sözcük sorgu sözcüklerinden biriyle eşleşiyor mu (tam, sözcük başı ≥3, sözcük içi ≥4). */
function wordHit(word, tokens) {
  const parts = fold(word).split(' ').filter(Boolean);
  return parts.some((w) => tokens.some((tk) => w === tk || (tk.length >= 3 && w.startsWith(tk)) || (tk.length >= 4 && w.includes(tk))));
}

/** Satırı span/mark parçalarına böler. */
function highlight(line, tokens, markCls) {
  if (!tokens.length) return [h('span', {}, line)];
  const out = [];
  for (const seg of line.split(/([\p{L}\p{N}]+)/u)) {
    if (!seg) continue;
    const hit = /^[\p{L}\p{N}]+$/u.test(seg) && wordHit(seg, tokens);
    out.push(hit ? h('mark', { class: markCls }, seg) : h('span', {}, seg));
  }
  return out;
}

/** Karoya sığsın: eşleşmenin bir sözcük öncesi + eşleşme(ler) + bir sözcük sonrası. */
function compactParts(parts) {
  const i = parts.findIndex((p) => p.m);
  if (i < 0) return parts.slice(0, 3);
  let j = i;
  while (j + 2 < parts.length && parts[j + 2]?.m && /^\s+$/.test(parts[j + 1].t)) j += 2;
  const lead = i > 0 ? parts[i - 1].t.replace(/^.*?([\p{L}\p{N}]+[^\p{L}\p{N}]*)$/su, '$1') : '';
  const tail = parts[j + 1] ? parts[j + 1].t.replace(/^([^\p{L}\p{N}]*[\p{L}\p{N}]+).*$/su, '$1') : '';
  const out = [];
  if (i > 1 || (i === 1 && lead !== parts[0].t)) out.push({ t: '…', m: false });
  if (lead) out.push({ t: lead, m: false });
  out.push(...parts.slice(i, j + 1));
  if (tail) out.push({ t: tail, m: false });
  if (j + 2 < parts.length || (parts[j + 1] && tail !== parts[j + 1].t)) out.push({ t: '…', m: false });
  return out;
}

/** Arama karosu rozeti: .tile-ocr (yazıda bulunan sözcük vurgulu). */
export function tileBadge(a) {
  const m = a.text_match;
  const parts = compactParts(m.parts?.length ? m.parts : [{ t: m.snippet || '', m: false }]);
  const label = t('ocr_badge_label', { snippet: m.snippet || '' });
  return h('div', { class: 'tile-ocr', title: label, role: 'note', 'aria-label': label },
    ic(TEXT_IC, 'ic tile-ocr-ic'),
    h('span', { class: 'tile-ocr-text' }, ...parts.map((p) => (p.m ? h('mark', { class: 'tile-ocr-hit' }, p.t) : h('span', {}, p.t)))));
}

/** Arama başlığındaki özet satırı ("“fatura” yazısı 3 fotoğrafta bulundu") ya da null. */
export function searchSummary(r, q) {
  const n = r.text_matches || 0;
  if (!n) return null;
  return h('p', { class: 'search-ocr' }, ic(TEXT_IC), h('span', {}, t(n === 1 ? 'ocr_found_one' : 'ocr_found_n', { q, n: n.toLocaleString('tr-TR') })));
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    // eski tarayıcı / güvenli olmayan bağlam: gizli metin alanıyla
    const ta = h('textarea', { style: 'position:fixed;opacity:0;pointer-events:none', 'aria-hidden': 'true' });
    ta.value = text;
    document.body.append(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    ta.remove();
    return ok;
  }
}

let seq = 0;

/**
 * Görüntüleyici bilgi paneli bölümü. Önce boş bir yer tutucu döner; yazı yüklenince doldurulur.
 * Yazı yoksa (none/pending/unavailable) bölüm gizli kalır. term: aramadan gelindiyse vurgulanacak sorgu.
 */
export function infoSection(assetId, term = '') {
  const sec = h('section', { class: 'i-ocr', dataset: { state: 'loading' }, hidden: true });
  api('GET', `assets/${assetId}/text`).then((r) => {
    if (r.status !== 'done' || !r.lines?.length) return;
    fill(sec, r, term);
  }).catch(() => { /* bölüm gizli kalır */ });
  return sec;
}

function fill(sec, r, term) {
  const tokens = term ? queryTokens(term) : [];
  const bodyId = 'iocr' + (++seq);
  let stored = null;
  try { stored = localStorage.getItem(OPEN_KEY); } catch { stored = null; }
  // Aramadan gelindiyse ya da kısa yazıysa açık; aksi halde kullanıcının son seçimi (varsayılan kapalı).
  let open = tokens.length > 0 || r.lines.length <= 4 || stored === '1';
  const body = h('div', { class: 'i-ocr-body', id: bodyId, lang: r.lang || 'tr' },
    ...r.lines.map((l) => h('p', { class: 'i-ocr-line' }, ...highlight(l, tokens, 'i-ocr-hit'))));
  const toggle = h('button', { type: 'button', class: 'i-ocr-toggle', 'aria-controls': bodyId },
    ic(TEXT_IC, 'ic i-ocr-ic'), h('span', { class: 'i-ocr-title' }, t('ocr_title')),
    h('span', { class: 'i-ocr-count' }, t('ocr_words', { n: Number(r.words || 0).toLocaleString('tr-TR') })),
    ic(CHEV_IC, 'ic i-ocr-chev'));
  const set = (v, remember) => {
    open = v;
    sec.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    body.hidden = !open;
    if (remember) { try { localStorage.setItem(OPEN_KEY, open ? '1' : '0'); } catch { /* yok say */ } }
  };
  toggle.addEventListener('click', () => set(!open, true));
  const copy = h('button', { type: 'button', class: 'icon-btn i-ocr-copy', 'aria-label': t('ocr_copy'), title: t('ocr_copy') });
  copy.append(ic(COPY_IC));
  copy.addEventListener('click', async () => {
    const ok = await copyText(r.lines.join('\n'));
    toast(t(ok ? 'ocr_copied' : 'ocr_copy_failed'));
    if (ok) { copy.classList.add('done'); setTimeout(() => copy.classList.remove('done'), 1400); }
  });
  sec.replaceChildren(h('div', { class: 'i-ocr-head' }, toggle, copy), body);
  sec.dataset.state = 'done';
  sec.hidden = false;
  set(open, false);
}
