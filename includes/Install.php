<?php
namespace AffiLite;

if ( ! defined('ABSPATH') ) { exit; }

class Install {
    /** Uruchamiane przy aktywacji wtyczki */
    public static function activate() : void {
        self::create_tables();
        flush_rewrite_rules(); // potrzebne dla /ref/{kod}
    }

    /** Tworzenie/aktualizacja tabel (dbDelta) */
    public static function create_tables() : void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $partners  = $wpdb->prefix . 'aff_partners';
        $clicks    = $wpdb->prefix . 'aff_clicks';
        $referrals = $wpdb->prefix . 'aff_referrals';
        $payouts   = $wpdb->prefix . 'aff_payouts';

        $sql = [];

        $sql[] = <<<SQL
CREATE TABLE {$partners} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  commission_rate DECIMAL(6,3) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY user_idx (user_id),
  KEY status_idx (status)
) {$charset};
SQL;

        $sql[] = <<<SQL
CREATE TABLE {$clicks} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id BIGINT UNSIGNED NOT NULL,
  source_url TEXT NULL,
  dest_url TEXT NULL,
  ip_hash CHAR(64) NULL,
  ua_hash CHAR(64) NULL,
  session_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  meta_json LONGTEXT NULL,
  PRIMARY KEY (id),
  KEY partner_idx (partner_id),
  KEY created_idx (created_at)
) {$charset};
SQL;

        $sql[] = <<<SQL
CREATE TABLE {$referrals} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  partner_id BIGINT UNSIGNED NOT NULL,
  order_total DECIMAL(18,4) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  locked_until DATETIME NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY order_unique (order_id),
  KEY partner_idx (partner_id),
  KEY status_idx (status)
) {$charset};
SQL;

        $sql[] = <<<SQL
CREATE TABLE {$payouts} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,4) NOT NULL DEFAULT 0,
  method VARCHAR(20) NOT NULL,
  details_json LONGTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|processing|paid|rejected
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY partner_idx (partner_id),
  KEY status_idx (status)
) {$charset};
SQL;

        foreach ($sql as $q) {
            dbDelta($q);
        }
    }
}
