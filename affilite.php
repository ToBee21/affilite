<?php
/**
 * Plugin Name: AffiLite — Lightweight Affiliate for WooCommerce
 * Description: Lekka, nowoczesna wtyczka afiliacyjna dla WooCommerce. Shortcode [aff_portal] renderuje panel afilianta.
 * Version: 0.1.0
 * Author: You
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Text Domain: affilite
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AFFILITE_VERSION', '0.1.0' );
define( 'AFFILITE_FILE', __FILE__ );
define( 'AFFILITE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AFFILITE_URL', plugin_dir_url( __FILE__ ) );

// Autoloader klas z przestrzeni nazw AffiLite\
spl_autoload_register( function( $class ) {
    if ( str_starts_with( $class, 'AffiLite\\' ) ) {
        $path = AFFILITE_PATH . 'includes/' . str_replace( ['AffiLite\\', '\\'], ['', '/'], $class ) . '.php';
        if ( file_exists( $path ) ) { require_once $path; }
    }
} );

// Boot
add_action( 'plugins_loaded', function () {
    // Pokazujemy ostrzeżenie, ale nie blokujemy działania — część panelu działa nawet bez Woo.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function(){
            echo '<div class="notice notice-warning"><p><strong>AffiLite</strong>: wykryto brak WooCommerce. Niektóre funkcje będą nieaktywne.</p></div>';
        } );
    }
    (new AffiLite\Plugin())->init();
} );
