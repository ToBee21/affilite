<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Admin {

    public function hooks() : void {
        add_action('admin_menu', [ $this, 'menu' ]);
    }

    public function menu() : void {
        // Główne menu AffiLite
        add_menu_page(
            'AffiLite',
            'AffiLite',
            'manage_options',
            'affilite',
            [ $this, 'render_dashboard' ],
            'dashicons-networking',
            56
        );

        // Dashboard (alias, żeby pierwszy submenu nie znikał)
        add_submenu_page(
            'affilite',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'affilite',
            [ $this, 'render_dashboard' ]
        );

        // Ustawienia (-> admin.php?page=affilite-settings)
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
        echo '<div class="wrap"><h1>AffiLite — Dashboard</h1><p>Wkrótce…</p></div>';
    }

    // Mostek do widoku ustawień
    public function render_settings_bridge() : void {
        (new Settings())->render_settings();
    }
}
