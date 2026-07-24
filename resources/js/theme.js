const STORAGE_KEY = 'notes-theme';

export function theme() {
  return {
    mode: localStorage.getItem(STORAGE_KEY) || 'system',
    init() {
      this.apply();
    },
    apply() {
      const root = document.documentElement;
      if (this.mode === 'system') {
        root.removeAttribute('data-theme');
      } else {
        root.setAttribute('data-theme', this.mode);
      }
    },
    set(mode) {
      this.mode = mode;
      localStorage.setItem(STORAGE_KEY, mode);
      this.apply();
    },
  };
}
