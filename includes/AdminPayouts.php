<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class AdminPayouts {

    public function hooks() : void {
        add_action('admin_post_aff_payouts_update', [ $this, 'handle_update' ]);
    }

    public function render() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }

        global $wpdb;
        $t_pay  = $wpdb->prefix . 'aff_payouts';
        $t_part = $wpdb->prefix . 'aff_partners';
        $per_page = 20;

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $search = isset($_GET['s']) ? trim((string)wp_unslash($_GET['s'])) : '';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        $where = 'WHERE 1=1';
        $params = [];

        if ( in_array($status, ['pending','processing','paid','rejected'], true) ) {
            $where .= " AND p.status = %s";
            $params[] = $status;
        }

        if ( $search !== '' ) {
            $where .= " AND (
                CAST(p.id AS CHAR) LIKE %s
                OR pr.code LIKE %s
                OR p.method LIKE %s
            )";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
        }

        // --- SUMY ---
        $sql_totals = "
            SELECT COUNT(*) as cnt_all,
                   SUM(CASE WHEN p.status='pending' THEN 1 ELSE 0 END) as cnt_pending,
                   COALESCE(SUM(p.amount),0) as sum_all,
                   COALESCE(SUM(CASE WHEN p.status='pending' THEN p.amount ELSE 0 END),0) as sum_pending
            FROM $t_pay p
            JOIN $t_part pr ON pr.id = p.partner_id
            $where
        ";
        $totals = $wpdb->get_row( $this->maybe_prepare($sql_totals, $params) );
        // Bezpieczne domyślne wartości, gdyby wynik był null
        if ( ! $totals ) {
            $totals = (object)[ 'cnt_all'=>0, 'cnt_pending'=>0, 'sum_all'=>0.0, 'sum_pending'=>0.0 ];
        }

        // --- LICZNIK DO PAGINACJI ---
        $sql_count = "
            SELECT COUNT(*) FROM $t_pay p
            JOIN $t_part pr ON pr.id = p.partner_id
            $where
        ";
        $total_items = (int) $wpdb->get_var( $this->maybe_prepare($sql_count, $params) );
        $total_pages = max(1, (int)ceil($total_items / $per_page));

        // --- LISTA ---
        $sql_list = "
            SELECT p.*, pr.user_id, pr.code, u.display_name, u.user_email
            FROM $t_pay p
            JOIN $t_part pr ON pr.id = p.partner_id
            JOIN {$wpdb->users} u ON u.ID = pr.user_id
            $where
            ORDER BY p.id DESC
            LIMIT %d OFFSET %d
        ";
        $rows = $wpdb->get_results( $this->maybe_prepare($sql_list, array_merge($params,[ $per_page, $offset ])) );

        $notice = '';
        if ( isset($_GET['msg']) ) {
            $msg = sanitize_key($_GET['msg']);
            if ($msg === 'updated') $notice = '<div class="notice notice-success"><p>Zaktualizowano wypłaty.</p></div>';
            if ($msg === 'error')   $notice = '<div class="notice notice-error"><p>Błąd aktualizacji wypłat.</p></div>';
        }

        $base_url = admin_url('admin.php?page=affilite-payouts');

        echo '<div class="wrap"><h1>AffiLite — Wypłaty</h1>';
        echo $notice;

        echo '<div class="aff-cards" style="display:flex;gap:12px;margin:12px 0 18px 0;">';
        $this->card('Łączna liczba wypłat', number_format_i18n((int)$totals->cnt_all));
        $this->card('Oczekujące wypłaty (liczba)', number_format_i18n((int)$totals->cnt_pending));
        $this->card('Łączna kwota wypłat', $this->money( (float)$totals->sum_all ));
        $this->card('Kwota oczekujących wypłat', $this->money( (float)$totals->sum_pending ));
        echo '</div>';

        // Filtry
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="margin-bottom:10px">';
        echo '<input type="hidden" name="page" value="affilite-payouts">';
        echo '<select name="status">';
        echo '<option value="">— wszystkie statusy —</option>';
        foreach (['pending'=>'Oczekujące','processing'=>'Przetwarzanie','paid'=>'Wypłacone','rejected'=>'Odrzucone'] as $k=>$lab) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status,$k,false), esc_html($lab));
        }
        echo '</select> ';
        printf('<input type="search" name="s" value="%s" placeholder="Szukaj: #id / kod / metoda" style="min-width:260px;"> ', esc_attr($search));
        echo '<button class="button">Filtruj</button> ';
        if ($status || $search) echo '<a class="button" href="'.esc_url($base_url).'">Wyczyść</a>';
        echo '</form>';

        // Lista + bulk
        echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
        wp_nonce_field('aff_payouts_update', '_aff_nonce');
        echo '<input type="hidden" name="action" value="aff_payouts_update">';

        echo '<div style="margin:8px 0;">';
        echo '<button class="button" name="do" value="processing">Oznacz: Przetwarzanie</button> ';
        echo '<button class="button button-primary" name="do" value="paid">Oznacz: Wypłacone</button> ';
        echo '<button class="button" name="do" value="rejected">Odrzuć</button>';
        echo '</div>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:28px"><input type="checkbox" onclick="jQuery(\'.affpay\').prop(\'checked\',this.checked)"></th>';
        echo '<th>#ID</th><th>Afiliant</th><th>Kod</th><th>Kwota</th><th>Metoda</th><th>Dane</th><th>Status</th><th>Utworzone</th><th>Akcje</th>';
        echo '</tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="10">Brak wyników.</td></tr>';
        } else {
            foreach ( $rows as $r ) {
                $user_link  = esc_url( admin_url('user-edit.php?user_id='.(int)$r->user_id ) );
                $details = $r->details_json ? json_decode($r->details_json, true) : [];
                $details_str = '';
                if ($r->method === 'paypal') { $details_str = 'PayPal: '.esc_html($details['email'] ?? ''); }
                elseif ($r->method === 'bank') { $details_str = 'IBAN: '.esc_html($details['iban'] ?? '').' BIC: '.esc_html($details['bic'] ?? '').' / '.esc_html($details['name'] ?? ''); }
                else { $details_str = 'Network: '.esc_html($details['network'] ?? '').' Addr: '.esc_html($details['address'] ?? ''); }

                $proc_url = wp_nonce_url( add_query_arg(['action'=>'aff_payouts_update','do'=>'processing','ids[]'=>(int)$r->id], admin_url('admin-post.php')), 'aff_payouts_update', '_aff_nonce');
                $paid_url = wp_nonce_url( add_query_arg(['action'=>'aff_payouts_update','do'=>'paid','ids[]'=>(int)$r->id], admin_url('admin-post.php')), 'aff_payouts_update', '_aff_nonce');
                $rej_url  = wp_nonce_url( add_query_arg(['action'=>'aff_payouts_update','do'=>'rejected','ids[]'=>(int)$r->id], admin_url('admin-post.php')), 'aff_payouts_update', '_aff_nonce');

                echo '<tr>';
                printf('<td><input class="affpay" type="checkbox" name="ids[]" value="%d"></td>', (int)$r->id);
                printf('<td>%d</td>', (int)$r->id);
                printf('<td><a href="%s">%s</a><br><small>%s</small></td>', $user_link, esc_html($r->display_name), esc_html($r->user_email));
                printf('<td><code>%s</code></td>', esc_html($r->code));
                printf('<td>%s</td>', $this->money( (float)$r->amount ));
                printf('<td>%s</td>', esc_html($r->method));
                printf('<td style="max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">%s</td>', $details_str ?: '—');
                printf('<td>%s</td>', esc_html($r->status));
                printf('<td>%s</td>', esc_html( get_date_from_gmt( $r->created_at, 'Y-m-d H:i' ) ));
                echo '<td>';
                echo '<a class="button button-small" href="'.esc_url($proc_url).'">Przetwarzaj</a> ';
                echo '<a class="button button-small" href="'.esc_url($paid_url).'">Wypłacone</a> ';
                echo '<a class="button button-small" href="'.esc_url($rej_url).'">Odrzuć</a>';
                echo '</td>';
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

        echo '<div style="margin:8px 0;">';
        echo '<button class="button" name="do" value="processing">Oznacz: Przetwarzanie</button> ';
        echo '<button class="button button-primary" name="do" value="paid">Oznacz: Wypłacone</button> ';
        echo '<button class="button" name="do" value="rejected">Odrzuć</button>';
        echo '</div>';

        echo '</form></div>';
    }

    /** prepare() tylko gdy są parametry */
    private function maybe_prepare( string $sql, array $params ) : string {
        global $wpdb;
        return empty($params) ? $sql : $wpdb->prepare( $sql, ...$params );
    }

    private function card(string $title, string $value) : void {
        echo '<div class="aff-card" style="padding:12px 14px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;">';
        echo '<div style="font-size:12px;color:#666;margin-bottom:4px;">'.esc_html($title).'</div>';
        echo '<div style="font-size:18px;font-weight:700;">'.$value.'</div>';
        echo '</div>';
    }

    private function money( $v ) : string {
        return function_exists('wc_price') ? wc_price( (float)$v ) : number_format_i18n( (float)$v, 2 );
    }

    public function handle_update() : void {
        if ( ! current_user_can('manage_options') ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-payouts')) ); exit; }
        check_admin_referer('aff_payouts_update', '_aff_nonce');

        $do  = isset($_REQUEST['do']) ? sanitize_key($_REQUEST['do']) : '';
        $ids = isset($_REQUEST['ids']) ? (array)$_REQUEST['ids'] : [];

        $ids = array_values( array_filter( array_map('intval', $ids), fn($v) => $v > 0 ) );
        if ( ! $ids || ! in_array($do, ['processing','paid','rejected'], true) ) {
            wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-payouts')) ); exit;
        }

        global $wpdb;
        $t_pay = $wpdb->prefix . 'aff_payouts';

        foreach ( $ids as $id ) {
            $wpdb->update(
                $t_pay,
                [ 'status'=> $do, 'updated_at'=> current_time('mysql', true) ],
                [ 'id' => $id ],
                [ '%s','%s' ],
                [ '%d' ]
            );
        }

        wp_redirect( add_query_arg('msg','updated', admin_url('admin.php?page=affilite-payouts')) );
        exit;
    }
}
