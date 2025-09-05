<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class AdminMaterials {

    const CPT  = 'aff_material';
    const META = '_aff_blocks';

    /** Walidacja rozszerzeń */
    private array $allowed_images = ['jpg','jpeg','png','gif','webp'];
    private array $allowed_video  = ['mp4','webm','ogg'];
    private array $allowed_files  = ['pdf','zip','csv','xlsx','docx','pptx','txt'];

    public function hooks() : void {
        add_action('init', array( $this, 'register_cpt' ));
        add_action('add_meta_boxes', array( $this, 'metaboxes' ));
        add_action('save_post_' . self::CPT, array( $this, 'save' ));
        add_action('admin_enqueue_scripts', array( $this, 'assets' ));

        // Kolumna „Kolejność” i sortowanie w liście
        add_filter('manage_edit-' . self::CPT . '_columns', [ $this, 'columns' ]);
        add_action('manage_' . self::CPT . '_posts_custom_column', [ $this, 'column_content' ], 10, 2);
        add_filter('manage_edit-' . self::CPT . '_sortable_columns', [ $this, 'sortable_columns' ]);
        add_action('pre_get_posts', [ $this, 'admin_default_ordering' ]);
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
            'show_in_menu'    => 'affilite',
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            // Włączamy „Kolejność” (menu_order). Ustawiamy hierarchical=true,
            // żeby pole było też w „Szybkiej edycji”.
            'hierarchical'    => true,
            'supports'        => array( 'title', 'page-attributes' ),
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
            plugins_url( 'assets/js/materials-admin.js', dirname(__DIR__) . '/affilite.php' ),
            array( 'jquery' ),
            '1.2.0',
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
                    'margin' => 'Ustaw margines',
                    'type_heading' => 'Nagłówek',
                    'type_text'    => 'Tekst',
                    'type_image'   => 'Grafika',
                    'type_gallery' => 'Zestaw grafik',
                    'type_video'   => 'Film (plik)',
                    'type_file'    => 'Pobierz plik',
                    'type_embed'   => 'Zasysacz wideo (YouTube/Vimeo)',
                    'type_html'    => 'Kod HTML',
                    'type_divider' => 'Rozdzielacz'
                )
            )
        );

        $css = '
        .aff-block{border:1px solid #e5e5e5;padding:10px;margin:8px 0;border-radius:6px;background:#fff}
        .aff-block .row{display:flex;gap:8px;align-items:center;margin:6px 0}
        .aff-block label{min-width:130px;font-weight:600}
        .aff-controls{display:flex;gap:6px;margin:6px 0;flex-wrap:wrap}
        .aff-list{margin-top:8px}
        .aff-handle{cursor:move;user-select:none;padding:4px 8px;border:1px solid #e5e5e5;border-radius:4px;background:#f7f7f7;font-size:12px}
        .aff-preview{margin:6px 0}
        .aff-embed-thumb{width:200px;height:112px;object-fit:cover;border-radius:6px;border:1px solid #eee}
        .aff-block.dragging{opacity:.6}
        .aff-margins-panel{padding:8px;border:1px dashed #e5e7eb;border-radius:6px;background:#fcfcfc;margin-top:6px}
        ';
        wp_register_style('aff-materials-inline', false);
        wp_enqueue_style('aff-materials-inline');
        wp_add_inline_style('aff-materials-inline', $css);
    }

    public function render_metabox( \WP_Post $post ) : void {
        $blocks = get_post_meta($post->ID, self::META, true);
        $blocks = is_array($blocks) ? $blocks : array();
        wp_nonce_field('aff_materials_save', '_aff_nonce');

        echo '<p>Dodawaj bloki z treściami dla afiliantów. Kolejność ma znaczenie. Przeciągaj za <kbd class="aff-handle">≡</kbd> aby sortować. Każdy blok może mieć własne marginesy (Góra/Dół).</p>';
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
                <option value="divider">Rozdzielacz</option>
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
        $val   = $b['val']  ?? '';
        $val2  = $b['val2'] ?? '';
        $mtop  = isset($b['mtop']) ? (int)$b['mtop'] : ( $type === 'divider' && is_numeric($val)  ? (int)$val  : 16 );
        $mbot  = isset($b['mbot']) ? (int)$b['mbot'] : ( $type === 'divider' && is_numeric($val2) ? (int)$val2 : 16 );
        $items = $b['items'] ?? array();

        echo '<div class="aff-block" data-index="'.esc_attr($i).'" data-type="'.esc_attr($type).'" draggable="true">';
        echo '<div class="row"><span class="aff-handle" title="Przeciągnij">≡</span> <label>Typ</label><strong>'.esc_html($type).'</strong></div>';

        switch ($type) {
            case 'heading':
                echo '<div class="row"><label>Tytuł</label><input type="text" class="widefat aff-field" data-k="val" value="'.esc_attr((string)$val).'"></div>';
                break;

            case 'text':
                echo '<div class="row"><label>Tekst</label><textarea class="widefat aff-field" data-k="val" rows="5">'.esc_textarea((string)$val).'</textarea></div>';
                break;

            case 'image':
                echo '<div class="row"><label>Grafika</label>
                        <input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL (wp-content/uploads/...)" value="'.esc_attr((string)$val).'">
                        <button type="button" class="button aff-pick" data-target="val">Wybierz</button></div>';
                echo '<div class="row"><label>Podpis</label><input type="text" class="widefat aff-field" data-k="val2" value="'.esc_attr((string)$val2).'"></div>';
                echo '<div class="aff-preview js-preview-image"></div>';
                break;

            case 'gallery':
                $ids = is_array($items) ? implode(',', array_map('intval', $items)) : '';
                echo '<div class="row"><label>ID obrazów</label>
                        <input type="text" class="regular-text aff-field" data-k="items" value="'.esc_attr($ids).'" placeholder="np. 12,15,18">
                        <button type="button" class="button aff-pick-gallery" data-target="items">Wybierz</button></div>';
                break;

            case 'video':
                echo '<div class="row"><label>Plik wideo</label>
                        <input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL (wp-content/uploads/...)" value="'.esc_attr((string)$val).'">
                        <button type="button" class="button aff-pick" data-target="val">Wybierz</button></div>';
                break;

            case 'file':
                echo '<div class="row"><label>Plik do pobrania</label>
                        <input type="text" class="regular-text aff-field" data-k="val" placeholder="ID lub URL (wp-content/uploads/...)" value="'.esc_attr((string)$val).'">
                        <button type="button" class="button aff-pick" data-target="val">Wybierz</button></div>';
                echo '<div class="row"><label>Nazwa/przycisk</label><input type="text" class="widefat aff-field" data-k="val2" value="'.esc_attr((string)$val2).'" placeholder="np. Pobierz PDF"></div>';
                break;

            case 'embed':
                echo '<div class="row"><label>URL filmu</label><input type="url" class="widefat aff-field js-embed-url" data-k="val" value="'.esc_attr((string)$val).'" placeholder="https://youtu.be/... lub https://vimeo.com/..."></div>';
                echo '<div class="aff-preview js-preview-embed"></div>';
                break;

            case 'html':
                echo '<div class="row"><label>Kod HTML</label><textarea class="widefat aff-field" data-k="val" rows="6">'.esc_textarea((string)$val).'</textarea></div>';
                break;

            case 'divider':
                echo '<div class="aff-preview"><hr style="border:0;border-top:1px solid #e5e7eb;margin:8px 0 0 0;max-width:320px"></div>';
                break;
        }

        echo '<div class="aff-margins-panel" hidden>
                <div class="row"><label>Margines górny (px)</label><input type="number" min="0" max="200" step="1" class="small-text aff-field" data-k="mtop" value="'.esc_attr($mtop).'"></div>
                <div class="row"><label>Margines dolny (px)</label><input type="number" min="0" max="200" step="1" class="small-text aff-field" data-k="mbot" value="'.esc_attr($mbot).'"></div>
              </div>';

        echo '<div class="aff-controls">';
        echo '<button type="button" class="button aff-move-up">Góra</button> ';
        echo '<button type="button" class="button aff-move-down">Dół</button> ';
        echo '<button type="button" class="button aff-edit-margins">Ustaw margines</button> ';
        echo '<button type="button" class="button-link-delete aff-remove">Usuń</button>';
        echo '</div>';

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
                $type = (isset($b['type']) && in_array($b['type'], array('heading','text','image','gallery','video','file','embed','html','divider'), true))
                    ? $b['type'] : 'text';

                $row = array( 'type' => $type );

                // Marginesy – zawsze dwa pola
                $mt = isset($b['mtop']) ? (int)$b['mtop'] : 16;
                $mb = isset($b['mbot']) ? (int)$b['mbot'] : 16;
                $row['mtop'] = max(0, min(200, $mt));
                $row['mbot'] = max(0, min(200, $mb));

                switch ($type) {
                    case 'heading':
                    case 'embed':
                        $row['val'] = sanitize_text_field( $b['val'] ?? '' );
                        if ( $type === 'embed' ) {
                            $url = $row['val'];
                            if ( $url !== '' && ! $this->is_allowed_embed($url) ) {
                                $row['val'] = '';
                            }
                        }
                        break;

                    case 'text':
                    case 'html':
                        $row['val'] = wp_kses_post( $b['val'] ?? '' );
                        break;

                    case 'image':
                        $row['val']  = $this->sanitize_media_ref( $b['val'] ?? '', $this->allowed_images );
                        $row['val2'] = sanitize_text_field( $b['val2'] ?? '' );
                        break;

                    case 'gallery':
                        $raw   = isset($b['items']) ? (string)$b['items'] : '';
                        $items = array_filter( array_map('intval', explode(',', $raw)) );
                        $valid = array();
                        foreach ( $items as $id ) {
                            $mime = (string) get_post_mime_type( $id );
                            if ( strpos($mime, 'image/') === 0 ) { $valid[] = $id; }
                        }
                        $row['items'] = array_values($valid);
                        break;

                    case 'video':
                        $row['val']  = $this->sanitize_media_ref( $b['val'] ?? '', $this->allowed_video );
                        $row['val2'] = sanitize_text_field( $b['val2'] ?? '' );
                        break;

                    case 'file':
                        $row['val']  = $this->sanitize_media_ref( $b['val'] ?? '', $this->allowed_files );
                        $row['val2'] = sanitize_text_field( $b['val2'] ?? '' );
                        break;

                    case 'divider':
                        // treść niepotrzebna
                        break;
                }
                $clean[] = $row;
            }
        }

        update_post_meta($post_id, self::META, $clean);
    }

    private function sanitize_media_ref( $val, array $allowed_ext ) : string {
        $val = is_string($val) ? trim($val) : '';
        if ($val === '') return '';

        if ( ctype_digit((string)$val) ) {
            $id = (int)$val;
            $url = wp_get_attachment_url($id);
            if ( ! $url ) return '';
            $ext = strtolower( pathinfo( parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) );
            return in_array($ext, $allowed_ext, true) ? (string)$id : '';
        }

        $uploads = wp_get_upload_dir();
        $baseurl = (string) ($uploads['baseurl'] ?? '');
        if ( $baseurl && str_starts_with($val, $baseurl) ) {
            $ext = strtolower( pathinfo( parse_url($val, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) );
            return in_array($ext, $allowed_ext, true) ? esc_url_raw($val) : '';
        }
        return '';
    }

    private function is_allowed_embed( string $url ) : bool {
        $u = wp_parse_url( $url );
        if ( ! is_array($u) || empty($u['host']) ) return false;
        $h = strtolower($u['host']);
        return (str_contains($h,'youtube.com') || str_contains($h,'youtu.be') || str_contains($h,'vimeo.com'));
    }

    /* ===== Admin: lista – kolumna i sortowanie po menu_order ===== */

    public function columns( array $cols ) : array {
        // wstawiamy kolumnę „Kolejność” za Tytułem
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['menu_order'] = 'Kolejność';
            }
        }
        if (!isset($new['menu_order'])) $new['menu_order'] = 'Kolejność';
        return $new;
    }

    public function column_content( string $column, int $post_id ) : void {
        if ($column === 'menu_order') {
            echo (int) get_post_field('menu_order', $post_id);
        }
    }

    public function sortable_columns( array $cols ) : array {
        $cols['menu_order'] = 'menu_order';
        return $cols;
    }

    public function admin_default_ordering( \WP_Query $q ) : void {
        if ( ! is_admin() || ! $q->is_main_query() ) return;
        if ( $q->get('post_type') !== self::CPT ) return;

        // Domyślnie sortuj wg kolejności rosnąco.
        if ( ! isset($_GET['orderby']) ) {
            $q->set('orderby', 'menu_order date');
            $q->set('order', 'ASC DESC');
        }
    }
}
