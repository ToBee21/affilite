<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Materials {

    public function render_for_affiliate() : void {
        // Sortowanie: najpierw menu_order ASC, potem data DESC.
        $q = new \WP_Query([
            'post_type'      => AdminMaterials::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
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
                $mt = isset($b['mtop']) ? (int)$b['mtop'] : ( ($type==='divider' && isset($b['val']))  ? (int)$b['val']  : 16 );
                $mb = isset($b['mbot']) ? (int)$b['mbot'] : ( ($type==='divider' && isset($b['val2'])) ? (int)$b['val2'] : 16 );
                $mt = max(0, min(200, $mt));
                $mb = max(0, min(200, $mb));

                echo '<div class="aff-mblock" style="margin:'.esc_attr($mt).'px 0 '.esc_attr($mb).'px 0">';

                switch ($type) {
                    case 'heading':
                        echo '<h4>'.esc_html( (string)($b['val'] ?? '') ).'</h4>';
                        break;

                    case 'text':
                        echo wpautop( wp_kses_post( (string)($b['val'] ?? '') ) );
                        break;

                    case 'image':
                        $src = $this->media_url( $b['val'] ?? '' );
                        if ( $src ) {
                            echo '<figure><img src="'.esc_url($src).'" style="max-width:100%;height:auto" loading="lazy" />';
                            if ( ! empty($b['val2']) ) {
                                echo '<figcaption>'.esc_html((string)$b['val2']).'</figcaption>';
                            }
                            echo '</figure>';
                        }
                        break;

                    case 'gallery':
                        $raw = $b['items'] ?? [];
                        if ( is_array($raw) ) {
                            $ids = array_values(array_filter(array_map('intval', $raw)));
                        } else {
                            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$raw))));
                        }
                        if ( $ids ) {
                            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">';
                            foreach ( $ids as $id ) {
                                $src = wp_get_attachment_image_url((int)$id, 'large');
                                if ( $src ) {
                                    echo '<img src="'.esc_url($src).'" style="width:100%;height:auto" loading="lazy" alt="">';
                                }
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
                            $label = ($b['val2'] ?? '') !== '' ? (string)$b['val2'] : 'Pobierz plik';
                            echo '<p><a class="button" href="'.esc_url($src).'" target="_blank" rel="noopener">'.esc_html($label).'</a></p>';
                        }
                        break;

                    case 'embed':
                        $url = (string) ($b['val'] ?? '');
                        $embed = $this->safe_embed_html( $url );
                        if ( $embed ) {
                            echo '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden">';
                            echo $embed;
                            echo '</div>';
                        }
                        break;

                    case 'html':
                        echo wp_kses_post( (string)($b['val'] ?? '') );
                        break;

                    case 'divider':
                        echo '<hr style="border:0;border-top:1px solid rgba(0,0,0,.12);margin:0">';
                        break;
                }

                echo '</div>'; // .aff-mblock
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
        $u = is_string($id_or_url) ? $id_or_url : '';
        $uploads = wp_get_upload_dir();
        $baseurl = (string) ($uploads['baseurl'] ?? '');
        if ( $u && $baseurl && str_starts_with($u, $baseurl) ) {
            return esc_url_raw($u);
        }
        return '';
    }

    private function safe_embed_html( string $url ) : string {
        $u = wp_parse_url($url);
        if ( ! is_array($u) || empty($u['host']) ) return '';
        $host = strtolower($u['host']);

        if ( str_contains($host,'youtube.com') || str_contains($host,'youtu.be') ) {
            $id = '';
            if ( str_contains($host,'youtu.be') ) {
                $id = ltrim((string)($u['path'] ?? ''), '/');
            } else {
                parse_str( $u['query'] ?? '', $q );
                $id = isset($q['v']) ? (string)$q['v'] : '';
            }
            if ( $id !== '' ) {
                $src = 'https://www.youtube.com/embed/' . rawurlencode($id);
                return '<iframe src="'.esc_url($src).'" frameborder="0" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%"></iframe>';
            }
        }

        if ( str_contains($host,'vimeo.com') ) {
            $path = trim((string)($u['path'] ?? ''), '/');
            if ( $path !== '' && ctype_digit($path) ) {
                $src = 'https://player.vimeo.com/video/' . rawurlencode($path);
                return '<iframe src="'.esc_url($src).'" frameborder="0" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%"></iframe>';
            }
        }

        return '';
    }
}
