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
            'materials' => 'Materiały promocyjne',
            'link'      => 'Generator linku',
            'settings'  => 'Ustawienia',
        ];

        $user = wp_get_current_user();
        $partner = class_exists('\\AffiLite\\Tracking') ? \AffiLite\Tracking::ensure_partner_for_user( (int)$user->ID ) : null;
        $base_link = $partner ? home_url('/ref/' . rawurlencode($partner->code) . '/') : '';

        ob_start(); ?>
        <div class="aff-portal">
            <ul class="aff-tablist">
                <?php foreach ( $tabs as $key => $label ): ?>
                    <li><a href="#aff-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></a></li>
                <?php endforeach; ?>
            </ul>

            <section id="aff-dashboard"><h2>Dashboard</h2><p>Wkrótce…</p></section>
            <section id="aff-orders"><h2>Zamówienia</h2><p>Wkrótce…</p></section>
            <section id="aff-payouts"><h2>Wypłaty</h2><p>Wkrótce…</p></section>
            <section id="aff-materials"><h2>Materiały promocyjne</h2><p>Wkrótce…</p></section>

            <section id="aff-link">
                <h2>Generator linku</h2>
                <?php if ( $partner ): ?>
                    <p><strong>Twój kod:</strong> <code><?php echo esc_html($partner->code); ?></code></p>
                    <p><strong>Link bazowy:</strong> <input type="text" readonly value="<?php echo esc_attr($base_link); ?>" style="width:100%"></p>

                    <form method="get" onsubmit="event.preventDefault();">
                        <label>Podstrona (opcjonalnie):</label>
                        <input type="text" id="aff-to" placeholder="/produkt/nazwa/" style="width:100%;margin-bottom:8px;">
                        <button type="button" onclick="
                            const to = document.getElementById('aff-to').value.trim();
                            let url = '<?php echo esc_js($base_link); ?>';
                            if (to) {
                                if (to.startsWith('http')) {
                                    try { const u = new URL(to); if (u.host === (new URL('<?php echo esc_js(home_url('/')); ?>')).host) { url += '?url=' + encodeURIComponent(to); } else { alert('Dozwolone są tylko adresy z tej samej domeny.'); return; } }
                                    catch(e){ alert('Nieprawidłowy URL.'); return; }
                                } else if (to.startsWith('/')) {
                                    url += '?to=' + encodeURIComponent(to);
                                } else {
                                    url += '?to=' + encodeURIComponent('/' + to);
                                }
                            }
                            const out = document.getElementById('aff-out');
                            out.value = url;
                            out.select();
                        ">Wygeneruj</button>
                    </form>
                    <p><strong>Twój link:</strong> <input type="text" id="aff-out" readonly style="width:100%"></p>
                    <p style="font-size:12px;color:#666">Uwaga: tylko adresy w tej samej domenie są dozwolone. <code>?to=</code> przyjmuje ścieżkę względną, <code>?url=</code> pełny adres do tej samej domeny.</p>
                <?php else: ?>
                    <p>Twoje zgłoszenie do programu jest <em>oczekujące</em> lub konto nie zostało jeszcze utworzone.</p>
                <?php endif; ?>
            </section>

            <section id="aff-settings"><h2>Ustawienia</h2><p>Wkrótce…</p></section>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
