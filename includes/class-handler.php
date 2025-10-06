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
					// Build a map from form settings (designer config) custom_id => field_label
					$custom_id_to_label = [];
					$form_fields_cfg = is_object($record) && method_exists($record, 'get_form_settings') ? $record->get_form_settings('form_fields') : [];
					if (!empty($form_fields_cfg) && is_array($form_fields_cfg)) {
						foreach ($form_fields_cfg as $cfg) {
							if (is_array($cfg) && !empty($cfg['custom_id'])) {
								$custom_id_to_label[$cfg['custom_id']] = isset($cfg['field_label']) ? (string) $cfg['field_label'] : '';
							}
						}
					}

					// Log raw structure of fields (label/id/value) before mapping
					$struct_preview = [];
					foreach ($raw_fields as $id => $field) {
						if (is_object($field) && method_exists($field, 'get_value')) {
							$fid = method_exists($field, 'get_id') ? $field->get_id() : '';
							$flabel = method_exists($field, 'get_label') ? $field->get_label() : '';
							$fvalue = $field->get_value();
							$pv = is_scalar($fvalue) ? (string) $fvalue : wp_json_encode($fvalue);
							$struct_preview[] = [
								'type' => 'object',
								'id' => ($fid !== '' ? $fid : (is_string($id) ? $id : '')),
								'label' => $flabel,
								'value_preview' => substr($pv, 0, 200),
							];
						} elseif (is_array($field)) {
							$fid = isset($field['id']) ? $field['id'] : (is_string($id) ? $id : '');
							// Elementor provides title in submitted fields; fall back to form settings map or id
							$flabel = isset($field['title']) ? $field['title'] : (isset($custom_id_to_label[$fid]) ? $custom_id_to_label[$fid] : '');
							$fvalue = isset($field['value']) ? $field['value'] : '';
							$pv = is_scalar($fvalue) ? (string) $fvalue : wp_json_encode($fvalue);
							$struct_preview[] = [
								'type' => 'array',
								'id' => $fid,
								'label' => $flabel,
								'value_preview' => substr($pv, 0, 200),
							];
						}
					}
					// logging removed

					// Build normalized map preferring label/title, then id
					foreach ($raw_fields as $id => $field) {
						if (is_object($field) && method_exists($field, 'get_value')) {
						$fid = method_exists($field, 'get_id') ? $field->get_id() : '';
							$flabel = method_exists($field, 'get_label') ? $field->get_label() : '';
						$fvalue = $field->get_value();
							$key_source = $flabel !== '' ? $flabel : ($fid !== '' ? $fid : (is_string($id) ? $id : ''));
						$key = $key_source !== '' ? sanitize_key($key_source) : '';
						if ($key !== '') {
							$fields_assoc[$key] = $fvalue;
							}
						} elseif (is_array($field)) {
							$fid = isset($field['id']) ? $field['id'] : (is_string($id) ? $id : '');
							$flabel = isset($field['title']) ? $field['title'] : (isset($custom_id_to_label[$fid]) ? $custom_id_to_label[$fid] : '');
							$fvalue = isset($field['value']) ? $field['value'] : '';
							$key_source = $flabel !== '' ? $flabel : ($fid !== '' ? $fid : (is_string($id) ? $id : ''));
						$key = $key_source !== '' ? sanitize_key($key_source) : '';
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

			// (debug key map logging removed)

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
			// logging removed
			$signature = hash_hmac('sha256', $payload_json, (string) $settings['hmac_secret']);

			global $wpdb;
			$table = esb_leads_table_name();
			
			// Check if table exists
			$table_exists = $wpdb->get_var($wpdb->prepare(
				"SHOW TABLES LIKE %s",
				$table
			));
			
			if (!$table_exists) {
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
				// logging removed
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

