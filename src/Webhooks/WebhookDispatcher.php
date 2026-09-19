<?php
/**
 * Webhook Dispatcher
 *
 * @package CertificateManager
 */

namespace CertificateManager\Webhooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook dispatcher class
 */
class WebhookDispatcher {
	
	/**
	 * Webhook service
	 *
	 * @var Services\WebhookService
	 */
	private $webhook_service;
	
	/**
	 * Log repository
	 *
	 * @var Repositories\LogRepository
	 */
	private $log_repo;
	
	/**
	 * Constructor
	 *
	 * @param Services\WebhookService       $webhook_service Webhook service.
	 * @param Repositories\LogRepository    $log_repo Log repository.
	 */
	public function __construct( $webhook_service, $log_repo ) {
		$this->webhook_service = $webhook_service;
		$this->log_repo = $log_repo;
	}
	
	/**
	 * Dispatch webhooks for an event
	 *
	 * @param string $event Event name.
	 * @param array  $data Event data.
	 * @return array Dispatch results.
	 */
	public function dispatch( $event, $data ) {
		return $this->webhook_service->dispatch_webhook(
			(string) $event,
			is_array( $data ) ? $data : array(),
			absint( is_array( $data ) ? ( $data['certificate_id'] ?? 0 ) : 0 )
		);
	}
	
	/**
	 * Retry a failed webhook delivery
	 *
	 * @param int $delivery_id Delivery ID.
	 * @return bool True on success, false on failure.
	 */
	public function retry_delivery( $delivery_id ) {
		return $this->webhook_service->retry_delivery( $delivery_id );
	}
}
