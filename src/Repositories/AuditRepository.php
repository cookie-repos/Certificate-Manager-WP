<?php
/**
 * Certificate Manager Audit Repository
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
 * Audit repository
 */
class AuditRepository {
	
	/**
	 * Log audit event
	 *
	 * @param string $type Record type (certificate|template|system)
	 * @param string $event Event name
	 * @param int $record_id Record ID
	 * @param array $data Change data
	 * @param array $performer Performer info
	 * @param int|null $related_record_id Related record ID
	 * @param string|null $related_event Related event
	 * @return int Audit log ID
	 */
	public function log( string $type, string $event, int $record_id, array $data, array $performer, ?int $related_record_id = null, ?string $related_event = null ): int {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_audit_log';
		$performer_type = sanitize_key( $performer['type'] ?? 'user' );
		$performer_id = absint( $performer['id'] ?? 0 );
		$performer_details = isset( $performer['details'] ) && is_array( $performer['details'] ) ? $performer['details'] : array();
		if ( 'user' === $performer_type && $performer_id && empty( $performer_details ) ) {
			$user = get_userdata( $performer_id );
			if ( $user ) {
				$performer_details = array(
					'username' => $user->user_login,
					'display_name' => $user->display_name,
				);
			}
		}
		
		$wpdb->insert( $table_name, array(
			'certificate_id' => $type === 'certificate' ? $record_id : null,
			'event' => sanitize_text_field( $event ),
			'occurred_at' => current_time( 'mysql' ),
			'source' => $this->determine_source(),
			'source_details' => wp_json_encode( $this->get_source_details() ),
			'changed_data' => isset( $data ) ? wp_json_encode( $data ) : null,
			'performer_type' => $performer_type,
			'performer_id' => $performer_id ?: null,
			'performer_details' => ! empty( $performer_details ) ? wp_json_encode( $performer_details ) : null,
			'related_certificate_id' => $related_record_id,
			'related_event' => $related_event,
			'ip_address' => $this->get_client_ip(),
			'meta' => isset( $data ) ? wp_json_encode( $data ) : null,
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Determine audit source
	 *
	 * @return string
	 */
	private function determine_source(): string {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'system';
		}
		
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'api';
		}
		
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		
		return 'manual';
	}
	
	/**
	 * Get source details
	 *
	 * @return array
	 */
	private function get_source_details(): array {
		$details = array();
		
		if ( $this->determine_source() === 'api' ) {
			$api_key_id = $this->get_api_key_id();
			if ( $api_key_id ) {
				$details['api_key_id'] = $api_key_id;
			}
		}
		
		return $details;
	}
	
	/**
	 * Get API key ID
	 *
	 * @return int|false
	 */
	private function get_api_key_id() {
		// Get API key from headers or request
		$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : null;
		
		if ( $auth && strpos( $auth, 'Bearer ' ) === 0 ) {
			$token = trim( substr( $auth, 7 ) );
			if ( strlen( $token ) < 8 ) {
				return false;
			}
			// API secrets use WordPress password hashes, so compare candidate rows
			// by prefix and then verify the hash rather than querying a reversible
			// digest of the bearer token.
			global $wpdb;
			$table_name = $wpdb->prefix . 'certificate_manager_api_keys';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is WP-prefixed.
			$keys = $wpdb->get_results( $wpdb->prepare( "SELECT id, secret_hash FROM {$table_name} WHERE secret_prefix = %s AND is_active = 1", substr( $token, 0, 8 ) ), ARRAY_A );
			foreach ( $keys as $key ) {
				if ( wp_check_password( $token, $key['secret_hash'] ) ) {
					return (int) $key['id'];
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Get client IP address
	 *
	 * @return string|null
	 */
	private function get_client_ip(): ?string {
		$ip_keys = array(
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);
		
		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_text_field + wp_unslash applied.
				// Handle multiple IPs
				if ( strpos( $ip, ',' ) !== false ) {
					$ips = array_map( 'trim', explode( ',', $ip ) );
					$ip = end( $ips );
				}
				// Validate IP
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		
		return null;
	}
	
	/**
	 * Get audit log entries
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_audit_entries( array $args = array() ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_audit_log';
		
		$page = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$offset = ( $page - 1 ) * $per_page;
		
		$where = array( '1=1' );
		$params = array();
		
		if ( ! empty( $args['certificate_id'] ) ) {
			$where[] = 'certificate_id = %d';
			$params[] = absint( $args['certificate_id'] );
		}
		
		if ( ! empty( $args['event'] ) ) {
			$where[] = 'event = %s';
			$params[] = $args['event'];
		}
		
		if ( ! empty( $args['source'] ) ) {
			$where[] = 'source = %s';
			$params[] = $args['source'];
		}
		
		// Build WHERE clause
		$where_sql = implode( ' AND ', $where );
		
		// Get total count
		$count_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic query built from validated inputs; table name is WP-prefixed.
		$total = $wpdb->get_var( $params ? $wpdb->prepare( $count_query, $params ) : $count_query );
		
		// Get entries
		$query = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY occurred_at DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic query built from validated inputs; table name is WP-prefixed.
		$entries = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		
		return array(
			'data' => $entries,
			'total' => (int) $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}
	
	/**
	 * Get recent audit entries
	 *
	 * @param int $limit Limit count
	 * @return array
	 */
	public function get_recent_audit_entries( int $limit = 10 ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_audit_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed; no caching needed.
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} ORDER BY occurred_at DESC LIMIT %d",
			$limit
		), ARRAY_A );
	}

	/**
	 * Clear all audit entries.
	 *
	 * @return bool Whether the delete query succeeded.
	 */
	public function clear(): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'certificate_manager_audit_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed; full-table delete for clear operation.
		return false !== $wpdb->query( "DELETE FROM {$table_name}" );
	}
}
