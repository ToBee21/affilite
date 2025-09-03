<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Referrals {

    public function hooks() : void {
        // Zmiany statusu zamówienia -> aktualizacja prowizji
        add_action('woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4);
        // Cron: zatwierdzanie po okresie lock
        add_action('affilite_maybe_approve_referral', [ $this, 'cron_maybe_approve' ], 10, 1);
    }

    /** Gdy status zamówienia się zmienia */
    public function on_status_changed( $order_id, $old_status, $new_status, $order ) : void {
        $ref = $this->get_referral_by_order( (int)$order_id );
        if ( ! $ref ) { return; }

        // Odrzucenie gdy zamówienie nieudane/anulowane/zwrot
        if ( in_array( $new_status, [ 'cancelled', 'refunded', 'failed' ], true ) ) {
            $this->reject( (int)$order_id, 'order_'.$new_status );
            return;
        }

        // Zatwierdzanie natychmiast, jeśli brak locka
        $opts = get_option(Settings::OPTION_KEY, Settings::defaults());
        $lock_days = max(0, (int)($opts['lock_days'] ?? 14));
        if ( $lock_days === 0 && in_array( $new_status, [ 'processing', 'completed' ], true ) ) {
            $this->approve( (int)$order_id );
        }
    }

    /** Cron: wywoływany po locku — zatwierdza jeśli zamówienie OK */
    public function cron_maybe_approve( int $order_id ) : void {
        $ref = $this->get_referral_by_order( $order_id );
        if ( ! $ref || $ref->status !== 'pending' ) { return; }

        $order = wc_get_order( $order_id );
        if ( ! $order ) { return; }

        // Jeśli zamówienie nadal OK — zatwierdzamy
        if ( ! in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed' ], true ) ) {
            $now = current_time( 'timestamp', true );
            if ( empty($ref->locked_until) || strtotime( $ref->locked_until ) <= $now ) {
                $this->approve( $order_id );
            }
        } else {
            $this->reject( $order_id, 'order_'.$order->get_status() );
        }
    }

    /* ====================== helpers ====================== */

    private function table() : string {
        global $wpdb;
        return $wpdb->prefix . 'aff_referrals';
    }

    private function get_referral_by_order( int $order_id ) : ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE order_id = %d LIMIT 1", $order_id ) );
        return $row ?: null;
    }

    private function approve( int $order_id ) : void {
        global $wpdb;
        $wpdb->update(
            $this->table(),
            [ 'status' => 'approved', 'updated_at' => current_time('mysql', true), 'reason' => null ],
            [ 'order_id' => $order_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    private function reject( int $order_id, string $reason ) : void {
        global $wpdb;
        $wpdb->update(
            $this->table(),
            [ 'status' => 'rejected', 'updated_at' => current_time('mysql', true), 'reason' => $reason ],
            [ 'order_id' => $order_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }
}
