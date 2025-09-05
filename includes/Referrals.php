<?php 
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Integracja z WooCommerce (MVP, lekko i bez CRON):
 * - podczas checkoutu przypinamy partnera do zamówienia (na podstawie cookie / user_meta),
 * - na pozytywnych statusach (processing/completed) tworzymy/aktualizujemy referral jako "approved"
 *   z ewentualnym locked_until w przyszłości,
 * - na negatywnych (cancelled/refunded/failed/trash) oznaczamy referral jako "rejected".
 *
 * Ważne: locked_until NIE jest ponownie wydłużane przy kolejnych przejściach w processing/completed.
 * Ustawiamy je tylko przy pierwszym "approve" (jeżeli było NULL).
 */
class Referrals {

    public function hooks() : void {
        // Podpinamy partnera do zamówienia, gdy powstaje obiekt WC_Order (przed zapisem)
        add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_partner_to_order' ], 10, 2 );

        // Reagujemy na każdą zmianę statusu zamówienia
        add_action( 'woocommerce_order_status_changed', [ $this, 'order_status_changed' ], 10, 4 );
    }

    /**
     * Przypina partnera do zamówienia (meta _aff_code i _aff_partner_id).
     */
    public function attach_partner_to_order( \WC_Order $order, $data ) : void {
        $opts = get_option( Settings::OPTION_KEY, Settings::defaults() );

        // 1) priorytet – cookie z ostatniego kliknięcia
        $code = isset($_COOKIE['aff_code']) ? sanitize_title( wp_unslash($_COOKIE['aff_code']) ) : '';

        // 2) jeśli brak, a zalogowany user i włączone cross_device — weź z user_meta
        if ( $code === '' && is_user_logged_in() && ! empty($opts['cross_device']) ) {
            $stored = get_user_meta( get_current_user_id(), '_aff_last_code', true );
            if ( is_string($stored) && $stored !== '' ) {
                $code = sanitize_title( $stored );
            }
        }

        if ( $code === '' ) {
            return; // brak info o afiliancie – nic nie zapisujemy
        }

        $partner = $this->get_partner_by_code( $code );
        if ( ! $partner ) { return; }
        if ( isset($partner->status) && $partner->status === 'banned' ) { return; }

        // Zakaz „self-purchase”, jeśli wyłączony w ustawieniach
        $order_user_id = (int) $order->get_user_id();
        if ( ! empty($order_user_id)
             && (int)$partner->user_id === $order_user_id
             && empty($opts['allow_self_purchase']) ) {
            return; // nie zapisujemy partnera, bo to własny zakup
        }

        // Zapis meta do zamówienia
        $order->update_meta_data( '_aff_code',        $code );
        $order->update_meta_data( '_aff_partner_id',  (int) $partner->id );
    }

    /**
     * Tworzy/aktualizuje referral przy zmianie statusu zamówienia.
     */
    public function order_status_changed( $order_id, $old_status, $new_status, \WC_Order $order ) : void {
        $partner_id = (int) $order->get_meta( '_aff_partner_id' );
        $code       = (string) $order->get_meta( '_aff_code' );

        if ( $partner_id <= 0 && $code !== '' ) {
            $p = $this->get_partner_by_code( $code );
            if ( $p ) { $partner_id = (int) $p->id; }
        }
        if ( $partner_id <= 0 ) { return; } // zamówienie nie jest przypisane do partnera

        $opts   = get_option( Settings::OPTION_KEY, Settings::defaults() );
        $rate   = max(0, (float) ( $opts['commission_rate'] ?? 0 )); // %
        $lock   = max(0, (int) ( $opts['lock_days'] ?? 0 ));
        $nowUTC = current_time( 'mysql', true ); // UTC

        // Kwota bazowa do prowizji – na MVP używamy całkowitej kwoty zamówienia
        $order_total = (float) $order->get_total();
        $commission  = round( $order_total * ( $rate / 100 ), 2 );

        // Tabela
        global $wpdb;
        $t_refs = $wpdb->prefix . 'aff_referrals';

        // Pozytywne statusy (płatne zamówienie)
        if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {

            // data zakończenia lock – wyliczona teraz
            $locked_until_new = $lock > 0
                ? gmdate( 'Y-m-d H:i:s', time() + $lock * DAY_IN_SECONDS )
                : null;

            // UPSERT po unique(order_id)
            // locked_until ustawiamy tylko, jeśli dotychczas było NULL (nie wydłużamy locka przy ponownym "approve")
            $sql = $wpdb->prepare(
                "INSERT INTO $t_refs
                    (partner_id, order_id, user_id, order_total, commission_amount, status,    locked_until,     created_at, updated_at)
                 VALUES
                    (%d,         %d,       %d,      %f,         %f,                'approved', %s,               %s,         %s)
                 ON DUPLICATE KEY UPDATE
                    partner_id        = VALUES(partner_id),
                    user_id           = VALUES(user_id),
                    order_total       = VALUES(order_total),
                    commission_amount = VALUES(commission_amount),
                    status            = 'approved',
                    locked_until      = IF(locked_until IS NULL, VALUES(locked_until), locked_until),
                    updated_at        = VALUES(updated_at)",
                $partner_id,
                (int) $order_id,
                (int) $order->get_user_id(),
                $order_total,
                $commission,
                $locked_until_new,
                $nowUTC,
                $nowUTC
            );
            $wpdb->query( $sql );

            /**
             * Hook informacyjny — możesz wpiąć powiadomienia/świetliki itd.
             * @param int $order_id
             * @param int $partner_id
             */
            do_action( 'affilite_referral_approved', (int) $order_id, (int) $partner_id );

            return;
        }

        // Negatywne statusy – odrzucamy prowizję
        if ( in_array( $new_status, array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
            $wpdb->update(
                $t_refs,
                array(
                    'status'     => 'rejected',
                    'updated_at' => $nowUTC,
                    'reason'     => 'order_' . $new_status,
                ),
                array( 'order_id' => (int) $order_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            /**
             * Hook informacyjny — jak wyżej.
             * @param int    $order_id
             * @param int    $partner_id
             * @param string $reason
             */
            do_action( 'affilite_referral_rejected', (int) $order_id, (int) $partner_id, 'order_' . $new_status );

            return;
        }
    }

    private function get_partner_by_code( string $code ) {
        global $wpdb;
        $t = $wpdb->prefix . 'aff_partners';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE code = %s LIMIT 1",
            $code
        ) );
    }
}
