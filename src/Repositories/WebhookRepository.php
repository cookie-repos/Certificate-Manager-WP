<?php
/**
 * Webhook Repository
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package CertificateManager
 */

namespace CertificateManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook repository class
 */
class WebhookRepository {
	
	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table_name;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'certificate_manager_webhooks';
	}
	
	/**
	 * Create webhook
	 *
	 * @param array $data Webhook data.
	 * @return int|false Webhook ID or false on failure.
	 */
	public function create( $data ) {
		global $wpdb;
		
		$result = $wpdb->insert( $this->table_name, array(
			'name' => $data['name'] ?? '',
			'url' => $data['url'] ?? '',
			'secret' => $data['secret'] ?? $this->generate_secret(),
			'events' => json_encode( $data['events'] ?? array() ),
			'payload_fields' => json_encode( $data['payload_fields'] ?? array() ),
			'is_active' => $data['enabled'] ?? 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		) );
		
		return $result ? $wpdb->insert_id : false;
	}
	
	/**
	 * Get webhook by ID
	 *
	 * @param int $id Webhook ID.
	 * @return array|false Webhook data or false if not found.
	 */
	public function get_by_id( $id ) {
		global $wpdb;
		
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $id ),
			ARRAY_A
		);
		
		if ( ! $row ) {
			return false;
		}
		
		return $this->normalize_row( $row );
	}
	
	/**
	 * Get all webhooks
	 *
	 * @param array $args Query arguments.
	 * @return array Array of webhook data.
	 */
	public function get_all( $args = array() ) {
		global $wpdb;
		
		$defaults = array(
			'number' => -1,
			'offset' => 0,
			'orderby' => 'id',
			'order' => 'DESC',
			'is_active' => null,
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$query = "SELECT * FROM {$this->table_name} WHERE 1=1";
		
		if ( $args['is_active'] !== null ) {
			$query .= $wpdb->prepare( " AND is_active = %d", $args['is_active'] );
		}
		
		$allowed_orderby = array( 'id', 'name', 'created_at', 'updated_at' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$query .= " ORDER BY {$orderby} {$order}";
		
		if ( $args['number'] > 0 ) {
			$query .= $wpdb->prepare( " LIMIT %d OFFSET %d", $args['number'], $args['offset'] );
		}
		
		$rows = $wpdb->get_results( $query, ARRAY_A );
		
		return array_map( array( $this, 'normalize_row' ), $rows );
	}
	
	/**
	 * Update webhook
	 *
	 * @param int   $id Webhook ID.
	 * @param array $data Webhook data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( $id, $data ) {
		global $wpdb;
		
		$update_data = array();
		
		if ( isset( $data['name'] ) ) {
			$update_data['name'] = $data['name'];
		}
		if ( isset( $data['url'] ) ) {
			$update_data['url'] = $data['url'];
		}
		if ( isset( $data['events'] ) ) {
			$update_data['events'] = json_encode( $data['events'] );
		}
		if ( isset( $data['payload_fields'] ) ) {
			$update_data['payload_fields'] = json_encode( $data['payload_fields'] );
		}
		if ( isset( $data['headers'] ) ) {
			$update_data['headers'] = json_encode( $data['headers'] );
		}
		if ( isset( $data['enabled'] ) ) {
			$update_data['is_active'] = $data['enabled'];
		}
		
		$update_data['updated_at'] = current_time( 'mysql' );
		
		$result = $wpdb->update(
			$this->table_name,
			$update_data,
			array( 'id' => $id )
		);
		
		return $result !== false;
	}
	
	/**
	 * Delete webhook
	 *
	 * @param int $id Webhook ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $id ) {
		global $wpdb;
		
		$result = $wpdb->delete(
			$this->table_name,
			array( 'id' => $id )
		);
		
		return $result !== false;
	}
	
	/**
	 * Create delivery record
	 *
	 * @param int    $webhook_id Webhook ID.
	 * @param array  $payload Request payload.
	 * @param array  $headers Request headers.
	 * @param string $status Delivery status.
	 * @param int    $response_code Response code.
	 * @param string $response_body Response body.
	 * @return int|false Delivery ID or false on failure.
	 */
	public function create_delivery( $webhook_id, $payload, $headers, $status = 'pending', $response_code = null, $response_body = '' ) {
		global $wpdb;
		
		$result = $wpdb->insert( $wpdb->prefix . 'certificate_manager_webhook_deliveries', array(
			'webhook_id' => $webhook_id,
			'payload' => json_encode( $payload ),
			'request_headers' => json_encode( $headers ),
			'request_url' => '',
			'status' => $status,
			'response_code' => $response_code,
			'response_body' => $response_body,
			'retry_count' => 0,
			'created_at' => current_time( 'mysql' ),
			'last_retry_at' => null,
		) );
		
		return $result ? $wpdb->insert_id : false;
	}
	
	/**
	 * Update delivery record
	 *
	 * @param int    $delivery_id Delivery ID.
	 * @param string $status New status.
	 * @param int    $response_code Response code.
	 * @param string $response_body Response body.
	 * @param int    $retry_count Retry count.
	 * @return bool True on success, false on failure.
	 */
	public function update_delivery( $delivery_id, $status, $response_code = null, $response_body = '', $retry_count = 0 ) {
		global $wpdb;
		
		$update_data = array(
			'status' => $status,
			'response_code' => $response_code,
			'response_body' => $response_body,
			'updated_at' => current_time( 'mysql' ),
		);
		
		if ( $retry_count > 0 ) {
			$update_data['retry_count'] = $retry_count;
			$update_data['last_retry_at'] = current_time( 'mysql' );
		}
		
		$result = $wpdb->update(
			$wpdb->prefix . 'certificate_manager_webhook_deliveries',
			$update_data,
			array( 'id' => $delivery_id )
		);
		
		return $result !== false;
	}
	
	/**
	 * Get pending deliveries
	 *
	 * @param int $limit Number of deliveries to get.
	 * @return array Array of delivery data.
	 */
	public function get_pending_deliveries( $limit = 10 ) {
		global $wpdb;
		
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}certificate_manager_webhook_deliveries 
				WHERE status IN ('pending', 'retry') 
				AND retry_count < 5 
				ORDER BY created_at ASC 
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		
		return $rows;
	}
	
	/**
	 * Generate secret for webhook
	 *
	 * @return string Generated secret.
	 */
	private function generate_secret() {
		return bin2hex( random_bytes( 32 ) );
	}
	
	/**
	 * Normalize database row
	 *
	 * @param array $row Database row.
	 * @return array Normalized webhook data.
	 */
	private function normalize_row( $row ) {
		$row['events'] = json_decode( $row['events'], true ) ?: array();
		$row['payload_fields'] = json_decode( $row['payload_fields'] ?? '[]', true ) ?: array();
		$row['headers'] = json_decode( $row['headers'], true ) ?: array();
		return $row;
	}
}
