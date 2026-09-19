<?php
/**
 * Certificate Manager Webhook Service
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook service
 */
class WebhookService {
	
	/**
	 * Webhook repository
	 *
	 * @var mixed
	 */
	private $webhook_repo;
	
	/**
	 * Log repository
	 *
	 * @var mixed
	 */
	private $log_repo;
	
	/**
	 * Constructor
	 *
	 * @param mixed $webhook_repo Webhook repository
	 * @param mixed $log_repo Log repository
	 */
	public function __construct( $webhook_repo, $log_repo ) {
		$this->webhook_repo = $webhook_repo;
		$this->log_repo = $log_repo;
	}
	
	/**
	 * Dispatch webhook
	 *
	 * @param string $event Event name
	 * @param array $certificate_data Certificate data
	 * @param int $certificate_id Certificate ID
	 * @return array
	 */
	public function dispatch_webhook( string $event, array $certificate_data, int $certificate_id, ?array $webhook_ids = null ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_webhooks';
		
		// Get active webhooks for this event
		$webhooks = $wpdb->get_results(
			"SELECT * FROM {$table_name} WHERE is_active = 1",
			ARRAY_A
		);
		
		$results = array();
		
		$webhook_ids = is_array( $webhook_ids ) ? array_values( array_filter( array_map( 'absint', $webhook_ids ) ) ) : null;
		foreach ( $webhooks as $webhook ) {
			if ( is_array( $webhook_ids ) && ( empty( $webhook_ids ) || ! in_array( absint( $webhook['id'] ), $webhook_ids, true ) ) ) {
				continue;
			}
			$webhook['payload_fields'] = json_decode( $webhook['payload_fields'] ?? '[]', true );
			$webhook['payload_fields'] = is_array( $webhook['payload_fields'] ) ? $webhook['payload_fields'] : array();
			// Check if webhook listens to this event
			$events = json_decode( $webhook['events'], true );
			if ( ! in_array( $event, $events ) ) {
				continue;
			}
			
			// Create delivery record
			$delivery_id = $this->create_delivery( $webhook['id'], $certificate_id, $event, $certificate_data, $webhook );
			
			// Send webhook
			$result = $this->send_webhook_delivery( $delivery_id, $webhook, $event, $certificate_data );
			
			$results[] = array(
				'webhook_id' => $webhook['id'],
				'delivery_id' => $delivery_id,
				'status' => $result ? 'success' : 'failed',
			);
		}
		
		return $results;
	}
	
