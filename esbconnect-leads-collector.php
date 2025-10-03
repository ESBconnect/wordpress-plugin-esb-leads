<?php
/*
Plugin Name: ESBConnect Leads Collector
Plugin URI: https://esbconnect.com
Description: Captures Elementor form submissions, validates clientaccountid, signs payload with HMAC, and posts to AWS Lambda. Includes admin settings and submissions list.
Version: 1.0.0
Author: ESBConnect
*/

if (!defined('ABSPATH')) {
	exit;
}

// Plugin constants
define('ESB_LEADS_PLUGIN_FILE', __FILE__);
define('ESB_LEADS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ESB_LEADS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ESB_LEADS_OPTION_KEY', 'esbconnect_leads_settings');
define('ESB_LEADS_TABLE_SLUG', 'esbconnect_leads_submissions');

// Includes
require_once ESB_LEADS_PLUGIN_DIR . 'includes/helpers.php';
require_once ESB_LEADS_PLUGIN_DIR . 'includes/class-activator.php';
require_once ESB_LEADS_PLUGIN_DIR . 'includes/class-admin.php';
require_once ESB_LEADS_PLUGIN_DIR . 'includes/class-handler.php';
require_once ESB_LEADS_PLUGIN_DIR . 'includes/class-list-table.php';

// Hooks
register_activation_hook(__FILE__, ['ESBConnect_Leads_Activator', 'activate']);

// Bootstrap instances
new ESBConnect_Leads_Admin();
new ESBConnect_Leads_Handler();
