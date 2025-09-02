<?php
namespace AffiLite;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Admin {
    public function hooks() : void {
        add_action( 'admin_menu', [ $this, 'menu' ] );
    }

    public function menu() : void {
        add_menu_page(
            'AffiLite', 'AffiLite', 'manage_options',
            'affilite', [ $this, 'render_dashboard' ],
            'dashicons-groups', 56
        );
        add_submenu_page( 'affilite', 'Dashboard', 'Dashboard', 'manage_options', 'affilite', [ $this, 'render_dashboard' ] );
    }

    private function card( string $title, string $content ) : void {
        echo '<div class="aff-card"><h2>' . esc_html( $title ) . '</h2><div>' . $content . '</div></div>';
    }

    public function render_dashboard() : void {
        echo '<div class="wrap aff-wrap"><h1>AffiLite — Dashboard</h1>';
        $this->card('Status', '<ul><li>Wersja: ' . esc_html( AFFILITE_VERSION ) . '</li><li>WooCommerce: ' . ( class_exists('WooCommerce') ? 'OK' : 'BRAK' ) . '</li></ul>');
        $this->card('Co dalej?', '<ol>
            <li>Utwórz stronę i dodaj shortcode <code>[aff_portal]</code>.</li>
            <li>Wejdź na stronę jako zalogowany użytkownik.</li>
            <li>W kolejnych krokach dodamy ustawienia, tracking i wypłaty.</li>
        </ol>');
        echo '</div>';
    }
}
