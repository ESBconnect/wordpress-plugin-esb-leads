<?php
if (!defined('ABSPATH')) { exit; }

class ESBConnect_Leads_Admin {
	public function __construct() {
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('load-toplevel_page_esbconnect-leads', [$this, 'add_screen_options']);
		add_filter('set-screen-option', [$this, 'set_screen_option'], 10, 3);
	}

	public function add_screen_options() {
		$screen = get_current_screen();
		if ($screen) {
			$screen->add_option('per_page', [
				'label' => 'Submissions per page',
				'default' => 20,
				'option' => 'esb_leads_per_page'
			]);
		}
		
		// Add help tab
		$screen->add_help_tab([
			'id' => 'esb_leads_help',
			'title' => 'ESB Leads Help',
			'content' => '<p>This page shows all validated form submissions that contain a client_account_id field.</p>'
		]);
	}

	public function set_screen_option($status, $option, $value) {
		if ('esb_leads_per_page' === $option) {
			return $value;
		}
		return $status;
	}

	public function register_admin_menu() {
		$capability = 'manage_options';
		$menu_slug = 'esbconnect-leads';
		add_menu_page(
			'ESB Leads',
			'ESB Leads',
			$capability,
			$menu_slug,
			[$this, 'render_submissions_page'],
			'dashicons-feedback',
			58
		);
		add_submenu_page(
			$menu_slug,
			'Submissions',
			'Submissions',
			$capability,
			$menu_slug,
			[$this, 'render_submissions_page']
		);
		add_submenu_page(
			$menu_slug,
			'Settings',
			'Settings',
			$capability,
			'esbconnect-leads-settings',
			[$this, 'render_settings_page']
		);
	}

	public function register_settings() {
		register_setting(ESB_LEADS_OPTION_KEY, ESB_LEADS_OPTION_KEY, [
			'type' => 'array',
			'sanitize_callback' => [$this, 'sanitize_settings'],
			'default' => [
				'enabled' => false,
				'hmac_secret' => '',
				'lambda_url' => '',
			],
		]);

		add_settings_section(
			'esbconnect_leads_main',
			'Settings',
			function () {
				echo '<p>Configure HMAC secret, Lambda URL, and plugin status.</p>';
			},
			ESB_LEADS_OPTION_KEY
		);

		add_settings_field(
			'enabled',
			'Enable Plugin',
			[$this, 'field_enabled'],
			ESB_LEADS_OPTION_KEY,
			'esbconnect_leads_main'
		);

		add_settings_field(
			'hmac_secret',
			'HMAC Secret',
			[$this, 'field_hmac_secret'],
			ESB_LEADS_OPTION_KEY,
			'esbconnect_leads_main'
		);

		add_settings_field(
			'lambda_url',
			'Lambda Endpoint URL',
			[$this, 'field_lambda_url'],
			ESB_LEADS_OPTION_KEY,
			'esbconnect_leads_main'
		);
	}

	public function sanitize_settings($input) {
		$sanitized = [];
		$sanitized['enabled'] = !empty($input['enabled']);
		$sanitized['hmac_secret'] = isset($input['hmac_secret']) ? sanitize_text_field($input['hmac_secret']) : '';
		$sanitized['lambda_url'] = isset($input['lambda_url']) ? esc_url_raw($input['lambda_url']) : '';
		return $sanitized;
	}

	public function field_enabled() {
		$settings = esb_leads_get_settings();
		echo '<label><input type="checkbox" name="' . esc_attr(ESB_LEADS_OPTION_KEY) . '[enabled]" value="1" ' . checked(true, (bool)$settings['enabled'], false) . ' /> Enable processing</label>';
		echo '<p class="description">Enable this plugin to start capturing and processing Elementor form submissions.</p>';
	}

	public function field_hmac_secret() {
		$settings = esb_leads_get_settings();
		echo '<input type="password" autocomplete="new-password" size="50" name="' . esc_attr(ESB_LEADS_OPTION_KEY) . '[hmac_secret]" value="' . esc_attr($settings['hmac_secret']) . '" />';
		echo '<p class="description">Secret key for HMAC signature validation. Must match the HMAC_SECRET in your Lambda function environment variables.</p>';
	}

	public function field_lambda_url() {
		$settings = esb_leads_get_settings();
		echo '<input type="url" size="70" placeholder="https://..." name="' . esc_attr(ESB_LEADS_OPTION_KEY) . '[lambda_url]" value="' . esc_attr($settings['lambda_url']) . '" />';
		echo '<p class="description">URL of your AWS Lambda function endpoint. For local testing, use: <code>http://localhost:3001/api/submit</code></p>';
	}

