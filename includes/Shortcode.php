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

        ob_start();
        ?>
        <div class="aff-portal">
            <ul class="aff-tablist">
                <?php foreach ( $tabs as $key => $label ): ?>
                    <li><a href="#aff-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></a></li>
                <?php endforeach; ?>
            </ul>

            <?php foreach ( $tabs as $key => $label ): ?>
                <section id="aff-<?php echo esc_attr($key); ?>">
                    <h2><?php echo esc_html($label); ?></h2>
                    <p>Wkrótce…</p>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
