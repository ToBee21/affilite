<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class PortalPayouts {

    public function render( int $partner_id ) : void {
        if ( $partner_id <= 0 ) { echo '<p>Brak danych.</p>'; return; }

        $opts = get_option( Settings::OPTION_KEY, Settings::defaults() );
        $min  = (float) ( $opts['min_payout'] ?? 100.0 );

        $methods_allowed = [
            'paypal' => !empty($opts['payout_methods']['paypal']),
            'bank'   => !empty($opts['payout_methods']['bank']),
            'crypto' => !empty($opts['payout_methods']['crypto']),
        ];

        $tot = Balance::totals($partner_id);

        // Obsługa submitu
        if ( isset($_POST['aff_payout_nonce']) && wp_verify_nonce($_POST['aff_payout_nonce'], 'aff_payout_request') ) {
            $this->handle_request( $partner_id, $methods_allowed, $min, $tot['available'] );
        }

        // Boxy salda
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">';
        $this->card('Dostępne środki', Balance::money($tot['available']));
        $this->card('W trakcie wypłaty', Balance::money($tot['requested']));
        $this->card('Wypłacone łącznie', Balance::money($tot['paid']));
        echo '</div>';

        // Formularz wypłaty
        $this->form( $partner_id, $methods_allowed, $min, $tot['available'] );

        // Tabela historii wypłat
        $this->table( $partner_id );
    }

    private function handle_request( int $partner_id, array $methods_allowed, float $min, float $available ) : void {
        if ( ! is_user_logged_in() ) { echo '<div class="notice notice-error"><p>Musisz być zalogowany.</p></div>'; return; }

        $amount = isset($_POST['amount']) ? (float) str_replace(',', '.', (string)$_POST['amount']) : 0.0;
        $method = isset($_POST['method']) ? sanitize_key($_POST['method']) : '';

        if ( $amount <= 0 || $amount > $available ) {
            echo '<div class="notice notice-error"><p>Nieprawidłowa kwota.</p></div>'; return;
        }
        if ( $amount < $min ) {
            echo '<div class="notice notice-error"><p>Minimalna kwota wypłaty to '.esc_html(Balance::money($min)).'.</p></div>'; return;
        }
        if ( empty($methods_allowed[$method]) ) {
            echo '<div class="notice notice-error"><p>Wybrana metoda wypłaty nie jest dostępna.</p></div>'; return;
        }

        // Dane do płatności – pobierz z meta usera (jeśli wysłane, zaktualizuj).
        $details = [];
        $uid = get_current_user_id();

        if ( $method === 'paypal' ) {
            $email = sanitize_email( $_POST['pp_email'] ?? '' );
            if ( ! is_email($email) ) { echo '<div class="notice notice-error"><p>Nieprawidłowy email PayPal.</p></div>'; return; }
            update_user_meta( $uid, '_aff_paypal_email', $email );
            $details = [ 'email' => $email ];
        } elseif ( $method === 'bank' ) {
            $holder = sanitize_text_field( $_POST['bank_holder'] ?? '' );
            $iban   = sanitize_text_field( $_POST['bank_iban'] ?? '' );
            $bank   = sanitize_text_field( $_POST['bank_name'] ?? '' );
            $swift  = sanitize_text_field( $_POST['bank_swift'] ?? '' );
            if ( $holder === '' || $iban === '' ) { echo '<div class="notice notice-error"><p>Uzupełnij dane bankowe (właściciel i IBAN).</p></div>'; return; }
            update_user_meta( $uid, '_aff_bank_holder', $holder );
            update_user_meta( $uid, '_aff_bank_iban',   $iban );
            update_user_meta( $uid, '_aff_bank_name',   $bank );
            update_user_meta( $uid, '_aff_bank_swift',  $swift );
            $details = compact('holder','iban','bank','swift');
        } else { // crypto
            $coin = sanitize_text_field( $_POST['crypto_coin'] ?? '' );
            $addr = sanitize_text_field( $_POST['crypto_address'] ?? '' );
            if ( $coin === '' || $addr === '' ) { echo '<div class="notice notice-error"><p>Uzupełnij dane krypto (token/coin i adres).</p></div>'; return; }
            update_user_meta( $uid, '_aff_crypto_coin',    $coin );
            update_user_meta( $uid, '_aff_crypto_address', $addr );
            $details = compact('coin','addr');
        }

        global $wpdb;
        $t = $wpdb->prefix . 'aff_payouts';
        $wpdb->insert( $t, [
            'partner_id'   => $partner_id,
            'amount'       => $amount,
            'method'       => $method,
            'details_json' => wp_json_encode($details),
            'status'       => 'pending',
            'created_at'   => current_time('mysql', true),
            'updated_at'   => current_time('mysql', true),
        ], [ '%d','%f','%s','%s','%s','%s','%s' ] );
        \AffiLite\Mailer::admin_new_payout( $partner_id, $amount, $method );
        echo '<div class="notice notice-success"><p>Wniosek o wypłatę złożony.</p></div>';
    }

    private function form( int $partner_id, array $methods_allowed, float $min, float $available ) : void {
        if ( $available <= 0 ) {
            echo '<div class="notice notice-info"><p>Brak dostępnych środków do wypłaty.</p></div>';
            return;
        }

        $uid = get_current_user_id();
        $pp_email = (string) get_user_meta($uid, '_aff_paypal_email', true);
        $bank_holder = (string) get_user_meta($uid, '_aff_bank_holder', true);
        $bank_iban   = (string) get_user_meta($uid, '_aff_bank_iban', true);
        $bank_name   = (string) get_user_meta($uid, '_aff_bank_name', true);
        $bank_swift  = (string) get_user_meta($uid, '_aff_bank_swift', true);
        $crypto_coin = (string) get_user_meta($uid, '_aff_crypto_coin', true);
        $crypto_addr = (string) get_user_meta($uid, '_aff_crypto_address', true);

        echo '<div class="aff-card" style="padding:12px;border:1px solid rgba(0,0,0,.08);border-radius:8px;background:#fff;margin-bottom:16px">';
        echo '<h3>Wniosek o wypłatę</h3>';
        echo '<form method="post">';
        wp_nonce_field('aff_payout_request', 'aff_payout_nonce');

        echo '<p>Minimalna kwota: <strong>'.esc_html( Balance::money($min) ).'</strong>. Dostępne: <strong>'.esc_html( Balance::money($available) ).'</strong></p>';

        echo '<p><label>Kwota do wypłaty: <input type="number" step="0.01" min="0" max="'.esc_attr($available).'" name="amount" required style="width:160px"></label></p>';

        echo '<p><label>Metoda: ';
        echo '<select name="method" id="aff-payout-method" required>';
        if ( $methods_allowed['paypal'] ) echo '<option value="paypal">PayPal</option>';
        if ( $methods_allowed['bank'] )   echo '<option value="bank">Przelew bankowy</option>';
        if ( $methods_allowed['crypto'] ) echo '<option value="crypto">Krypto</option>';
        echo '</select></label></p>';

        // PayPal
        echo '<div class="aff-paypal-fields aff-pay" style="margin:8px 0">';
        echo '<label>Email PayPal: <input type="email" name="pp_email" value="'.esc_attr($pp_email).'" style="width:260px"></label>';
        echo '</div>';

        // Bank
        echo '<div class="aff-bank-fields aff-pay" style="margin:8px 0;display:none">';
        echo '<p><label>Właściciel rachunku: <input type="text" name="bank_holder" value="'.esc_attr($bank_holder).'" style="width:260px"></label></p>';
        echo '<p><label>IBAN: <input type="text" name="bank_iban" value="'.esc_attr($bank_iban).'" style="width:260px"></label></p>';
        echo '<p><label>Nazwa banku (opcjonalnie): <input type="text" name="bank_name" value="'.esc_attr($bank_name).'" style="width:260px"></label></p>';
        echo '<p><label>SWIFT (opcjonalnie): <input type="text" name="bank_swift" value="'.esc_attr($bank_swift).'" style="width:260px"></label></p>';
        echo '</div>';

        // Crypto
        echo '<div class="aff-crypto-fields aff-pay" style="margin:8px 0;display:none">';
        echo '<p><label>Token/Coin: <input type="text" name="crypto_coin" value="'.esc_attr($crypto_coin).'" style="width:260px"></label></p>';
        echo '<p><label>Adres portfela: <input type="text" name="crypto_address" value="'.esc_attr($crypto_addr).'" style="width:360px"></label></p>';
        echo '</div>';

        echo '<p><button class="button button-primary" type="submit">Złóż wniosek</button></p>';

        echo '</form>';

        // Prosty JS przełączający pola
        echo '<script>
        (function(){
          var sel=document.getElementById("aff-payout-method");
          function sw(){
            var v=sel.value;
            document.querySelector(".aff-paypal-fields").style.display=(v==="paypal")?"block":"none";
            document.querySelector(".aff-bank-fields").style.display=(v==="bank")?"block":"none";
            document.querySelector(".aff-crypto-fields").style.display=(v==="crypto")?"block":"none";
          }
          sel.addEventListener("change", sw); sw();
        })();
        </script>';

        echo '</div>';
    }

    private function table( int $partner_id ) : void {
        global $wpdb;
        $t = $wpdb->prefix . 'aff_payouts';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, amount, method, status, created_at, updated_at
             FROM $t
             WHERE partner_id=%d
             ORDER BY id DESC
             LIMIT 100",
            $partner_id
        ) );

        echo '<h3>Historia wypłat</h3>';
        echo '<table class="aff-table" style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="text-align:left;border-bottom:1px solid #e5e5e5">';
        echo '<th style="padding:8px">#</th><th style="padding:8px">Kwota</th><th style="padding:8px">Metoda</th><th style="padding:8px">Status</th><th style="padding:8px">Utworzone</th><th style="padding:8px">Aktualizacja</th>';
        echo '</tr></thead><tbody>';

        if ( ! $rows ) {
            echo '<tr><td colspan="6" style="padding:8px">Brak wniosków o wypłatę.</td></tr>';
        } else {
            foreach ( $rows as $r ) {
                echo '<tr style="border-bottom:1px solid #f0f0f0">';
                printf('<td style="padding:8px">%d</td>', (int)$r->id);
                printf('<td style="padding:8px">%s</td>', Balance::money((float)$r->amount));
                printf('<td style="padding:8px">%s</td>', esc_html($this->method_label($r->method)));
                printf('<td style="padding:8px">%s</td>', esc_html($this->status_label($r->status)));
                printf('<td style="padding:8px">%s</td>', esc_html( get_date_from_gmt($r->created_at, 'Y-m-d H:i') ));
                printf('<td style="padding:8px">%s</td>', esc_html( get_date_from_gmt($r->updated_at, 'Y-m-d H:i') ));
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
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

    private function card(string $title, string $value) : void {
        echo '<div class="aff-card" style="min-width:200px;padding:12px;border:1px solid rgba(0,0,0,.08);border-radius:10px;background:#fff">';
        printf('<div style="font-size:12px;color:#667">%s</div>', esc_html($title));
        printf('<div style="font-size:18px;font-weight:600;margin-top:4px">%s</div>', wp_kses_post($value));
        echo '</div>';
    }
}
