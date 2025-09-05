<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Balance {

    /** Zwraca tablicę: earned, requested, paid, available (kwoty w float). */
    public static function totals( int $partner_id ) : array {
        global $wpdb;
        $t_refs = $wpdb->prefix . 'aff_referrals';
        $t_pays = $wpdb->prefix . 'aff_payouts';

        $nowUTC = current_time('mysql', true);

        // Zarobione: zatwierdzone i po locku
        $earned = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount),0)
             FROM $t_refs
             WHERE partner_id=%d
               AND status='approved'
               AND (locked_until IS NULL OR locked_until <= %s)",
            $partner_id, $nowUTC
        ) );

        // Już wypłacone
        $paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0)
             FROM $t_pays
             WHERE partner_id=%d AND status='paid'",
            $partner_id
        ) );

        // Zgłoszone (oczekujące / w trakcie)
        $requested = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0)
             FROM $t_pays
             WHERE partner_id=%d AND status IN ('pending','processing')",
            $partner_id
        ) );

        $available = max( $earned - $paid - $requested, 0.0 );

        return [
            'earned'    => $earned,
            'requested' => $requested,
            'paid'      => $paid,
            'available' => $available,
        ];
    }

    public static function money( float $v ) : string {
        return function_exists('wc_price') ? wc_price($v) : number_format_i18n($v, 2);
    }
}
