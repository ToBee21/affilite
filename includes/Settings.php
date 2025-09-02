<?php
namespace AffiLite;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings {
    const OPTION_KEY = 'affilite_options';

    public static function defaults() : array {
        return [
            'commission_rate' => 10,      // %
            'lock_days' => 14,
            'cookie_ttl' => 30,           // days
            'join_mode' => 'configurable', // 'auto' or 'manual' (configurable via admin)
            'attribution' => 'last',      // 'last' or 'first'
            'cross_device' => true,
            'payout_methods' => [ 'paypal' => true, 'bank' => true, 'crypto' => true ],
            'min_payout' => 100,
            'fraud_thresholds' => [ 'clicks_day' => 500, 'conv_day' => 20, 'commission_day' => 1000 ],
        ];
    }

    public function register() : void {
        // Ensure defaults
        $opts = get_option( self::OPTION_KEY );
        if ( ! is_array( $opts ) ) {
            add_option( self::OPTION_KEY, self::defaults() );
        } else {
            // merge to keep future keys
            $merged = array_replace_recursive( self::defaults(), $opts );
            update_option( self::OPTION_KEY, $merged );
        }

        // Admin settings page
        add_action( 'admin_menu', function(){
            add_menu_page(
                'AffiLite', 'AffiLite', 'manage_options', 'affilite', [ $this, 'render_dashboard' ], 'dashicons-groups', 56
            );
            add_submenu_page( 'affilite', 'Dashboard', 'Dashboard', 'manage_options', 'affilite', [ $this, 'render_dashboard' ] );
            add_submenu_page( 'affilite', 'Zamówienia', 'Zamówienia', 'manage_options', 'affilite-orders', [ $this, 'render_orders' ] );
            add_submenu_page( 'affilite', 'Wypłaty', 'Wypłaty', 'manage_options', 'affilite-payouts', [ $this, 'render_payouts' ] );
            add_submenu_page( 'affilite', 'Afilianci', 'Afilianci', 'manage_options', 'affilite-partners', [ $this, 'render_partners' ] );
            add_submenu_page( 'affilite', 'Materiały promocyjne', 'Materiały promocyjne', 'manage_options', 'affilite-materials', [ $this, 'render_materials' ] );
            add_submenu_page( 'affilite', 'Ustawienia', 'Ustawienia', 'manage_options', 'affilite-settings', [ $this, 'render_settings' ] );
        } );

        register_setting( 'affilite_settings', self::OPTION_KEY, [ $this, 'sanitize' ] );
    }

    public function sanitize( $input ) : array {
        $d = self::defaults();
        $out = $d;
        $out['commission_rate'] = max(0, min(100, (int)($input['commission_rate'] ?? $d['commission_rate'])));
        $out['lock_days'] = max(0, (int)($input['lock_days'] ?? $d['lock_days']));
        $out['cookie_ttl'] = max(0, (int)($input['cookie_ttl'] ?? $d['cookie_ttl']));
        $join = $input['join_mode'] ?? 'auto';
        $out['join_mode'] = in_array($join, ['auto','manual'], true) ? $join : 'auto';
        $attr = $input['attribution'] ?? 'last';
        $out['attribution'] = in_array($attr, ['last','first'], true) ? $attr : 'last';
        $out['cross_device'] = !empty($input['cross_device']);
        $out['payout_methods'] = [
            'paypal' => !empty($input['payout_methods']['paypal']),
            'bank' => !empty($input['payout_methods']['bank']),
            'crypto' => !empty($input['payout_methods']['crypto']),
        ];
        $out['min_payout'] = max(0, (float)($input['min_payout'] ?? $d['min_payout']));
        $out['fraud_thresholds'] = [
            'clicks_day' => max(0, (int)($input['fraud_thresholds']['clicks_day'] ?? $d['fraud_thresholds']['clicks_day'])),
            'conv_day' => max(0, (int)($input['fraud_thresholds']['conv_day'] ?? $d['fraud_thresholds']['conv_day'])),
            'commission_day' => max(0, (float)($input['fraud_thresholds']['commission_day'] ?? $d['fraud_thresholds']['commission_day'])),
        ];
        return $out;
    }

    private function card($title, $content){
        echo '<div class="aff-card"><h2>' . esc_html($title) . '</h2><div>' . $content . '</div></div>';
    }

