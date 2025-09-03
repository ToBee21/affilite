<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class AdminOrders {

    public function hooks() : void {
        // obsługa submitów z listy (bulk/single)
        add_action('admin_post_aff_referrals_update', [ $this, 'handle_update' ]);
    }

    public function render() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }

        global $wpdb;
        $t_ref   = $wpdb->prefix . 'aff_referrals';
        $t_part  = $wpdb->prefix . 'aff_partners';
        $per_page = 20;

        // Filtry
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $search = isset($_GET['s']) ? trim((string)wp_unslash($_GET['s'])) : '';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        // WHERE
        $where = 'WHERE 1=1';
        $params = [];

        if ( in_array($status, ['pending','approved','rejected'], true) ) {
            $where .= " AND r.status = %s";
            $params[] = $status;
        }

        if ( $search !== '' ) {
            // szukamy po order_id, kodzie partnera, emailu partnera, reason
            $where .= " AND (
                CAST(r.order_id AS CHAR) LIKE %s
                OR p.code LIKE %s
                OR u.user_email LIKE %s
                OR r.reason LIKE %s
            )";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like, $like);
        }

        // SUMY
        $sql_totals = "
            SELECT COUNT(*) as cnt,
                   COALESCE(SUM(r.order_total),0) as sum_order,
                   COALESCE(SUM(r.commission_amount),0) as sum_comm
            FROM $t_ref r
            JOIN $t_part p ON p.id = r.partner_id
            JOIN {$wpdb->users} u ON u.ID = p.user_id
            $where
        ";
        $totals = $wpdb->get_row( $wpdb->prepare( $sql_totals, ...$params ) );

        // LICZNIK do paginacji
        $sql_count = "
            SELECT COUNT(*) FROM $t_ref r
            JOIN $t_part p ON p.id = r.partner_id
            JOIN {$wpdb->users} u ON u.ID = p.user_id
            $where
        ";
        $total_items = (int)$wpdb->get_var( $wpdb->prepare( $sql_count, ...$params ) );
        $total_pages = max(1, (int)ceil($total_items / $per_page));

        // LISTA
        $sql_list = "
            SELECT r.*, p.code, p.user_id, u.display_name, u.user_email
            FROM $t_ref r
            JOIN $t_part p ON p.id = r.partner_id
            JOIN {$wpdb->users} u ON u.ID = p.user_id
            $where
            ORDER BY r.id DESC
            LIMIT %d OFFSET %d
        ";
        $params_list = array_merge($params, [ $per_page, $offset ]);
        $rows = $wpdb->get_results( $wpdb->prepare( $sql_list, ...$params_list ) );

        // komunikaty
        $notice = '';
        if ( isset($_GET['msg']) ) {
            $msg = sanitize_key($_GET['msg']);
            if ($msg === 'updated') $notice = '<div class="notice notice-success"><p>Zaktualizowano statusy.</p></div>';
            if ($msg === 'error')   $notice = '<div class="notice notice-error"><p>Nie udało się zaktualizować (sprawdź uprawnienia/nonce).</p></div>';
        }

        // URL bazowy strony
        $base_url = admin_url('admin.php?page=affilite-orders');

        echo '<div class="wrap"><h1>AffiLite — Zamówienia</h1>';
        echo $notice;

        // BOXy z sumami
        echo '<div class="aff-cards" style="display:flex;gap:12px;margin:12px 0 18px 0;">';
        $this->card('Łączna liczba zamówień', number_format_i18n((int)$totals->cnt));
        $this->card('Łączna wartość zamówień', wc_price( (float)$totals->sum_order ));
        $this->card('Łączna prowizja', wc_price( (float)$totals->sum_comm ));
        echo '</div>';

        // Filtry (status + search)
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="margin-bottom:10px">';
        echo '<input type="hidden" name="page" value="affilite-orders">';
        echo '<select name="status">';
        echo '<option value="">— wszystkie statusy —</option>';
        foreach (['pending'=>'Oczekujące','approved'=>'Zatwierdzone','rejected'=>'Odrzucone'] as $k=>$lab) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status,$k,false), esc_html($lab));
        }
        echo '</select> ';
        printf('<input type="search" name="s" value="%s" placeholder="Szukaj: #order / kod / email / reason" style="min-width:280px;"> ', esc_attr($search));
        echo '<button class="button">Filtruj</button> ';
        if ($status || $search) {
            echo '<a class="button" href="'.esc_url($base_url).'">Wyczyść</a>';
        }
        echo '</form>';

        // FORM listy (bulk actions)
        echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
        wp_nonce_field('aff_referrals_update', '_aff_nonce');
        echo '<input type="hidden" name="action" value="aff_referrals_update">';

        // Bulk przyciski
        echo '<div style="margin:8px 0;"><button class="button button-primary" name="do" value="approve">Zatwierdź zaznaczone</button> ';
        echo '<button class="button" name="do" value="reject">Odrzuć zaznaczone</button></div>';

        // Tabela
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:28px"><input type="checkbox" onclick="jQuery(\'.affchk\').prop(\'checked\',this.checked)"></th>';
        echo '<th>#Zamówienie</th><th>Afiliant</th><th>Kod</th><th>Wartość</th><th>Prowizja</th><th>Status</th><th>Lock do</th><th>Powód</th><th>Utworzone</th><th>Akcje</th>';
        echo '</tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="11">Brak wyników.</td></tr>';
        } else {
            foreach ( $rows as $r ) {
                $order_link = esc_url( admin_url('post.php?post='.(int)$r->order_id.'&action=edit') );
                $user_link  = esc_url( admin_url('user-edit.php?user_id='.(int)$r->user_id ) );
                $approve_url = wp_nonce_url( add_query_arg([
                    'action' => 'aff_referrals_update',
                    'do'     => 'approve',
                    'ids[]'  => (int)$r->id,
                ], admin_url('admin-post.php')), 'aff_referrals_update', '_aff_nonce' );
                $reject_url = wp_nonce_url( add_query_arg([
                    'action' => 'aff_referrals_update',
                    'do'     => 'reject',
                    'ids[]'  => (int)$r->id,
                ], admin_url('admin-post.php')), 'aff_referrals_update', '_aff_nonce' );

                echo '<tr>';
                printf('<td><input class="affchk" type="checkbox" name="ids[]" value="%d"></td>', (int)$r->id);
                printf('<td><a href="%s">#%d</a></td>', $order_link, (int)$r->order_id);
                printf('<td><a href="%s">%s</a><br><small>%s</small></td>', $user_link, esc_html($r->display_name), esc_html($r->user_email));
                printf('<td><code>%s</code></td>', esc_html($r->code));
                printf('<td>%s</td>', wc_price( (float)$r->order_total ));
                printf('<td>%s</td>', wc_price( (float)$r->commission_amount ));
                printf('<td>%s</td>', esc_html($r->status));
                printf('<td>%s</td>', $r->locked_until ? esc_html( get_date_from_gmt( $r->locked_until, 'Y-m-d H:i' ) ) : '—');
                printf('<td>%s</td>', $r->reason ? esc_html($r->reason) : '—');
                printf('<td>%s</td>', esc_html( get_date_from_gmt( $r->created_at, 'Y-m-d H:i' ) ));
                echo '<td>';
                echo '<a class="button button-small" href="'.esc_url($approve_url).'">Zatwierdź</a> ';
                echo '<a class="button button-small" href="'.esc_url($reject_url).'">Odrzuć</a>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Paginacja
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

        // Bulk przyciski dół
        echo '<div style="margin:8px 0;"><button class="button button-primary" name="do" value="approve">Zatwierdź zaznaczone</button> ';
        echo '<button class="button" name="do" value="reject">Odrzuć zaznaczone</button></div>';

        echo '</form>';
        echo '</div>';
    }

    private function card(string $title, string $value) : void {
        echo '<div class="aff-card" style="padding:12px 14px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;">';
        echo '<div style="font-size:12px;color:#666;margin-bottom:4px;">'.esc_html($title).'</div>';
        echo '<div style="font-size:18px;font-weight:700;">'.$value.'</div>';
        echo '</div>';
    }

    public function handle_update() : void {
        if ( ! current_user_can('manage_options') ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-orders')) ); exit; }
        check_admin_referer('aff_referrals_update', '_aff_nonce');

        $do  = isset($_REQUEST['do']) ? sanitize_key($_REQUEST['do']) : '';
        $ids = isset($_REQUEST['ids']) ? (array)$_REQUEST['ids'] : [];

        $ids = array_values( array_filter( array_map('intval', $ids), fn($v) => $v > 0 ) );
        if ( ! $ids || ! in_array($do, ['approve','reject'], true) ) {
            wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-orders')) ); exit;
        }

        global $wpdb;
        $t_ref = $wpdb->prefix . 'aff_referrals';

        if ( $do === 'approve' ) {
            foreach ( $ids as $id ) {
                $wpdb->update(
                    $t_ref,
                    [ 'status'=>'approved', 'updated_at'=> current_time('mysql', true), 'reason'=> null ],
                    [ 'id' => $id ],
                    [ '%s','%s','%s' ],
                    [ '%d' ]
                );
            }
        } else { // reject
            foreach ( $ids as $id ) {
                $wpdb->update(
                    $t_ref,
                    [ 'status'=>'rejected', 'updated_at'=> current_time('mysql', true), 'reason'=> 'manual_reject' ],
                    [ 'id' => $id ],
                    [ '%s','%s','%s' ],
                    [ '%d' ]
                );
            }
        }

        wp_redirect( add_query_arg('msg','updated', admin_url('admin.php?page=affilite-orders')) );
        exit;
    }
}
