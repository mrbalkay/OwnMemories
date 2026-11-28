// OwnMemories — Ayarlar: görünüm teması seçimi ve profil adı.
import { api } from './api.js';
import { h, t, toast, OM } from './util.js';
import { THEMES, DEFAULT_THEME, currentTheme, setTheme } from './theme.js';

const PREVIEW = {
  // Her temanın küçük, kendi renkleriyle çizilmiş önizlemesi (yalnızca CSS; gerçek görsel gerekmez).
  sinema: ['#0b0b0e', '#1c1a22', '#e8c27a'],
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
      document.querySelector('.me-name').textContent = OM.user.name;
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
      h('div', { class: 'settings-row' }, h('label', {}, t('your_name'), nameInput), saveName))));
}
