<?php
/**
 * Certificate Manager Retention Policy Service
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retention policy service for cleanup
 */
class RetentionPolicyService {
	
	/**
	 * Audit repository
	 *
	 * @var mixed|null
	 */
	private $audit_repo;
	
	/**
	 * Log repository
	 *
	 * @var mixed|null
	 */
	private $log_repo;
	
	/**
	 * Constructor
	 *
	 * @param mixed|null $audit_repo Audit repository
	 * @param mixed|null $log_repo Log repository
	 */
	public function __construct( $audit_repo, $log_repo ) {
		$this->audit_repo = $audit_repo;
		$this->log_repo = $log_repo;
	}
	
	/**
	 * Cleanup old data based on retention settings
	 */
	public function cleanup_old_data() {
		$settings = new \CertificateManager\Core\Settings();
		
		// Cleanup audit log
		if ( $settings->get( 'audit_retention_enabled', true ) ) {
			$days = $settings->get( 'audit_retention_days', 365 );
			$this->cleanup_old_audit_entries( $days );
		}
		
		// Cleanup operational logs
		if ( $settings->get( 'operational_retention_enabled', true ) ) {
			$days = $settings->get( 'operational_retention_days', 90 );
			$this->cleanup_old_operational_logs( $days );
		}
		
		// Cleanup old download tokens
		$this->cleanup_old_download_tokens();
		
		// Cleanup old idempotency keys
		$this->cleanup_old_idempotency_keys();
		
		// Cleanup trashed certificates older than retention period
		$this->cleanup_trashed_certificates();
	}
	
	/**
	 * Cleanup old audit entries
	 *
	 * @param int $days Number of days to retain
	 */
	private function cleanup_old_audit_entries( int $days ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_audit_log';
		$cut_off = date( 'Y-m-d H:i:s', time() - ( $days * 24 * 60 * 60 ) );
		
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table_name} WHERE occurred_at < %s",
			$cut_off
		) );
	}
	
	/**
	 * Cleanup old operational logs
	 *
	 * @param int $days Number of days to retain
	 */
	private function cleanup_old_operational_logs( int $days ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		$cut_off = date( 'Y-m-d H:i:s', time() - ( $days * 24 * 60 * 60 ) );
		
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table_name} WHERE created_at < %s",
			$cut_off
		) );
	}
	
	/**
	 * Cleanup old download tokens
	 */
	private function cleanup_old_download_tokens() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_download_tokens';
		
		$wpdb->query( "DELETE FROM {$table_name} WHERE expires_at < " . $wpdb->prepare( '%s', current_time( 'mysql' ) ) );
	}
	
	/**
	 * Cleanup old idempotency keys
	 */
	private function cleanup_old_idempotency_keys() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_idempotency_keys';
		
		$wpdb->query( "DELETE FROM {$table_name} WHERE expires_at < " . $wpdb->prepare( '%s', current_time( 'mysql' ) ) );
	}
	
	/**
	 * Cleanup trashed certificates
	 */
	private function cleanup_trashed_certificates() {
		global $wpdb;
		
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$field_table = $wpdb->prefix . 'certificate_manager_certificate_fields';
		$tag_table = $wpdb->prefix . 'certificate_manager_certificate_tags';
		
		// Get settings
		$settings = new \CertificateManager\Core\Settings();
		$days = $settings->get( 'trash_retention_days', 30 );
		
		$cut_off = date( 'Y-m-d H:i:s', time() - ( $days * 24 * 60 * 60 ) );
		
		// Get trashed certificates to delete
		$certs = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$cert_table}
			 WHERE status = 'trash' AND trashed_at < %s",
			$cut_off
		), ARRAY_A );
		
		if ( empty( $certs ) ) {
			return;
		}
		
		// Delete certificate fields
		$ids = implode( ',', array_column( $certs, 'id' ) );
		$wpdb->query( "DELETE FROM {$field_table} WHERE certificate_id IN ({$ids})" );
		
		// Delete certificate tags
		$wpdb->query( "DELETE FROM {$tag_table} WHERE certificate_id IN ({$ids})" );
		
		// Delete certificates
		$wpdb->query( "DELETE FROM {$cert_table} WHERE id IN ({$ids})" );
	}
}
