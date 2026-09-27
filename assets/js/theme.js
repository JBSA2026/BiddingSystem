/* Applies the saved (or system) colour theme before first paint to avoid a flash. Loaded in <head>. */
(function () {
  var t = null;
  try { t = localStorage.getItem('cl_theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') {
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', t);
})();