    public function render_dashboard() : void {
        echo '<div class="wrap aff-wrap"><h1>AffiLite — Dashboard</h1>';
        $this->card('Szybkie KPI', '<ul><li>Łączna prowizja: —</li><li>Łączna liczba konwersji: —</li><li>Liczba afiliantów: —</li><li>CR: —</li><li>Łączna liczba wizyt: —</li><li>Dzisiejsza prowizja: —</li><li>Dzisiejsze konwersje: —</li><li>Oczekujące wypłaty: —</li></ul>');
        $this->card('Ranking afiliantów', '<p>Wkrótce…</p>');
        echo '</div>';
    }
    public function render_orders() : void { echo '<div class="wrap aff-wrap"><h1>Zamówienia afiliacyjne</h1><p>Wkrótce…</p></div>'; }
    public function render_payouts() : void { echo '<div class="wrap aff-wrap"><h1>Wypłaty</h1><p>Wkrótce…</p></div>'; }
    public function render_partners() : void { echo '<div class="wrap aff-wrap"><h1>Afilianci</h1><p>Wkrótce…</p></div>'; }
    public function render_materials() : void { echo '<div class="wrap aff-wrap"><h1>Materiały promocyjne</h1><p>Wkrótce… (na MVP użyjemy Gutenberga)</p></div>'; }

    public function render_settings() : void {
        $opts = get_option( self::OPTION_KEY, self::defaults() );
        echo '<div class="wrap aff-wrap"><h1>Ustawienia AffiLite</h1><form method="post" action="options.php">';
        settings_fields( 'affilite_settings' );
        echo '<div class="aff-grid">';

        // Ogólne
        echo '<div class="aff-card"><h2>Ogólne</h2>';
        printf('<label>Stawka prowizji (%%): <input name="%1$s[commission_rate]" type="number" min="0" max="100" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$opts['commission_rate']);
        printf('<label>Okres blokady (dni): <input name="%1$s[lock_days]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$opts['lock_days']);
        printf('<label>Czas życia cookie (dni): <input name="%1$s[cookie_ttl]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$opts['cookie_ttl']);
        printf('<label>Model atrybucji: <select name="%1$s[attribution]">
            <option value="last" %2$s>Ostatnie kliknięcie</option>
            <option value="first" %3$s>Pierwsze kliknięcie</option>
        </select></label><br>',
            esc_attr(self::OPTION_KEY),
            selected($opts['attribution'], 'last', false),
            selected($opts['attribution'], 'first', false)
        );
        echo '</div>';

        // Dołączanie
        echo '<div class="aff-card"><h2>Program afiliacyjny</h2>';
        printf('<label>Tryb akceptacji: <select name="%1$s[join_mode]">
            <option value="auto" %2$s>Automatyczne zatwierdzanie</option>
            <option value="manual" %3$s>Ręczna akceptacja</option>
        </select></label><br>',
            esc_attr(self::OPTION_KEY),
            selected($opts['join_mode'], 'auto', false),
            selected($opts['join_mode'], 'manual', false)
        );
        echo '</div>';

        // Wypłaty
        echo '<div class="aff-card"><h2>Wypłaty</h2>';
        printf('<label><input type="checkbox" name="%1$s[payout_methods][paypal]" %2$s> PayPal</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($opts['payout_methods']['paypal']), true, false));
        printf('<label><input type="checkbox" name="%1$s[payout_methods][bank]" %2$s> Przelew bankowy</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($opts['payout_methods']['bank']), true, false));
        printf('<label><input type="checkbox" name="%1$s[payout_methods][crypto]" %2$s> Krypto</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($opts['payout_methods']['crypto']), true, false));
        printf('<label>Minimalny próg wypłaty: <input name="%1$s[min_payout]" type="number" min="0" step="0.01" value="%2$s"></label><br>',
            esc_attr(self::OPTION_KEY), esc_attr($opts['min_payout']));
        echo '</div>';

        // Śledzenie i bezpieczeństwo
        echo '<div class="aff-card"><h2>Śledzenie i bezpieczeństwo</h2>';
        printf('<label><input type="checkbox" name="%1$s[cross_device]" %2$s> Śledzenie między urządzeniami (dla zalogowanych)</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($opts['cross_device']), true, false));
        echo '<p>Fingerprint/IP będą haszowane w pełnej wersji. Na razie cookie + last-click.</p>';
        echo '</div>';

        // Progi anty-fraud
        echo '<div class="aff-card"><h2>Progi anty-fraud</h2>';
        printf('<label>Kliknięcia/dzień: <input name="%1$s[fraud_thresholds][clicks_day]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$opts['fraud_thresholds']['clicks_day']);
        printf('<label>Konwersje/dzień: <input name="%1$s[fraud_thresholds][conv_day]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$opts['fraud_thresholds']['conv_day']);
        printf('<label>Prowizja/dzień: <input name="%1$s[fraud_thresholds][commission_day]" type="number" min="0" step="0.01" value="%2$s"></label><br>',
            esc_attr(self::OPTION_KEY), esc_attr($opts['fraud_thresholds']['commission_day']));
        echo '</div>';

        echo '</div>'; // grid
        submit_button();
        echo '</form></div>';
    }
}
