<?php
namespace AffiLite;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Shortcode {
    public function register() : void {
        add_shortcode( 'aff_portal', [ $this, 'render' ] );
    }

    public function render( $atts = [] ) : string {
        if ( ! is_user_logged_in() ) {
            return '<div class="aff-portal"><p>Musisz być zalogowany, aby uzyskać dostęp do panelu afiliacyjnego.</p></div>';
        }

        // Enqueue lekkich styli portalu (bez zależności od stałych).
        $plugin_dir  = trailingslashit( dirname( __DIR__ ) );
        $plugin_main = $plugin_dir . 'affilite.php';
        $css_path    = $plugin_dir . 'assets/css/portal.css';
        $css_url     = plugins_url( 'assets/css/portal.css', $plugin_main );
        $css_ver     = file_exists($css_path) ? (string) filemtime($css_path) : null;
        wp_enqueue_style( 'affilite-portal', $css_url, [], $css_ver );

        $opts  = get_option(\AffiLite\Settings::OPTION_KEY, \AffiLite\Settings::defaults());
        $flags = $opts['flags'] ?? [];

        // Zakładki wg flag
        $tabs = [];
        $tabs['dashboard'] = 'Dashboard';
        if (!empty($flags['show_orders']))    { $tabs['orders']    = 'Zamówienia'; }
        if (!empty($flags['show_payouts']))   { $tabs['payouts']   = 'Wypłaty'; }
        if (!empty($flags['show_materials'])) { $tabs['materials'] = 'Materiały promocyjne'; }
        if (!empty($flags['show_link']))      { $tabs['link']      = 'Generator linku'; }
        $tabs['settings'] = 'Ustawienia';

        $user = wp_get_current_user();
        $partner = class_exists('\\AffiLite\\Tracking')
            ? \AffiLite\Tracking::ensure_partner_for_user( (int) $user->ID )
            : null;

        $base_link = $partner ? home_url('/ref/' . rawurlencode($partner->code) . '/') : '';

        // KPI + dane do wykresu (ostatnie 30 dni)
        $totals = ['earned'=>0.0,'requested'=>0.0,'paid'=>0.0,'available'=>0.0];
        $stats  = $this->fallback_last30(); // domyślnie 0/0 dla 30 dni

        if ( $partner ) {
            if ( class_exists('\\AffiLite\\Balance') ) {
                $totals = \AffiLite\Balance::totals( (int)$partner->id );
            }
            if ( class_exists('\\AffiLite\\Stats') ) {
                $fromStats = \AffiLite\Stats::last30( (int)$partner->id );
                if ( is_array($fromStats) && ! empty($fromStats['labels']) ) {
                    $stats = $this->normalize_stats_array( $fromStats );
                }
            }
        }

        ob_start(); ?>
        <div class="aff-portal" data-aff-portal>
            <nav class="aff-tabs" role="tablist" aria-label="Panel afilianta">
                <?php foreach ( $tabs as $key => $label ): ?>
                    <button type="button"
                            class="aff-tablink <?php echo $key==='dashboard' ? 'is-active' : ''; ?>"
                            role="tab"
                            data-tab-link="<?php echo esc_attr($key); ?>"
                            aria-controls="panel-<?php echo esc_attr($key); ?>"
                            aria-selected="<?php echo $key==='dashboard' ? 'true' : 'false'; ?>">
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <!-- DASHBOARD -->
            <section id="panel-dashboard" class="aff-tabpanel is-active" role="tabpanel" data-tab-panel="dashboard" aria-labelledby="tab-dashboard">
                <h2>Dashboard</h2>

                <?php if ( $partner ): ?>
                    <div class="aff-kpi-grid">
                        <div class="aff-card aff-kpi"><h3>Dostępne</h3><div class="aff-kpi-value"><?php echo \AffiLite\Balance::money((float)$totals['available']); ?></div></div>
                        <div class="aff-card aff-kpi"><h3>Zarobione</h3><div class="aff-kpi-value"><?php echo \AffiLite\Balance::money((float)$totals['earned']); ?></div></div>
                        <div class="aff-card aff-kpi"><h3>Kliknięcia (30 dni)</h3><div class="aff-kpi-value"><?php echo esc_html( array_sum($stats['clicks']) ); ?></div></div>
                        <div class="aff-card aff-kpi"><h3>Konwersje (30 dni)</h3><div class="aff-kpi-value"><?php echo esc_html( array_sum($stats['conv']) ); ?></div></div>
                    </div>

                    <div class="aff-card aff-chart-card">
                        <div class="aff-chart-header">
                            <h3>Aktywność — ostatnie 30 dni</h3>
                            <div class="aff-legend">
                                <span class="aff-dot" style="background:#3a78f2"></span> Kliknięcia
                                <span class="aff-dot" style="background:#26a269"></span> Konwersje
                            </div>
                        </div>
                        <div class="aff-chart">
                            <?php echo $this->render_chart_svg( $stats ); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>
                    <div class="aff-card aff-chart-card">
                        <div class="aff-chart-header">
                            <h3>Aktywność — ostatnie 30 dni</h3>
                            <div class="aff-legend">
                                <span class="aff-dot" style="background:#3a78f2"></span> Kliknięcia
                                <span class="aff-dot" style="background:#26a269"></span> Konwersje
                            </div>
                        </div>
                        <div class="aff-chart">
                            <?php echo $this->render_chart_svg( $stats ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if (!empty($flags['show_orders'])): ?>
            <section id="panel-orders" class="aff-tabpanel" role="tabpanel" data-tab-panel="orders" aria-labelledby="tab-orders" hidden>
                <h2>Zamówienia</h2>
                <?php if ( $partner ) { (new \AffiLite\PortalOrders())->render((int)$partner->id); } else { echo '<p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>'; } ?>
            </section>
            <?php endif; ?>

            <?php if (!empty($flags['show_payouts'])): ?>
            <section id="panel-payouts" class="aff-tabpanel" role="tabpanel" data-tab-panel="payouts" aria-labelledby="tab-payouts" hidden>
                <h2>Wypłaty</h2>
                <?php if ( $partner ) { (new \AffiLite\PortalPayouts())->render((int)$partner->id); } else { echo '<p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>'; } ?>
            </section>
            <?php endif; ?>

            <?php if (!empty($flags['show_materials'])): ?>
            <section id="panel-materials" class="aff-tabpanel" role="tabpanel" data-tab-panel="materials" aria-labelledby="tab-materials" hidden>
                <h2>Materiały promocyjne</h2>
                <?php if ( class_exists('\\AffiLite\\Materials') ) { (new \AffiLite\Materials())->render_for_affiliate(); } else { echo '<p>Brak modułu materiałów.</p>'; } ?>
            </section>
            <?php endif; ?>

            <?php if (!empty($flags['show_link'])): ?>
            <section id="panel-link" class="aff-tabpanel" role="tabpanel" data-tab-panel="link" aria-labelledby="tab-link" hidden>
                <h2>Generator linku</h2>
                <?php if ( $partner ): ?>
                    <p><strong>Twój kod:</strong> <code><?php echo esc_html($partner->code); ?></code></p>
                    <p><strong>Link bazowy:</strong> <input type="text" readonly value="<?php echo esc_attr($base_link); ?>" style="width:100%"></p>

                    <form method="get" onsubmit="event.preventDefault();" class="aff-link-form">
                        <label for="aff-to">Podstrona (opcjonalnie):</label>
                        <input type="text" id="aff-to" name="aff-to" placeholder="/produkt/nazwa/" style="width:100%;margin-bottom:8px;">
                        <button type="button" class="aff-generate">Wygeneruj</button>
                    </form>

                    <p><label for="aff-out"><strong>Twój link:</strong></label>
                    <input type="text" id="aff-out" name="aff-out" readonly style="width:100%"></p>

                    <p class="aff-hint">Uwaga: tylko adresy w tej samej domenie są dozwolone. <code>?to=</code> ścieżka względna, <code>?url=</code> pełny adres do tej samej domeny.</p>
                <?php else: ?>
                    <p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section id="panel-settings" class="aff-tabpanel" role="tabpanel" data-tab-panel="settings" aria-labelledby="tab-settings" hidden>
                <h2>Ustawienia</h2>
                <p>Wkrótce…</p>
            </section>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** 30 dni z zerami (bezpieczny fallback). */
    private function fallback_last30() : array {
        $labels = []; $clicks = []; $conv = [];
        $base = time();
        for ($i = 29; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' days', $base);
            $labels[] = gmdate('Y-m-d', $ts);
            $clicks[] = 0;
            $conv[]   = 0;
        }
        return ['labels'=>$labels,'clicks'=>$clicks,'conv'=>$conv];
    }

    /** Ujednolicenie formatu z Stats::last30. */
    private function normalize_stats_array( array $s ) : array {
        $labels = array_values( $s['labels'] ?? [] );
        if (empty($labels)) return $this->fallback_last30();
        $n = count($labels);
        $norm = function($a) use($n){ $a = is_array($a)? array_values($a):[]; if(count($a)<$n){ $a = array_pad($a,$n,0);} if(count($a)>$n){ $a = array_slice($a,0,$n);} return $a; };
        return [
            'labels' => $labels,
            'clicks' => $norm($s['clicks'] ?? []),
            'conv'   => $norm($s['conv'] ?? []),
        ];
    }

    /**
     * Wykres dzień-po-dniu (SVG, bez JS). Linie „step”, siatka dzienna,
     * natychmiastowy tooltip + guideline przez CSS :hover (bez opóźnienia).
     */
    private function render_chart_svg( array $s ) : string {
        $labels = $s['labels']; $clicks = $s['clicks']; $conv = $s['conv'];
        $n = count($labels);
        $maxY = max(1, (int) ceil( max( $clicks ? max($clicks) : 0, $conv ? max($conv) : 0 ) * 1.1 ));

        // Wymiary + marginesy
        $W=800; $H=320; $padL=48; $padR=16; $padT=16; $padB=56;
        $iw = max(10, $W - $padL - $padR);
        $ih = max(10, $H - $padT - $padB);
        $den = max(1, $n - 1);

        $x = function($i) use($padL,$iw,$den){ return $padL + ($i * ($iw/$den)); };
        $y = function($v) use($padT,$ih,$maxY){ return $padT + ($ih - (($v / max(1,$maxY)) * $ih)); };

        // Ścieżka „krokowa”
        $step = function(array $arr) use($x,$y){
            $d=''; $prev=0;
            foreach ($arr as $i=>$v) {
                $xi = (float)$x($i); $yi = (float)$y($prev);
                $d .= ($i===0 ? 'M' : 'L') . $xi . ' ' . $yi . ' ';
                $yi2 = (float)$y((float)$v);
                $d .= 'L' . $xi . ' ' . $yi2 . ' ';
                $prev = (float)$v;
            }
            return trim($d);
        };
        $dClicks = $step($clicks);
        $dConv   = $step($conv);

        // Markery (kropki)
        $markers = '';
        for ($i=0;$i<$n;$i++) {
            $cx = $x($i);
            $cy1= $y((float)$clicks[$i]); $cy2 = $y((float)$conv[$i]);
            $r1 = ((float)$clicks[$i] > 0) ? 3.5 : 2.2;
            $r2 = ((float)$conv[$i]   > 0) ? 3.5 : 2.2;
            $markers .= '<circle cx="'.$cx.'" cy="'.$cy1.'" r="'.$r1.'" class="pt pt-c" />';
            $markers .= '<circle cx="'.$cx.'" cy="'.$cy2.'" r="'.$r2.'" class="pt pt-k" />';
        }

        // Siatka pozioma (co 1/4) + etykiety Y
        $gridH = '';
        for ($gy=0; $gy<=4; $gy++) {
            $val = ($maxY/4)*$gy; $yy = $y($val);
            $gridH .= '<line x1="'.$padL.'" x2="'.($W-$padR).'" y1="'.$yy.'" y2="'.$yy.'" class="gh"/>';
            $gridH .= '<text x="'.($padL-10).'" y="'.($yy+4).'" text-anchor="end" class="ay">'.(int)round($val).'</text>';
        }

        // Siatka pionowa + etykiety X (co 2 dni)
        $gridV = '';
        for ($i=0; $i<$n; $i++) {
            $xi = $x($i);
            $gridV .= '<line x1="'.$xi.'" x2="'.$xi.'" y1="'.$padT.'" y2="'.($H-$padB).'" class="gv"/>';
            if ($i%2===0 || $i===$n-1) {
                $gridV .= '<text x="'.$xi.'" y="'.($H-$padB+18).'" transform="rotate(45 '.$xi.','.($H-$padB+18).')" class="ax">'.esc_html($labels[$i]).'</text>';
            }
        }

        // Hitboxy + guideline + tooltip bez opóźnienia (CSS :hover)
        $hitboxes = '';
        $band = ($den > 0) ? ($iw / $den) : $iw; // szerokość jednego dnia
        $tipW = 160; $tipH = 48; $tipPad = 8;

        for ($i=0; $i<$n; $i++) {
            $xi = (float)$x($i);
            $left = ($xi > ($padL + $iw*0.7)) ? 1 : 0; // przy prawej krawędzi — wyświetl po lewej
            $tx = $left ? ($xi - $tipW - $tipPad) : ($xi + $tipPad);
            $ty = $padT + $tipPad;

            $hitboxes .= '<g class="hit" data-i="'.$i.'">';
            // transparentny prostokąt zbierający hover
            $hitboxes .= '<rect class="hitbox" x="'.($xi - $band/2).'" y="'.$padT.'" width="'.$band.'" height="'.$ih.'"/>';
            // guideline
            $hitboxes .= '<line class="guide" x1="'.$xi.'" x2="'.$xi.'" y1="'.$padT.'" y2="'.($H-$padB).'"/>';
            // tooltip (czarny balonik)
            $hitboxes .= '<g class="tip" transform="translate('.$tx.','.$ty.')">';
            $hitboxes .=   '<rect class="tipbg" x="0" y="0" width="'.$tipW.'" height="'.$tipH.'" rx="6" ry="6"/>';
            $hitboxes .=   '<text class="tiptx" x="10" y="16">'.esc_html($labels[$i]).'</text>';
            $hitboxes .=   '<g class="tiprow" transform="translate(10,28)"><rect class="dot" x="0" y="-7" width="10" height="10" rx="2" ry="2"/><text x="16" y="2">Kliknięcia: '.(int)$clicks[$i].'</text></g>';
            $hitboxes .=   '<g class="tiprow" transform="translate(10,44)"><rect class="dot k" x="0" y="-7" width="10" height="10" rx="2" ry="2"/><text x="16" y="2">Konwersje: '.(int)$conv[$i].'</text></g>';
            $hitboxes .= '</g>';
            $hitboxes .= '</g>';
        }

        // Legendka w SVG
        $legend  = '<rect x="'.($W/2-70).'" y="12" width="140" height="20" rx="6" ry="6" class="legbg"/>';
        $legend .= '<rect x="'.($W/2-60).'" y="17" width="18" height="8" rx="2" ry="2" class="dot c"/>';
        $legend .= '<text x="'.($W/2-36).'" y="24" class="legtx">Kliknięcia</text>';
        $legend .= '<rect x="'.($W/2+36).'" y="17" width="18" height="8" rx="2" ry="2" class="dot k"/>';
        $legend .= '<text x="'.($W/2+60).'" y="24" class="legtx">Konwersje</text>';

        // CSS inline – żeby działało natychmiast bez zależności od zewnętrznych styli
        $css = '<style>
            .gh{stroke:rgba(0,0,0,.08)}
            .gv{stroke:rgba(0,0,0,.05)}
            .ay{fill:#666;font-size:11px}
            .ax{fill:#666;font-size:11px}
            .pt{stroke:#fff;stroke-width:1}
            .pt-c{fill:#3a78f2}
            .pt-k{fill:#26a269}
            .legbg{fill:#fff;stroke:rgba(0,0,0,.08)}
            .legtx{fill:#333;font-size:11px}
            .dot{fill:#3a78f2}
            .dot.k{fill:#26a269}
            .hitbox{fill:rgba(0,0,0,0);cursor:crosshair}
            .guide{stroke:rgba(0,0,0,.25);stroke-dasharray:2 3;opacity:0}
            .tip{opacity:0}
            .tipbg{fill:#111;fill-opacity:.92}
            .tiptx{fill:#fff;font-size:12px;font-weight:700}
            .tiprow text{fill:#fff;font-size:12px}
            .hit:hover .guide,.hit:focus .guide{opacity:1}
            .hit:hover .tip,.hit:focus .tip{opacity:1}
        </style>';

        // Składamy SVG
        $svg  = '<svg viewBox="0 0 '.$W.' '.$H.'" xmlns="http://www.w3.org/2000/svg" shape-rendering="geometricPrecision">';
        $svg .=   $css;
        $svg .=   '<g>'.$gridH.$gridV.'</g>';
        $svg .=   '<path d="'.esc_attr($dClicks).'" fill="none" stroke="#3a78f2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        $svg .=   '<path d="'.esc_attr($dConv).'"   fill="none" stroke="#26a269" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        $svg .=   '<g>'.$markers.'</g>';
        $svg .=   '<g>'.$legend.'</g>';
        $svg .=   '<g>'.$hitboxes.'</g>';
        $svg .= '</svg>';

        return $svg;
    }
}
