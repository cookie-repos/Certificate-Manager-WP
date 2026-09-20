<?php
/**
 * Certificate Manager Certificate Repository
 *
 * All direct database calls in this file use table names that are set by
 * WordPress via $wpdb->prefix and are never derived from user input. Complex
 * queries (pagination, bulk actions, dynamic WHERE clauses) cannot be fully
 * expressed through $wpdb->insert / $wpdb->update, so direct $wpdb->query /
 * get_results calls are intentional and safe.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package CertificateManager
 */

namespace CertificateManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate repository
 */
class CertificateRepository {
	
	/**
	 * Get certificate by ID
	 *
	 * @param int $id Certificate ID
	 * @return array|false
	 */
	public function get_certificate( int $id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$id
		), ARRAY_A );
	}
	
	/**
	 * Get certificate by internal ID
	 *
	 * @param string $internal_id Internal ID (UUID)
	 * @return array|false
	 */
	public function get_certificate_by_internal_id( string $internal_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE internal_id = %s",
			$internal_id
		), ARRAY_A );
	}
	
	/**
	 * Get certificate by verification token
	 *
	 * @param string $token Verification token
	 * @return array|false
	 */
	public function get_certificate_by_verification_token( string $token ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE verification_token = %s",
			$token
		), ARRAY_A );
	}
	
	/**
	 * Get certificate by number
	 *
	 * @param string $certificate_number Certificate number
	 * @return array|false
	 */
	public function get_certificate_by_number( string $certificate_number ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE certificate_number = %s",
			$certificate_number
		), ARRAY_A );
	}
	
	/**
	 * Check if certificate number exists (excluding current certificate)
	 *
	 * @param string $number Certificate number
	 * @param int $exclude_id Certificate ID to exclude
	 * @return bool
	 */
	public function certificate_number_exists( string $number, int $exclude_id = 0 ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		if ( $exclude_id ) {
			return (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE certificate_number = %s AND id != %d",
				$number, $exclude_id
			) );
		}
		
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE certificate_number = %s",
			$number
		) );
	}
	
	/**
	 * Get certificate fields
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array
	 */
	public function get_certificate_fields( int $certificate_id ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificate_fields';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE certificate_id = %d ORDER BY id",
			$certificate_id
		), ARRAY_A );
	}

	/**
	 * Get all stored variable values for a set of certificates.
	 *
	 * @param array $certificate_ids Certificate IDs.
	 * @return array
	 */
	public function get_certificate_fields_for_export( array $certificate_ids ): array {
		global $wpdb;

		$certificate_ids = array_values( array_filter( array_map( 'absint', $certificate_ids ) ) );
		if ( empty( $certificate_ids ) ) {
			return array();
		}

		$table_name = $wpdb->prefix . 'certificate_manager_certificate_fields';
		$placeholders = implode( ', ', array_fill( 0, count( $certificate_ids ), '%d' ) );
		$query = $wpdb->prepare(
			"SELECT certificate_id, variable_key, variable_label, value FROM {$table_name} WHERE certificate_id IN ({$placeholders}) ORDER BY certificate_id, id",
			$certificate_ids
		);

		return $wpdb->get_results( $query, ARRAY_A );
	}
	
	/**
	 * Get template by ID
	 *
	 * @param int $template_id Template ID
	 * @return array|false
	 */
	public function get_template( int $template_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d AND is_deleted = 0",
			$template_id
		), ARRAY_A );
	}
	
	/**
	 * Get template variables
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_template_variables( int $template_id ): array {
		global $wpdb;
		
		$tv_table = $wpdb->prefix . 'certificate_manager_template_variables';
		$v_table = $wpdb->prefix . 'certificate_manager_variables';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT v.* FROM {$tv_table} tv
			 JOIN {$v_table} v ON tv.variable_id = v.id
			 WHERE tv.template_id = %d
			 ORDER BY tv.sort_order, v.sort_order",
			$template_id
		), ARRAY_A );
	}
	
	/**
	 * Get certificates list with pagination
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_certificates( array $args = array() ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		// Parse arguments
		$page = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$offset = ( $page - 1 ) * $per_page;
		
		$where = array( '1=1' );
		$params = array();
		
		// Filter by status
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'c.status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['expiring_soon'] ) ) {
			$days = max( 1, absint( $args['expiry_days'] ?? 30 ) );
			$where[] = 'c.status = %s';
			$params[] = 'active';
			$where[] = 'c.expiry_date IS NOT NULL AND c.expiry_date > %s AND c.expiry_date <= %s';
			$params[] = current_time( 'mysql' );
			$params[] = wp_date( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		}
		
		// Filter by template
		if ( ! empty( $args['template_id'] ) ) {
			$where[] = 'c.template_id = %d';
			$params[] = $args['template_id'];
		}
		
		// Filter by date range
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = 'c.issue_date >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		
		if ( ! empty( $args['date_to'] ) ) {
			$where[] = 'c.issue_date <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		
		// Filter by recipient name
		if ( ! empty( $args['recipient_name'] ) ) {
			$where[] = 'c.recipient_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['recipient_name'] ) . '%';
		}
		
		// Filter by recipient email
		if ( ! empty( $args['recipient_email'] ) ) {
			$where[] = 'c.recipient_email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['recipient_email'] ) . '%';
		}
		
		// Filter by certificate number
		if ( ! empty( $args['certificate_number'] ) ) {
			$where[] = 'c.certificate_number LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['certificate_number'] ) . '%';
		}

		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(c.certificate_number LIKE %s OR c.recipient_name LIKE %s OR c.recipient_email LIKE %s OR CAST(c.id AS CHAR) LIKE %s)';
			$params = array_merge( $params, array( $search, $search, $search, $search ) );
		}
		
		// Filter by source
		if ( ! empty( $args['source'] ) ) {
			$where[] = 'c.source = %s';
			$params[] = $args['source'];
		}
		
		// Build WHERE clause
		$where_sql = implode( ' AND ', $where );
		
		$count_query = "SELECT COUNT(*) FROM {$table_name} c WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$count_query = $wpdb->prepare( $count_query, $params );
		}
		$total = $wpdb->get_var( $count_query );

		$templates_table = $wpdb->prefix . 'certificate_manager_templates';
		$query = "SELECT c.*, t.title AS template_title FROM {$table_name} c LEFT JOIN {$templates_table} t ON t.id = c.template_id WHERE {$where_sql} ORDER BY c.issue_date DESC LIMIT %d OFFSET %d";
		$certificates = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		
		return array(
			'data' => $certificates,
			'total' => (int) $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	public function expire_due_certificates(): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table_name} SET status = %s, updated_at = %s WHERE status = %s AND expiry_date IS NOT NULL AND expiry_date <= %s",
			'expired',
			current_time( 'mysql' ),
			'active',
			current_time( 'mysql' )
		) );

		return false === $updated ? 0 : (int) $updated;
	}

	public function set_manual_webhook_ids( int $certificate_id, array $webhook_ids ): bool {
		global $wpdb;

		$certificate = $this->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return false;
		}

		$snapshot = json_decode( $certificate['data_snapshot'] ?? '', true );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$snapshot['manual_webhook_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $webhook_ids ) ) ) );
		$updated = $wpdb->update(
			$wpdb->prefix . 'certificate_manager_certificates',
			array( 'data_snapshot' => wp_json_encode( $snapshot ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $certificate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}
	
	/**
	 * Get certificates for export
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_certificates_for_export( array $args = array() ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		$where = array( '1=1' );
		$params = array();
		
		// Filter by status
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'c.status = %s';
			$params[] = $args['status'];
		}
		
		// Filter by template
		if ( ! empty( $args['template_id'] ) ) {
			$where[] = 'c.template_id = %d';
			$params[] = $args['template_id'];
		}
		
		// Filter by date range
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = 'c.issue_date >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		
		if ( ! empty( $args['date_to'] ) ) {
			$where[] = 'c.issue_date <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		
		// Build WHERE clause
		$where_sql = implode( ' AND ', $where );
		
		$templates_table = $wpdb->prefix . 'certificate_manager_templates';
		$query = "SELECT c.*, t.title AS template_title FROM {$table_name} c LEFT JOIN {$templates_table} t ON t.id = c.template_id WHERE {$where_sql} ORDER BY c.issue_date DESC";
		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, $params );
		}

		return $wpdb->get_results( $query, ARRAY_A );
	}
	
	/**
	 * Get certificate statistics
	 *
	 * @return array
	 */
	/**
	 * Get certificate statistics.
	 *
	 * Optimized to use a single query with conditional aggregation
	 * instead of multiple separate queries.
	 *
	 * @return array Statistics array with counts by status.
	 */
	public function get_statistics(): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		// Single query with conditional aggregation for better performance
		$stats = $wpdb->get_row(
			"SELECT 
				COUNT(*) as total,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
				SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
				SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) as revoked,
				SUM(CASE WHEN status = 'replaced' THEN 1 ELSE 0 END) as replaced,
				SUM(CASE WHEN status = 'trash' THEN 1 ELSE 0 END) as trash
			FROM {$table_name}",
			ARRAY_A
		);
		
		// Expiring soon (within 30 days) - still needs separate query due to date condition
		$expiring_soon = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} 
			 WHERE status = 'active' 
			 AND expiry_date IS NOT NULL 
			 AND expiry_date BETWEEN %s AND %s",
			current_time( 'mysql' ),
			gmdate( 'Y-m-d H:i:s', time() + 30 * 24 * 60 * 60 )
		) );
		
		return array(
			'total'         => (int) ( $stats['total'] ?? 0 ),
			'active'        => (int) ( $stats['active'] ?? 0 ),
			'expired'       => (int) ( $stats['expired'] ?? 0 ),
			'revoked'       => (int) ( $stats['revoked'] ?? 0 ),
			'replaced'      => (int) ( $stats['replaced'] ?? 0 ),
			'trash'         => (int) ( $stats['trash'] ?? 0 ),
			'expiring_soon' => $expiring_soon,
		);
	}
	
	/**
	 * Get statistics for date range
	 *
	 * @param string $start_date Start date
	 * @param string $end_date End date
	 * @return array
	 */
	public function get_statistics_by_date( string $start_date, string $end_date ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT 
				DATE(issue_date) as date,
				COUNT(*) as count,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
				SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
				SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) as revoked
			FROM {$table_name}
			WHERE issue_date BETWEEN %s AND %s
			GROUP BY DATE(issue_date)
			ORDER BY date DESC",
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		), ARRAY_A );
		
		return $results;
	}
	
	/**
	 * Get most used templates
	 *
	 * @param int $limit Limit count
	 * @return array
	 */
	public function get_most_used_templates( int $limit = 10 ): array {
		global $wpdb;
		
		$templates_table = $wpdb->prefix . 'certificate_manager_templates';
		$certificates_table = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, t.title, COUNT(c.id) as certificate_count
			 FROM {$templates_table} t
			 LEFT JOIN {$certificates_table} c ON t.id = c.template_id
			 WHERE t.is_deleted = 0
			 GROUP BY t.id
			 ORDER BY certificate_count DESC
			 LIMIT %d",
			$limit
		), ARRAY_A );
	}
	
	/**
	 * Update certificate
	 *
	 * @param int $id Certificate ID
	 * @param array $data Update data
	 * @return bool
	 */
	public function update_certificate( int $id, array $data ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return (bool) $wpdb->update( $table_name, $data, array( 'id' => $id ) );
	}
	
	/**
	 * Bulk update certificates
	 *
	 * @param array $certificate_ids Certificate IDs
	 * @param array $data Update data
	 * @return bool
	 */
	public function bulk_update_certificates( array $certificate_ids, array $data ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		if ( empty( $certificate_ids ) ) {
			return false;
		}
		
		$ids = implode( ',', array_map( 'intval', $certificate_ids ) );
		
		// Build SET clause
		$set_clause = array();
		foreach ( $data as $key => $value ) {
			$set_clause[] = $key . ' = %s';
		}
		
		$set_sql = implode( ', ', $set_clause );
		
		// Use prepared statement with IN clause
		$query = "UPDATE {$table_name} SET {$set_sql} WHERE id IN ({$ids})";
		
		// Prepare values
		$values = array_values( $data );
		
		return (bool) $wpdb->query( $wpdb->prepare( $query, $values ) );
	}

	public function count_all(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}certificate_manager_certificates" );
	}

	/**
	 * Purge all certificates and related data.
	 *
	 * Uses database transactions for data integrity. All deletions are
	 * atomic - if any fail, the entire operation is rolled back.
	 *
	 * @return array|\WP_Error Array with count on success, WP_Error on failure.
	 */
	public function purge_all() {
		global $wpdb;

		$prefix = $wpdb->prefix . 'certificate_manager_';
		$certificates_table = $prefix . 'certificates';
		$count = $this->count_all();
		
		// Check if we have anything to purge
		if ( $count === 0 ) {
			return array( 'count' => 0 );
		}
		
		// Start transaction
		$wpdb->query( 'START TRANSACTION' );
		
		$queries = array(
			"DELETE FROM {$prefix}certificate_fields",
			"DELETE FROM {$prefix}certificate_tags",
			"DELETE FROM {$prefix}download_tokens",
			"DELETE FROM {$prefix}idempotency_keys WHERE certificate_id IS NOT NULL",
			"DELETE FROM {$prefix}scheduled_jobs WHERE certificate_id IS NOT NULL",
			"DELETE FROM {$prefix}sequences",
			"DELETE FROM {$certificates_table}",
		);
		
		$failed_query = null;
		foreach ( $queries as $query ) {
			$result = $wpdb->query( $query );
			if ( false === $result ) {
				$failed_query = $query;
				break;
			}
		}
		
		if ( null !== $failed_query ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error(
				'certificate_purge_failed',
				__( 'The certificates could not be cleared. The operation was rolled back.', 'certificate-manager' ),
				array( 'query' => $failed_query, 'error' => $wpdb->last_error )
			);
		}
		
		$commit_result = $wpdb->query( 'COMMIT' );
		if ( false === $commit_result ) {
			return new \WP_Error(
				'certificate_purge_commit_failed',
				__( 'Failed to commit the purge transaction.', 'certificate-manager' ),
				array( 'error' => $wpdb->last_error )
			);
		}

		return array( 'count' => $count );
	}
	
	/**
	 * Get certificates in trash
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_trashed_certificates( array $args = array() ): array {
		$args['status'] = 'trash';
		return $this->get_certificates( $args );
	}
	
	/**
	 * Get certificate template ID
	 *
	 * @param int $certificate_id Certificate ID
	 * @return int|false Template ID or false
	 */
	public function get_certificate_template_id( int $certificate_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT template_id FROM {$table_name} WHERE id = %d",
			$certificate_id
		) );
	}
	
	/**
	 * Get certificate by replacement ID
	 *
	 * @param int $replacement_id Replacement certificate ID
	 * @return array|false Original certificate data or false
	 */
	public function get_certificate_by_replacement_id( int $replacement_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE replaced_by = %d",
			$replacement_id
		), ARRAY_A );
	}
}
