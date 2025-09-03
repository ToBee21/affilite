<?php
namespace AffiLite;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Settings {
    public const OPTION_KEY = 'affilite_options';

    public static function defaults() : array {
        return [
            'commission_rate' => 10,
            'lock_days'       => 14,
            'cookie_ttl'      => 30,
            'join_mode'       => 'auto',   // 'auto' | 'manual'
            'attribution'     => 'last',   // 'last' | 'first'
            'cross_device'    => true,
            'allow_self_purchase' => false, // NOWE: blokuj samozakup (domyślnie)
            'payout_methods'  => [ 'paypal' => true, 'bank' => true, 'crypto' => true ],
            'min_payout'      => 100.0,
            'fraud_thresholds'=> [ 'clicks_day' => 500, 'conv_day' => 20, 'commission_day' => 1000.0 ],
        ];
    }

    public function register() : void {
        $opts = get_option(self::OPTION_KEY);
        if (!is_array($opts)) {
            add_option(self::OPTION_KEY, self::defaults());
        } else {
            update_option(self::OPTION_KEY, array_replace_recursive(self::defaults(), $opts));
        }
        register_setting('affilite_settings', self::OPTION_KEY, [ $this, 'sanitize' ]);
    }

    public function sanitize($input) : array {
        $d = self::defaults();
        $o = $d;

        $o['commission_rate'] = max(0, min(100, (int)($input['commission_rate'] ?? $d['commission_rate'])));
        $o['lock_days']       = max(0, (int)($input['lock_days'] ?? $d['lock_days']));
        $o['cookie_ttl']      = max(0, (int)($input['cookie_ttl'] ?? $d['cookie_ttl']));

        $j = $input['join_mode'] ?? $d['join_mode'];
        $o['join_mode'] = in_array($j, ['auto','manual'], true) ? $j : 'auto';

        $a = $input['attribution'] ?? $d['attribution'];
        $o['attribution'] = in_array($a, ['last','first'], true) ? $a : 'last';

        $o['cross_device'] = !empty($input['cross_device']);
        $o['allow_self_purchase'] = !empty($input['allow_self_purchase']);

        $o['payout_methods'] = [
            'paypal' => !empty($input['payout_methods']['paypal']),
            'bank'   => !empty($input['payout_methods']['bank']),
            'crypto' => !empty($input['payout_methods']['crypto']),
        ];

        $o['min_payout'] = max(0, (float)($input['min_payout'] ?? $d['min_payout']));

        $o['fraud_thresholds'] = [
            'clicks_day'     => max(0, (int)($input['fraud_thresholds']['clicks_day'] ?? $d['fraud_thresholds']['clicks_day'])),
            'conv_day'       => max(0, (int)($input['fraud_thresholds']['conv_day'] ?? $d['fraud_thresholds']['conv_day'])),
            'commission_day' => max(0, (float)($input['fraud_thresholds']['commission_day'] ?? $d['fraud_thresholds']['commission_day'])),
        ];

        return $o;
    }

    private function card(string $title, string $html) : void {
        echo '<div class="aff-card"><h2>'.esc_html($title).'</h2><div>'.$html.'</div></div>';
    }

    public function render_settings() : void {
        $o = get_option(self::OPTION_KEY, self::defaults());

        echo '<div class="wrap aff-wrap"><h1>Ustawienia AffiLite</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('affilite_settings');

        echo '<div class="aff-grid">';

        // Ogólne
        ob_start();
        printf('<label>Stawka prowizji (%%): <input name="%1$s[commission_rate]" type="number" min="0" max="100" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$o['commission_rate']);
        printf('<label>Okres blokady (dni): <input name="%1$s[lock_days]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$o['lock_days']);
        printf('<label>Czas życia cookie (dni): <input name="%1$s[cookie_ttl]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$o['cookie_ttl']);
        printf('<label>Model atrybucji: <select name="%1$s[attribution]">
            <option value="last" %2$s>Ostatnie kliknięcie</option>
            <option value="first" %3$s>Pierwsze kliknięcie</option>
        </select></label><br>',
            esc_attr(self::OPTION_KEY),
            selected($o['attribution'],'last',false),
            selected($o['attribution'],'first',false)
        );
        $this->card('Ogólne', ob_get_clean());

        // Program afiliacyjny
        ob_start();
        printf('<label>Tryb akceptacji: <select name="%1$s[join_mode]">
            <option value="auto" %2$s>Automatyczne zatwierdzanie</option>
            <option value="manual" %3$s>Ręczna akceptacja</option>
        </select></label><br>',
            esc_attr(self::OPTION_KEY),
            selected($o['join_mode'],'auto',false),
            selected($o['join_mode'],'manual',false)
        );
        $this->card('Program afiliacyjny', ob_get_clean());

        // Wypłaty
        ob_start();
        printf('<label><input type="checkbox" name="%1$s[payout_methods][paypal]" %2$s> PayPal</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($o['payout_methods']['paypal']),true,false));
        printf('<label><input type="checkbox" name="%1$s[payout_methods][bank]" %2$s> Przelew bankowy</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($o['payout_methods']['bank']),true,false));
        printf('<label><input type="checkbox" name="%1$s[payout_methods][crypto]" %2$s> Krypto</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($o['payout_methods']['crypto']),true,false));
        printf('<label>Minimalny próg wypłaty: <input name="%1$s[min_payout]" type="number" min="0" step="0.01" value="%2$s"></label><br>',
            esc_attr(self::OPTION_KEY), esc_attr($o['min_payout']));
        $this->card('Wypłaty', ob_get_clean());

        // Śledzenie i bezpieczeństwo
        ob_start();
        printf('<label><input type="checkbox" name="%1$s[cross_device]" %2$s> Śledzenie między urządzeniami (dla zalogowanych)</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($o['cross_device']),true,false));
        printf('<label><input type="checkbox" name="%1$s[allow_self_purchase]" %2$s> Zezwól na zakupy przez własny link (NIEZALECANE)</label><br>',
            esc_attr(self::OPTION_KEY), checked(!empty($o['allow_self_purchase']),true,false));
        $this->card('Śledzenie i bezpieczeństwo', ob_get_clean());

        // Progi anty-fraud
        ob_start();
        printf('<label>Kliknięcia/dzień: <input name="%1$s[fraud_thresholds][clicks_day]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$o['fraud_thresholds']['clicks_day']);
        printf('<label>Konwersje/dzień: <input name="%1$s[fraud_thresholds][conv_day]" type="number" min="0" value="%2$d"></label><br>',
            esc_attr(self::OPTION_KEY), (int)$o['fraud_thresholds']['conv_day']);
        printf('<label>Prowizja/dzień: <input name="%1$s[fraud_thresholds][commission_day]" type="number" min="0" step="0.01" value="%2$s"></label><br>',
            esc_attr(self::OPTION_KEY), esc_attr($o['fraud_thresholds']['commission_day']));
        $this->card('Progi anty-fraud', ob_get_clean());

        echo '</div>';
        submit_button();
        echo '</form></div>';
    }
}
