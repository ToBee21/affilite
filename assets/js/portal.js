(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var portal = document.querySelector('[data-aff-portal]');
    if (!portal) return;

    var links  = Array.prototype.slice.call(portal.querySelectorAll('[data-tab-link]'));
    var panes  = Array.prototype.slice.call(portal.querySelectorAll('[data-tab-panel]'));
    var cfgEl  = document.getElementById('aff-portal-config');
    var cfg    = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : {};

    function activate(name, pushHash) {
      links.forEach(function (b) {
        var active = b.getAttribute('data-tab-link') === name;
        b.classList.toggle('is-active', active);
        b.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panes.forEach(function (p) {
        var show = p.getAttribute('data-tab-panel') === name;
        p.classList.toggle('is-active', show);
        if (show) { p.removeAttribute('hidden'); } else { p.setAttribute('hidden', 'hidden'); }
      });
      if (pushHash !== false) {
        try {
          history.replaceState(null, '', location.pathname + location.search + '#aff=' + encodeURIComponent(name));
        } catch (e) {}
      }
    }

    // Click -> switch tab (no scrolling)
    portal.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-tab-link]');
      if (btn) {
        e.preventDefault();
        activate(btn.getAttribute('data-tab-link'));
        // optionally: portal.scrollIntoView({behavior:'smooth', block:'start'});
      }

      // Generator linku
      if (e.target && e.target.classList.contains('aff-generate')) {
        e.preventDefault();
        var input = portal.querySelector('#aff-to');
        var out   = portal.querySelector('#aff-out');
        var base  = (cfg && cfg.base_link) ? cfg.base_link : '';
        var to    = (input && input.value || '').trim();
        var home  = (cfg && cfg.home) ? cfg.home : (location.origin + '/');

        var url = base;
        if (to) {
          if (to.startsWith('http')) {
            try {
              var u = new URL(to);
              var h = new URL(home);
              if (u.host === h.host) {
                url += '?url=' + encodeURIComponent(to);
              } else {
                alert('Dozwolone są tylko adresy z tej samej domeny.');
                return;
              }
            } catch (_) {
              alert('Nieprawidłowy URL.');
              return;
            }
          } else if (to.startsWith('/')) {
            url += '?to=' + encodeURIComponent(to);
          } else {
            url += '?to=' + encodeURIComponent('/' + to);
          }
        }

        if (out) {
          out.value = url;
          out.focus();
          out.select();
        }
      }
    });

    // Initial tab from hash (#aff=link)
    var m = (location.hash || '').match(/aff=([a-z]+)/i);
    activate(m ? m[1] : 'dashboard', false);
  });
})();
