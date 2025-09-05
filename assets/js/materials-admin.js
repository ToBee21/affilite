(function($){
  function serialize(){
    var out = [];
    $('#aff-list .aff-block').each(function(){
      var $b = $(this), obj = {};
      obj.type = $b.find('strong').first().text().trim();
      // read fields
      $b.find('.aff-field').each(function(){
        var k = $(this).data('k');
        if (k === 'items') {
          obj.items = $(this).val();
        } else {
          obj[k] = $(this).val();
        }
      });
      out.push(obj);
    });
    $('#aff-blocks-json').val(JSON.stringify(out));
  }

  function pickSingle(cb){
    var frame = wp.media({ multiple: false });
    frame.on('select', function(){
      var a = frame.state().get('selection').first().toJSON();
      cb(a);
    });
    frame.open();
  }

  function pickGallery(cb){
    var frame = wp.media({ multiple: true });
    frame.on('select', function(){
      var ids = frame.state().get('selection').map(m=>m.toJSON().id).join(',');
      cb(ids);
    });
    frame.open();
  }

  $(document).on('click', '#aff-add-block', function(){
    var t = $('#aff-new-type').val();
    // tworzymy pusty DIV i prosimy o zapis przez serialize (backend wyrenderuje typ po reloadzie)
    var $row = $('<div class="aff-block"><div class="row"><label>Typ</label><strong>'+t+'</strong></div></div>');
    // minimalny placeholder, aby serialize nie zgubił
    if (t==='heading' || t==='embed')
      $row.append('<div class="row"><label>Wartość</label><input type="text" class="widefat aff-field" data-k="val" value=""></div>');
    else if (t==='text' || t==='html')
      $row.append('<div class="row"><label>Wartość</label><textarea class="widefat aff-field" data-k="val" rows="4"></textarea></div>');
    else if (t==='gallery')
      $row.append('<div class="row"><label>ID obrazów</label><input type="text" class="regular-text aff-field" data-k="items"><button type="button" class="button aff-pick-gallery" data-target="items">'+AffMaterials.i18n.pick+'</button></div>');
    else
      $row.append('<div class="row"><label>Wartość</label><input type="text" class="regular-text aff-field" data-k="val"><button type="button" class="button aff-pick" data-target="val">'+AffMaterials.i18n.pick+'</button></div>');

    $row.append('<div class="aff-controls"><button type="button" class="button aff-move-up">'+AffMaterials.i18n.up+'</button><button type="button" class="button aff-move-down">'+AffMaterials.i18n.down+'</button><button type="button" class="button-link-delete aff-remove">'+AffMaterials.i18n.remove+'</button></div>');
    $('#aff-list').append($row);
    serialize();
  });

  $(document).on('click', '.aff-remove', function(){
    $(this).closest('.aff-block').remove();
    serialize();
  });

  $(document).on('click', '.aff-move-up', function(){
    var $b = $(this).closest('.aff-block');
    $b.prev('.aff-block').before($b);
    serialize();
  });
  $(document).on('click', '.aff-move-down', function(){
    var $b = $(this).closest('.aff-block');
    $b.next('.aff-block').after($b);
    serialize();
  });

  $(document).on('change', '.aff-field', serialize);

  $(document).on('click', '.aff-pick', function(){
    var $row = $(this).closest('.row');
    var $input = $row.find('[data-k="'+$(this).data('target')+'"]');
    pickSingle(function(a){
      $input.val(a.id || a.url);
      serialize();
    });
  });

  $(document).on('click', '.aff-pick-gallery', function(){
    var $row = $(this).closest('.row');
    var $input = $row.find('[data-k="items"]');
    pickGallery(function(ids){
      $input.val(ids);
      serialize();
    });
  });

  // na start – zserializuj aktualny stan
  $(function(){ serialize(); });
})(jQuery);
