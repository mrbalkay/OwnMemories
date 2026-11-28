// OwnMemories — Ayarlar: görünüm teması seçimi ve profil adı.
import { api } from './api.js';
import { h, t, toast, icons, OM } from './util.js';
import { THEMES, DEFAULT_THEME, currentTheme, setTheme } from './theme.js';

const PREVIEW = {
  // Her temanın küçük, kendi renkleriyle çizilmiş önizlemesi (yalnızca CSS; gerçek görsel gerekmez).
  sinema: ['#0a0b0e', '#1e2129', '#eceae6'],
  galeri: ['#f6f4f0', '#ffffff', '#2c4a5a'],
  yerel: ['#f2f2f7', '#ffffff', '#0a7cff'],
};

export function renderSettings(view) {
  const cards = THEMES.map((name) => {
    const [bg, surface, accent] = PREVIEW[name];
    const input = h('input', { type: 'radio', name: 'theme', value: name, checked: currentTheme() === name, class: 'visually-hidden' });
    input.addEventListener('change', async () => {
      if (!input.checked) return;
      try {
        await setTheme(name);
        toast(t('theme_saved'));
      } catch (e) {
        toast(e.message);
        view.querySelector(`input[value="${currentTheme()}"]`).checked = true;
      }
    });
    return h('label', { class: 'theme-card', 'data-theme-name': name },
      input,
      h('span', { class: 'theme-preview', style: `--p-bg:${bg};--p-surface:${surface};--p-accent:${accent}`, 'aria-hidden': 'true' },
        h('span', { class: 'tp-hero' }), h('span', { class: 'tp-row' }, h('span'), h('span'), h('span'))),
      h('span', { class: 'theme-text' },
        h('strong', {}, t('theme_' + name), name === DEFAULT_THEME ? h('span', { class: 'badge' }, t('default_badge')) : null),
        h('span', { class: 'muted' }, t('theme_' + name + '_desc'))));
  });

  const nameInput = h('input', { type: 'text', value: OM.user.name || '', maxlength: 120, 'aria-label': t('your_name') });
  const saveName = h('button', { class: 'btn btn-soft', onclick: async () => {
    try {
      OM.user = (await api('PATCH', 'me', { name: nameInput.value })).user;
      // Yalnızca ad metnini ve baş harf avatarlarını güncelle (.me-name içindeki .me-avatar korunur).
      const name = OM.user.name || '';
      const initial = (Array.from(name)[0] || '').toUpperCase(); // index.php'deki mb_strtoupper ile aynı
      const nameEl = document.querySelector('.me-name-text');
      if (nameEl) nameEl.textContent = name;
      for (const el of document.querySelectorAll('.me-avatar, .top-avatar')) el.textContent = initial;
      document.querySelector('.top-avatar')?.setAttribute('title', name);
      toast(t('saved'));
    } catch (e) { toast(e.message); }
  } }, t('save'));

  view.replaceChildren(h('div', { class: 'settings' },
    h('div', { class: 'page-head' }, h('h1', {}, t('settings'))),
    h('section', { class: 'settings-section' },
      h('h2', {}, t('appearance')),
      h('p', { class: 'muted' }, t('appearance_note')),
      h('div', { class: 'theme-grid', role: 'radiogroup', 'aria-label': t('appearance') }, ...cards)),
    h('section', { class: 'settings-section' },
      h('h2', {}, t('profile')),
      h('div', { class: 'settings-row' }, h('label', {}, t('your_name'), nameInput), saveName)),
    OM.user.is_admin ? backupSection() : null));
}

function ago(iso) {
  const s = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
  if (s < 90) return t('ago_now');
  if (s < 5400) return t('ago_min', { n: Math.round(s / 60) });
  if (s < 172800) return t('ago_hour', { n: Math.round(s / 3600) });
  return t('ago_day', { n: Math.round(s / 86400) });
}

const whenFmt = new Intl.DateTimeFormat('tr-TR', { weekday: 'short', hour: '2-digit', minute: '2-digit' });

/** Yönetici: sunucu yedeği özeti (GET /v1/admin/backup). Ayrıntı ve "Şimdi yedekle" yönetim panelinde. */
function backupSection() {
  const body = h('div', { class: 'backup-card', dataset: { level: 'loading' }, 'aria-busy': 'true' }, h('p', { class: 'muted' }, t('loading')));
  const sec = h('section', { class: 'settings-section settings-backup' },
    h('h2', {}, t('backup_section')), h('p', { class: 'muted' }, t('backup_section_note')), body);
  api('GET', 'admin/backup').then(({ backup: b }) => {
    body.dataset.level = b.level;
    body.removeAttribute('aria-busy');
    const head = h('div', { class: 'backup-card-head' },
      h('span', { class: 'backup-card-ic', 'aria-hidden': 'true', html: b.level === 'ok' ? icons.shieldOk : icons.shield }),
      h('div', { class: 'backup-card-title' }, h('strong', {}, t('backup_lvl_' + b.level)),
        h('span', { class: 'muted' }, b.running ? t('backup_running') : (b.next_run_at ? t('backup_next', { t: whenFmt.format(new Date(b.next_run_at)) }) : ''))),
      h('a', { class: 'btn btn-soft', href: '/admin/backup.php' }, t('backup_manage')));
    const rows = b.targets.map((x) => h('li', { class: 'backup-target', dataset: { level: x.health.level } },
      h('span', { class: 'backup-dot', 'aria-hidden': 'true' }),
      h('span', { class: 'backup-target-name' }, x.label || x.id),
      h('span', { class: 'backup-target-when muted' }, x.last_success_at ? t('backup_last', { t: ago(x.last_success_at) }) : t('backup_never'))));
    const extra = b.configured ? h('ul', { class: 'backup-targets' }, ...rows)
      : h('div', { class: 'backup-setup' }, h('span', { class: 'muted' }, t('backup_setup_hint')), h('code', {}, b.setup_command));
    body.replaceChildren(head, extra);
  }).catch(() => { body.dataset.level = 'error'; body.replaceChildren(h('p', { class: 'muted' }, t('backup_load_failed'))); });
  return sec;
}