	/**
	 * Create webhook delivery record
	 *
	 * @param int $webhook_id Webhook ID
	 * @param int $certificate_id Certificate ID
	 * @param string $event Event name
	 * @param array $data Data
	 * @return int Delivery ID
	 */
	private function create_delivery( int $webhook_id, int $certificate_id, string $event, array $data, array $webhook ): int {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_webhook_deliveries';
		
		$payload = $this->build_payload( $event, $certificate_id, $data, $webhook['payload_fields'] ?? array() );
		
		$signature = $this->generate_signature( $payload, $webhook['secret'] );
		
		$wpdb->insert( $table_name, array(
			'webhook_id' => $webhook_id,
			'certificate_id' => $certificate_id,
			'event' => $event,
			'payload' => wp_json_encode( $payload ),
			'headers' => wp_json_encode( array(
				'Content-Type' => 'application/json',
				'X-Certificate-Manager-Signature' => $signature,
			) ),
			'request_body' => $payload,
			'retry_count' => 0,
			'max_retries' => 3,
			'status' => 'pending',
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Build webhook payload
	 *
	 * @param string $event Event name
	 * @param int $certificate_id Certificate ID
	 * @param array $data Data
	 * @return string
	 */
	private function build_payload( string $event, int $certificate_id, array $data, array $payload_fields = array() ): string {
		$available_fields = array(
			'event' => $event,
			'certificate_id' => $certificate_id,
			'internal_id' => $data['internal_id'] ?? null,
			'certificate_number' => $data['certificate_number'] ?? null,
			'recipient_name' => $data['recipient_name'] ?? null,
			'recipient_email' => $data['recipient_email'] ?? null,
			'status' => $data['status'] ?? null,
			'issue_date' => $data['issue_date'] ?? null,
			'expiry_date' => $data['expiry_date'] ?? null,
			'template_id' => $data['template_id'] ?? null,
			'template_name' => $data['template_name'] ?? null,
			'template_fields' => $data['template_fields'] ?? array(),
			'certificates' => $data['certificates'] ?? array(),
			'certificate_count' => $data['certificate_count'] ?? null,
			'wallet_claim_url' => $data['wallet_claim_url'] ?? null,
			'verification_url' => $this->get_verification_url( $certificate_id ),
			'issued_at' => current_time( 'mysql' ),
		);
		$payload_fields = array_values( array_filter( array_map( 'sanitize_key', $payload_fields ) ) );
		if ( empty( $payload_fields ) ) {
			$payload_fields = array_keys( $available_fields );
		}
		return wp_json_encode( array_intersect_key( $available_fields, array_flip( $payload_fields ) ) );
	}
	
	/**
	 * Get verification URL for certificate
	 *
	 * @param int $certificate_id Certificate ID
	 * @return string
	 */
	private function get_verification_url( int $certificate_id ): string {
		global $wpdb;
		
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$certificate = $wpdb->get_row( $wpdb->prepare(
			"SELECT verification_token FROM {$cert_table} WHERE id = %d",
			$certificate_id
		), ARRAY_A );
		
		if ( ! $certificate ) {
			return '';
		}
		
		$settings = new \CertificateManager\Core\Settings();
		return add_query_arg( 'v', substr( $certificate['verification_token'], 0, 16 ), $settings->get_verification_base_url() );
	}
	
	/**
	 * Generate HMAC signature
	 *
	 * @param string $payload Payload
	 * @param string $secret Secret
	 * @return string
	 */
	private function generate_signature( string $payload, string $secret ): string {
		return hash_hmac( 'sha256', $payload, $secret );
	}
	
	/**
	 * Send webhook delivery
	 *
	 * @param int $delivery_id Delivery ID
	 * @param array $webhook Webhook data
	 * @param string $event Event name
	 * @param array $data Data
	 * @return bool
	 */
	private function send_webhook_delivery( int $delivery_id, array $webhook, string $event, array $data ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_webhook_deliveries';
		
		$delivery = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$delivery_id
		), ARRAY_A );
		
		if ( ! $delivery ) {
			return false;
		}
		
		// Get delivery data
		$headers = json_decode( $delivery['headers'], true );
		$body = $delivery['request_body'];
		
		// Send webhook
		$response = wp_remote_post( $webhook['url'], array(
			'headers' => $headers,
			'body' => $body,
			'timeout' => 30,
		) );
		
		// Update delivery record
		if ( is_wp_error( $response ) ) {
			$this->mark_delivery_failed( $delivery, $response->get_error_message(), 0 );
			
			$this->log_repo->add_log( 'error', 'Webhook', sprintf(
				/* translators: %s: error message */
				__( 'Webhook delivery failed: %s', 'certificate-manager' ),
				$response->get_error_message()
			), array(
				'webhook_id' => $webhook['id'],
				'delivery_id' => $delivery_id,
				'event' => $event,
			) );
			
			return false;
		}
		
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		
		if ( $response_code >= 200 && $response_code < 300 ) {
			$result = $wpdb->update( $table_name, array(
				'response_code' => $response_code,
				'response_body' => $response_body,
				'status' => 'success',
				'updated_at' => current_time( 'mysql' ),
			), array( 'id' => $delivery_id ) );
			
			if ( false === $result ) {
				$this->log_repo->add_log( 'error', 'Webhook', __( 'Failed to update delivery status.', 'certificate-manager' ), array(
					'webhook_id' => $webhook['id'],
					'delivery_id' => $delivery_id,
				) );
			}
			
			$this->log_repo->add_log( 'info', 'Webhook', sprintf(
				/* translators: %d: HTTP response status code */
				__( 'Webhook delivered successfully (status %d)', 'certificate-manager' ),
				$response_code
			), array(
				'webhook_id' => $webhook['id'],
				'delivery_id' => $delivery_id,
				'event' => $event,
			) );
			return true;
		}
		
		$this->mark_delivery_failed( $delivery, $response_body, $response_code );
		
		$this->log_repo->add_log( 'warning', 'Webhook', sprintf(
			/* translators: %d: HTTP response status code */
			__( 'Webhook returned non-success status: %d', 'certificate-manager' ),
			$response_code
		), array(
			'webhook_id' => $webhook['id'],
			'delivery_id' => $delivery_id,
			'event' => $event,
		) );
		
		return false;
	}
	
	/**
	 * Persist a failed attempt and arrange the next attempt when retries remain.
	 */
	/**
	 * Persist a failed attempt and arrange the next attempt when retries remain.
	 *
	 * @param array  $delivery      Delivery record.
	 * @param string $response_body Response body.
	 * @param int    $response_code Response code.
	 */
	private function mark_delivery_failed( array $delivery, string $response_body, int $response_code ): void {
		global $wpdb;

		$retry_count = (int) $delivery['retry_count'] + 1;
		$result = $wpdb->update( $wpdb->prefix . 'certificate_manager_webhook_deliveries', array(
			'response_code' => $response_code,
			'response_body' => $response_body,
			'status' => 'failed',
			'retry_count' => $retry_count,
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => (int) $delivery['id'] ) );
		
		if ( false === $result ) {
			$this->log_repo->add_log( 'error', 'Webhook', __( 'Failed to update delivery failure status.', 'certificate-manager' ), array(
				'delivery_id' => (int) $delivery['id'],
				'error' => $wpdb->last_error,
			) );
		}

		if ( (int) $delivery['retry_count'] < (int) $delivery['max_retries'] ) {
			wp_schedule_single_event( time() + ( 60 * $retry_count ), 'certificate_manager_webhook_retry', array( (int) $delivery['id'] ) );
		}
	}

	/**
	 * Retry a failed delivery immediately when its scheduled retry is due.
	 *
	 * @param int $delivery_id Delivery ID.
	 * @return bool
	 */
	public function retry_delivery( int $delivery_id ): bool {
		global $wpdb;

		$delivery_table = $wpdb->prefix . 'certificate_manager_webhook_deliveries';
		$webhook_table = $wpdb->prefix . 'certificate_manager_webhooks';
		$delivery = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$delivery_table} WHERE id = %d",
			$delivery_id
		), ARRAY_A );

		if ( ! $delivery || 'success' === $delivery['status'] || (int) $delivery['retry_count'] > (int) $delivery['max_retries'] ) {
			return false;
		}

		$webhook = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$webhook_table} WHERE id = %d AND is_active = 1",
			$delivery['webhook_id']
		), ARRAY_A );
		if ( ! $webhook ) {
			return false;
		}

		$payload = (string) $delivery['request_body'];
		if ( '' === $payload ) {
			$payload = $this->build_payload( $delivery['event'], $delivery['certificate_id'], array() );
		}
		$signature = $this->generate_signature( $payload, $webhook['secret'] );
		$wpdb->update( $delivery_table, array(
			'request_body' => $payload,
			'headers' => wp_json_encode( array(
				'Content-Type' => 'application/json',
				'X-Certificate-Manager-Signature' => $signature,
			) ),
		), array( 'id' => $delivery_id ) );

		return $this->send_webhook_delivery( $delivery_id, $webhook, (string) $delivery['event'], array() );
	}
	
	/**
	 * Test webhook
	 *
	 * @param array $webhook Webhook data
	 * @return array
	 */
	public function test_webhook( array $webhook ): array {
		$test_payload = array(
			'event' => 'test',
			'certificate' => array(
				'id' => 0,
				'certificate_number' => 'TEST-001',
				'status' => 'test',
			),
			'verification_url' => home_url( '/?cm_test=true' ),
			'issued_at' => current_time( 'mysql' ),
		);
		
		$payload = wp_json_encode( $test_payload );
		$signature = $this->generate_signature( $payload, $webhook['secret'] );
		
		$response = wp_remote_post( $webhook['url'], array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Certificate-Manager-Signature' => $signature,
			),
			'body' => $payload,
			'timeout' => 10,
		) );
		
		$status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$success = ! is_wp_error( $response ) && $status_code >= 200 && $status_code < 300;
		$result = array(
			'success' => $success,
			'status_code' => $status_code,
			'message' => is_wp_error( $response ) ? $response->get_error_message() : ( $success ? __( 'Webhook test successful', 'certificate-manager' ) :
			/* translators: %d: HTTP status code returned */
			sprintf( __( 'Webhook returned HTTP %d.', 'certificate-manager' ), $status_code ) ),
			'response_body' => is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response ),
		);
		
		return $result;
	}
	
	/**
	 * Create webhook
	 *
	 * @param array $data Webhook data
	 * @return int|WP_Error Webhook ID or error
	 */
	public function create_webhook( array $data ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_webhooks';
		
		$secret = bin2hex( random_bytes( 32 ) );
		
		$wpdb->insert( $table_name, array(
			'name' => sanitize_text_field( $data['name'] ),
			'url' => esc_url_raw( $data['url'] ),
			'events' => wp_json_encode( $data['events'] ?? array() ),
			'is_active' => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
			'secret' => $secret,
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Get webhooks by event
	 *
	 * @param string $event Event name
	 * @return array
	 */
	public function get_webhooks_by_event( string $event ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_webhooks';
		
		$webhooks = $wpdb->get_results(
			"SELECT * FROM {$table_name} WHERE is_active = 1",
			ARRAY_A
		);

		return array_values( array_filter( $webhooks, function ( $webhook ) use ( $event ) {
			$events = json_decode( $webhook['events'] ?? '[]', true );
			return is_array( $events ) && in_array( $event, $events, true );
		} ) );
	}
}
