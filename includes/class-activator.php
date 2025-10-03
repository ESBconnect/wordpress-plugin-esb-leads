<?php
if (!defined('ABSPATH')) { exit; }

class ESBConnect_Leads_Activator {
	public static function activate() {
		self::maybe_create_table();
		$defaults = [
			'enabled' => false,
			'hmac_secret' => '',
			'lambda_url' => '',
		];
		$existing = get_option(ESB_LEADS_OPTION_KEY);
		if (!is_array($existing)) {
			update_option(ESB_LEADS_OPTION_KEY, $defaults, false);
		}
	}

	private static function maybe_create_table() {
		global $wpdb;
		$table = esb_leads_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			form_name VARCHAR(255) DEFAULT '' NOT NULL,
			client_account_id VARCHAR(255) DEFAULT '' NOT NULL,
			payload LONGTEXT NOT NULL,
			signature VARCHAR(255) DEFAULT '' NOT NULL,
			lambda_status VARCHAR(50) DEFAULT '' NOT NULL,
			lambda_response LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY client_account_id (client_account_id)
		) {$charset_collate};";
		dbDelta($sql);
	}
}

