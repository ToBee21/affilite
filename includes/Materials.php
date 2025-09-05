<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Materials {

    public function render_for_affiliate() : void {
        // pobierz opublikowane materiały
        $q = new \WP_Query([
            'post_type'      => AdminMaterials::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        if ( ! $q->have_posts() ) {
            echo '<p>Brak materiałów udostępnionych przez administratora.</p>';
            return;
        }

        echo '<div class="aff-materials">';
        while ( $q->have_posts() ) {
            $q->the_post();
            $blocks = get_post_meta(get_the_ID(), AdminMaterials::META, true);
            $blocks = is_array($blocks) ? $blocks : [];

            echo '<div class="aff-card" style="margin-bottom:16px;padding:16px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff">';
            echo '<h3 style="margin-top:0">'.esc_html( get_the_title() ).'</h3>';

            foreach ( $blocks as $b ) {
                $type = $b['type'] ?? 'text';
                switch ($type) {
                    case 'heading':
                        echo '<h4>'.esc_html( $b['val'] ?? '' ).'</h4>';
                        break;
                    case 'text':
                        echo wpautop( wp_kses_post( $b['val'] ?? '' ) );
                        break;
                    case 'image':
                        $src = $this->media_url( $b['val'] ?? '' );
                        if ( $src ) {
                            echo '<figure><img src="'.esc_url($src).'" style="max-width:100%;height:auto" loading="lazy" />';
                            if ( ! empty($b['val2']) ) echo '<figcaption>'.esc_html($b['val2']).'</figcaption>';
                            echo '</figure>';
                        }
                        break;
                    case 'gallery':
                        $ids = array_filter(array_map('intval', explode(',', (string)($b['items'] ?? ''))));
                        if ( $ids ) {
                            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">';
                            foreach ( $ids as $id ) {
                                $src = wp_get_attachment_image_url($id, 'large');
                                if ( $src ) echo '<img src="'.esc_url($src).'" style="width:100%;height:auto" loading="lazy">';
                            }
                            echo '</div>';
                        }
                        break;
                    case 'video':
                        $src = $this->media_url( $b['val'] ?? '' );
                        if ( $src ) {
                            echo '<video controls style="max-width:100%"><source src="'.esc_url($src).'"></video>';
                        }
                        break;
                    case 'file':
                        $src = $this->media_url( $b['val'] ?? '' );
                        if ( $src ) {
                            $label = $b['val2'] !== '' ? $b['val2'] : 'Pobierz plik';
                            echo '<p><a class="button" href="'.esc_url($src).'" target="_blank" rel="noopener">'.esc_html($label).'</a></p>';
                        }
                        break;
                    case 'embed':
                        $url = esc_url( $b['val'] ?? '' );
                        if ( $url ) {
                            // prosta ramka o proporcjach 16:9
                            echo '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden">';
                            echo '<iframe src="'.esc_url($url).'" frameborder="0" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%"></iframe>';
                            echo '</div>';
                        }
                        break;
                    case 'html':
                        // pozwalamy na zapisany HTML (zapisany był już przez wp_kses_post)
                        echo (string)($b['val'] ?? '');
                        break;
                }
            }

            echo '</div>';
        }
        echo '</div>';
        wp_reset_postdata();
    }

    private function media_url( $id_or_url ) : string {
        if ( is_numeric($id_or_url) ) {
            $u = wp_get_attachment_url( (int)$id_or_url );
            return $u ?: '';
        }
        return is_string($id_or_url) ? $id_or_url : '';
    }
}
