<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class AdminOrders {

    public function hooks() : void {
        add_action('admin_post_aff_orders_update', [ $this, 'handle_update' ]);
        add_action('admin_post_aff_orders_export', [ $this, 'handle_export' ]);
    }

    public function render() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }

        global $wpdb;
        $t_refs = $wpdb->prefix . 'aff_referrals';
        $t_part = $wpdb->prefix . 'aff_partners';

        $per_page = 20;
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $search = isset($_GET['s']) ? trim((string)wp_unslash($_GET['s'])) : '';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        $where = 'WHERE 1=1';
        $params = [];

        if ( in_array($status, ['pending','approved','rejected'], true) ) {
            $where .= " AND r.status = %s";
            $params[] = $status;
        }

        if ( $search !== '' ) {
            $where .= " AND ( CAST(r.order_id AS CHAR) LIKE %s OR u.user_email LIKE %s OR pr.code LIKE %s )";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
        }

        // Sumy (boxy)
        $sql_sum = "
            SELECT
              COUNT(*) AS total_rows,
              COALESCE(SUM(r.order_total),0) AS sum_orders,
              COALESCE(SUM(r.commission_amount),0) AS sum_commission,
              SUM(CASE WHEN r.status='pending' THEN 1 ELSE 0 END) AS cnt_pending
            FROM $t_refs r
            JOIN $t_part pr ON pr.id = r.partner_id
            JOIN {$wpdb->users} u ON u.ID = pr.user_id
            $where
        ";
        $totals = $wpdb->get_row( $this->maybe_prepare($sql_sum, $params) );
        if ( ! $totals ) {
            $totals = (object)[ 'total_rows'=>0, 'sum_orders'=>0.0, 'sum_commission'=>0.0, 'cnt_pending'=>0 ];
        }

        // Paginacja
        $sql_count = "
            SELECT COUNT(*)
            FROM $t_refs r
            JOIN $t_part pr ON pr.id = r.partner_id
            JOIN {$wpdb->users} u ON u.ID = pr.user_id
            $where
        ";
        $total_items = (int)$wpdb->get_var( $this->maybe_prepare($sql_count, $params) );
        $total_pages = max(1, (int)ceil($total_items / $per_page));

        // Lista
        $sql_list = "
            SELECT r.*, pr.code, u.display_name, u.user_email, u.ID AS wp_user_id
            FROM $t_refs r
            JOIN $t_part pr ON pr.id = r.partner_id
            JOIN {$wpdb->users} u ON u.ID = pr.user_id
            $where
            ORDER BY r.id DESC
            LIMIT %d OFFSET %d
        ";
        $rows = $wpdb->get_results( $this->maybe_prepare($sql_list, array_merge($params, [ $per_page, $offset ])) );

        $notice = '';
        if ( isset($_GET['msg']) ) {
            $msg = sanitize_key($_GET['msg']);
            if ($msg === 'updated') $notice = '<div class="notice notice-success"><p>Zaktualizowano.</p></div>';
            if ($msg === 'error')   $notice = '<div class="notice notice-error"><p>Operacja nie powiodła się.</p></div>';
        }

        $base_url = admin_url('admin.php?page=affilite-orders');

        echo '<div class="wrap"><h1>AffiLite — Zamówienia (prowizje)</h1>';
        echo $notice;

        echo '<div class="aff-cards" style="display:flex;gap:12px;margin:12px 0 18px 0;flex-wrap:wrap">';
        $this->card('Łączna liczba zamówień', number_format_i18n((int)$totals->total_rows));
        $this->card('Łączna wartość zamówień', $this->money((float)$totals->sum_orders));
        $this->card('Łączna prowizja', $this->money((float)$totals->sum_commission));
        $this->card('Oczekujące (liczba)', number_format_i18n((int)$totals->cnt_pending));
        echo '</div>';

        // Filtry + eksport
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap">';
        echo '<input type="hidden" name="page" value="affilite-orders">';
        echo '<select name="status">';
        echo '<option value="">— wszystkie statusy —</option>';
        foreach (['pending'=>'W trakcie akceptacji','approved'=>'Zaakceptowano','rejected'=>'Odrzucono'] as $k=>$lab) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status,$k,false), esc_html($lab));
        }
        echo '</select> ';
        printf('<input type="search" name="s" value="%s" placeholder="Szukaj: #order / email / kod" style="min-width:260px;"> ', esc_attr($search));
        echo '<button class="button">Filtruj</button> ';
        if ($status || $search) echo '<a class="button" href="'.esc_url($base_url).'">Wyczyść</a>';

        // Eksport CSV z bieżącymi filtrami
        $export_url = add_query_arg(array_merge($_GET, ['action'=>'aff_orders_export']), admin_url('admin-post.php'));
        $export_url = wp_nonce_url($export_url, 'aff_orders_export', '_aff_nonce');
        echo ' <a class="button" href="'.esc_url($export_url).'">Eksport CSV</a>';

        echo '</form>';

        // Lista + bulk
        echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
        wp_nonce_field('aff_orders_update', '_aff_nonce');
        echo '<input type="hidden" name="action" value="aff_orders_update">';

        echo '<div class="aff-bulk" style="margin:8px 0;">';
        echo '<button class="button button-primary" name="do" value="approve">Zatwierdź</button> ';
        echo '<button class="button" name="do" value="reject">Odrzuć</button>';
        echo '</div>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:28px"><input type="checkbox" onclick="jQuery(\'.afford\').prop(\'checked\',this.checked)"></th>';
        echo '<th>#ID</th><th>Zamówienie</th><th>Afiliant</th><th>Kod</th><th>Kwota zam.</th><th>Prowizja</th><th>Status</th><th>Locked do</th><th>Data</th>';
        echo '</tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="10">Brak wyników.</td></tr>';
        } else {
            foreach ( $rows as $r ) {
                $order_link  = esc_url( admin_url('post.php?post='.(int)$r->order_id.'&action=edit') );
                $user_link   = esc_url( admin_url('user-edit.php?user_id='.(int)$r->wp_user_id ) );
                echo '<tr>';
                printf('<td><input class="afford" type="checkbox" name="ids[]" value="%d"></td>', (int)$r->id);
                printf('<td>%d</td>', (int)$r->id);
                printf('<td><a href="%s">#%d</a></td>', $order_link, (int)$r->order_id);
                printf('<td><a href="%s">%s</a><br><small>%s</small></td>', $user_link, esc_html($r->display_name), esc_html($r->user_email));
                printf('<td><code>%s</code></td>', esc_html($r->code));
                printf('<td>%s</td>', $this->money((float)$r->order_total));
                printf('<td>%s</td>', $this->money((float)$r->commission_amount));
                printf('<td>%s</td>', esc_html($r->status));
                printf('<td>%s</td>', $r->locked_until ? esc_html( get_date_from_gmt($r->locked_until, 'Y-m-d') ) : '—');
                printf('<td>%s</td>', esc_html( get_date_from_gmt($r->created_at, 'Y-m-d H:i') ));
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links( [
                'base'      => add_query_arg( ['paged'=>'%#%'], $base_url ),
                'format'    => '',
                'prev_text' => '«',
                'next_text' => '»',
                'total'     => $total_pages,
                'current'   => $paged,
            ] );
            echo '</div></div>';
        }

        echo '<div class="aff-bulk" style="margin:8px 0;">';
        echo '<button class="button button-primary" name="do" value="approve">Zatwierdź</button> ';
        echo '<button class="button" name="do" value="reject">Odrzuć</button>';
        echo '</div>';

        echo '</form></div>';
    }

    private function maybe_prepare(string $sql, array $params) : string {
        global $wpdb;
        return empty($params) ? $sql : $wpdb->prepare($sql, ...$params);
    }

    private function money( $v ) : string {
        return function_exists('wc_price') ? wc_price( (float)$v ) : number_format_i18n( (float)$v, 2 );
    }

    /** NOWE: helper do boxów KPI */
    private function card( string $title, string $value ) : void {
        echo '<div class="aff-card" style="min-width:200px;padding:12px 14px;border:1px solid rgba(0,0,0,.08);border-radius:10px;background:#fff">';
        printf('<div style="font-size:12px;color:#667">%s</div>', esc_html($title));
        printf('<div style="font-size:18px;font-weight:600;margin-top:4px">%s</div>', wp_kses_post($value));
        echo '</div>';
    }

    public function handle_update() : void {
        if ( ! current_user_can('manage_options') ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-orders')) ); exit; }
        check_admin_referer('aff_orders_update', '_aff_nonce');

        $do  = isset($_POST['do']) ? sanitize_key($_POST['do']) : '';
        $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
        $ids = array_values( array_filter( array_map('intval', $ids), fn($v)=>$v>0 ) );

        if ( ! $ids || ! in_array($do, ['approve','reject'], true) ) {
            wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-orders')) ); exit;
        }

        global $wpdb;
        $t_refs = $wpdb->prefix . 'aff_referrals';
        $nowUTC = current_time('mysql', true);

        foreach ( $ids as $id ) {
            if ( $do === 'approve' ) {
                $wpdb->update( $t_refs, [ 'status'=>'approved', 'updated_at'=>$nowUTC ], [ 'id'=>$id ], [ '%s','%s' ], [ '%d' ] );
            } else {
                $wpdb->update( $t_refs, [ 'status'=>'rejected', 'updated_at'=>$nowUTC, 'reason'=>'admin_reject' ], [ 'id'=>$id ], [ '%s','%s','%s' ], [ '%d' ] );
            }
        }

        wp_redirect( add_query_arg('msg','updated', admin_url('admin.php?page=affilite-orders')) ); exit;
    }

    public function handle_export() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }
        if ( ! isset($_GET['_aff_nonce']) || ! wp_verify_nonce($_GET['_aff_nonce'], 'aff_orders_export') ) { wp_die('Błędny token'); }

        global $wpdb;
        $t_refs = $wpdb->prefix . 'aff_referrals';
        $t_part = $wpdb->prefix . 'aff_partners';

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $search = isset($_GET['s']) ? trim((string)wp_unslash($_GET['s'])) : '';

        $where = 'WHERE 1=1';
        $params = [];

        if ( in_array($status, ['pending','approved','rejected'], true) ) {
            $where .= " AND r.status = %s";
            $params[] = $status;
        }
        if ( $search !== '' ) {
            $where .= " AND ( CAST(r.order_id AS CHAR) LIKE %s OR u.user_email LIKE %s OR pr.code LIKE %s )";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = "
            SELECT r.id, r.order_id, r.order_total, r.commission_amount, r.status, r.locked_until, r.created_at,
                   pr.code, u.display_name, u.user_email
            FROM $t_refs r
            JOIN $t_part pr ON pr.id = r.partner_id
            JOIN {$wpdb->users} u ON u.ID = pr.user_id
            $where
            ORDER BY r.id DESC
            LIMIT 10000
        ";
        $rows = $wpdb->get_results( $this->maybe_prepare($sql, $params), ARRAY_A );

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=aff-orders-export.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, [ 'id','order_id','order_total','commission_amount','status','locked_until','created_at','code','display_name','user_email' ]);
        foreach ( (array)$rows as $row ) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}
