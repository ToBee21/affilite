<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Tracking {

    public function hooks() : void {
        add_action('init', [ $this, 'add_rewrite' ]);
        add_filter('query_vars', [ $this, 'query_vars' ]);
        add_action('template_redirect', [ $this, 'handle_ref_link' ]);
        add_action('woocommerce_checkout_order_processed', [ $this, 'on_order_processed' ], 10, 3);
    }

    public function add_rewrite() : void {
        add_rewrite_rule('^ref/([^/]+)/?$', 'index.php?aff_code=$matches[1]', 'top');
    }
    public function query_vars($vars) { $vars[] = 'aff_code'; return $vars; }

    /** Obsługa /ref/{kod}?to=/sciezka lub ?url=PELNY_URL (ta sama domena) */
    public function handle_ref_link() : void {
        $code = get_query_var('aff_code');
        if ( empty($code) ) { return; }
        $code = sanitize_title( wp_unslash($code) );

        global $wpdb;
        $partners = $wpdb->prefix . 'aff_partners';
        $clicks   = $wpdb->prefix . 'aff_clicks';

        $partner = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM $partners WHERE code = %s LIMIT 1", $code
        ) );

        $dest = $this->resolve_destination();
        if ( ! $partner || $partner->status === 'banned' ) {
            $this->redirect($dest);
        }

        $opts = get_option(Settings::OPTION_KEY, Settings::defaults());
        $ttl_days = max(0, (int)($opts['cookie_ttl'] ?? 30));
        $expire   = time() + $ttl_days * DAY_IN_SECONDS;

        // Ustawiamy cookie *raz*, w nowej wersji API
        setcookie('aff_code', $code, $expire, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        if ( COOKIEPATH !== SITECOOKIEPATH ) {
            setcookie('aff_code', $code, $expire, SITECOOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }

        // Lekki log kliknięcia (hash IP/UA)
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip_hash = $ip ? hash('sha256', wp_salt('auth').'|'.$ip) : null;
        $ua_hash = $ua ? hash('sha256', $ua) : null;

        $wpdb->insert($clicks, [
            'partner_id' => (int)$partner->id,
            'source_url' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : null,
            'dest_url'   => $dest,
            'ip_hash'    => $ip_hash,
            'ua_hash'    => $ua_hash,
            'session_id' => wp_get_session_token(),
            'meta_json'  => wp_json_encode([ 'aff_code'=>$code ]),
        ], [ '%d','%s','%s','%s','%s','%s','%s' ]);

        $this->redirect($dest);
    }

    /** Tworzenie wstępnego referral przy złożeniu zamówienia */
    public function on_order_processed( $order_id, $posted_data, $order ) : void {
        if ( ! class_exists('WooCommerce') || ! $order_id ) { return; }

        $code = isset($_COOKIE['aff_code']) ? sanitize_title( wp_unslash($_COOKIE['aff_code']) ) : '';
        if ( empty($code) ) { return; }

        global $wpdb;
        $partners  = $wpdb->prefix . 'aff_partners';
        $referrals = $wpdb->prefix . 'aff_referrals';

        $partner = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, user_id, status, commission_rate FROM $partners WHERE code = %s LIMIT 1", $code
        ) );
        if ( ! $partner || $partner->status !== 'approved' ) { return; }

        $opts = get_option(Settings::OPTION_KEY, Settings::defaults());
        $deny_self = empty($opts['allow_self_purchase']); // domyślnie blokujemy samozakup

        // Samozakup?
        $order_user_id   = (int) $order->get_user_id();
        $order_email     = (string) $order->get_billing_email();
        $affiliate_user  = get_userdata( (int)$partner->user_id );
        $affiliate_email = $affiliate_user ? (string) $affiliate_user->user_email : '';

        if ( $deny_self && ( $order_user_id === (int)$partner->user_id || strcasecmp($order_email, $affiliate_email) === 0 ) ) {
            $wpdb->insert($referrals, [
                'order_id'          => (int)$order_id,
                'partner_id'        => (int)$partner->id,
                'order_total'       => (float)$order->get_total(),
                'commission_amount' => 0,
                'status'            => 'rejected',
                'locked_until'      => null,
                'reason'            => 'self_purchase',
            ], [ '%d','%d','%f','%f','%s','%s','%s' ]);
            return;
        }

        $rate = $partner->commission_rate !== null ? (float)$partner->commission_rate : (float)($opts['commission_rate'] ?? 10);
        $total = (float) $order->get_total();
        $commission = round( $total * ($rate/100), wc_get_price_decimals() );

        $lock_days = max(0, (int)($opts['lock_days'] ?? 14));
        $locked_until = $lock_days > 0 ? gmdate('Y-m-d H:i:s', time() + $lock_days * DAY_IN_SECONDS) : null;

        $wpdb->insert($referrals, [
            'order_id'          => (int)$order_id,
            'partner_id'        => (int)$partner->id,
            'order_total'       => $total,
            'commission_amount' => $commission,
            'status'            => 'pending',
            'locked_until'      => $locked_until,
            'reason'            => null,
        ], [ '%d','%d','%f','%f','%s','%s','%s' ]);

        if ( $locked_until ) {
            wp_schedule_single_event( strtotime($locked_until), 'affilite_maybe_approve_referral', [ (int)$order_id ] );
        }
    }

    /* ====================== helpers ====================== */

    private function resolve_destination() : string {
        $home = home_url('/');
        $to   = isset($_GET['to']) ? wp_unslash($_GET['to']) : '';
        $url  = isset($_GET['url']) ? wp_unslash($_GET['url']) : '';

        $candidate = $to ?: $url;
        if ( empty($candidate) ) { return $home; }

        if ( str_starts_with($candidate, '/') ) {
            return home_url($candidate);
        }

        $candidate = esc_url_raw($candidate);
        $home_host = wp_parse_url($home, PHP_URL_HOST);
        $cand_host = wp_parse_url($candidate, PHP_URL_HOST);

        if ( $cand_host && $home_host && strtolower($cand_host) === strtolower($home_host) ) {
            return $candidate;
        }
        return $home;
    }

    private function redirect(string $url) : void {
        wp_safe_redirect($url, 302);
        exit;
    }

    /** Upewnia się, że user ma rekord partnera (używane w portalu) */
    public static function ensure_partner_for_user( int $user_id ) : ?object {
        if ( $user_id <= 0 ) return null;
        global $wpdb;
        $partners = $wpdb->prefix . 'aff_partners';

        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $partners WHERE user_id=%d LIMIT 1", $user_id) );
        if ( $row ) return $row;

        $opts = get_option(Settings::OPTION_KEY, Settings::defaults());
        $status = ($opts['join_mode'] ?? 'auto') === 'manual' ? 'pending' : 'approved';

        $code = self::generate_unique_code();

        $wpdb->insert($partners, [
            'user_id'   => $user_id,
            'code'      => $code,
            'status'    => $status,
            'created_at'=> current_time('mysql', true),
            'updated_at'=> current_time('mysql', true),
        ], [ '%d','%s','%s','%s','%s' ]);

        return $wpdb->get_row( $wpdb->prepare("SELECT * FROM $partners WHERE id=%d", $wpdb->insert_id) );
    }

    private static function generate_unique_code(int $length=8) : string {
        global $wpdb;
        $partners = $wpdb->prefix . 'aff_partners';
        do {
            $candidate = strtolower( wp_generate_password( $length, false, false ) );
            $exists = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(1) FROM $partners WHERE code=%s", $candidate) );
        } while ( $exists > 0 );
        return $candidate;
    }
}
