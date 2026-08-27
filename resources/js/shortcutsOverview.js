export function shortcutsOverview() {
  return {
    open: false,

    init() {
      window.addEventListener('shortcuts:open', () => {
        this.open = true;
      });
    },

    close() {
      this.open = false;
    },
  };
}
