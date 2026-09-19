<?php
/**
 * Designer Controller
 *
 * @package CertificateManager
 */

namespace CertificateManager\Designer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Designer controller class
 */
class Controller {
	
	/**
	 * Designer service
	 *
	 * @var Services\DesignerService
	 */
	private $designer_service;
	
	/**
	 * Settings
	 *
	 * @var Core\Settings
	 */
	private $settings;
	
	/**
	 * Constructor
	 *
	 * @param Services\DesignerService $designer_service Designer service.
	 * @param Core\Settings           $settings Settings instance.
	 */
	public function __construct( $designer_service, $settings ) {
		$this->designer_service = $designer_service;
		$this->settings = $settings;
	}
	
	/**
	 * Initialize frontend functionality
	 */
	public function init() {
		add_action( 'wp_ajax_cm_get_preview', array( $this, 'get_preview' ) );
	}
	
	/**
	 * Get template preview via AJAX
	 */
	public function get_preview() {
		if ( ! current_user_can( 'cm_view_template' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$template_id = intval( $_POST['template_id'] );
		$variables = isset( $_POST['variables'] ) ? wp_kses_post_deep( $_POST['variables'] ) : array();
		
		$preview_html = $this->designer_service->render_preview( $template_id, $variables );
		
		if ( $preview_html ) {
			wp_send_json_success( array( 'html' => $preview_html ) );
		}
		
		wp_send_json_error( array( 'message' => __( 'Failed to generate preview', 'certificate-manager' ) ), 500 );
	}
}
