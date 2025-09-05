<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Plugin {

    public function init() : void {
        // i18n
        load_plugin_textdomain( 'affilite', false, dirname( plugin_basename( AFFILITE_FILE ) ) . '/languages' );

        // Rejestry i opcje
        if ( class_exists(__NAMESPACE__ . '\\Roles') )    { (new Roles())->register(); }
        if ( class_exists(__NAMESPACE__ . '\\Settings') ) { (new Settings())->register(); }

        // Panel admina
        if ( is_admin() && class_exists(__NAMESPACE__ . '\\Admin') ) {
            (new Admin())->hooks();
        }

        // Front/shortcode
        if ( class_exists(__NAMESPACE__ . '\\Shortcode') ) { (new Shortcode())->register(); }

        // Tracking/referrals (opcjonalne moduły – uruchamiamy, jeśli są wtyczce)
        if ( class_exists(__NAMESPACE__ . '\\Tracking') )  { (new Tracking())->hooks(); }
        if ( class_exists(__NAMESPACE__ . '\\Referrals') ) { (new Referrals())->hooks(); }

        // NOWE: obsługa wypłat (formularz w portalu)
        if ( class_exists(__NAMESPACE__ . '\\Payouts') )   { (new Payouts())->hooks(); }

        // Assety
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_front_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function enqueue_front_assets() : void {
        if ( is_singular() ) {
            global $post;
            if ( $post && has_shortcode( (string) $post->post_content, 'aff_portal' ) ) {
                wp_enqueue_style(  'affilite-portal', AFFILITE_URL . 'assets/css/portal.css', [], AFFILITE_VERSION );
                wp_enqueue_script( 'affilite-portal', AFFILITE_URL . 'assets/js/portal.js',   [ 'jquery' ], AFFILITE_VERSION, true );
            }
        }
    }

    public function enqueue_admin_assets( $hook ) : void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        // Ładujemy style/JS na naszych stronach + CPT materiałów
        $is_aff_screen =
            $screen
            && (
                $screen->id === 'toplevel_page_affilite'
                || str_starts_with($screen->id, 'affilite_page_')
                || ( isset($screen->post_type) && $screen->post_type === 'aff_material' )
            );

        if ( $is_aff_screen ) {
            wp_enqueue_style(  'affilite-admin', AFFILITE_URL . 'assets/css/admin.css', [], AFFILITE_VERSION );
            wp_enqueue_script( 'affilite-admin', AFFILITE_URL . 'assets/js/admin.js',   [ 'jquery' ], AFFILITE_VERSION, true );
        }
    }
}
