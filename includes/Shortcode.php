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

        $tabs = [
            'dashboard' => 'Dashboard',
            'orders'    => 'Zamówienia',
            'payouts'   => 'Wypłaty',
            'materials' => 'Materiały promocyjne', // <-- ta zakładka
            'link'      => 'Generator linku',
            'settings'  => 'Ustawienia',
        ];

        $user = wp_get_current_user();
        $partner = class_exists('\\AffiLite\\Tracking')
            ? \AffiLite\Tracking::ensure_partner_for_user( (int) $user->ID )
            : null;

        $base_link = $partner ? home_url('/ref/' . rawurlencode($partner->code) . '/') : '';

        // Dane do wypłat
        $opts = get_option(Settings::OPTION_KEY, Settings::defaults());
        $allowed_methods = [
            'paypal' => !empty($opts['payout_methods']['paypal']),
            'bank'   => !empty($opts['payout_methods']['bank']),
            'crypto' => !empty($opts['payout_methods']['crypto']),
        ];
        $min_payout = (float)($opts['min_payout'] ?? 0);
        $balance = $partner ? \AffiLite\Payouts::get_balance( (int)$partner->id ) : ['approved'=>0,'reserved'=>0,'available'=>0];

        ob_start(); ?>
        <div class="aff-portal" data-aff-portal>
            <nav class="aff-tabs" role="tablist" aria-label="Panel afilianta">
                <?php foreach ( $tabs as $key => $label ): ?>
                    <button type="button"
                            class="aff-tablink"
                            role="tab"
                            data-tab-link="<?php echo esc_attr($key); ?>"
                            aria-controls="panel-<?php echo esc_attr($key); ?>">
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <!-- DASHBOARD -->
            <section id="panel-dashboard" class="aff-tabpanel" role="tabpanel" data-tab-panel="dashboard" aria-labelledby="tab-dashboard">
                <h2>Dashboard</h2>
                <p>Wkrótce…</p>
            </section>

           <!-- ZAMÓWIENIA -->
<section id="panel-orders" class="aff-tabpanel" role="tabpanel" data-tab-panel="orders" aria-labelledby="tab-orders" hidden>
    <h2>Zamówienia</h2>
    <?php
    if ( $partner ) {
        (new \AffiLite\PortalOrders())->render( (int)$partner->id );
    } else {
        echo '<p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>';
    }
    ?>
