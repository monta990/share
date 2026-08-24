(function () {
  'use strict';
  var KEY = 'portal-theme';
  var media = window.matchMedia('(prefers-color-scheme: dark)');
  function saved() { var v = localStorage.getItem(KEY); return (v === 'light' || v === 'dark' || v === 'auto') ? v : 'auto'; }
  function apply(theme) {
    var effective = theme === 'auto' ? (media.matches ? 'dark' : 'light') : theme;
    document.documentElement.setAttribute('data-bs-theme', effective);
    document.documentElement.setAttribute('data-theme-choice', theme);
  }
  function set(theme) { if (theme !== 'light' && theme !== 'dark' && theme !== 'auto') theme='auto'; localStorage.setItem(KEY, theme); apply(theme); }
  window.portalTheme = { get: saved, set: set, apply: apply };
  apply(saved());
  if (media.addEventListener) media.addEventListener('change', function(){ if(saved()==='auto') apply('auto'); });
  else if (media.addListener) media.addListener(function(){ if(saved()==='auto') apply('auto'); });
  document.addEventListener('click', function(e){ var b=e.target.closest('.theme-choice'); if(!b)return; e.preventDefault(); set(b.getAttribute('data-theme') || 'auto'); });
})();