	public function render_settings_page() {
		echo '<div class="wrap">';
		echo '<h1>ESBConnect Leads Collector Settings</h1>';
		// Brief note and primary settings form first
		echo '<p class="description" style="margin-top:10px;">Enter your Lambda URL and HMAC secret, then enable processing. Explanations and examples are shown below.</p>';
		echo '<form method="post" action="options.php">';
		settings_fields(ESB_LEADS_OPTION_KEY);
		do_settings_sections(ESB_LEADS_OPTION_KEY);
		submit_button('Save Settings');
		echo '</form>';
		
		// Description section (now below the form)
		echo '<div class="notice notice-info" style="margin-top:20px;">';
		echo '<h3>Plugin Overview</h3>';
		echo '<p>This plugin captures Elementor form submissions that contain a <code>client_account_id</code> field, validates the data, signs it with HMAC security, and sends it to your AWS Lambda endpoint for processing.</p>';
		echo '</div>';
		
		// Setup instructions (below)
		echo '<div class="card" style="max-width: 800px;">';
		echo '<h3>Setup Instructions</h3>';
		echo '<ol>';
		echo '<li><strong>Configure your Elementor form:</strong> Add a hidden field named <code>client_account_id</code> with the appropriate client account ID value.</li>';
		echo '<li><strong>Set your Lambda endpoint:</strong> Enter your AWS Lambda function URL in the settings above.</li>';
		echo '<li><strong>Configure HMAC secret:</strong> Use the same secret as your Lambda <code>HMAC_SECRET</code>.</li>';
		echo '<li><strong>Enable the plugin:</strong> Toggle the plugin on to start processing submissions.</li>';
		echo '</ol>';
		echo '</div>';
		
		// Example images section (below)
		echo '<div class="card" style="max-width: 1000px;">';
		echo '<h3>Elementor Form Setup Examples</h3>';
		echo '<p>Designers need to include a <code>client_account_id</code> field as a hidden field type and set the default value to the relevant client account ID.</p>';
		
		// First image
		echo '<div style="text-align: center; margin: 20px 0;">';
		echo '<h4>Step 1: Adding the Hidden Field</h4>';
		echo '<img src="' . plugin_dir_url(dirname(__FILE__)) . 'assets/Screenshot 2025-10-02 174506.png" alt="Adding client_account_id field in Elementor" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" />';
		echo '<p><em>Add a hidden field named "client_account_id" to your Elementor form</em></p>';
		echo '</div>';
		
		// Second image
		echo '<div style="text-align: center; margin: 20px 0;">';
		echo '<h4>Step 2: Setting the Default Value</h4>';
		echo '<img src="' . plugin_dir_url(dirname(__FILE__)) . 'assets/Screenshot 2025-10-02 174552.png" alt="Setting default value for client_account_id" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" />';
		echo '<p><em>Set the default value to the specific client account ID for this form</em></p>';
		echo '</div>';
		
		echo '</div>';
		echo '</div>';
	}

	public function render_submissions_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new ESBConnect_Leads_Table(esb_leads_table_name());
		
		// Handle search
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$table->search = $search;
		
		// Prepare items after setting search
		$table->prepare_items();
		
		echo '<div class="wrap">';
		echo '<h1>Validated Submissions</h1>';
		
		// Search form
		echo '<form method="get" class="search-form">';
		echo '<input type="hidden" name="page" value="esbconnect-leads" />';
		echo '<p class="search-box">';
		echo '<label class="screen-reader-text" for="post-search-input">Search:</label>';
		echo '<input type="search" id="post-search-input" name="s" value="' . esc_attr($search) . '" />';
		submit_button('Search', 'button', false, false, ['id' => 'search-submit']);
		echo '</p>';
		echo '</form>';
		
		// Display the table with pagination
		$table->display();
		
		// Debug screen options
		if (current_user_can('manage_options') && isset($_GET['debug'])) {
			$screen = get_current_screen();
			$per_page = $table->get_items_per_page('esb_leads_per_page', 20);
			echo '<div style="background: #f0f0f0; padding: 10px; margin: 10px 0; border: 1px solid #ccc;">';
			echo '<h3>Screen Options Debug:</h3>';
			echo '<p>Screen ID: ' . ($screen ? $screen->id : 'No screen') . '</p>';
			echo '<p>Items per page: ' . $per_page . '</p>';
			echo '<p>User option: ' . get_user_meta(get_current_user_id(), 'esb_leads_per_page', true) . '</p>';
			echo '</div>';
		}
		
		echo '</div>';
	}
}

