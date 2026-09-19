<?php
/**
 * Certificate Manager Scheduling Service
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduling service for background jobs
 */
class SchedulingService {
	
	/**
	 * Issuance service
	 *
	 * @var IssuanceService|null
	 */
	private $issuance_service;
	
	/**
	 * Log repository
	 *
	 * @var mixed|null
	 */
	private $log_repo;
	
	/**
	 * Constructor
	 *
	 * @param IssuanceService|null $issuance_service Issuance service
	 * @param mixed|null $log_repo Log repository
	 */
	public function __construct( ?IssuanceService $issuance_service, $log_repo ) {
		$this->issuance_service = $issuance_service;
		$this->log_repo = $log_repo;
	}
	
	/**
	 * Schedule certificate issuance
	 *
	 * @param array $data Certificate data
	 * @param string $scheduled_at Scheduled date/time
	 * @return int|WP_Error Job ID or error
	 */
	public function schedule_certificate_issuance( array $data, string $scheduled_at ) {
		global $wpdb;
		
		// Validate date
		$schedule_time = strtotime( $scheduled_at );
		if ( ! $schedule_time || $schedule_time < time() ) {
			return new \WP_Error( 'invalid_date', __( 'Invalid scheduled date', 'certificate-manager' ) );
		}
		
		$table_name = $wpdb->prefix . 'certificate_manager_scheduled_jobs';
		
		$wpdb->insert( $table_name, array(
			'job_type' => 'certificate_issuance',
			'certificate_id' => null,
			'scheduled_at' => $scheduled_at,
			'arguments' => wp_json_encode( $data ),
			'is_completed' => 0,
			'failed' => 0,
			'created_at' => current_time( 'mysql' ),
		) );
		
		$job_id = $wpdb->insert_id;
		
		// Schedule WP Cron event
		wp_schedule_single_event( $schedule_time, 'certificate_manager_scheduled_job', array( $job_id, $data ) );
		
		return $job_id;
	}
	
	/**
	 * Schedule expiry reminder
	 *
	 * @param int $certificate_id Certificate ID
	 * @param int $hours_before Hours before expiry
	 * @return bool
	 */
	public function schedule_expiry_reminder( int $certificate_id, int $hours_before ): bool {
		global $wpdb;
		
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$certificate = $wpdb->get_row( $wpdb->prepare(
			"SELECT issue_date, expiry_date FROM {$cert_table} WHERE id = %d",
			$certificate_id
		), ARRAY_A );
		
		if ( ! $certificate || ! $certificate['expiry_date'] ) {
			return false;
		}
		
		$remind_time = strtotime( $certificate['expiry_date'] ) - ( $hours_before * 3600 );
		
		if ( $remind_time < time() ) {
			$remind_time = time();
		}
		
		// Schedule WP Cron event
		wp_schedule_single_event( $remind_time, 'certificate_manager_expiry_reminder', array( $certificate_id ) );
		
		return true;
	}
	
	/**
	 * Process scheduled job
	 *
	 * @param int $job_id Job ID
	 * @param array $args Job arguments
	 * @return array|WP_Error
	 */
	public function process_scheduled_job( int $job_id, array $args ) {
		global $wpdb;
		
		$job_table = $wpdb->prefix . 'certificate_manager_scheduled_jobs';
		
		// Mark job as processing
		$wpdb->update( $job_table, array(
			'status' => 'processing',
		), array( 'id' => $job_id ), array( '%s' ), array( '%d' ) );
		
		// Issue certificate
		$result = $this->issuance_service->issue_scheduled_certificate( $job_id );
		
		return $result;
	}
	
	/**
	 * Get pending scheduled jobs
	 *
	 * @param int $limit Limit count
	 * @return array
	 */
	public function get_pending_jobs( int $limit = 50 ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_scheduled_jobs';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name}
			 WHERE is_completed = 0 AND failed = 0 AND scheduled_at <= %s
			 ORDER BY scheduled_at ASC
			 LIMIT %d",
			current_time( 'mysql' ), $limit
		), ARRAY_A );
	}
}
