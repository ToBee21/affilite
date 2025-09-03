<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class AdminPartners {

    public function hooks() : void {
        add_action('admin_post_aff_partners_update', [ $this, 'handle_bulk' ]);
        add_action('admin_post_aff_partner_save',   [ $this, 'handle_save' ]);
        add_action('admin_post_aff_partner_reset',  [ $this, 'handle_reset_code' ]);
    }

    public function render() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }

        global $wpdb;
        $t_part = $wpdb->prefix . 'aff_partners';
        $per_page = 20;

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $search = isset($_GET['s']) ? trim((string)wp_unslash($_GET['s'])) : '';
        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        $where = 'WHERE 1=1';
        $params = [];

        if ( in_array($status, ['pending','approved','banned'], true) ) {
            $where .= " AND p.status = %s";
            $params[] = $status;
        }

        if ( $search !== '' ) {
            $where .= " AND ( u.display_name LIKE %s OR u.user_email LIKE %s OR p.code LIKE %s )";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
        }

        // --- karty (sumy) ---
        $sql_sum = "
            SELECT
              COUNT(*)                                           AS total,
              SUM(CASE WHEN p.status='approved' THEN 1 ELSE 0 END) AS approved,
              SUM(CASE WHEN p.status='pending'  THEN 1 ELSE 0 END) AS pending,
              SUM(CASE WHEN p.status='banned'   THEN 1 ELSE 0 END) AS banned
            FROM $t_part p
            JOIN {$wpdb->users} u ON u.ID = p.user_id
            $where
        ";
        $totals = $wpdb->get_row( $this->maybe_prepare($sql_sum, $params) );
        if ( ! $totals ) { $totals = (object)['total'=>0,'approved'=>0,'pending'=>0,'banned'=>0]; }

        // --- licznik do paginacji ---
        $sql_count = "
            SELECT COUNT(*)
            FROM $t_part p
            JOIN {$wpdb->users} u ON u.ID = p.user_id
            $where
        ";
        $total_items = (int) $wpdb->get_var( $this->maybe_prepare($sql_count, $params) );
        $total_pages = max(1, (int)ceil($total_items / $per_page));

        // --- lista ---
        $sql_list = "
            SELECT p.*, u.display_name, u.user_email
            FROM $t_part p
            JOIN {$wpdb->users} u ON u.ID = p.user_id
            $where
            ORDER BY p.id DESC
            LIMIT %d OFFSET %d
        ";
        $rows = $wpdb->get_results( $this->maybe_prepare($sql_list, array_merge($params, [ $per_page, $offset ])) );

        $notice = '';
        if ( isset($_GET['msg']) ) {
            $msg = sanitize_key($_GET['msg']);
            if ($msg === 'updated') $notice = '<div class="notice notice-success"><p>Zaktualizowano.</p></div>';
            if ($msg === 'error')   $notice = '<div class="notice notice-error"><p>Operacja nie powiodła się.</p></div>';
        }

        $base_url = admin_url('admin.php?page=affilite-partners');

        echo '<div class="wrap"><h1>AffiLite — Afilianci</h1>';
        echo $notice;

        echo '<div class="aff-cards" style="display:flex;gap:12px;margin:12px 0 18px 0;">';
        $this->card('Łącznie',          number_format_i18n((int)$totals->total));
        $this->card('Zatwierdzeni',     number_format_i18n((int)$totals->approved));
        $this->card('Oczekujący',       number_format_i18n((int)$totals->pending));
        $this->card('Zbanowani',        number_format_i18n((int)$totals->banned));
        echo '</div>';

        // Filtry
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'" style="margin-bottom:10px">';
        echo '<input type="hidden" name="page" value="affilite-partners">';
        echo '<select name="status">';
        echo '<option value="">— wszyscy —</option>';
        foreach (['approved'=>'Zatwierdzeni','pending'=>'Oczekujący','banned'=>'Zbanowani'] as $k=>$lab) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status,$k,false), esc_html($lab));
        }
        echo '</select> ';
        printf('<input type="search" name="s" value="%s" placeholder="Szukaj: imię/email/kod" style="min-width:260px;"> ', esc_attr($search));
        echo '<button class="button">Filtruj</button> ';
        if ($status || $search) echo '<a class="button" href="'.esc_url($base_url).'">Wyczyść</a>';
        echo '</form>';

        // Lista + bulk
        echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'">';
        wp_nonce_field('aff_partners_update', '_aff_nonce');
        echo '<input type="hidden" name="action" value="aff_partners_update">';

        echo '<div class="aff-bulk" style="margin:8px 0;">';
        echo '<button class="button" name="do" value="approve">Zatwierdź</button> ';
        echo '<button class="button" name="do" value="pending">Ustaw: Oczekujący</button> ';
        echo '<button class="button" name="do" value="ban">Zbanuj</button>';
        echo '</div>';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:28px"><input type="checkbox" onclick="jQuery(\'.affprt\').prop(\'checked\',this.checked)"></th>';
        echo '<th>#ID</th><th>Afiliant</th><th>Email</th><th>Kod</th><th>Status</th><th>Prowizja %</th><th>Utworzone</th><th>Akcje</th>';
        echo '</tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="9">Brak wyników.</td></tr>';
        } else {
            foreach ( $rows as $r ) {
                $user_link = esc_url( admin_url('user-edit.php?user_id='.(int)$r->user_id ) );

                $approve_url = wp_nonce_url( add_query_arg(['action'=>'aff_partners_update','do'=>'approve','ids[]'=>(int)$r->id], admin_url('admin-post.php')), 'aff_partners_update', '_aff_nonce');
                $pending_url = wp_nonce_url( add_query_arg(['action'=>'aff_partners_update','do'=>'pending','ids[]'=>(int)$r->id], admin_url('admin-post.php')), 'aff_partners_update', '_aff_nonce');
                $ban_url     = wp_nonce_url( add_query_arg(['action'=>'aff_partners_update','do'=>'ban','ids[]'=>(int)$r->id], admin_url('admin-post.php')), 'aff_partners_update', '_aff_nonce');
                $reset_url   = wp_nonce_url( add_query_arg(['action'=>'aff_partner_reset','id'=>(int)$r->id], admin_url('admin-post.php')), 'aff_partner_reset', '_aff_nonce');

                echo '<tr>';
                printf('<td><input class="affprt" type="checkbox" name="ids[]" value="%d"></td>', (int)$r->id);
                printf('<td>%d</td>', (int)$r->id);
                printf('<td><a href="%s">%s</a></td>', $user_link, esc_html($r->display_name));
                printf('<td>%s</td>', esc_html($r->user_email));
                printf('<td><code>%s</code></td>', esc_html($r->code));
                printf('<td>%s</td>', esc_html($r->status));

                // mały formularz zmiany prowizji
                echo '<td>';
                echo '<form method="post" action="'.esc_url( admin_url('admin-post.php') ).'" style="display:flex;gap:6px;align-items:center">';
                wp_nonce_field('aff_partner_save', '_aff_nonce');
                echo '<input type="hidden" name="action" value="aff_partner_save">';
                printf('<input type="hidden" name="id" value="%d">', (int)$r->id);
                $val = is_null($r->commission_rate) ? '' : (string)(float)$r->commission_rate;
                printf('<input type="number" step="0.01" min="0" max="100" name="commission_rate" value="%s" placeholder="domyślna" style="width:100px;">', esc_attr($val));
                echo '<button class="button button-small">Zapisz</button>';
                echo '</form>';
                echo '</td>';

                printf('<td>%s</td>', esc_html( get_date_from_gmt( $r->created_at, 'Y-m-d H:i' ) ));

                echo '<td>';
                echo '<a class="button button-small" href="'.esc_url($approve_url).'">Zatwierdź</a> ';
                echo '<a class="button button-small" href="'.esc_url($pending_url).'">Oczekujący</a> ';
                echo '<a class="button button-small" href="'.esc_url($ban_url).'">Ban</a> ';
                echo '<a class="button button-small" href="'.esc_url($reset_url).'" title="Wygeneruj nowy kod">Reset kodu</a>';
                echo '</td>';

                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // paginacja
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

        echo '</form></div>';
    }

    private function maybe_prepare(string $sql, array $params) : string {
        global $wpdb;
        return empty($params) ? $sql : $wpdb->prepare($sql, ...$params);
    }

    private function card(string $title, string $value) : void {
        echo '<div class="aff-card" style="padding:12px 14px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;">';
        echo '<div style="font-size:12px;color:#666;margin-bottom:4px;">'.esc_html($title).'</div>';
        echo '<div style="font-size:18px;font-weight:700;">'.$value.'</div>';
        echo '</div>';
    }

    /** BULK: approve/pending/ban */
    public function handle_bulk() : void {
        if ( ! current_user_can('manage_options') ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-partners')) ); exit; }
        check_admin_referer('aff_partners_update', '_aff_nonce');

        $do  = isset($_REQUEST['do']) ? sanitize_key($_REQUEST['do']) : '';
        $ids = isset($_REQUEST['ids']) ? (array)$_REQUEST['ids'] : [];
        $ids = array_values( array_filter( array_map('intval', $ids), fn($v)=>$v>0 ) );

        if ( ! $ids || ! in_array($do, ['approve','pending','ban'], true) ) {
            wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-partners')) ); exit;
        }

        $new_status = $do === 'approve' ? 'approved' : ( $do === 'pending' ? 'pending' : 'banned' );

        global $wpdb;
        $t_part = $wpdb->prefix . 'aff_partners';
        foreach ( $ids as $id ) {
            $wpdb->update(
                $t_part,
                [ 'status' => $new_status, 'updated_at'=> current_time('mysql', true) ],
                [ 'id' => (int)$id ],
                [ '%s','%s' ],
                [ '%d' ]
            );
        }

        wp_redirect( add_query_arg('msg','updated', admin_url('admin.php?page=affilite-partners')) ); exit;
    }

    /** SAVE: zmiana indywidualnej prowizji (NULL = domyślna) */
    public function handle_save() : void {
        if ( ! current_user_can('manage_options') ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-partners')) ); exit; }
        check_admin_referer('aff_partner_save', '_aff_nonce');

        $id  = (int)($_POST['id'] ?? 0);
        $val = isset($_POST['commission_rate']) && $_POST['commission_rate'] !== '' ? (float)$_POST['commission_rate'] : null;

        global $wpdb;
        $t_part = $wpdb->prefix . 'aff_partners';

        if ( $id > 0 ) {
            if ( is_null($val) ) {
                // ustaw NULL (czyli „używaj domyślnej”)
                $wpdb->query( $wpdb->prepare("UPDATE $t_part SET commission_rate = NULL, updated_at=%s WHERE id=%d", current_time('mysql', true), $id) );
            } else {
                $val = max(0, min(100, $val));
                $wpdb->update(
                    $t_part,
                    [ 'commission_rate' => $val, 'updated_at'=> current_time('mysql', true) ],
                    [ 'id' => $id ],
                    [ '%f','%s' ],
                    [ '%d' ]
                );
            }
        }

        wp_redirect( add_query_arg('msg','updated', admin_url('admin.php?page=affilite-partners')) ); exit;
    }

    /** RESET: wygeneruj nowy unikalny kod */
    public function handle_reset_code() : void {
        if ( ! current_user_can('manage_options') ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-partners')) ); exit; }
        check_admin_referer('aff_partner_reset', '_aff_nonce');

        $id = (int)($_GET['id'] ?? 0);
        if ( $id <= 0 ) { wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-partners')) ); exit; }

        global $wpdb;
        $t_part = $wpdb->prefix . 'aff_partners';

        // generuj unikalny kod
        $code = '';
        for ($i=0; $i<5; $i++) {
            $code = strtolower( wp_generate_password( 8, false, false ) );
            $exists = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $t_part WHERE code=%s AND id<>%d", $code, $id) );
            if ( $exists === 0 ) break;
        }

        if ( $code ) {
            $wpdb->update(
                $t_part,
                [ 'code' => $code, 'updated_at'=> current_time('mysql', true) ],
                [ 'id' => $id ],
                [ '%s','%s' ],
                [ '%d' ]
            );
            wp_redirect( add_query_arg('msg','updated', admin_url('admin.php?page=affilite-partners')) );
        } else {
            wp_redirect( add_query_arg('msg','error', admin_url('admin.php?page=affilite-partners')) );
        }
        exit;
    }
}
