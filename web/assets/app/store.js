// OwnMemories — paylaşılan durum: seçim ve görünür liste (görüntüleyicide gezinme için).
const listeners = new Set();

export const store = {
  selection: new Set(),
  /** Şu anki görünümdeki asset'ler (sırasıyla) ve devamını yükleme işlevi. */
  list: [],
  loadMore: null,
  context: {},
  onChange(fn) { listeners.add(fn); return () => listeners.delete(fn); },
  emit() { for (const fn of listeners) fn(); },
  toggle(id) {
    if (this.selection.has(id)) this.selection.delete(id); else this.selection.add(id);
    this.emit();
  },
  /** Birden çok öğeyi birlikte seçer ya da bırakır (gün başlığındaki "Seç"). */
  setMany(ids, on) {
    for (const id of ids) { if (on) this.selection.add(id); else this.selection.delete(id); }
    this.emit();
  },
  clear() { this.selection.clear(); this.emit(); },
  byId(id) { return this.list.find((a) => a.id === id); },
  update(asset) {
    const i = this.list.findIndex((a) => a.id === asset.id);
    if (i >= 0) this.list[i] = { ...this.list[i], ...asset };
  },
  remove(ids) {
    const set = new Set(ids);
    this.list = this.list.filter((a) => !set.has(a.id));
  },
};
