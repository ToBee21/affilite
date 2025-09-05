<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Logika wypłat: obliczanie salda i obsługa wniosku z portalu afilianta.
 *
 * Wymaga tabel z Install.php:
 * - wp_aff_referrals (status, commission_amount, locked_until, partner_id, created_at)
 * - wp_aff_payouts   (partner_id, amount, method, details_json, status)
 */
class Payouts {

    public function hooks() : void {
        // obsługa formularza z portalu: admin-post.php?action=aff_request_payout
        add_action('admin_post_aff_request_payout', [ $this, 'handle_request' ]);
        add_action('admin_post_nopriv_aff_request_payout', [ $this, 'deny' ]);
    }

    public function deny() : void {
        wp_die('Musisz być zalogowany, aby złożyć wniosek o wypłatę.');
    }

    /**
     * Zwraca tablicę:
     * [
     *   'approved'  => suma wszystkich ZATWIERDZONYCH prowizji (niezależnie od lock),
     *   'reserved'  => suma zatwierdzonych, ale jeszcze zablokowanych (locked_until > NOW),
     *   'available' => suma do wypłaty: zatwierdzone i odblokowane minus już złożone/wyplacone wnioski
     * ]
     */
    public static function get_balance( int $partner_id ) : array {
        global $wpdb;
        $t_refs = $wpdb->prefix . 'aff_referrals';
        $t_pay  = $wpdb->prefix . 'aff_payouts';

        // Suma wszystkich zatwierdzonych prowizji (approved)
        $sum_approved = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount),0)
             FROM $t_refs
             WHERE partner_id = %d AND status = 'approved'",
            $partner_id
        ) );

        // Suma ZAREZERWOWANA (jeszcze zablokowane do daty locked_until)
        $sum_reserved = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount),0)
             FROM $t_refs
             WHERE partner_id = %d
               AND status = 'approved'
               AND locked_until IS NOT NULL
               AND locked_until > UTC_TIMESTAMP()",
            $partner_id
        ) );

        // Suma odblokowana (approved i lock minął)
        $sum_unlocked = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount),0)
             FROM $t_refs
             WHERE partner_id = %d
               AND status = 'approved'
               AND (locked_until IS NULL OR locked_until <= UTC_TIMESTAMP())",
            $partner_id
        ) );

        // Już złożone/rozliczane wypłaty (odejmujemy pending/processing/approved/paid)
        $sum_requested = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0)
             FROM $t_pay
             WHERE partner_id = %d AND status IN ('pending','processing','approved','paid')",
            $partner_id
        ) );

        $available = max(0, $sum_unlocked - $sum_requested);

        return [
            'approved'  => round($sum_approved, 2),
            'reserved'  => round($sum_reserved, 2),
            'available' => round($available, 2),
        ];
    }

    /**
     * Obsługa POST z portalu afilianta (z Shortcode.php).
     */
    public function handle_request() : void {
        if ( ! is_user_logged_in() ) {
            wp_die('Brak uprawnień.');
        }
        if ( ! isset($_POST['_aff_nonce']) || ! wp_verify_nonce($_POST['_aff_nonce'], 'aff_request_payout') ) {
            wp_die('Błędny token (nonce). Odśwież stronę i spróbuj ponownie.');
        }

        $user_id = get_current_user_id();
        $partner = $this->get_partner_by_user( $user_id );
        if ( ! $partner ) {
            wp_die('Nie znaleziono konta afilianta.');
        }

        // Konfiguracja
        $opts = get_option(Settings::OPTION_KEY, Settings::defaults());
        $min  = (float) ($opts['min_payout'] ?? 0);
        $allowed = [
            'paypal' => !empty($opts['payout_methods']['paypal']),
            'bank'   => !empty($opts['payout_methods']['bank']),
            'crypto' => !empty($opts['payout_methods']['crypto']),
        ];

        // Metoda + dane
        $method = isset($_POST['method']) ? sanitize_key($_POST['method']) : '';
        if ( ! isset($allowed[$method]) || ! $allowed[$method] ) {
            wp_die('Niedozwolona metoda wypłaty.');
        }

        $details = [];
        switch ($method) {
            case 'paypal':
                $details['paypal_email'] = isset($_POST['paypal_email']) ? sanitize_email($_POST['paypal_email']) : '';
                if ( empty($details['paypal_email']) ) {
                    wp_die('Podaj adres e-mail PayPal.');
                }
                break;
            case 'bank':
                $details['bank_name'] = isset($_POST['bank_name']) ? sanitize_text_field($_POST['bank_name']) : '';
                $details['bank_iban'] = isset($_POST['bank_iban']) ? sanitize_text_field($_POST['bank_iban']) : '';
                $details['bank_bic']  = isset($_POST['bank_bic'])  ? sanitize_text_field($_POST['bank_bic'])  : '';
                if ( empty($details['bank_name']) || empty($details['bank_iban']) ) {
                    wp_die('Podaj nazwę odbiorcy i numer IBAN.');
                }
                break;
            case 'crypto':
                $details['crypto_network'] = isset($_POST['crypto_network']) ? sanitize_text_field($_POST['crypto_network']) : '';
                $details['crypto_address'] = isset($_POST['crypto_address']) ? sanitize_text_field($_POST['crypto_address']) : '';
                if ( empty($details['crypto_address']) ) {
                    wp_die('Podaj adres portfela krypto.');
                }
                break;
        }

        // Wyliczamy saldo
        $bal = self::get_balance( (int)$partner->id );

        if ( $bal['available'] < $min ) {
            wp_die('Nie osiągnięto minimalnego progu wypłaty.');
        }
        if ( $bal['available'] <= 0 ) {
            wp_die('Brak środków do wypłaty.');
        }

        $amount = $bal['available']; // na tym etapie wypłacamy pełne dostępne saldo

        // Zapis wniosku
        global $wpdb;
        $t_pay = $wpdb->prefix . 'aff_payouts';

        $res = $wpdb->insert(
            $t_pay,
            [
                'partner_id'   => (int)$partner->id,
                'amount'       => $amount,
                'method'       => $method,
                'details_json' => wp_json_encode($details),
                'status'       => 'pending',
                'created_at'   => current_time( 'mysql', true ), // UTC
                'updated_at'   => current_time( 'mysql', true ),
            ],
            [ '%d','%f','%s','%s','%s','%s' ]
        );

        if ( false === $res ) {
            wp_die('Nie udało się zapisać wniosku. Spróbuj ponownie.');
        }

        // (opcjonalnie) prosty mail do admina
        if ( ! empty($opts['notify_admin_payout']) ) {
            $admin_email = get_option('admin_email');
            wp_mail(
                $admin_email,
                'AffiLite: nowy wniosek o wypłatę',
                sprintf(
                    "Afiliant ID #%d złożył wniosek o wypłatę %0.2f (%s).",
                    (int)$partner->id,
                    $amount,
                    $method
                )
            );
        }

        // powrót do portalu
        $redirect = wp_get_referer();
        if ( ! $redirect ) { $redirect = home_url('/'); }
        $redirect = add_query_arg( 'aff_msg', 'payout_requested', $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    private function get_partner_by_user( int $user_id ) {
        global $wpdb;
        $t_part = $wpdb->prefix . 'aff_partners';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t_part WHERE user_id = %d LIMIT 1",
            $user_id
        ) );
    }
}
