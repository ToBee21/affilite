<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class PortalOrders {

    public function render( int $partner_id ) : void {
        if ( $partner_id <= 0 ) { echo '<p>Brak danych.</p>'; return; }

        global $wpdb;
        $t = $wpdb->prefix . 'aff_referrals';

        // Paginacja (prosto, 15 wierszy)
        $per_page = 15;
        $paged  = max(1, (int)($_GET['affp'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        $total_items = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE partner_id=%d", $partner_id) );
        $total_pages = max(1, (int)ceil($total_items / $per_page));

        $rows = $wpdb->get_results( $wpdb->prepare("
            SELECT id, order_id, order_total, commission_amount, status, locked_until, reason, created_at
            FROM $t
            WHERE partner_id=%d
            ORDER BY id DESC
            LIMIT %d OFFSET %d
        ", $partner_id, $per_page, $offset ) );

        echo '<div class="aff-orders">';

        // Legenda statusów
        echo '<div class="aff-card" style="padding:12px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;margin-bottom:12px">';
        echo '<strong>Legenda statusów:</strong><br>';
        echo '<ul style="margin:.5em 0 0 1.2em;list-style:disc">';
        echo '<li><strong>W trakcie akceptacji</strong> — czekamy na upłynięcie okresu (np. 14 dni), żeby wykluczyć zwroty.</li>';
        echo '<li><strong>Zaakceptowano</strong> — prowizja doliczona do salda.</li>';
        echo '<li><strong>Odrzucono</strong> — np. zwrot/anulowanie lub samozakup.</li>';
        echo '</ul>';
        echo '</div>';

        if ( ! $rows ) {
            echo '<p>Brak zamówień z Twojego linku.</p></div>';
            return;
        }

        echo '<table class="aff-table" style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="text-align:left;border-bottom:1px solid #e5e5e5">';
        echo '<th style="padding:8px">#</th>';
        echo '<th style="padding:8px">Zamówienie</th>';
        echo '<th style="padding:8px">Kwota zamówienia</th>';
        echo '<th style="padding:8px">Prowizja</th>';
        echo '<th style="padding:8px">Status</th>';
        echo '<th style="padding:8px">Data</th>';
        echo '</tr></thead><tbody>';

        $now = gmdate('Y-m-d H:i:s');

        foreach ( $rows as $r ) {
            $display_status = $this->display_status($r->status, $r->locked_until, $now);
            $order_link = admin_url('post.php?post='.(int)$r->order_id.'&action=edit');
            echo '<tr style="border-bottom:1px solid #f0f0f0">';
            printf('<td style="padding:8px">%d</td>', (int)$r->id);
            printf('<td style="padding:8px">#%d</td>', (int)$r->order_id);
            printf('<td style="padding:8px">%s</td>', $this->money((float)$r->order_total));
            printf('<td style="padding:8px">%s</td>', $this->money((float)$r->commission_amount));
            printf('<td style="padding:8px">%s</td>', esc_html($display_status));
            printf('<td style="padding:8px">%s</td>', esc_html(get_date_from_gmt($r->created_at, 'Y-m-d H:i')));
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Paginacja (prosta)
        if ( $total_pages > 1 ) {
            $base = remove_query_arg('affp');
            echo '<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">';
            for ($p=1; $p <= $total_pages; $p++) {
                $url = esc_url( add_query_arg('affp', $p, $base) );
                $is = $p === $paged;
                echo '<a class="button'.($is?' button-primary':'').'" href="'.$url.'">'.$p.'</a>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    private function display_status( string $status, ?string $locked_until, string $nowUTC ) : string {
        if ( $status === 'rejected' ) return 'Odrzucono';
        if ( $status === 'approved' ) {
            if ( $locked_until && $locked_until > $nowUTC ) return 'W trakcie akceptacji';
            return 'Zaakceptowano';
        }
        // pending
        return 'W trakcie akceptacji';
    }

    private function money( $v ) : string {
        return function_exists('wc_price') ? wc_price( (float)$v ) : number_format_i18n( (float)$v, 2 );
    }
}
