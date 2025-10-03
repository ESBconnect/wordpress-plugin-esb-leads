<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('esb_leads_table_name')) {
	function esb_leads_table_name() {
		global $wpdb;
		return $wpdb->prefix . ESB_LEADS_TABLE_SLUG;
	}
}

if (!function_exists('esb_leads_get_settings')) {
	function esb_leads_get_settings() {
		$settings = get_option(ESB_LEADS_OPTION_KEY, []);
		return wp_parse_args($settings, [
			'enabled' => false,
			'hmac_secret' => '',
			'lambda_url' => '',
		]);
	}
}

