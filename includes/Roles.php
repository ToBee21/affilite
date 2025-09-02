<?php
namespace AffiLite;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Roles {
    public function register() : void {
        add_role( 'affiliate', 'Affiliate', [
            'read' => true,
        ] );
        // Dalsze uprawnienia dodamy, gdy dojdą akcje edycyjne.
    }
}
