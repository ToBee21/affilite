<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class AdminPayouts {

    public function hooks() : void {
        add_action('admin_post_aff_payouts_update', [ $this, 'handle_update' ]);
        add_action('admin_post_aff_payouts_export', [ $this, 'handle_export' ]);
    }

    public function render() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }

        global $wpdb;
        $t = $wpdb->prefix . 'aff_payouts';
        $tp= $wpdb->prefix . 'aff_partners';
        $users = $wpdb->users;

        $per_page = 20;
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $search = isset($_GET['s']) ? trim((string)wp_unslash($_GET['s'])) : '';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        $where = 'WHERE 1=1';
        $params = [];
        if ( in_array($status, ['pending','processing','paid','rejected'], true) ) {
            $where .= " AND p.status=%s";
            $params[] = $status;
        }
        if ( $search !== '' ) {
            $where .= " AND ( u.user_email LIKE %s OR pr.code LIKE %s OR CAST(p.id AS CHAR) LIKE %s )";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
        }

        // KPI
        $sum_sql = "
            SELECT
              COUNT(*) AS cnt_all,
              COALESCE(SUM(CASE WHEN p.status='pending' THEN 1 ELSE 0 END),0) AS cnt_pending,
              COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) AS sum_paid,
              COALESCE(SUM(p.amount),0) AS sum_all
            FROM $t p
            JOIN $tp pr ON pr.id = p.partner_id
            JOIN $users u ON u.ID = pr.user_id
            $where
        ";
        $tot = $wpdb->get_row( $this->maybe_prepare($sum_sql, $params) );
        if ( ! $tot ) $tot = (object)['cnt_all'=>0,'cnt_pending'=>0,'sum_paid'=>0.0,'sum_all'=>0.0];

        // Liczba rekordów
        $count_sql = "
            SELECT COUNT(*)
            FROM $t p
            JOIN $tp pr ON pr.id = p.partner_id
            JOIN $users u ON u.ID = pr.user_id
            $where
        ";
        $total_items = (int) $wpdb->get_var( $this->maybe_prepare($count_sql, $params) );
        $total_pages = max(1, (int)ceil($total_items / $per_page));

        // Lista
        $list_sql = "
            SELECT p.*, pr.code, u.display_name, u.user_email, u.ID AS wp_user_id
            FROM $t p
            JOIN $tp pr ON pr.id = p.partner_id
            JOIN $users u ON u.ID = pr.user_id
            $where
            ORDER BY p.id DESC
            LIMIT %d OFFSET %d
        ";
        $rows = $wpdb->get_results( $this->maybe_prepare($list_sql, array_merge($params,[ $per_page, $offset ])) );

        $base = admin_url('admin.php?page=affilite-payouts');

        echo '<div class="wrap"><h1>AffiLite — Wypłaty</h1>';

        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">';
        $this->card('Łączna liczba wypłat', number_format_i18n((int)$tot->cnt_all));
        $this->card('Oczekujące wypłaty (liczba)', number_format_i18n((int)$tot->cnt_pending));
        $this->card('Łączna kwota wypłat (paid)', Balance::money((float)$tot->sum_paid));
        $this->card('Kwota wszystkich wniosków', Balance::money((float)$tot->sum_all));
        echo '</div>';

        // Filtry
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap">';
        echo '<input type="hidden" name="page" value="affilite-payouts">';
        echo '<select name="status">';
        echo '<option value="">— wszystkie statusy —</option>';
        foreach ( ['pending'=>'Oczekuje','processing'=>'W trakcie','paid'=>'Wypłacono','rejected'=>'Odrzucono'] as $k=>$lab ) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status,$k,false), esc_html($lab));
        }
        echo '</select>';
        printf('<input type="search" name="s" value="%s" placeholder="Szukaj: email / kod / #id" style="min-width:240px">', esc_attr($search));
        echo '<button class="button">Filtruj</button> ';
        if ($status || $search) echo '<a class="button" href="'.esc_url($base).'">Wyczyść</a>';

        // Eksport CSV
        $export = add_query_arg( array_merge($_GET, ['action'=>'aff_payouts_export']), admin_url('admin-post.php') );
        $export = wp_nonce_url($export, 'aff_payouts_export', '_aff_nonce');
        echo ' <a class="button" href="'.esc_url($export).'">Eksport CSV</a>';

        echo '</form>';

        // Lista + bulk
        echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
        wp_nonce_field('aff_payouts_update', '_aff_nonce');
        echo '<input type="hidden" name="action" value="aff_payouts_update">';

        echo '<div class="aff-bulk" style="margin:8px 0">';
        echo '<button class="button" name="do" value="processing">Oznacz: W trakcie</button> ';
        echo '<button class="button button-primary" name="do" value="paid">Oznacz: Wypłacono</button> ';
        echo '<button class="button" name="do" value="rejected">Odrzuć</button>';
        echo '</div>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:28px"><input type="checkbox" onclick="jQuery(\'.affpay\').prop(\'checked\',this.checked)"></th>';
        echo '<th>#ID</th><th>Afiliant</th><th>Kod</th><th>Kwota</th><th>Metoda</th><th>Status</th><th>Utworzone</th><th>Aktualizacja</th>';
        echo '</tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="9">Brak wyników.</td></tr>';
        } else {
            foreach ( $rows as $r ) {
                $user_link = esc_url( admin_url('user-edit.php?user_id='.(int)$r->wp_user_id) );
                echo '<tr>';
                printf('<td><input class="affpay" type="checkbox" name="ids[]" value="%d"></td>', (int)$r->id);
                printf('<td>%d</td>', (int)$r->id);
                printf('<td><a href="%s">%s</a><br><small>%s</small></td>', $user_link, esc_html($r->display_name), esc_html($r->user_email));
                printf('<td><code>%s</code></td>', esc_html($r->code));
                printf('<td>%s</td>', Balance::money((float)$r->amount));
                printf('<td>%s</td>', esc_html($this->method_label($r->method)));
                printf('<td>%s</td>', esc_html($this->status_label($r->status)));
                printf('<td>%s</td>', esc_html( get_date_from_gmt($r->created_at, 'Y-m-d H:i') ));
                printf('<td>%s</td>', esc_html( get_date_from_gmt($r->updated_at, 'Y-m-d H:i') ));
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        if ( $total_pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo paginate_links([
                'base'      => add_query_arg(['paged'=>'%#%'], $base),
                'format'    => '',
                'prev_text' => '«',
                'next_text' => '»',
                'total'     => $total_pages,
                'current'   => $paged,
            ]);
            echo '</div></div>';
        }

        echo '<div class="aff-bulk" style="margin:8px 0">';
        echo '<button class="button" name="do" value="processing">Oznacz: W trakcie</button> ';
        echo '<button class="button button-primary" name="do" value="paid">Oznacz: Wypłacono</button> ';
        echo '<button class="button" name="do" value="rejected">Odrzuć</button>';
        echo '</div>';

        echo '</form></div>';
    }

    private function method_label(string $m) : string {
        return $m==='paypal' ? 'PayPal' : ($m==='bank' ? 'Przelew' : 'Krypto');
    }
    private function status_label(string $s) : string {
        return [
            'pending'    => 'Oczekuje',
            'processing' => 'W trakcie',
            'paid'       => 'Wypłacono',
            'rejected'   => 'Odrzucono',
        ][$s] ?? $s;
    }

    private function card( string $title, string $value ) : void {
        echo '<div class="aff-card" style="min-width:220px;padding:12px 14px;border:1px solid rgba(0,0,0,.08);border-radius:10px;background:#fff">';
        printf('<div style="font-size:12px;color:#667">%s</div>', esc_html($title));
        printf('<div style="font-size:18px;font-weight:600;margin-top:4px">%s</div>', wp_kses_post($value));
        echo '</div>';
    }

    private function maybe_prepare(string $sql, array $params) : string {
        global $wpdb;
        return empty($params) ? $sql : $wpdb->prepare($sql, ...$params);
    }

    public function handle_update() : void {
        if ( ! current_user_can('manage_options') ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-payouts')) ); exit; }
        check_admin_referer('aff_payouts_update', '_aff_nonce');

        $do  = isset($_POST['do']) ? sanitize_key($_POST['do']) : '';
        $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
        $ids = array_values( array_filter( array_map('intval', $ids), fn($v)=>$v>0 ) );

        if ( ! $ids || ! in_array($do, ['processing','paid','rejected'], true) ) {
            wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-payouts')) ); exit;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'aff_payouts';
        $now = current_time('mysql', true);

        foreach ( $ids as $id ) {
            $wpdb->update( $t, [ 'status'=>$do, 'updated_at'=>$now ], [ 'id'=>$id ], [ '%s','%s' ], [ '%d' ] );
        }

        wp_redirect( add_query_arg('msg','updated', admin_url('admin.php?page=affilite-payouts')) ); exit;
    }

    public function handle_export() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }
        if ( ! isset($_GET['_aff_nonce']) || ! wp_verify_nonce($_GET['_aff_nonce'], 'aff_payouts_export') ) { wp_die('Błędny token'); }

        global $wpdb;
        $t = $wpdb->prefix . 'aff_payouts';
        $tp= $wpdb->prefix . 'aff_partners';
        $users = $wpdb->users;

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $search = isset($_GET['s']) ? trim((string)wp_unslash($_GET['s'])) : '';

        $where = 'WHERE 1=1';
        $params = [];
        if ( in_array($status, ['pending','processing','paid','rejected'], true) ) {
            $where .= " AND p.status=%s";
            $params[] = $status;
        }
        if ( $search !== '' ) {
            $where .= " AND ( u.user_email LIKE %s OR pr.code LIKE %s OR CAST(p.id AS CHAR) LIKE %s )";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = "
            SELECT p.id, p.amount, p.method, p.status, p.created_at, p.updated_at,
                   pr.code, u.display_name, u.user_email
            FROM $t p
            JOIN $tp pr ON pr.id = p.partner_id
            JOIN $users u ON u.ID = pr.user_id
            $where
            ORDER BY p.id DESC
            LIMIT 10000
        ";
        $rows = $wpdb->get_results( $this->maybe_prepare($sql, $params), ARRAY_A );

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=aff-payouts-export.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, [ 'id','amount','method','status','created_at','updated_at','code','display_name','user_email' ]);
        foreach ( (array)$rows as $row ) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}
