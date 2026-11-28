// OwnMemories — bağlantıyla paylaşım uçtan uca testi (Playwright). Kurulu bir sunucuya karşı:
//   OM_E2E_URL=http://127.0.0.1:8080 OM_E2E_EMAIL=... OM_E2E_PASS=... node tests/e2e/share.mjs
// Kütüphanede en az bir albüm (2+ fotoğraf) olmalı. Paylaşım adresi sunucunun shares.public_url ayarından gelir
// (ayrı paylaşım portu); bağlantılar çerezsiz yeni bir tarayıcı bağlamında açılır. Ekranlar tests/e2e/screens/.
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
const { chromium } = createRequire(import.meta.url)('playwright');

const base = process.env.OM_E2E_URL || 'http://127.0.0.1:8080';
const email = process.env.OM_E2E_EMAIL;
const pass = process.env.OM_E2E_PASS;
const out = new URL('./screens/', import.meta.url).pathname;
mkdirSync(out, { recursive: true });
const step = (s) => console.log('✓', s);
const fail = (s) => { console.error('✗', s); process.exitCode = 1; };
const expect = (c, s) => (c ? step(s) : fail(s));

const browser = await chromium.launch();
const errors = [];
const watch = (page, tag, origin) => {
  page.on('pageerror', (e) => errors.push(`${tag}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 4(01|03|04|10|29)/.test(m.text())) errors.push(`${tag} console: ${m.text()}`); });
  if (origin) page.on('request', (r) => { if (!r.url().startsWith(origin) && !r.url().startsWith('data:') && !r.url().startsWith('blob:')) errors.push(`${tag}: üçüncü taraf istek ${r.url()}`); });
};

// ---------------------------------------------------------------- sahip: bağlantı oluştur
const owner = await browser.newPage({ viewport: { width: 1440, height: 900 } });
watch(owner, 'owner');
await owner.goto(base + '/login.php');
await owner.fill('#email', email);
await owner.fill('#password', pass);
await Promise.all([owner.waitForURL('**/app/**'), owner.click('button[type=submit]')]);
await owner.goto(base + '/app/#/albums');
await owner.waitForSelector('.album-card');
await owner.click('.album-card >> nth=0');
await owner.waitForSelector('.album-link-btn');
const listIds = () => owner.evaluate(async () => (await (await fetch('/api/v1/shares')).json()).links.map((l) => l.id));
const before = new Set(await listIds());
await owner.click('.album-link-btn');
await owner.waitForSelector('dialog.sl-dialog[open]');
await owner.screenshot({ path: `${out}share-owner-dialog.png` });
await owner.click('.sl-seg-opt:has-text("1 hafta")');
await owner.click('dialog.sl-dialog button[type=submit]');
await owner.waitForSelector('.sl-url');
const url = await owner.inputValue('.sl-url');
expect(/\/s\/[A-Za-z0-9_-]{43}$/.test(url), `bağlantı oluştu: ${url}`);
expect(await owner.$('.sl-qr svg path') !== null, 'QR kodu çizildi');
const firstId = (await listIds()).find((id) => !before.has(id));
await owner.screenshot({ path: `${out}share-owner-result.png` });
await owner.click('dialog.sl-dialog .btn-primary:has-text("Bitti")');

// Seçimden parolalı bağlantı (konum açık, indirme kapalı)
await owner.goto(base + '/app/#/photos');
await owner.waitForSelector('.tile');
await owner.hover('.tile >> nth=0');
await owner.click('.tile >> nth=0 >> .tile-check').catch(() => {});
if (!(await owner.$('#selbar:not([hidden])'))) await owner.click('.tile >> nth=0', { modifiers: ['Control'] });
await owner.waitForSelector('#selbar:not([hidden])');
await owner.click('#selbar button:has-text("Bağlantıyla paylaş")');
await owner.waitForSelector('dialog.sl-dialog[open]');
await owner.fill('dialog.sl-dialog .sl-pw input', 'anneanne2025');
await owner.click('dialog.sl-dialog label.sl-toggle:has-text("İndirmeye izin ver")');
await owner.click('dialog.sl-dialog button[type=submit]');
await owner.waitForSelector('.sl-url');
const pwUrl = await owner.inputValue('.sl-url');
step(`parolalı seçim bağlantısı: ${pwUrl}`);
await owner.click('dialog.sl-dialog .btn-primary:has-text("Bitti")');

// ------------------------------------------------------- ziyaretçi: çerezsiz yeni bağlam
const origin = new URL(url).origin;
for (const [w, h, tag] of [[1440, 900, 'desktop'], [390, 844, 'mobile']]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: tag === 'mobile' ? 2 : 1, hasTouch: tag === 'mobile', acceptDownloads: true });
  expect((await ctx.cookies()).length === 0, `${tag}: çerezsiz bağlam`);
  const p = await ctx.newPage();
  watch(p, tag, origin);
  const res = await p.goto(url);
  expect(res.status() === 200, `${tag}: galeri 200`);
  const hdr = res.headers();
  expect(/default-src 'none'/.test(hdr['content-security-policy'] || '') && /noindex/.test(hdr['x-robots-tag'] || '') && hdr['referrer-policy'] === 'no-referrer', `${tag}: CSP, noindex, no-referrer`);
  expect(!('set-cookie' in hdr) || !/om_sid/.test(hdr['set-cookie']), `${tag}: oturum çerezi yok`);
  await p.waitForSelector('.grid.is-laid .tile img');
  await p.waitForTimeout(800);
  await p.screenshot({ path: `${out}share-gallery-${tag}.png` });
  await p.screenshot({ path: `${out}share-gallery-${tag}-full.png`, fullPage: true });
  await p.click('.tile >> nth=1');
  await p.waitForSelector('.lb.in img.lb-media.ready, .lb.in video');
  expect((await p.textContent('.lb-count')).trim().startsWith('2 /'), `${tag}: görüntüleyici 2. öğede`);
  if (tag === 'desktop') {
    await p.keyboard.press('ArrowRight');
    await p.waitForTimeout(400);
    expect((await p.textContent('.lb-count')).trim().startsWith('3 /'), 'klavye → sonraki');
  } else {
    const box = await p.$eval('.lb-stage', (e) => { const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
    await p.evaluate(({ x, y }) => {
      const st = document.querySelector('.lb-stage');
      const ev = (type, cx) => st.dispatchEvent(new PointerEvent(type, { pointerId: 7, pointerType: 'touch', isPrimary: true, clientX: cx, clientY: y, bubbles: true }));
      ev('pointerdown', x + 120); ev('pointermove', x); ev('pointermove', x - 120); ev('pointerup', x - 120);
    }, box);
    await p.waitForTimeout(400);
    expect((await p.textContent('.lb-count')).trim().startsWith('3 /'), 'kaydırma → sonraki');
  }
  await p.waitForTimeout(500);
  await p.screenshot({ path: `${out}share-lightbox-${tag}.png` });
  await p.keyboard.press('Escape');
  await p.waitForTimeout(300);
  expect(!(await p.$('.lb.in')), `${tag}: Esc kapatır`);
  if (tag === 'desktop') {
    const [dl] = await Promise.all([p.waitForEvent('download'), p.click('.hero-actions a')]);
    const path = `${out}share-download.zip`;
    await dl.saveAs(path);
    const buf = readFileSync(path);
    expect(buf.length > 1000 && buf.readUInt32LE(0) === 0x04034b50, `zip indirildi (${buf.length} bayt): ${dl.suggestedFilename()}`);
  }
  await ctx.close();
}

// ------------------------------------------------------------------- parola sayfası
{
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  watch(p, 'password', origin);
  const r = await p.goto(pwUrl);
  expect(r.status() === 401 && (await p.$('input[type=password]')) !== null, 'parola sayfası (401)');
  await p.screenshot({ path: `${out}share-password-mobile.png` });
  await p.fill('#pw', 'yanlis-parola');
  await p.click('button[type=submit]');
  await p.waitForSelector('#pw-err:not([hidden])');
  await p.screenshot({ path: `${out}share-password-wrong-mobile.png` });
  await p.fill('#pw', 'anneanne2025');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.waitForSelector('.tile');
  expect(!(await p.$('.hero-actions a')), 'indirme kapalıyken zip düğmesi yok');
  const k = await p.$eval('#share-data', (e) => JSON.parse(e.textContent).items[0].k);
  const st = await p.evaluate(async (u) => (await fetch(u)).status, `${new URL(pwUrl).pathname}/m/${k}/o`);
  expect(st === 403, 'indirme kapalı: orijinal 403');
  await ctx.close();
}

// ------------------------------------------------------- sahip: liste ve kapatma
for (const theme of ['sinema', 'galeri', 'yerel']) {
  await owner.evaluate(async (th) => { await fetch('/api/v1/me', { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.OM.csrf }, body: JSON.stringify({ theme: th }) }); }, theme);
  for (const [w, h, tag] of [[1440, 900, 'desktop'], [390, 844, 'mobile']]) {
    await owner.setViewportSize({ width: w, height: h });
    await owner.goto(base + '/app/#/links');
    await owner.reload();
    await owner.waitForSelector('.sl-item');
    await owner.waitForTimeout(400);
    await owner.screenshot({ path: `${out}share-links-${theme}-${tag}.png` });
  }
  await owner.setViewportSize({ width: 1440, height: 900 });
  await owner.goto(base + '/app/#/albums');
  await owner.click('.album-card >> nth=0');
  await owner.click('.album-link-btn');
  await owner.waitForSelector('dialog.sl-dialog[open]');
  await owner.click('dialog.sl-dialog button[type=submit]');
  await owner.waitForSelector('.sl-url');
  await owner.screenshot({ path: `${out}share-owner-result-${theme}.png` });
  await owner.setViewportSize({ width: 390, height: 844 });
  await owner.screenshot({ path: `${out}share-owner-result-${theme}-mobile.png` });
  await owner.click('dialog.sl-dialog .btn-primary:has-text("Bitti")');
}
await owner.evaluate(async () => { await fetch('/api/v1/me', { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.OM.csrf }, body: JSON.stringify({ theme: 'sinema' }) }); });
await owner.setViewportSize({ width: 1440, height: 900 });
await owner.goto(base + '/app/#/links');
await owner.reload();
await owner.waitForSelector('.sl-item');
// Bu çalıştırmada ilk oluşturulan albüm bağlantısını arayüzden kapat
await owner.locator(`.sl-item[data-id="${firstId}"] button:has-text("Kapat")`).click();
await owner.click('dialog.modal button.btn-danger');
await owner.waitForSelector(`.sl-item[data-id="${firstId}"][data-status="revoked"]`);
step('bağlantı arayüzden kapatıldı');
{
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const r = await p.goto(url);
  expect(r.status() === 410 && /artık geçerli değil/.test(await p.textContent('h1')), 'kapatılan bağlantı 410 sayfası');
  await p.screenshot({ path: `${out}share-revoked-mobile.png` });
  const r2 = await p.goto(origin + '/s/' + 'A'.repeat(43));
  expect(r2.status() === 404, 'bilinmeyen belirteç 404');
  await p.screenshot({ path: `${out}share-notfound-mobile.png` });
  const r3 = await p.goto(origin + '/api/v1/me');
  expect(r3.status() === 404 || r3.status() === 429, `paylaşım portunda API yok (${r3.status()})`);
  await ctx.close();
}

if (errors.length) { console.error(errors.join('\n')); process.exitCode = 1; } else step('konsol hatası / üçüncü taraf istek yok');
await browser.close();
