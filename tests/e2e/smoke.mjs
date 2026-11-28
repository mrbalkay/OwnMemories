// OwnMemories web duman testi (Playwright). Kurulu bir sunucuya karşı çalışır:
//   OM_E2E_URL=http://127.0.0.1:8080 OM_E2E_EMAIL=... OM_E2E_PASS=... node tests/e2e/smoke.mjs
// Kütüphanede en az bir fotoğraf olmalı. Ekran görüntüleri tests/e2e/screens/ altına yazılır.
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
import { mkdirSync } from 'node:fs';

const base = process.env.OM_E2E_URL || 'http://127.0.0.1:8080';
const email = process.env.OM_E2E_EMAIL;
const pass = process.env.OM_E2E_PASS;
const out = new URL('./screens/', import.meta.url).pathname;
mkdirSync(out, { recursive: true });

const browser = await chromium.launch();
const errors = [];
async function run(width, height, tag) {
  const page = await browser.newPage({ viewport: { width, height } });
  page.on('response', (r) => { if (process.env.DBG && r.url().includes('/api/') && r.request().method() !== 'GET') console.log(r.request().method(), r.url(), r.status()); });
  page.on('pageerror', (e) => errors.push(`${tag}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(`${tag} console: ${m.text()}`); });
  await page.goto(base + '/login.php');
  await page.fill('#email', email);
  await page.fill('#password', pass);
  await Promise.all([page.waitForURL('**/app/**'), page.click('button[type=submit]')]);
  await page.waitForSelector('.tile img', { timeout: 10000 });
  await page.screenshot({ path: `${out}${tag}-timeline.png` });
  return page;
}

const step = (s) => console.log('✓', s);
try {
  const page = await run(1280, 820, 'desktop');
  step('giriş ve zaman çizelgesi');
  const firstId = await page.getAttribute('.tile', 'data-id');
  await page.click('.tile');
  await page.waitForSelector('.viewer .v-img, .viewer video');
  step('görüntüleyici açıldı');
  await page.click('.viewer .icon-btn[aria-label="Bilgi"]');
  await page.waitForSelector('.v-info .i-row');
  await page.screenshot({ path: `${out}desktop-viewer.png` });
  const wasFav = (await page.$('.viewer button[aria-label="Favorilerden çıkar"]')) !== null;
  if (!wasFav) {
    await Promise.all([page.waitForResponse((r) => r.request().method() === 'PATCH'), page.click('.viewer button[aria-label="Favorilere ekle"]')]);
  }
  await page.waitForSelector('.viewer button[aria-label="Favorilerden çıkar"]');
  await page.keyboard.press('Escape');
  await page.waitForSelector('.viewer', { state: 'detached' });
  await page.waitForTimeout(300); // görüntüleyicinin history.back() işlemi bitsin
  await page.goto(base + '/app/#/favorites');
  await page.waitForSelector(`.tile[data-id="${firstId}"]`);
  step('favori eklendi ve Favoriler\'de görünüyor');
  await page.goto(base + '/app/#/photos');
  await page.waitForSelector(`.tile[data-id="${firstId}"]`);
  await page.hover(`.tile[data-id="${firstId}"]`);
  await page.click(`.tile[data-id="${firstId}"] .tile-check`);
  await page.waitForSelector('#selbar:not([hidden])');
  await page.screenshot({ path: `${out}desktop-selection.png` });
  await page.click('#selbar button:has-text("Çöpe at")');
  await page.waitForSelector(`.tile[data-id="${firstId}"]`, { state: 'detached' });
  await page.goto(base + '/app/#/trash');
  await page.waitForSelector(`.tile[data-id="${firstId}"]`);
  step('çöpe atıldı ve Çöp kutusu\'nda');
  await page.click(`.tile[data-id="${firstId}"] .tile-check`);
  await page.click('#selbar button:has-text("Geri al")');
  await page.waitForSelector(`.tile[data-id="${firstId}"]`, { state: 'detached' });
  await page.goto(base + '/app/#/photos');
  await page.waitForSelector(`.tile[data-id="${firstId}"]`);
  step('geri alındı');
  // favoriyi eski haline getir
  if (!wasFav) {
    await page.click(`.tile[data-id="${firstId}"]`);
    await Promise.all([page.waitForResponse((r) => r.request().method() === 'PATCH'), page.click('.viewer button[aria-label="Favorilerden çıkar"]')]);
    await page.keyboard.press('Escape');
  }
  // Tarayıcıdan yükleme (benzersiz içerikli küçük bir PNG)
  const { writeFileSync } = await import('node:fs');
  const png = Buffer.from('89504e470d0a1a0a0000000d4948445200000001000000010806000000' + '1f15c489' + '0000000d49444154789c63f8cfc0f01f0005000201' + 'a5f6b0a1' + '0000000049454e44ae426082', 'hex');
  const tmp = `${out}upload-${Date.now()}.png`;
  writeFileSync(tmp, Buffer.concat([png, Buffer.from(String(Date.now()))]));
  await page.setInputFiles('#fileInput', tmp);
  await page.waitForSelector('.up-list li.ok, .up-list li.err', { timeout: 15000 });
  if (await page.$('.up-list li.err')) throw new Error('yükleme başarısız: ' + await page.textContent('.up-list li.err'));
  step('tarayıcıdan yükleme');
  // Anı gösterisinden "Fotoğrafı aç" sonrası zaman çizelgesi gezinmesi bozulmamalı
  await page.goto(base + '/app/#/photos');
  await page.waitForSelector('.tile img');
  if (await page.$('.mem-card')) {
    await page.click('.mem-card');
    await page.waitForSelector('.story');
    await page.click('.story-actions button:first-child');
    await page.waitForSelector('.viewer');
    await page.keyboard.press('Escape');
    await page.waitForSelector('.viewer', { state: 'detached' });
    await page.waitForTimeout(300);
    const tiles = await page.$$('.tile');
    await tiles[1].click();
    await page.waitForSelector('.viewer .v-next:not([hidden])', { timeout: 5000 });
    await page.keyboard.press('Escape');
    await page.waitForSelector('.viewer', { state: 'detached' });
    await page.waitForTimeout(300);
    // Geri tuşu anı gösterisini kapatmalı
    await page.click('.mem-card');
    await page.waitForSelector('.story');
    await page.goBack();
    await page.waitForSelector('.story', { state: 'detached', timeout: 3000 });
    step('anı gösterisi: fotoğrafı aç, geri tuşu, zaman çizelgesi gezinmesi');
  }
  // Takvim: raydaki yıl düğmesi takvimi açar; fotoğraflı güne tıklayınca o güne gidilir.
  await page.goto(base + '/app/#/photos');
  await page.waitForSelector('.tile img');
  if (await page.waitForSelector('.scrubber button[data-year]', { timeout: 5000 }).catch(() => null)) {
    // Yükleme paneli sağ alt köşede rayın üstünü örter: kapat.
    if (await page.isVisible('#uploads .up-head button')) await page.click('#uploads .up-head button');
    await page.click('.scrub-now');
    await page.waitForSelector('.cal[role=dialog] .cal-day.has');
    await page.keyboard.press('Escape');
    await page.waitForSelector('.cal', { state: 'detached' });
    if (!(await page.evaluate(() => document.activeElement?.classList.contains('scrub-now')))) throw new Error('takvim kapanınca odak geri dönmedi: ' + await page.evaluate(() => document.activeElement?.outerHTML.slice(0, 200) + ' url=' + location.hash + ' n=' + document.querySelectorAll('.scrub-now').length));
    await page.click('.scrubber button[data-year]:last-of-type');
    await page.waitForSelector('.cal .cal-day.has');
    const day = await page.getAttribute('.cal .cal-day.has', 'data-day');
    await page.click(`.cal .cal-day[data-day="${day}"]`);
    await page.waitForSelector('.cal', { state: 'detached' });
    const sec = await page.waitForSelector(`.day-group[data-day="${day}"]`, { timeout: 8000 });
    await page.waitForTimeout(400);
    const box = await sec.boundingBox();
    if (!box || box.y < 0 || box.y > 820 * 0.6) throw new Error(`takvim günü görünür değil: ${day} y=${box?.y}`);
    step(`takvim: ${day} gününe gidildi`);
  } else {
    step('takvim atlandı (yıl rayı için en az 3 ay gerekir)');
  }
  await page.goto(base + '/app/#/albums');
  await page.waitForSelector('.album-card, .empty');
  await page.screenshot({ path: `${out}desktop-albums.png` });
  step('albümler');
  // Arama: öneri yongaları, sonuç ızgarası (akıllı ya da metin modu) ve sonuç listesinde görüntüleyici gezinmesi
  await page.goto(base + '/app/#/search');
  await page.waitForSelector('.search-start .search-chip');
  const fname = await page.evaluate(async (id) => (await (await fetch(`/api/v1/assets/${id}`)).json()).asset.filename, firstId);
  const term = fname.replace(/\.[^.]+$/, '').slice(0, 12);
  await page.fill('#searchInput', term);
  await page.press('#searchInput', 'Enter');
  await page.waitForSelector('.search-view .tile');
  const head = await page.textContent('.search-title');
  if (!/sonuç/.test(head)) throw new Error(`arama başlığı beklenmedik: ${head}`);
  const mode = await page.getAttribute('.search-mode', 'data-mode');
  await page.screenshot({ path: `${out}desktop-search.png` });
  const nTiles = await page.locator('.search-view .tile').count();
  await page.click('.search-view .tile');
  await page.waitForSelector('.viewer .v-img, .viewer video');
  if (nTiles > 1) {
    const cur = () => document.querySelector('.viewer .v-img')?.getAttribute('src') || document.querySelector('.viewer video')?.getAttribute('src') || '';
    const before = await page.evaluate(cur);
    await page.keyboard.press('ArrowRight');
    await page.waitForFunction(([b, f]) => (new Function('return ' + f))()() !== b, [before, cur.toString()], { timeout: 5000 });
  }
  await page.keyboard.press('Escape');
  await page.waitForSelector('.viewer', { state: 'detached' });
  step(`arama (${mode}): "${term}" → ${nTiles} sonuç, görüntüleyicide gezinme`);
  // API hatasında sonsuz "Yükleniyor" yerine "Tekrar dene" görünmeli
  const errPage = await browser.newPage();
  await errPage.context().addCookies(await page.context().cookies());
  let assetCalls = 0;
  await errPage.route(/\/api\/v1\/assets\?/, (r) => { assetCalls++; r.fulfill({ status: 500, contentType: 'application/json', body: '{"error":{"code":"server_error","message":"x"}}' }); });
  await errPage.goto(base + '/app/#/photos');
  await errPage.waitForSelector('.tl-status button', { timeout: 8000 });
  await errPage.waitForTimeout(1500);
  if (assetCalls > 1) throw new Error(`hata sonrası istek döngüsü: ${assetCalls} istek`);
  await errPage.close();
  step('API hatasında "Tekrar dene"');
  const mobile = await run(390, 844, 'mobile');
  if (await mobile.waitForSelector('.cal-fab', { state: 'visible', timeout: 5000 }).catch(() => null)) {
    await mobile.click('.cal-fab');
    await mobile.waitForSelector('.cal .cal-day.has');
    await mobile.waitForTimeout(400);
    await mobile.screenshot({ path: `${out}mobile-calendar.png` });
    await mobile.click('.cal-close');
    await mobile.waitForSelector('.cal', { state: 'detached' });
    step('mobil takvim');
  }
  await mobile.click('#menuBtn');
  await mobile.screenshot({ path: `${out}mobile-menu.png` });
  step('mobil görünüm');
} catch (e) {
  console.error('FAIL', e.message);
  errors.push(e.message);
}
await browser.close();
if (errors.length) {
  console.error(errors.join('\n'));
  process.exit(1);
}
console.log('smoke: PASS');
