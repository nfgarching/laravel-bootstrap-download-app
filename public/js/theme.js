// Hell/Dunkel nach Systemeinstellung (Bootstrap 5.3 data-bs-theme).
// Externe Datei statt Inline-Skript, weil die CSP nur eigene Skripte erlaubt.
(() => {
    const dark = window.matchMedia('(prefers-color-scheme: dark)');
    const apply = () => document.documentElement.setAttribute('data-bs-theme', dark.matches ? 'dark' : 'light');
    apply();
    dark.addEventListener('change', apply);
})();
