<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Mailer {

    /** Prosty wrapper na wp_mail z HTML-em */
    public static function send_html( string $to, string $subject, string $html ) : void {
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $body = self::wrap( $html );
        wp_mail( $to, $subject, $body, $headers );
    }

    /** Szablon HTML (lekki) */
    private static function wrap( string $content ) : string {
        $site = wp_specialchars_decode( get_bloginfo('name'), ENT_QUOTES );
        return '
        <div style="background:#f6f7f9;padding:24px">
          <div style="max-width:620px;margin:0 auto;background:#fff;border:1px solid #e6e7ea;border-radius:10px;padding:20px">
            <div style="font-size:18px;font-weight:600;margin-bottom:12px">'.$site.' — AffiLite</div>
            <div style="font-size:14px;line-height:1.55;color:#222">'.$content.'</div>
          </div>
          <div style="max-width:620px;margin:8px auto 0;text-align:center;color:#889;font-size:12px">
            Wiadomość wygenerowana automatycznie przez wtyczkę AffiLite.
          </div>
        </div>';
    }

    /** Admin: nowy wniosek o wypłatę */
    public static function admin_new_payout( int $partner_id, float $amount, string $method ) : void {
        $opts = get_option( Settings::OPTION_KEY, Settings::defaults() );
        // flaga ustawień (domyślnie włączone); jeśli chcesz, podłączysz to do UI Settings później
        $enabled = $opts['notify_admin']['new_payout'] ?? true;
        if ( ! $enabled ) return;

        global $wpdb;
        $partners = $wpdb->prefix.'aff_partners';
        $u = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.code, u.display_name, u.user_email
             FROM $partners p JOIN {$wpdb->users} u ON u.ID=p.user_id
             WHERE p.id=%d LIMIT 1", $partner_id
        ) );
        if ( ! $u ) return;

        $admin = get_option('admin_email');
        $m = self::label_method($method);
        $html = sprintf(
            '<p>Nowy wniosek o wypłatę od: <strong>%s</strong> (<a href="mailto:%s">%s</a>)</p>
             <p>Kod afilianta: <code>%s</code><br>Kwota: <strong>%s</strong><br>Metoda: <strong>%s</strong></p>
             <p>Panel: <a href="%s">%s</a></p>',
            esc_html($u->display_name),
            esc_attr($u->user_email),
            esc_html($u->user_email),
            esc_html($u->code),
            esc_html( self::money($amount) ),
            esc_html($m),
            esc_url( admin_url('admin.php?page=affilite-payouts') ),
            esc_html( admin_url('admin.php?page=affilite-payouts') )
        );
        self::send_html( $admin, 'AffiLite: nowy wniosek o wypłatę', $html );
    }

    /** Afiliant: status wypłaty zmieniony */
    public static function affiliate_payout_status( int $payout_id ) : void {
        $opts = get_option( Settings::OPTION_KEY, Settings::defaults() );
        $enabled = $opts['notify_affiliate']['payout_status'] ?? true;
        if ( ! $enabled ) return;

        global $wpdb;
        $t  = $wpdb->prefix.'aff_payouts';
        $tp = $wpdb->prefix.'aff_partners';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT p.id, p.amount, p.method, p.status, p.updated_at,
                    pr.code, u.display_name, u.user_email
             FROM $t p
             JOIN $tp pr ON pr.id=p.partner_id
             JOIN {$wpdb->users} u ON u.ID=pr.user_id
             WHERE p.id=%d LIMIT 1", $payout_id
        ) );
        if ( ! $row ) return;

        $sub = sprintf( 'AffiLite: status wypłaty #%d: %s', (int)$row->id, self::label_status($row->status) );
        $html = sprintf(
            '<p>Wniosek o wypłatę <strong>#%d</strong> zmienił status na: <strong>%s</strong>.</p>
             <p>Kwota: <strong>%s</strong><br>Metoda: <strong>%s</strong><br>Kod afilianta: <code>%s</code></p>
             <p>Data aktualizacji: %s</p>',
            (int)$row->id,
            esc_html( self::label_status($row->status) ),
            esc_html( self::money((float)$row->amount) ),
            esc_html( self::label_method($row->method) ),
            esc_html( $row->code ),
            esc_html( get_date_from_gmt($row->updated_at, 'Y-m-d H:i') )
        );
        self::send_html( (string)$row->user_email, $sub, $html );
    }

    private static function label_method(string $m) : string {
        return $m==='paypal' ? 'PayPal' : ($m==='bank' ? 'Przelew bankowy' : 'Krypto');
    }
    private static function label_status(string $s) : string {
        return [
            'pending'    => 'Oczekuje',
            'processing' => 'W trakcie',
            'paid'       => 'Wypłacono',
            'rejected'   => 'Odrzucono',
        ][$s] ?? $s;
    }
    private static function money(float $v) : string {
        return function_exists('wc_price') ? wc_price($v) : number_format_i18n($v, 2);
    }
}
