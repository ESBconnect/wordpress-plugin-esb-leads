<?php
if (!defined('ABSPATH')) { exit; }

class ESBConnect_Leads_Handler {
	public function __construct() {
		add_action('elementor_pro/forms/new_record', [$this, 'handle_elementor_submission'], 10, 2);
	}

	public function handle_elementor_submission($record, $handler) {
		$settings = esb_leads_get_settings();
		if (empty($settings['enabled'])) {
			return;
		}
		if (empty($settings['lambda_url']) || empty($settings['hmac_secret'])) {
			return;
		}

		// Note: Skipping form validation for localhost testing
		// Email/SMTP issues on localhost can cause false "failed" status
		// but the form data is still captured successfully

		try {
			$form_name = '';
			if (is_object($record) && method_exists($record, 'get_form_settings')) {
				$form_name = (string) $record->get_form_settings('form_name');
			}

			$fields_assoc = [];
			$client_account_id = '';
			if (is_object($record) && method_exists($record, 'get')) {
				$raw_fields = $record->get('fields');
				if (is_array($raw_fields)) {
					foreach ($raw_fields as $field) {
						if (is_object($field) && method_exists($field, 'get_value')) {
							$fid = method_exists($field, 'get_id') ? $field->get_id() : '';
							$flabel = method_exists($field, 'get_label') ? $field->get_label() : '';
							$fvalue = $field->get_value();
							$key = $fid ?: $flabel;
							if ($key !== '') {
								$fields_assoc[$key] = $fvalue;
							}
						} elseif (is_array($field)) {
							$fid = isset($field['id']) ? $field['id'] : '';
							$flabel = isset($field['label']) ? $field['label'] : '';
							$fvalue = isset($field['value']) ? $field['value'] : '';
							$key = $fid ?: $flabel;
							if ($key !== '') {
								$fields_assoc[$key] = $fvalue;
							}
						}
					}
				}
			}

			if (empty($fields_assoc) && is_object($record) && method_exists($record, 'get_formatted_data')) {
				$fields_assoc = (array) $record->get_formatted_data();
			}

			foreach ($fields_assoc as $key => $value) {
				if (is_string($key) && strcasecmp($key, 'client_account_id') === 0) {
					$client_account_id = is_array($value) ? wp_json_encode($value) : (string) $value;
					break;
				}
			}

			if ($client_account_id === '' || $client_account_id === null) {
				return;
			}

				$payload = [
					'form_name' => $form_name,
					'client_account_id' => $client_account_id,
					'fields' => $fields_assoc,
				'site' => [
					'home_url' => home_url('/'),
					'site_url' => site_url('/'),
					'blogname' => get_bloginfo('name'),
				],
				'timestamp' => time(),
			];

			$payload_json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$signature = hash_hmac('sha256', $payload_json, (string) $settings['hmac_secret']);

			global $wpdb;
			$table = esb_leads_table_name();
			
			// Check if table exists
			$table_exists = $wpdb->get_var($wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$table
			));
			
			if (!$table_exists) {
				error_log('ESBConnect Leads: Database table does not exist: ' . $table);
				return;
			}
			
			$wpdb->insert(
				$table,
				[
					'created_at' => current_time('mysql', 1),
					'form_name' => $form_name,
					'client_account_id' => $client_account_id,
					'payload' => $payload_json,
					'signature' => $signature,
					'lambda_status' => 'pending',
					'lambda_response' => '',
				]
			);
			$insert_id = (int) $wpdb->insert_id;
			
			if ($wpdb->last_error) {
				error_log('ESBConnect Leads Database Error: ' . $wpdb->last_error);
				return;
			}

			// Send to Lambda endpoint
			$args = [
				'timeout' => 10,
				'headers' => [
					'Content-Type' => 'application/json',
					'X-ESB-HMAC' => $signature,
				],
				'body' => $payload_json,
			];
			
			$log_url = isset($settings['lambda_url']) ? (string) $settings['lambda_url'] : '';

			$t0 = microtime(true);
			$response = wp_remote_post($log_url, $args);
			$elapsed_ms = (int) round((microtime(true) - $t0) * 1000);
			$status = 'sent';
			$resp_body = '';
			if (is_wp_error($response)) {
				$status = 'error';
				$resp_body = $response->get_error_message();
				error_log('ESBConnect Leads ← Lambda: WP_Error after ' . $elapsed_ms . 'ms: ' . $resp_body);
				error_log('ESBConnect Leads ← Lambda: WP_Error object: ' . print_r($response, true));
			} else {
				$code = (int) wp_remote_retrieve_response_code($response);
				$resp_body = (string) wp_remote_retrieve_body($response);
				$resp_headers = wp_remote_retrieve_headers($response);
				$req_id = '';
				if (is_array($resp_headers)) {
					$h = array_change_key_case($resp_headers, CASE_LOWER);
					$req_id = isset($h['x-amzn-requestid']) ? $h['x-amzn-requestid'] : (isset($h['x-amzn-trace-id']) ? $h['x-amzn-trace-id'] : '');
				}
				if ($code < 200 || $code >= 300) {
					$status = 'error';
				}
				if ($code < 200 || $code >= 300) {
					$status = 'error';
				}
			}

			$wpdb->update(
				$table,
				[
					'lambda_status' => $status,
					'lambda_response' => $resp_body,
				],
				['id' => $insert_id]
			);
		} catch (\Throwable $e) {
			// Log the error for debugging
			error_log('ESBConnect Leads Error: ' . $e->getMessage());
			error_log('ESBConnect Leads Error File: ' . $e->getFile() . ' Line: ' . $e->getLine());
			// Silent failure to avoid breaking user flow
		}
	}

	private function is_form_submission_successful($record, $handler) {
		// Check if the record has any validation errors
		if (is_object($record) && method_exists($record, 'get_errors')) {
			$errors = $record->get_errors();
			if (!empty($errors)) {
				error_log('ESBConnect Leads: Form has validation errors: ' . print_r($errors, true));
				return false;
			}
		}

		// Check if the handler has any errors
		if (is_object($handler) && method_exists($handler, 'get_errors')) {
			$handler_errors = $handler->get_errors();
			if (!empty($handler_errors)) {
				error_log('ESBConnect Leads: Handler has errors: ' . print_r($handler_errors, true));
				return false;
			}
		}

		// Check if the record is valid
		if (is_object($record) && method_exists($record, 'is_valid')) {
			if (!$record->is_valid()) {
				error_log('ESBConnect Leads: Record is not valid');
				return false;
			}
		}

		// Check if the form was submitted successfully by looking at the record status
		if (is_object($record) && method_exists($record, 'get_status')) {
			$status = $record->get_status();
			if ($status !== 'success' && $status !== 'completed') {
				error_log('ESBConnect Leads: Form status is not successful: ' . $status);
				return false;
			}
		}

		// If we get here, assume the submission was successful
		return true;
	}
}

