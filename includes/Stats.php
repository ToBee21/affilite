<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Lekkie agregacje do Dashboardu portalu afilianta.
 * Daty w UTC (spójnie z kolumnami created_at i użyciem current_time('mysql', true)).
 */
class Stats {

    /**
     * Zwraca dane z ostatnich 30 dni (dziś i 29 dni wstecz):
     * - labels: tablica 'Y-m-d' (30 pozycji)
     * - clicks: tablica int (30 pozycji)
     * - conv:   tablica int (30 pozycji, tylko referrals.status='approved')
     * - clicks_sum: int
     * - conv_sum:   int
     */
    public static function last30( int $partner_id ) : array {
        global $wpdb;

        $t_clicks = $wpdb->prefix . 'aff_clicks';
        $t_refs   = $wpdb->prefix . 'aff_referrals';

        $now_ts  = time();
        $from_ts = $now_ts - 29 * DAY_IN_SECONDS;
        $from    = gmdate('Y-m-d 00:00:00', $from_ts);

        // Przygotuj „szkielet” dni
        $labels = [];
        $index  = []; // 'Y-m-d' => i
        for ($i = 0; $i < 30; $i++) {
            $d = gmdate('Y-m-d', $from_ts + $i * DAY_IN_SECONDS);
            $labels[]     = $d;
            $index[$d]    = $i;
        }
        $clicks = array_fill(0, 30, 0);
        $conv   = array_fill(0, 30, 0);

        // Kliknięcia 30d (zgrupowane)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM $t_clicks
             WHERE partner_id = %d
               AND created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            $partner_id, $from
        ) );

        if ( $rows ) {
            foreach ( $rows as $r ) {
                $d = (string) $r->d;
                if ( isset($index[$d]) ) {
                    $clicks[ $index[$d] ] = (int) $r->c;
                }
            }
        }

        // Konwersje 30d (tylko approved)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM $t_refs
             WHERE partner_id = %d
               AND status = 'approved'
               AND created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY d ASC",
            $partner_id, $from
        ) );

        if ( $rows ) {
            foreach ( $rows as $r ) {
                $d = (string) $r->d;
                if ( isset($index[$d]) ) {
                    $conv[ $index[$d] ] = (int) $r->c;
                }
            }
        }

        return [
            'labels'     => $labels,
            'clicks'     => $clicks,
            'conv'       => $conv,
            'clicks_sum' => array_sum($clicks),
            'conv_sum'   => array_sum($conv),
        ];
    }
}
