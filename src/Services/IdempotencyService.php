<?php
/**
 * Certificate Manager Idempotency Service
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
 * Idempotency service for API requests
 */
class IdempotencyService {
	
	/**
	 * Store idempotency record
	 *
	 * @param string $key Idempotency key
	 * @param int $certificate_id Certificate ID
	 * @param int $api_key_id API key ID used to namespace the request key.
	 * @return bool
	 */
	public function store_idempotency_record( string $key, int $certificate_id, int $api_key_id = 0 ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_idempotency_keys';
		$key_hash = $this->hash_key( $key, $api_key_id );
		
		$wpdb->insert( $table_name, array(
			'key_hash' => $key_hash,
			'certificate_id' => $certificate_id,
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), // 24 hours
			'created_at' => current_time( 'mysql' ),
		) );
		
		return (bool) $wpdb->insert_id;
	}
	
	/**
	 * Get certificate by idempotency key
	 *
	 * @param string $key Idempotency key
	 * @param int $api_key_id API key ID used to namespace the request key.
	 * @return array|false Certificate data or false
	 */
	public function get_certificate_by_idempotency_key( string $key, int $api_key_id = 0 ) {
		global $wpdb;
		
		$key_hash = $this->hash_key( $key, $api_key_id );
		
		$idem_table = $wpdb->prefix . 'certificate_manager_idempotency_keys';
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are WP-prefixed, not user input.
			"SELECT c.* FROM {$idem_table} i
			 JOIN {$cert_table} c ON i.certificate_id = c.id
			 WHERE i.key_hash = %s AND i.expires_at > %s",
			$key_hash, current_time( 'mysql' )
		), ARRAY_A );
	}

	/**
	 * Derive the stored idempotency hash without allowing one API client to
	 * retrieve another client's result by reusing the same request key.
	 */
	private function hash_key( string $key, int $api_key_id ): string {
		return hash( 'sha256', $api_key_id . "\0" . $key );
	}
}