</section>


            <!-- WYPŁATY -->
            <section id="panel-payouts" class="aff-tabpanel" role="tabpanel" data-tab-panel="payouts" aria-labelledby="tab-payouts" hidden>
                <h2>Wypłaty</h2>
                <?php if ( $partner ): ?>
                    <div style="display:flex;gap:12px;margin-bottom:12px;">
                        <div class="aff-card"><h3>Saldo zatwierdzone</h3><strong><?php echo wc_price($balance['approved']); ?></strong></div>
                        <div class="aff-card"><h3>Zarezerwowane</h3><strong><?php echo wc_price($balance['reserved']); ?></strong></div>
                        <div class="aff-card"><h3>Dostępne</h3><strong><?php echo wc_price($balance['available']); ?></strong></div>
                        <div class="aff-card"><h3>Próg wypłaty</h3><strong><?php echo wc_price($min_payout); ?></strong></div>
                    </div>

                    <?php if ( $balance['available'] >= $min_payout ): ?>
                        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="aff-payout-form">
                            <?php wp_nonce_field('aff_request_payout', '_aff_nonce'); ?>
                            <input type="hidden" name="action" value="aff_request_payout">

                            <label>Metoda wypłaty:</label><br>
                            <select name="method" required>
                                <?php if ($allowed_methods['paypal']) : ?><option value="paypal">PayPal</option><?php endif; ?>
                                <?php if ($allowed_methods['bank'])   : ?><option value="bank">Przelew bankowy</option><?php endif; ?>
                                <?php if ($allowed_methods['crypto']) : ?><option value="crypto">Krypto</option><?php endif; ?>
                            </select>

                            <div class="aff-paypal" data-paypal style="margin-top:10px;display:none;">
                                <label>Email PayPal:</label>
                                <input type="email" name="paypal_email" placeholder="name@example.com">
                            </div>

                            <div class="aff-bank" data-bank style="margin-top:10px;display:none;">
                                <label>Nazwa odbiorcy:</label>
                                <input type="text" name="bank_name" placeholder="Imię i nazwisko / Firma">
                                <label>IBAN:</label>
                                <input type="text" name="bank_iban" placeholder="PL...">
                                <label>BIC/SWIFT (opcjonalnie):</label>
                                <input type="text" name="bank_bic" placeholder="XXXXXX">
                            </div>

                            <div class="aff-crypto" data-crypto style="margin-top:10px;display:none;">
                                <label>Sieć:</label>
                                <input type="text" name="crypto_network" placeholder="np. TRC20, ERC20">
                                <label>Adres portfela:</label>
                                <input type="text" name="crypto_address" placeholder="0x... / T...">
                            </div>

                            <p style="margin-top:12px;">
                                <button type="submit" class="button button-primary">Złóż wniosek o wypłatę</button>
                            </p>
                        </form>
                        <script>
                        (function(){
                          var root = document.currentScript.closest('.aff-portal');
                          if(!root) root = document.querySelector('[data-aff-portal]');
                          var sel = root.querySelector('select[name="method"]');
                          function update() {
                            var v = sel.value;
                            ['paypal','bank','crypto'].forEach(function(n){
                              var el = root.querySelector('[data-'+n+']');
                              if (el) el.style.display = (v===n) ? 'block' : 'none';
                            });
                          }
                          sel && (sel.addEventListener('change', update), update());
                        })();
                        </script>
                    <?php else: ?>
                        <p>Nie osiągnąłeś jeszcze minimalnego progu wypłaty.</p>
                    <?php endif; ?>

                <?php else: ?>
                    <p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>
                <?php endif; ?>
            </section>

            <!-- MATERIAŁY PROMOCYJNE -->
            <section id="panel-materials" class="aff-tabpanel" role="tabpanel" data-tab-panel="materials" aria-labelledby="tab-materials" hidden>
                <h2>Materiały promocyjne</h2>
                <?php
                if ( class_exists('\\AffiLite\\Materials') ) {
                    (new \AffiLite\Materials())->render_for_affiliate();
                } else {
                    echo '<p>Brak modułu materiałów.</p>';
                }
                ?>
            </section>

            <!-- GENERATOR LINKU -->
            <section id="panel-link" class="aff-tabpanel" role="tabpanel" data-tab-panel="link" aria-labelledby="tab-link" hidden>
                <h2>Generator linku</h2>
                <?php if ( $partner ): ?>
                    <p><strong>Twój kod:</strong> <code><?php echo esc_html($partner->code); ?></code></p>
                    <p><strong>Link bazowy:</strong> <input type="text" readonly value="<?php echo esc_attr($base_link); ?>" style="width:100%"></p>

                    <form method="get" onsubmit="event.preventDefault();" class="aff-link-form">
                        <label>Podstrona (opcjonalnie):</label>
                        <input type="text" id="aff-to" placeholder="/produkt/nazwa/" style="width:100%;margin-bottom:8px;">
                        <button type="button" class="aff-generate">Wygeneruj</button>
                    </form>

                    <p><strong>Twój link:</strong> <input type="text" id="aff-out" readonly style="width:100%"></p>
                    <p class="aff-hint">Uwaga: tylko adresy w tej samej domenie są dozwolone. <code>?to=</code> ścieżka względna, <code>?url=</code> pełny adres do tej samej domeny.</p>
                <?php else: ?>
                    <p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>
                <?php endif; ?>
            </section>

            <!-- USTAWIENIA -->
            <section id="panel-settings" class="aff-tabpanel" role="tabpanel" data-tab-panel="settings" aria-labelledby="tab-settings" hidden>
                <h2>Ustawienia</h2>
                <p>Wkrótce…</p>
            </section>
        </div>
        <?php
        $config = [
            'home'      => home_url('/'),
            'base_link' => $base_link,
        ];
        printf('<script type="application/json" id="aff-portal-config">%s</script>', wp_json_encode( $config ));
        return (string) ob_get_clean();
    }
}
