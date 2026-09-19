<?php
/**
 * Email Queue
 *
 * @package CertificateManager
 */

namespace CertificateManager\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email queue class
 */
class EmailQueue {
	
	/**
	 * Email service
	 *
	 * @var Services\EmailService
	 */
	private $email_service;
	
	/**
	 * Log repository
	 *
	 * @var Repositories\LogRepository
	 */
	private $log_repo;
	
	/**
	 * Constructor
	 *
	 * @param Services\EmailService        $email_service Email service.
	 * @param Repositories\LogRepository   $log_repo Log repository.
	 */
	public function __construct( $email_service, $log_repo ) {
		$this->email_service = $email_service;
		$this->log_repo = $log_repo;
	}
	
	/**
	 * Queue an email for delivery
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body Email body.
	 * @param array  $attachments Optional attachments.
	 * @param array  $headers Optional headers.
	 * @return int|false Email ID or false on failure.
	 */
	public function queue( $to, $subject, $body, $attachments = array(), $headers = array() ) {
		global $wpdb;
		
		$result = $wpdb->insert( $wpdb->prefix . 'certificate_manager_email_queue', array(
			'to' => $to,
			'subject' => $subject,
			'body' => $body,
			'attachments' => json_encode( $attachments ),
			'headers' => json_encode( $headers ),
			'status' => 'pending',
			'retry_count' => 0,
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $result ? $wpdb->insert_id : false;
	}
	
	/**
	 * Process queued emails
	 *
	 * @param int $limit Maximum number of emails to process.
	 * @return array Processing results.
	 */
	public function process_queue( $limit = 10 ) {
		global $wpdb;
		
		$results = array(
			'success' => 0,
			'failed' => 0,
			'errors' => array(),
		);
		
		$emails = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}certificate_manager_email_queue 
				WHERE status = 'pending' 
				AND retry_count < 3 
				ORDER BY created_at ASC 
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		
		foreach ( $emails as $email ) {
			$attachments = json_decode( $email['attachments'], true ) ?: array();
			$headers = json_decode( $email['headers'], true ) ?: array();
			
			$result = $this->email_service->send(
				$email['to'],
				$email['subject'],
				$email['body'],
				$attachments,
				$headers
			);
			
			if ( $result ) {
				$wpdb->update(
					$wpdb->prefix . 'certificate_manager_email_queue',
					array(
						'status' => 'sent',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $email['id'] )
				);
				$results['success']++;
			} else {
				$wpdb->update(
					$wpdb->prefix . 'certificate_manager_email_queue',
					array(
						'status' => 'failed',
						'retry_count' => $email['retry_count'] + 1,
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $email['id'] )
				);
				$results['failed']++;
				$results['errors'][] = sprintf( __( 'Failed to send email to %s', 'certificate-manager' ), $email['to'] );
			}
		}
		
		return $results;
	}
	
	/**
	 * Clear old emails from queue
	 *
	 * @param int $days_old Number of days.
	 * @return int Number of emails cleared.
	 */
	public function clear_old( $days_old = 30 ) {
		global $wpdb;
		
		$before = current_time( 'mysql' ) . ' - INTERVAL ' . (int) $days_old . ' DAY';
		
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}certificate_manager_email_queue 
				WHERE created_at < %s",
				$before
			)
		);
		
		return $result ? $result : 0;
	}
}
