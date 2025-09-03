<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Admin {

    public function hooks() : void {
        add_action('admin_menu', [ $this, 'menu' ]);

        if ( is_admin() ) {
            (new AdminOrders())->hooks();
            (new AdminPayouts())->hooks(); // już było
            (new AdminPartners())->hooks(); // <-- NOWE (panel Afilianci)
        }
    }

    public function menu() : void {
        add_menu_page(
            'AffiLite',
            'AffiLite',
            'manage_options',
            'affilite',
            [ $this, 'render_dashboard' ],
            'dashicons-networking',
            56
        );

        add_submenu_page(
            'affilite',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'affilite',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'affilite',
            'Zamówienia',
            'Zamówienia',
            'manage_options',
            'affilite-orders',
            [ $this, 'render_orders_bridge' ]
        );

        add_submenu_page(
            'affilite',
            'Wypłaty',
            'Wypłaty',
            'manage_options',
            'affilite-payouts',
            [ $this, 'render_payouts_bridge' ]
        );

        // <-- NOWE: Afilianci
        add_submenu_page(
            'affilite',
            'Afilianci',
            'Afilianci',
            'manage_options',
            'affilite-partners',
            [ $this, 'render_partners_bridge' ]
        );

        add_submenu_page(
            'affilite',
            'Ustawienia',
            'Ustawienia',
            'manage_options',
            'affilite-settings',
            [ $this, 'render_settings_bridge' ]
        );
    }

    public function render_dashboard() : void {
    (new Dashboard())->render();
}


    public function render_settings_bridge() : void {
        (new Settings())->render_settings();
    }

    public function render_orders_bridge() : void {
        (new AdminOrders())->render();
    }

    public function render_payouts_bridge() : void {
        (new AdminPayouts())->render();
    }

    // <-- NOWE: mostek do widoku „Afilianci”
    public function render_partners_bridge() : void {
        (new AdminPartners())->render();
    }
}
