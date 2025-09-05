<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class AdminMaterials {

    const CPT  = 'aff_material';
    const META = '_aff_blocks';

    public function hooks() : void {
        add_action('init', array( $this, 'register_cpt' ));
        add_action('add_meta_boxes', array( $this, 'metaboxes' ));
        add_action('save_post_' . self::CPT, array( $this, 'save' ));
        add_action('admin_enqueue_scripts', array( $this, 'assets' ));
    }

    public function register_cpt() : void {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => 'Materiały promocyjne',
                'singular_name' => 'Materiał',
                'add_new'       => 'Dodaj materiał',
                'add_new_item'  => 'Dodaj materiał',
                'edit_item'     => 'Edytuj materiał',
                'new_item'      => 'Nowy materiał',
                'view_item'     => 'Podgląd',
                'search_items'  => 'Szukaj materiałów',
                'not_found'     => 'Brak materiałów',
            ),
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'affilite',  // pod menu AffiLite
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'supports'        => array( 'title' ),
            'menu_icon'       => 'dashicons-megaphone',
        ));
    }

    public function metaboxes() : void {
        add_meta_box(
            'aff_blocks',
            'Bloki materiałów',
            array( $this, 'render_metabox' ),
            self::CPT,
            'normal',
            'high'
        );
    }

    public function assets( $hook ) : void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== self::CPT ) { return; }

        wp_enqueue_media();
        wp_enqueue_script(
            'aff-materials-admin',
            AFFILITE_URL . 'assets/js/materials-admin.js',
            array( 'jquery' ),
            AFFILITE_VERSION,
            true
        );

        wp_localize_script(
            'aff-materials-admin',
            'AffMaterials',
            array(
                'i18n' => array(
                    'add'    => 'Dodaj blok',
                    'remove' => 'Usuń',
                    'up'     => 'Góra',
                    'down'   => 'Dół',
                    'pick'   => 'Wybierz',
                )
            )
        );

        // proste style inline (możesz przenieść do assets/css/admin.css)
        $css = '.aff-block{border:1px solid #e5e5e5;padding:10px;margin:8px 0;border-radius:6px;background:#fff}
        .aff-block .row{display:flex;gap:8px;align-items:center;margin:6px 0}
        .aff-block label{min-width:130px;font-weight:600}
        .aff-controls{display:flex;gap:6px;margin:6px 0}
        .aff-list{margin-top:8px}';
        // używamy uchwytu adminowego (wp-components nie zawsze jest w kolejce)
        wp_register_style('aff-materials-inline', false);
        wp_enqueue_style('aff-materials-inline');
        wp_add_inline_style('aff-materials-inline', $css);
    }

    public function render_metabox( \WP_Post $post ) : void {
        $blocks = get_post_meta($post->ID, self::META, true);
        $blocks = is_array($blocks) ? $blocks : array();
        wp_nonce_field('aff_materials_save', '_aff_nonce');

        echo '<p>Dodawaj bloki z treściami dla afiliantów (nagłówki, teksty, grafiki, wideo, pliki, osadzenia, HTML). Kolejność ma znaczenie.</p>';
        echo '<div class="aff-controls">';
        echo '<select id="aff-new-type">
                <option value="heading">Nagłówek</option>
                <option value="text">Tekst</option>
                <option value="image">Grafika</option>
                <option value="gallery">Zestaw grafik</option>
                <option value="video">Film (plik)</option>
                <option value="file">Pobierz plik</option>
                <option value="embed">Zasysacz wideo (YouTube/Vimeo)</option>
                <option value="html">Kod HTML</option>
              </select>
              <button type="button" class="button button-primary" id="aff-add-block">Dodaj blok</button>';
        echo '</div>';

        echo '<div class="aff-list" id="aff-list">';
        foreach ( $blocks as $i => $b ) {
            $this->block_row( (int)$i, is_array($b) ? $b : array() );
        }
        echo '</div>';

        echo '<input type="hidden" id="aff-blocks-json" name="aff_blocks_json" value="">';
        echo '<p class="description">Zmiany zapisują się po kliknięciu „Zaktualizuj”.</p>';
    }

    private function block_row( int $i, array $b ) : void {
        $type  = isset($b['type']) ? $b['type'] : 'text';
        $val   = isset($b['val'])  ? $b['val']  : '';
        $val2  = isset($b['val2']) ? $b['val2'] : '';
        $items = isset($b['items']) ? $b['items'] : array();

        echo '<div class="aff-block" data-index="'.esc_attr($i).'">';
        echo '<div class="row"><label>Typ</label><strong>'.esc_html($type).'</strong></div>';

        switch ($type) {
            case 'heading':
                echo '<div class="row"><label>Tytuł</label><input type="text" class="widefat aff-field" data-k="val" value="'.esc_attr($val).'"></div>';
                break;

            case 'text':
                echo '<div class="row"><label>Tekst</label><textarea class="widefat aff-field" data-k="val" rows="5">'.esc_textarea($val).'</textarea></div>';
                break;

            case 'image':
                echo '<div class="row"><label>Grafika</label>
                        <input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL" value="'.esc_attr($val).'">
                        <button type="button" class="button aff-pick" data-target="val">Wybierz</button></div>';
                echo '<div class="row"><label>Podpis</label><input type="text" class="widefat aff-field" data-k="val2" value="'.esc_attr($val2).'"></div>';
                break;

            case 'gallery':
                $ids = is_array($items) ? implode(',', array_map('intval', $items)) : '';
                echo '<div class="row"><label>ID obrazów</label>
                        <input type="text" class="regular-text aff-field" data-k="items" value="'.esc_attr($ids).'" placeholder="np. 12,15,18">
                        <button type="button" class="button aff-pick-gallery" data-target="items">Wybierz</button></div>';
                break;

            case 'video':
                echo '<div class="row"><label>Plik wideo</label>
                        <input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL" value="'.esc_attr($val).'">
                        <button type="button" class="button aff-pick" data-target="val">Wybierz</button></div>';
                break;

            case 'file':
                echo '<div class="row"><label>Plik do pobrania</label>
                        <input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL" value="'.esc_attr($val).'">
                        <button type="button" class="button aff-pick" data-target="val">Wybierz</button></div>';
                echo '<div class="row"><label>Nazwa/przycisk</label><input type="text" class="widefat aff-field" data-k="val2" value="'.esc_attr($val2).'" placeholder="np. Pobierz PDF"></div>';
                break;

            case 'embed':
                echo '<div class="row"><label>URL filmu</label><input type="url" class="widefat aff-field" data-k="val" value="'.esc_attr($val).'" placeholder="https://youtu.be/..."></div>';
                break;

            case 'html':
                echo '<div class="row"><label>Kod HTML</label><textarea class="widefat aff-field" data-k="val" rows="6">'.esc_textarea($val).'</textarea></div>';
                break;
        }

        echo '<div class="aff-controls">
                <button type="button" class="button aff-move-up">Góra</button>
                <button type="button" class="button aff-move-down">Dół</button>
                <button type="button" class="button-link-delete aff-remove">Usuń</button>
              </div>';
        echo '</div>';
    }

    public function save( int $post_id ) : void {
        if ( ! isset($_POST['_aff_nonce']) || ! wp_verify_nonce($_POST['_aff_nonce'], 'aff_materials_save') ) { return; }
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can('edit_post', $post_id) ) { return; }

        $json = isset($_POST['aff_blocks_json']) ? (string) wp_unslash($_POST['aff_blocks_json']) : '';
        $arr  = json_decode($json, true);
        $clean = array();

        if ( is_array($arr) ) {
            foreach ( $arr as $b ) {
                $type = (isset($b['type']) && in_array($b['type'], array('heading','text','image','gallery','video','file','embed','html'), true))
                    ? $b['type'] : 'text';

                $row = array( 'type' => $type );

                switch ($type) {
                    case 'heading':
                    case 'embed':
                        $row['val'] = sanitize_text_field( isset($b['val']) ? $b['val'] : '' );
                        break;
                    case 'text':
                    case 'html':
                        $row['val'] = wp_kses_post( isset($b['val']) ? $b['val'] : '' );
                        break;
                    case 'image':
                        $row['val']  = sanitize_text_field( isset($b['val']) ? $b['val'] : '' );   // ID lub URL
                        $row['val2'] = sanitize_text_field( isset($b['val2']) ? $b['val2'] : '' ); // podpis
                        break;
                    case 'gallery':
                        $raw   = isset($b['items']) ? (string)$b['items'] : '';
                        $items = array_filter( array_map('intval', explode(',', $raw)) );
                        $row['items'] = array_values($items);
                        break;
                    case 'video':
                    case 'file':
                        $row['val']  = sanitize_text_field( isset($b['val']) ? $b['val'] : '' );   // ID lub URL
                        $row['val2'] = sanitize_text_field( isset($b['val2']) ? $b['val2'] : '' ); // etykieta
                        break;
                }
                $clean[] = $row;
            }
        }

        update_post_meta($post_id, self::META, $clean);
    }
}
