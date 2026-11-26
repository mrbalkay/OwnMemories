// OwnMemories — tarayıcıdan parçalı yükleme (50 MB parçalar, yarıda kalırsa devam, SHA-1 ile yineleme kontrolü).
import { api } from './api.js';
import { h, t, formatBytes } from './util.js';

const EXT = /\.(jpe?g|png|gif|webp|heic|heif|avif|dng|tiff?|bmp|mp4|mov|m4v|3gp|avi|mkv|mts|m2ts|webm|mpe?g)$/i;
const queue = [];
let running = false;
let onUploaded = () => {};

export function setUploadHandler(fn) { onUploaded = fn; }

export function enqueue(files) {
  const panel = document.getElementById('uploads');
  panel.hidden = false;
  if (!panel.firstChild) {
    panel.append(h('div', { class: 'up-head' }, h('strong', {}, t('uploads_title')),
      h('button', { class: 'linklike', onclick: () => { panel.hidden = true; panel.replaceChildren(); } }, t('close'))), h('ul', { class: 'up-list' }));
  }
  const list = panel.querySelector('.up-list');
  for (const f of files) {
    const bar = h('progress', { max: 100, value: 0 });
    const state = h('span', { class: 'up-state' }, '');
    const li = h('li', {}, h('div', { class: 'up-name' }, f.name, h('span', { class: 'muted' }, formatBytes(f.size))), bar, state);
    list.append(li);
    if (!EXT.test(f.name)) { state.textContent = t('upload_unsupported'); li.classList.add('err'); continue; }
    queue.push({ file: f, bar, state, li });
  }
  run();
}

async function run() {
  if (running) return;
  running = true;
  while (queue.length) {
    const job = queue.shift();
    try {
      job.state.textContent = t('uploading');
      const status = await uploadOne(job);
      job.bar.value = 100;
      job.state.textContent = status === 'created' ? t('upload_done') : t('upload_dup');
      job.li.classList.add('ok');
      if (status === 'created') onUploaded();
    } catch (e) {
      job.state.textContent = `${t('upload_failed')}: ${e.message}`;
      job.li.classList.add('err');
    }
  }
  running = false;
}

async function sha1(file) {
  if (!crypto.subtle || file.size > 512 * 1024 * 1024) return null; // büyük dosyada sunucu doğrular
  const buf = await file.arrayBuffer();
  const d = await crypto.subtle.digest('SHA-1', buf);
  return [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function uploadOne({ file, bar }) {
  const hash = await sha1(file);
  if (hash) {
    const c = await api('POST', 'assets/check', { sha1: [hash] });
    if (!c.missing.includes(hash)) return 'duplicate';
  }
  const meta = {};
  const start = await api('POST', 'uploads', { filename: file.name, size: file.size, sha1: hash, meta });
  let offset = start.received;
  const chunk = start.chunk_size;
  let tries = 0;
  while (offset < file.size) {
    const end = Math.min(file.size, offset + chunk);
    try {
      const r = await api('PUT', `uploads/${start.id}?offset=${offset}`, file.slice(offset, end));
      offset = r.received;
      tries = 0;
      bar.value = Math.round((offset / file.size) * 100);
    } catch (e) {
      if (e.code === 'offset_mismatch') { offset = e.extra.received; continue; }
      if (++tries > 4) throw e;
      await new Promise((r) => setTimeout(r, 1000 * 2 ** tries));
      try { offset = (await api('GET', `uploads/${start.id}`)).received; } catch { /* yeniden dene */ }
    }
  }
  const done = await api('POST', `uploads/${start.id}/complete`);
  return done.status;
}
