<?php
/**
 * Audit Logger
 *
 * @package CertificateManager
 */

namespace CertificateManager\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit logger class
 */
class AuditLogger {
	
	/**
	 * Audit repository
	 *
	 * @var Repositories\AuditRepository
	 */
	private $audit_repo;
	
	/**
	 * Log repository
	 *
	 * @var Repositories\LogRepository
	 */
	private $log_repo;
	
	/**
	 * Constructor
	 *
	 * @param Repositories\AuditRepository $audit_repo Audit repository.
	 * @param Repositories\LogRepository  $log_repo Log repository.
	 */
	public function __construct( $audit_repo, $log_repo ) {
		$this->audit_repo = $audit_repo;
		$this->log_repo = $log_repo;
	}
	
	/**
	 * Log an audit event
	 *
	 * @param string $user User who performed the action.
	 * @param string $event Event name.
	 * @param array  $data Additional data.
	 * @return int|false Audit ID or false on failure.
	 */
	public function log( $user, $event, $data = array() ) {
		return $this->audit_repo->log( $user, $event, $data );
	}
	
	/**
	 * Log certificate issued
	 *
	 * @param int   $certificate_id Certificate ID.
	 * @param int   $user_id User ID.
	 * @param array $variables Variable values.
	 * @return int|false Audit ID or false on failure.
	 */
	public function log_certificate_issued( $certificate_id, $user_id, $variables = array() ) {
		$certificate = $this->audit_repo->get_certificate_data( $certificate_id );
		
		return $this->audit_repo->log( 'user_' . $user_id, 'certificate_issued', array(
			'certificate_id' => $certificate_id,
			'certificate_number' => $certificate['certificate_number'] ?? '',
			'recipient_name' => $certificate['recipient_name'] ?? '',
			'recipient_email' => $certificate['recipient_email'] ?? '',
			'template_id' => $certificate['template_id'] ?? 0,
			'variables' => $variables,
		) );
	}
	
	/**
	 * Log certificate revoked
	 *
	 * @param int  $certificate_id Certificate ID.
	 * @param int  $user_id User ID.
	 * @param string $reason Revocation reason.
	 * @return int|false Audit ID or false on failure.
	 */
	public function log_certificate_revoked( $certificate_id, $user_id, $reason = '' ) {
		return $this->audit_repo->log( 'user_' . $user_id, 'certificate_revoked', array(
			'certificate_id' => $certificate_id,
			'reason' => $reason,
		) );
	}
	
	/**
	 * Log certificate replaced
	 *
	 * @param int $old_certificate_id Old certificate ID.
	 * @param int $new_certificate_id New certificate ID.
	 * @param int $user_id User ID.
	 * @return int|false Audit ID or false on failure.
	 */
	public function log_certificate_replaced( $old_certificate_id, $new_certificate_id, $user_id ) {
		return $this->audit_repo->log( 'user_' . $user_id, 'certificate_replaced', array(
			'old_certificate_id' => $old_certificate_id,
			'new_certificate_id' => $new_certificate_id,
		) );
	}
	
	/**
	 * Log system event
	 *
	 * @param string $event Event name.
	 * @param array  $data Additional data.
	 * @return int|false Audit ID or false on failure.
	 */
	public function log_system_event( $event, $data = array() ) {
		return $this->audit_repo->log( 'system', $event, $data );
	}
}
