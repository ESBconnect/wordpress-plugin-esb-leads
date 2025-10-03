<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if (!class_exists('ESBConnect_Leads_Table')) {
	class ESBConnect_Leads_Table extends WP_List_Table {
		private $table;
		public $search = '';

		public function __construct($table_name = null) {
			parent::__construct([
				'singular' => 'submission',
				'plural' => 'submissions',
				'ajax' => false,
			]);
			$this->table = $table_name ? $table_name : esb_leads_table_name();
		}

		public function display() {
			parent::display();
		}

		public function get_columns() {
			return [
				'created_at' => 'Date',
				'form_name' => 'Form',
				'client_account_id' => 'Client Account ID',
				'lambda_status' => 'Lambda Status',
				'payload' => 'Payload',
			];
		}

		protected function get_sortable_columns() {
			return [
				'created_at' => ['created_at', true],
				'form_name' => ['form_name', false],
				'client_account_id' => ['client_account_id', false],
				'lambda_status' => ['lambda_status', false],
			];
		}

		protected function column_default($item, $column_name) {
			switch ($column_name) {
				case 'payload':
					$max = 120;
					$text = isset($item['payload']) ? (string) $item['payload'] : '';
					if (strlen($text) > $max) {
						$text = substr($text, 0, $max) . '…';
					}
					return '<code style="white-space:pre-wrap;word-break:break-word;">' . esc_html($text) . '</code>';
				default:
					return isset($item[$column_name]) ? esc_html((string) $item[$column_name]) : '';
			}
		}

		public function prepare_items() {
			global $wpdb;
			$per_page = $this->get_items_per_page('esb_leads_per_page', 20);
			$current_page = $this->get_pagenum();
			$offset = ($current_page - 1) * $per_page;
			$orderby = isset($_GET['orderby']) ? sanitize_sql_orderby(wp_unslash($_GET['orderby'])) : 'created_at';
			$order = (isset($_GET['order']) && strtolower((string) $_GET['order']) === 'asc') ? 'ASC' : 'DESC';
			$valid_orderby = ['created_at', 'form_name', 'client_account_id', 'lambda_status'];
			if (!in_array($orderby, $valid_orderby, true)) {
				$orderby = 'created_at';
			}
			$where = 'WHERE 1=1';
			$params = [];
			if ($this->search !== '') {
				$where .= ' AND (client_account_id LIKE %s OR form_name LIKE %s OR lambda_status LIKE %s)';
				$like = '%' . $wpdb->esc_like($this->search) . '%';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
			if (empty($params)) {
				$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} {$where}");
				$items = $wpdb->get_results($wpdb->prepare(
					"SELECT id, created_at, form_name, client_account_id, lambda_status, payload FROM {$this->table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
					[$per_page, $offset]
				), ARRAY_A);
			} else {
				$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} {$where}", $params));
				$items = $wpdb->get_results($wpdb->prepare(
					"SELECT id, created_at, form_name, client_account_id, lambda_status, payload FROM {$this->table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
					array_merge($params, [$per_page, $offset])
				), ARRAY_A);
			}

			$this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'created_at'];
			$this->items = is_array($items) ? $items : [];
			$this->set_pagination_args([
				'total_items' => $total,
				'per_page' => $per_page,
				'total_pages' => ceil($total / $per_page),
			]);
		}


	}
}

