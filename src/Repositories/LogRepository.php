<?php
/**
 * Certificate Manager Log Repository
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
 * Log repository
 */
class LogRepository {
	
	/**
	 * Add operational log entry
	 *
	 * @param string $level Log level
	 * @param string $category Log category
	 * @param string $message Log message
	 * @param array $context Context data
	 * @param string|null $stack_trace Stack trace
	 * @return int Log ID
	 */
	public function add_log( string $level, string $category, string $message, array $context = array(), ?string $stack_trace = null ): int {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		
		$wpdb->insert( $table_name, array(
			'log_level' => sanitize_text_field( $level ),
			'log_category' => sanitize_text_field( $category ),
			'message' => sanitize_text_field( $message ),
			'context' => wp_json_encode( $context ),
			'stack_trace' => $stack_trace,
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Get log entries
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_logs( array $args = array() ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		
		$page = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$offset = ( $page - 1 ) * $per_page;
		
		$where = array( '1=1' );
		$params = array();
		
		if ( ! empty( $args['level'] ) ) {
			$where[] = 'log_level = %s';
			$params[] = $args['level'];
		}
		
		if ( ! empty( $args['category'] ) ) {
			$where[] = 'log_category = %s';
			$params[] = $args['category'];
		}
		
		if ( ! empty( $args['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$params[] = $args['date_from'];
		}
		
		if ( ! empty( $args['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$params[] = $args['date_to'];
		}
		
		// Build WHERE clause
		$where_sql = implode( ' AND ', $where );
		
		// Get total count
		$count_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic query built from validated inputs; table name is WP-prefixed.
		$total = $wpdb->get_var( $params ? $wpdb->prepare( $count_query, $params ) : $count_query );
		
		// Get entries
		$query = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
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
	 * Get recent error logs
	 *
	 * @param int $limit Limit count
	 * @return array
	 */
	public function get_error_logs( int $limit = 20 ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		
		return $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed.
			"SELECT * FROM {$table_name} WHERE log_level IN (%s, %s) ORDER BY created_at DESC LIMIT %d",
			'error', 'critical', $limit
		), ARRAY_A );
	}
	
	/**
	 * Get logs by category
	 *
	 * @param string $category Log category
	 * @param int $limit Limit count
	 * @return array
	 */
	public function get_logs_by_category( string $category, int $limit = 20 ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		
		return $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed.
			"SELECT * FROM {$table_name} WHERE log_category = %s ORDER BY created_at DESC LIMIT %d",
			$category, $limit
		), ARRAY_A );
	}

	/**
	 * Clear all operational log entries.
	 *
	 * @return bool Whether the delete query succeeded.
	 */
	public function clear(): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed, one-time full-table delete.
		return false !== $wpdb->query( "DELETE FROM {$table_name}" );
	}
	
	/**
	 * Delete old logs based on retention
	 *
	 * @param int $days Number of days to retain
	 * @return int Number of logs deleted
	 */
	public function cleanup_old_logs( int $days ): int {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		
		$cut_off = gmdate( 'Y-m-d H:i:s', time() - ( $days * 24 * 60 * 60 ) );
		
		return (int) $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed.
			"DELETE FROM {$table_name} WHERE created_at < %s",
			$cut_off
		) );
	}
	
	/**
	 * Add PDF generation log
	 *
	 * @param int $certificate_id Certificate ID
	 * @param string $message Message
	 * @param array $context Context data
	 * @return int Log ID
	 */
	public function log_pdf_generation( int $certificate_id, string $message, array $context = array() ): int {
		return $this->add_log( 'info', 'PDF', $message, array(
			'certificate_id' => $certificate_id,
			...$context,
		) );
	}
	
	/**
	 * Add email log
	 *
	 * @param int $certificate_id Certificate ID
	 * @param string $email Recipient email
	 * @param string $status Status
	 * @param string $message Message
	 * @return int Log ID
	 */
	public function log_email( int $certificate_id, string $email, string $status, string $message ): int {
		return $this->add_log( $status === 'success' ? 'info' : 'warning', 'Email', $message, array(
			'certificate_id' => $certificate_id,
			'email' => $email,
			'status' => $status,
		) );
	}
	
	/**
	 * Add webhook log
	 *
	 * @param int $webhook_id Webhook ID
	 * @param string $event Event name
	 * @param string $status Status
	 * @param string $message Message
	 * @return int Log ID
	 */
	public function log_webhook( int $webhook_id, string $event, string $status, string $message ): int {
		return $this->add_log( $status === 'success' ? 'info' : 'error', 'Webhook', $message, array(
			'webhook_id' => $webhook_id,
			'event' => $event,
			'status' => $status,
		) );
	}
}
