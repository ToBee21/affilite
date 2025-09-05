(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var portal = document.querySelector('[data-aff-portal]');
    if (!portal) return;

    var links  = [].slice.call(portal.querySelectorAll('[data-tab-link]'));
    var panes  = [].slice.call(portal.querySelectorAll('[data-tab-panel]'));
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
        if (show) p.removeAttribute('hidden'); else p.setAttribute('hidden','hidden');
      });
      if (pushHash !== false) {
        try { history.replaceState(null, '', location.pathname + location.search + '#aff=' + encodeURIComponent(name)); } catch(e){}
      }
      if (name === 'dashboard') requestAnimationFrame(renderChart);
    }

    portal.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-tab-link]');
      if (btn) { e.preventDefault(); activate(btn.getAttribute('data-tab-link')); }

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
              var u = new URL(to), h = new URL(home);
              if (u.host === h.host) url += '?url=' + encodeURIComponent(to);
              else { alert('Dozwolone są tylko adresy z tej samej domeny.'); return; }
            } catch(_) { alert('Nieprawidłowy URL.'); return; }
          } else if (to.startsWith('/')) url += '?to=' + encodeURIComponent(to);
          else url += '?to=' + encodeURIComponent('/' + to);
        }
        if (out) { out.value = url; out.focus(); out.select(); }
      }
    });

    // Startowa zakładka
    var m = (location.hash || '').match(/aff=([a-z]+)/i);
    activate(m ? m[1] : 'dashboard', false);

    // ---- Interaktywny wykres 30 dni (SVG + tooltip) ----
    var statsEl = document.getElementById('aff-portal-stats');
    var stats   = statsEl ? JSON.parse(statsEl.textContent || '{}') : null;

    function fallbackLast30() {
      var labels=[], clicks=[], conv=[];
      var today = new Date();
      var base = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()));
      for (var i=29;i>=0;i--) {
        var d = new Date(base); d.setUTCDate(base.getUTCDate()-i);
        var y = d.getUTCFullYear(), mo = String(d.getUTCMonth()+1).padStart(2,'0'), da = String(d.getUTCDate()).padStart(2,'0');
        labels.push(y + '-' + mo + '-' + da); clicks.push(0); conv.push(0);
      }
      return { labels:labels, clicks:clicks, conv:conv, clicks_sum:0, conv_sum:0 };
    }

    function normalizeStats(s) {
      if (!s || !Array.isArray(s.labels) || s.labels.length===0) return fallbackLast30();
      var n = s.labels.length;
      function norm(a){ a=Array.isArray(a)?a.slice():[]; for (var i=a.length;i<n;i++) a.push(0); if(a.length>n) a.length=n; return a; }
      s.clicks = norm(s.clicks); s.conv = norm(s.conv); return s;
    }

    function renderChart() {
      var mount = portal.querySelector('[data-chart]'); if (!mount) return;

      // Czyścimy (nadpisujemy ewentualny serwerowy fallback)
      mount.innerHTML = '';

      var s = normalizeStats(stats);
      var labels = s.labels.slice(), clicks = s.clicks.slice(), conv = s.conv.slice();

      // Rozmiar/zapasy
      var rect = mount.getBoundingClientRect();
      var W = Math.max(320, rect.width || mount.clientWidth || 640);
      var H = 300;

      var padL=44, padR=14, padT=14, padB=46;            // więcej miejsca na daty
      var iw = Math.max(10, W - padL - padR);
      var ih = Math.max(10, H - padT - padB);

      function maxOf(a){ var m=0; for(var i=0;i<a.length;i++) if(+a[i]>m) m=+a[i]; return m; }
      var maxY = Math.max(1, maxOf(clicks), maxOf(conv)); maxY = Math.ceil(maxY * 1.1);

      var n = labels.length;
      var den = Math.max(1, n-1);

      function x(i){ return padL + (i * (iw/den)); }
      function y(v){ return padT + (ih - (v/Math.max(1,maxY))*ih); }
      function shortDate(s){ var m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/); return m ? (m[1]+'-'+m[2]+'-'+m[3]) : s; }

      // SVG
      var NS='http://www.w3.org/2000/svg';
      var svg=document.createElementNS(NS,'svg');
      svg.setAttribute('viewBox','0 0 '+W+' '+H);
      svg.setAttribute('shape-rendering','geometricPrecision');

      // siatka pozioma (4 linie)
      for (var gy=0; gy<=4; gy++){
        var val=(maxY/4)*gy, yy=y(val);
        var line=document.createElementNS(NS,'line');
        line.setAttribute('x1', padL); line.setAttribute('x2', W-padR);
        line.setAttribute('y1', yy);   line.setAttribute('y2', yy);
        line.setAttribute('style','stroke:rgba(0,0,0,.08)');
        svg.appendChild(line);

        var tx=document.createElementNS(NS,'text');
        tx.setAttribute('x', padL-10); tx.setAttribute('y', yy+3);
        tx.setAttribute('text-anchor','end');
        tx.setAttribute('style','fill:#666;font-size:11px'); tx.textContent = Math.round(val);
        svg.appendChild(tx);
      }

      // siatka pionowa dzienna + etykiety X (co 2 dni, przekręcone)
      for (var i=0;i<n;i++){
        var xi=x(i);
        var vline=document.createElementNS(NS,'line');
        vline.setAttribute('x1', xi); vline.setAttribute('x2', xi);
        vline.setAttribute('y1', padT); vline.setAttribute('y2', H-padB);
        vline.setAttribute('style','stroke:rgba(0,0,0,.05)');
        svg.appendChild(vline);

        if (i%2===0 || i===n-1) {
          var t=document.createElementNS(NS,'text');
          t.setAttribute('x', xi); t.setAttribute('y', H-padB+16);
          t.setAttribute('transform','rotate(45 '+xi+','+(H-padB+16)+')');
          t.setAttribute('style','fill:#666;font-size:11px'); t.textContent=shortDate(labels[i]);
          svg.appendChild(t);
        }
      }

      // Ścieżka krokowa + markery
      function stepPath(arr){
        var d='', prev=0;
        for (var i=0;i<arr.length;i++){
          var xi=x(i), yi=y(prev);
          d += (i===0?'M':'L') + xi + ' ' + yi + ' ';
          var yi2=y(arr[i]);
          d += 'L' + xi + ' ' + yi2 + ' ';
          prev = +arr[i];
        }
        return d.trim();
      }

      function drawSeries(arr, color){
        var path=document.createElementNS(NS,'path');
        path.setAttribute('d', stepPath(arr));
        path.setAttribute('style','fill:none;stroke:'+color+';stroke-width:2;stroke-linecap:round;stroke-linejoin:round');
        svg.appendChild(path);

        // markery
        for (var i=0;i<arr.length;i++){
          var r = (+arr[i] > 0) ? 3.5 : 2;
          var c=document.createElementNS(NS,'circle');
          c.setAttribute('cx', x(i)); c.setAttribute('cy', y(+arr[i]));
          c.setAttribute('r', r);
          c.setAttribute('style','fill:'+color+';stroke:#fff;stroke-width:1');
          c.setAttribute('data-idx', String(i));
          svg.appendChild(c);
        }
      }

      drawSeries(clicks, '#3a78f2');   // Kliknięcia
      drawSeries(conv,   '#26a269');   // Konwersje

      // Pionowy „guideline” + tooltip
      var guide=document.createElementNS(NS,'line');
      guide.setAttribute('y1', padT); guide.setAttribute('y2', H-padB);
      guide.setAttribute('style','stroke:rgba(0,0,0,.25);stroke-dasharray:2 3;display:none');
      svg.appendChild(guide);

      var tip=document.createElement('div');
      tip.className='aff-chart-tooltip';
      tip.style.display='none';
      mount.appendChild(tip);

      function nearestIndex(px){
        // px to współrzędna X w układzie SVG; mapujemy na najbliższy dzień
        var i = Math.round( (px - padL) / (iw/den) );
        if (i<0) i=0; if (i>n-1) i=n-1;
        return i;
      }

      function svgPoint(evt){
        // przelicz piksele myszy na układ SVG
        var pt = svg.createSVGPoint();
        pt.x = evt.clientX; pt.y = evt.clientY;
        var ctm = svg.getScreenCTM();
        if (!ctm) return {x:0,y:0};
        var p = pt.matrixTransform(ctm.inverse());
        return {x:p.x, y:p.y};
      }

      function showAt(i, mouse){
        var xi = x(i);
        guide.setAttribute('x1', xi); guide.setAttribute('x2', xi);
        guide.style.display='block';

        // zawartość tooltipa
        var html  = '<div class="aff-tip-date">'+ shortDate(labels[i]) +'</div>';
        html += '<div><span class="dot" style="background:#3a78f2"></span> Kliknięcia: <strong>'+ (clicks[i]|0) +'</strong></div>';
        html += '<div><span class="dot" style="background:#26a269"></span> Konwersje: <strong>'+ (conv[i]|0) +'</strong></div>';
        tip.innerHTML = html;

        // pozycjonowanie — obok kursora, ale w granicach wykresu
        var tw = tip.offsetWidth || 160, th = tip.offsetHeight || 54;
        var left = (mouse.x + 12);
        var top  = (mouse.y - th - 12);
        // ograniczenia w kontenerze mount
        var bounds = mount.getBoundingClientRect();
        if (left + tw > bounds.right) left = mouse.x - tw - 12;
        if (top < bounds.top) top = mouse.y + 12;

        tip.style.left = left + 'px';
        tip.style.top  = top  + 'px';
        tip.style.display='block';
      }

      svg.addEventListener('mousemove', function (e) {
        var p = svgPoint(e);
        var i = nearestIndex(p.x);
        showAt(i, {x: e.clientX, y: e.clientY});
      });
      svg.addEventListener('mouseleave', function () {
        guide.style.display='none';
        tip.style.display='none';
      });

      mount.appendChild(svg);
    }

    requestAnimationFrame(renderChart);
    window.addEventListener('resize', renderChart);
  });
})();
