<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Payouts {

    public function hooks() : void {
        add_action('admin_post_aff_request_payout', [ $this, 'handle_request' ]);
    }

    /** Dostępny balans: approved referrals - (pending|processing|paid) payouts */
    public static function get_balance(int $partner_id) : array {
        global $wpdb;
        $t_ref = $wpdb->prefix . 'aff_referrals';
        $t_pay = $wpdb->prefix . 'aff_payouts';

        $approved = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount),0) FROM $t_ref WHERE partner_id=%d AND status='approved'", $partner_id
        ) );

        $reserved = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM $t_pay WHERE partner_id=%d AND status IN ('pending','processing','paid')", $partner_id
        ) );

        $available = max(0, $approved - $reserved);
        return [ 'approved' => $approved, 'reserved' => $reserved, 'available' => $available ];
    }

    /** Obsługa formularza z portalu afilianta */
    public function handle_request() : void {
        if ( ! is_user_logged_in() ) { wp_die('Musisz być zalogowany.'); }
        check_admin_referer('aff_request_payout', '_aff_nonce');

        $user = wp_get_current_user();
        $partner = \AffiLite\Tracking::ensure_partner_for_user( (int)$user->ID );
        if ( ! $partner || $partner->status !== 'approved' ) {
            wp_die('Twoje konto afilianta nie jest aktywne.');
        }

        $opts = get_option(Settings::OPTION_KEY, Settings::defaults());
        $allowed = array_merge(
            [],
            !empty($opts['payout_methods']['paypal']) ? ['paypal'=>true] : [],
            !empty($opts['payout_methods']['bank'])   ? ['bank'=>true]   : [],
            !empty($opts['payout_methods']['crypto']) ? ['crypto'=>true] : []
        );

        $method = isset($_POST['method']) ? sanitize_key($_POST['method']) : '';
        if ( ! isset($allowed[$method]) ) {
            wp_die('Wybrana metoda wypłaty jest niedostępna.');
        }

        // Szczegóły płatności
        $details = [];
        if ( $method === 'paypal' ) {
            $details['email'] = sanitize_email( (string)($_POST['paypal_email'] ?? '') );
            if ( empty($details['email']) ) wp_die('Podaj e-mail PayPal.');
        } elseif ( $method === 'bank' ) {
            $details['name'] = sanitize_text_field( (string)($_POST['bank_name'] ?? '') );
            $details['iban'] = preg_replace('/\s+/', '', strtoupper((string)($_POST['bank_iban'] ?? '')) );
            $details['bic']  = strtoupper( sanitize_text_field( (string)($_POST['bank_bic'] ?? '') ) );
            if ( empty($details['name']) || empty($details['iban']) ) wp_die('Podaj dane do przelewu (nazwa i IBAN).');
        } elseif ( $method === 'crypto' ) {
            $details['network'] = sanitize_text_field( (string)($_POST['crypto_network'] ?? '') );
            $details['address'] = sanitize_text_field( (string)($_POST['crypto_address'] ?? '') );
            if ( empty($details['address']) ) wp_die('Podaj adres portfela krypto.');
        }

        $balance = self::get_balance( (int)$partner->id );
        $min     = max(0, (float)($opts['min_payout'] ?? 0));
        if ( $balance['available'] < $min ) {
            wp_die('Nie osiągnąłeś minimalnego progu wypłaty.');
        }

        // Kwota = cały dostępny balans (MVP); w przyszłości dodamy wybór kwoty.
        $amount = $balance['available'];

        global $wpdb;
        $t_pay = $wpdb->prefix . 'aff_payouts';
        $wpdb->insert($t_pay, [
            'partner_id'   => (int)$partner->id,
            'amount'       => $amount,
            'method'       => $method,
            'details_json' => wp_json_encode($details),
            'status'       => 'pending',
            'created_at'   => current_time('mysql', true),
            'updated_at'   => current_time('mysql', true),
        ], [ '%d','%f','%s','%s','%s','%s','%s' ]);

        // Powrót do portalu z hash do zakładki wypłat
        wp_safe_redirect( home_url( add_query_arg([], '/').'#aff=payouts' ) );
        exit;
    }
}
