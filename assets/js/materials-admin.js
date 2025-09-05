(function($){
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function(){
    var list = document.getElementById('aff-list');
    if(!list) return;

    function serialize(){
      var out = [];
      list.querySelectorAll('.aff-block').forEach(function(b){
        var type = b.getAttribute('data-type') || 'text';
        var row = { type: type };

        // Dane treści
        if (type === 'gallery') {
          row.items = (b.querySelector('[data-k="items"]')?.value || '').trim();
        } else {
          var val = (b.querySelector('[data-k="val"]')?.value || '').trim();
          if (val) row.val = val;
          var v2  = (b.querySelector('[data-k="val2"]')?.value || '').trim();
          if (v2) row.val2 = v2;
        }

        // Marginesy (zawsze)
        var mt = (b.querySelector('[data-k="mtop"]')?.value || '').trim();
        var mb = (b.querySelector('[data-k="mbot"]')?.value || '').trim();
        row.mtop = mt === '' ? 16 : mt;
        row.mbot = mb === '' ? 16 : mb;

        out.push(row);
      });
      document.getElementById('aff-blocks-json').value = JSON.stringify(out);
    }

    function remapIndexes(){
      [].slice.call(list.querySelectorAll('.aff-block')).forEach(function(el, i){
        el.setAttribute('data-index', String(i));
      });
    }

    // Drag & drop sort
    var dragging = null;
    list.addEventListener('dragstart', function(e){
      var b = e.target.closest('.aff-block');
      if (!b) return;
      dragging = b;
      b.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', function(){
      if(dragging){ dragging.classList.remove('dragging'); dragging=null; remapIndexes(); }
    });
    list.addEventListener('dragover', function(e){
      e.preventDefault();
      var after = null;
      var y = e.clientY;
      var els = [].slice.call(list.querySelectorAll('.aff-block:not(.dragging)'));
      for (var i=0;i<els.length;i++){
        var r = els[i].getBoundingClientRect();
        var offset = y - r.top - r.height/2;
        if (offset < 0) { after = els[i]; break; }
      }
      if (!dragging) return;
      if (after) list.insertBefore(dragging, after);
      else list.appendChild(dragging);
    });

    // Akcje przycisków
    list.addEventListener('click', function(e){
      var b = e.target.closest('.aff-block'); if(!b) return;

      if (e.target.classList.contains('aff-remove')) {
        e.preventDefault(); b.remove(); remapIndexes(); return;
      }
      if (e.target.classList.contains('aff-move-up')) {
        e.preventDefault(); if (b.previousElementSibling) list.insertBefore(b, b.previousElementSibling);
        remapIndexes(); return;
      }
      if (e.target.classList.contains('aff-move-down')) {
        e.preventDefault(); if (b.nextElementSibling) list.insertBefore(b.nextElementSibling, b);
        remapIndexes(); return;
      }
      if (e.target.classList.contains('aff-edit-margins')) {
        e.preventDefault();
        var panel = b.querySelector('.aff-margins-panel');
        if (panel) panel.hidden = !panel.hidden;
        return;
      }
      if (e.target.classList.contains('aff-pick')) {
        e.preventDefault();
        var targetKey = e.target.getAttribute('data-target');
        var input = b.querySelector('[data-k="'+targetKey+'"]');
        var frame = wp.media({ multiple:false });
        frame.on('select', function(){
          var a = frame.state().get('selection').first().toJSON();
          input.value = String(a.id || a.url || '');
          var prev = b.querySelector('.js-preview-image');
          if (prev && a.type === 'image') prev.innerHTML = '<img src="'+(a.url)+'" class="aff-embed-thumb" />';
        });
        frame.open();
        return;
      }
      if (e.target.classList.contains('aff-pick-gallery')) {
        e.preventDefault();
        var targetKey2 = e.target.getAttribute('data-target');
        var input2 = b.querySelector('[data-k="'+targetKey2+'"]');
        var frame2 = wp.media({ multiple:true, library:{ type:'image' } });
        frame2.on('select', function(){
          var ids = [];
          frame2.state().get('selection').each(function(att){ ids.push(att.id); });
          input2.value = ids.join(',');
        });
        frame2.open();
        return;
      }
    });

    // Preview dla EMBED (YT/Vimeo)
    function ytId(url){
      try{
        var u = new URL(url);
        if (u.hostname.indexOf('youtu.be')>-1) return u.pathname.replace('/','');
        if (u.searchParams.has('v')) return u.searchParams.get('v');
      }catch(_){}
      return '';
    }
    function vimeoId(url){
      try{
        var u = new URL(url);
        if (u.hostname.indexOf('vimeo.com')>-1) {
          var p = u.pathname.replace('/','');
          if (/^\d+$/.test(p)) return p;
        }
      }catch(_){}
      return '';
    }
    function renderEmbedPreview(container, url){
      var y = ytId(url), v = vimeoId(url);
      if (y){
        container.innerHTML = '<img class="aff-embed-thumb" src="https://i.ytimg.com/vi/'+y+'/hqdefault.jpg" alt="">';
      } else if (v){
        container.innerHTML = '<iframe src="https://player.vimeo.com/video/'+v+'" style="width:200px;height:112px;border:0;border-radius:6px" loading="lazy" allowfullscreen></iframe>';
      } else {
        container.innerHTML = '';
      }
    }
    list.addEventListener('input', function(e){
      if (e.target.classList.contains('js-embed-url')) {
        var b = e.target.closest('.aff-block');
        var prev = b.querySelector('.js-preview-embed');
        if (prev) renderEmbedPreview(prev, e.target.value.trim());
      }
    });

    // Dodawanie bloków
    document.getElementById('aff-add-block').addEventListener('click', function(){
      var type = (document.getElementById('aff-new-type').value || 'text');
      var idx  = list.querySelectorAll('.aff-block').length;
      var html = createBlockHTML(idx, type);
      var div = document.createElement('div');
      div.innerHTML = html;
      var el = div.firstElementChild;
      list.appendChild(el);
      remapIndexes();
    });

    function row(label, inner){ return '<div class="row"><label>'+label+'</label>'+inner+'</div>'; }

    function marginPanel(){
      return '<div class="aff-margins-panel" hidden>'+
               row('Margines górny (px)','<input type="number" min="0" max="200" step="1" class="small-text aff-field" data-k="mtop" value="16">')+
               row('Margines dolny (px)','<input type="number" min="0" max="200" step="1" class="small-text aff-field" data-k="mbot" value="16">')+
             '</div>';
    }

    function createBlockHTML(i, type){
      var t = AffMaterials && AffMaterials.i18n || {};
      var out = '<div class="aff-block" data-index="'+i+'" data-type="'+type+'" draggable="true">';
      out += '<div class="row"><span class="aff-handle" title="Przeciągnij">≡</span> <label>Typ</label><strong>'+type+'</strong></div>';

      if (type==='heading') out += row('Tytuł','<input type="text" class="widefat aff-field" data-k="val" value="">');
      else if (type==='text') out += row('Tekst','<textarea class="widefat aff-field" data-k="val" rows="5"></textarea>');
      else if (type==='image') {
        out += row('Grafika','<input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL (wp-content/uploads/...)"> <button type="button" class="button aff-pick" data-target="val">'+(t.pick||'Wybierz')+'</button>');
        out += row('Podpis','<input type="text" class="widefat aff-field" data-k="val2" value="">');
        out += '<div class="aff-preview js-preview-image"></div>';
      }
      else if (type==='gallery') out += row('ID obrazów','<input type="text" class="regular-text aff-field" data-k="items" placeholder="np. 12,15,18"> <button type="button" class="button aff-pick-gallery" data-target="items">'+(t.pick||'Wybierz')+'</button>');
      else if (type==='video') out += row('Plik wideo','<input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL (wp-content/uploads/...)"> <button type="button" class="button aff-pick" data-target="val">'+(t.pick||'Wybierz')+'</button>');
      else if (type==='file') {
        out += row('Plik do pobrania','<input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL (wp-content/uploads/...)"> <button type="button" class="button aff-pick" data-target="val">'+(t.pick||'Wybierz')+'</button>');
        out += row('Nazwa/przycisk','<input type="text" class="widefat aff-field" data-k="val2" value="" placeholder="np. Pobierz PDF">');
      }
      else if (type==='embed') {
        out += row('URL filmu','<input type="url" class="widefat aff-field js-embed-url" data-k="val" placeholder="https://youtu.be/... lub https://vimeo.com/...">');
        out += '<div class="aff-preview js-preview-embed"></div>';
      }
      else if (type==='html') out += row('Kod HTML','<textarea class="widefat aff-field" data-k="val" rows="6"></textarea>');
      else if (type==='divider') out += '<div class="aff-preview"><hr style="border:0;border-top:1px solid #e5e7eb;margin:8px 0 0 0;max-width:320px"></div>';

      // Panel marginesów dla każdego
      out += marginPanel();

      out += '<div class="aff-controls">'+
               '<button type="button" class="button aff-move-up">'+(t.up||'Góra')+'</button> '+
               '<button type="button" class="button aff-move-down">'+(t.down||'Dół')+'</button> '+
               '<button type="button" class="button aff-edit-margins">'+(t.margin||'Ustaw margines')+'</button> '+
               '<button type="button" class="button-link-delete aff-remove">'+(t.remove||'Usuń')+'</button>'+
             '</div>';

      out += '</div>';
      return out;
    }

    var form = document.getElementById('post');
    if (form) form.addEventListener('submit', function(){ serialize(); });
  });
})(jQuery);
