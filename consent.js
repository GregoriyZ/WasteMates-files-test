/* WasteMates cookie notice.
   Choice is kept in localStorage "wm_consent" ("granted" | "denied").
   The tracking snippets in each page's <head> skip loading when it is "denied". */
(function () {
  var KEY = 'wm_consent';
  function get() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function set(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }

  function show() {
    if (document.getElementById('wm-consent')) return;
    var was = get();
    var bar = document.createElement('div');
    bar.id = 'wm-consent';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Cookie notice');
    bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:60;background:#111;color:#fff;' +
      'padding:14px 18px calc(14px + env(safe-area-inset-bottom));display:flex;flex-wrap:wrap;gap:12px 18px;' +
      'align-items:center;justify-content:center;font-size:14.5px;line-height:1.45;box-shadow:0 -4px 18px rgba(0,0,0,.25);';
    bar.innerHTML =
      '<p style="margin:0;max-width:640px;">We use cookies and analytics to see how the site is used and to measure our ads. ' +
      '<a href="privacy.html" style="color:#61DE2A;text-decoration:underline;">Privacy notice</a></p>' +
      '<div style="display:flex;gap:10px;">' +
      '<button type="button" data-v="denied" style="font:inherit;font-weight:600;cursor:pointer;padding:9px 16px;background:transparent;color:#fff;border:1px solid rgba(255,255,255,.5);">Decline</button>' +
      '<button type="button" data-v="granted" style="font:inherit;font-weight:700;cursor:pointer;padding:9px 18px;background:#61DE2A;color:#0a1303;border:1px solid #61DE2A;">Accept</button>' +
      '</div>';
    bar.addEventListener('click', function (e) {
      var v = e.target.getAttribute && e.target.getAttribute('data-v');
      if (!v) return;
      set(v);
      // Reload when tracking has to stop (decline) or start again (accept after an earlier decline).
      if (v === 'denied' || was === 'denied') { location.reload(); return; }
      bar.remove();
    });
    document.body.appendChild(bar);
  }

  if (!get()) show();

  // "Change my cookie choice" links, e.g. on the privacy page.
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('[data-wm-consent-open]')) { e.preventDefault(); show(); }
  });
})();
