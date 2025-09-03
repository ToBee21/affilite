<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Dashboard {

    public function render() : void {
        if ( ! current_user_can('manage_options') ) { wp_die('Brak uprawnień'); }

        global $wpdb;
        $t_part = $wpdb->prefix . 'aff_partners';
        $t_clicks = $wpdb->prefix . 'aff_clicks';
        $t_refs = $wpdb->prefix . 'aff_referrals';
        $t_pay = $wpdb->prefix . 'aff_payouts';

        // Zakres 30 dni wstecz (UTC w bazie)
        $days = 30;
        $start = gmdate('Y-m-d 00:00:00', strtotime("-{$days} days"));

        // ---- KPI ----
        // Łączna prowizja (wszystkie statusy)
        $sum_commission = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount),0) FROM $t_refs"
        ) );

        // Łączna liczba konwersji
        $cnt_refs = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_refs");

        // Łączna liczba wizyt (klików)
        $cnt_clicks = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_clicks");

        // Liczba afiliantów (zatwierdzonych)
        $cnt_partners = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_part WHERE status='approved'");

        // Współczynnik konwersji
        $cr = $cnt_clicks > 0 ? round( ($cnt_refs / $cnt_clicks) * 100, 2 ) : 0.0;

        // Dzisiejszy zakres (UTC, bo created_at mamy w UTC)
        $today_start = gmdate('Y-m-d 00:00:00');
        $today_end   = gmdate('Y-m-d 23:59:59');

        $today_sum = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount),0) FROM $t_refs WHERE created_at BETWEEN %s AND %s",
            $today_start, $today_end
        ) );
        $today_refs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t_refs WHERE created_at BETWEEN %s AND %s",
            $today_start, $today_end
        ) );

        // Oczekujące wypłaty (liczba)
        $pending_payouts = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_pay WHERE status='pending'");

        // ---- SZEREGI DOBOWE (ostatnie 30 dni) ----
        $rows = $wpdb->get_results( $wpdb->prepare("
            SELECT DATE(created_at) as d, COUNT(*) as cnt, COALESCE(SUM(commission_amount),0) as sum
            FROM $t_refs
            WHERE created_at >= %s
            GROUP BY DATE(created_at)
            ORDER BY d ASC
        ", $start) );

        // Wypełniamy dziury, żeby chart miał każdy dzień
        $series = [];
        for ($i = $days; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', strtotime("-{$i} days"));
            $series[$d] = [ 'cnt' => 0, 'sum' => 0.0 ];
        }
        foreach ( (array)$rows as $r ) {
            $d = $r->d;
            if ( isset($series[$d]) ) {
                $series[$d]['cnt'] = (int)$r->cnt;
                $series[$d]['sum'] = (float)$r->sum;
            }
        }
        $labels = array_keys($series);
        $data_cnt = array_map(fn($v)=>$v['cnt'], $series);
        $data_sum = array_map(fn($v)=>round($v['sum'],2), $series);

        // ---- RANKING TOP afiliantów (30 dni) ----
        $top = $wpdb->get_results( $wpdb->prepare("
            SELECT pr.id, pr.code, u.display_name, u.user_email,
                   COALESCE(SUM(r.commission_amount),0) as sum_comm,
                   COUNT(r.id) as convs
            FROM $t_part pr
            JOIN {$wpdb->users} u ON u.ID = pr.user_id
            LEFT JOIN $t_refs r ON r.partner_id = pr.id AND r.created_at >= %s
            WHERE pr.status='approved'
            GROUP BY pr.id
            ORDER BY sum_comm DESC
            LIMIT 10
        ", $start) );

        // Enqueue Chart.js tylko na tej stronie
        wp_enqueue_script('affilite-chart', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true);

        echo '<div class="wrap"><h1>AffiLite — Dashboard</h1>';

        // Karty KPI
        echo '<div class="aff-cards" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:12px 0 18px;">';
        $this->card('Łączna prowizja', $this->money($sum_commission));
        $this->card('Łączna liczba konwersji', number_format_i18n($cnt_refs));
        $this->card('Liczba afiliantów', number_format_i18n($cnt_partners));
        $this->card('Współczynnik konwersji', number_format_i18n($cr,2) . '%');
        $this->card('Łączna liczba wizyt', number_format_i18n($cnt_clicks));
        $this->card('Dzisiejsza prowizja', $this->money($today_sum));
        $this->card('Dzisiejsze konwersje', number_format_i18n($today_refs));
        $this->card('Oczekujące wypłaty', number_format_i18n($pending_payouts));
        echo '</div>';

        // Wykres
        echo '<div class="aff-card" style="padding:16px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;margin-bottom:16px;">';
        echo '<h2 style="margin:0 0 8px;">Wykres afiliacji — ostatnie 30 dni</h2>';
        echo '<canvas id="aff-dashboard-chart" height="90"></canvas>';
        echo '</div>';

        // Ranking TOP
        echo '<div class="aff-card" style="padding:16px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;">';
        echo '<h2 style="margin:0 0 8px;">Ranking najlepszych afiliantów (30 dni)</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>#</th><th>Afiliant</th><th>Kod</th><th>Konwersje</th><th>Prowizja</th>';
        echo '</tr></thead><tbody>';
        if ( ! $top ) {
            echo '<tr><td colspan="5">Brak danych.</td></tr>';
        } else {
            $i=1;
            foreach ( $top as $row ) {
                printf('<tr><td>%d</td><td>%s<br><small>%s</small></td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                    $i++,
                    esc_html($row->display_name),
                    esc_html($row->user_email),
                    esc_html($row->code),
                    number_format_i18n((int)$row->convs),
                    $this->money((float)$row->sum_comm)
                );
            }
        }
        echo '</tbody></table></div>';

        // Dane do wykresu
        $labels_js = wp_json_encode($labels);
        $data_cnt_js = wp_json_encode($data_cnt);
        $data_sum_js = wp_json_encode($data_sum);

        echo "<script>
        (function(){
          function run(){
            if (!window.Chart) { setTimeout(run,200); return; }
            var ctx = document.getElementById('aff-dashboard-chart');
            if (!ctx) return;
            new Chart(ctx, {
              type: 'line',
              data: {
                labels: $labels_js,
                datasets: [
                  { label: 'Konwersje', data: $data_cnt_js, tension: .3 },
                  { label: 'Prowizja', data: $data_sum_js, tension: .3, yAxisID: 'y1' }
                ]
              },
              options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                stacked: false,
                scales: {
                  y:  { beginAtZero: true, title: { display:true, text:'Konwersje' } },
                  y1: { beginAtZero: true, position: 'right', title: { display:true, text:'Prowizja' } }
                }
              }
            });
          }
          run();
        })();
        </script>";

        echo '</div>';
    }

    private function card(string $title, string $value) : void {
        echo '<div style="padding:12px 14px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;">';
        echo '<div style="font-size:12px;color:#666;margin-bottom:4px;">'.esc_html($title).'</div>';
        echo '<div style="font-size:18px;font-weight:700;">'.$value.'</div>';
        echo '</div>';
    }

    private function money( $v ) : string {
        return function_exists('wc_price') ? wc_price( (float)$v ) : number_format_i18n( (float)$v, 2 );
    }
}
