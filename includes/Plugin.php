<?php
namespace AffiLite;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Plugin {
    public function init() : void {
        load_plugin_textdomain( 'affilite', false, dirname( plugin_basename( AFFILITE_FILE ) ) . '/languages' );

        (new Roles())->register();
        (new Settings())->register();

        if ( is_admin() ) {
            (new Admin())->hooks();
        }

        (new Shortcode())->register();
        (new Tracking())->hooks();
        (new Referrals())->hooks(); // <-- NOWE

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function enqueue_front_assets() : void {
        if ( is_singular() ) {
            global $post;
            if ( $post && has_shortcode( (string)$post->post_content, 'aff_portal' ) ) {
                wp_enqueue_style( 'affilite-portal', AFFILITE_URL . 'assets/css/portal.css', [], AFFILITE_VERSION );
                wp_enqueue_script( 'affilite-portal', AFFILITE_URL . 'assets/js/portal.js', [ 'jquery' ], AFFILITE_VERSION, true );
            }
        }
    }

    public function enqueue_admin_assets( $hook ) : void {
        if ( strpos( (string)$hook, 'affilite' ) !== false ) {
            wp_enqueue_style( 'affilite-admin', AFFILITE_URL . 'assets/css/admin.css', [], AFFILITE_VERSION );
            wp_enqueue_script( 'affilite-admin', AFFILITE_URL . 'assets/js/admin.js', [ 'jquery' ], AFFILITE_VERSION, true );
        }
    }
}
