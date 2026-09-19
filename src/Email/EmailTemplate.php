<?php
/**
 * Email Template
 *
 * @package CertificateManager
 */

namespace CertificateManager\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template class
 */
class EmailTemplate {
	
	/**
	 * Template repository
	 *
	 * @var Repositories\TemplateRepository
	 */
	private $template_repo;
	
	/**
	 * Variable repository
	 *
	 * @var Repositories\VariableRepository
	 */
	private $variable_repo;
	
	/**
	 * Constructor
	 *
	 * @param Repositories\TemplateRepository $template_repo Template repository.
	 * @param Repositories\VariableRepository $variable_repo Variable repository.
	 */
	public function __construct( $template_repo, $variable_repo ) {
		$this->template_repo = $template_repo;
		$this->variable_repo = $variable_repo;
	}
	
	/**
	 * Get email template
	 *
	 * @param string $type Template type.
	 * @return array Template data.
	 */
	public function get_template( $type ) {
		$defaults = array(
			'issued' => array(
				'subject' => __( 'Your Certificate is Ready', 'certificate-manager' ),
				'body' => __( 'Hello {{recipient_name}},', 'certificate-manager' ) . "\n\n" . __( 'Your certificate has been issued successfully.', 'certificate-manager' ) . "\n\n" . __( 'You can download your certificate from the link below:', 'certificate-manager' ) . "\n\n{{certificate_download_url}}\n\n" . __( 'Best regards,', 'certificate-manager' ) . "\n{{issuer_name}}",
			),
			'revoked' => array(
				'subject' => __( 'Certificate Revoked', 'certificate-manager' ),
				'body' => __( 'Hello {{recipient_name}},', 'certificate-manager' ) . "\n\n" . __( 'Your certificate has been revoked by the administrator.', 'certificate-manager' ) . "\n\n" . __( 'Reason:', 'certificate-manager' ) . " {{revocation_reason}}\n\n" . __( 'Best regards,', 'certificate-manager' ) . "\n{{issuer_name}}",
			),
			'expiry_reminder' => array(
				'subject' => __( 'Certificate Expiration Reminder', 'certificate-manager' ),
				'body' => __( 'Hello {{recipient_name}},', 'certificate-manager' ) . "\n\n" . __( 'Your certificate will expire in {{days_before}} days.', 'certificate-manager' ) . "\n\n" . __( 'Certificate Number: {{certificate_number}}', 'certificate-manager' ) . "\n" . __( 'Expiration Date: {{expiration_date}}', 'certificate-manager' ) . "\n\n" . __( 'Best regards,', 'certificate-manager' ) . "\n{{issuer_name}}",
			),
		);
		
		return $defaults[ $type ] ?? array();
	}
	
	/**
	 * Render email template with variables
	 *
	 * @param string $type Template type.
	 * @param array  $variables Variable values.
	 * @return array Rendered template (subject and body).
	 */
	public function render( $type, $variables ) {
		$template = $this->get_template( $type );
		
		$subject = $template['subject'];
		$body = $template['body'];
		
		foreach ( $variables as $key => $value ) {
			$subject = str_replace( '{{' . $key . '}}', $value, $subject );
			$body = str_replace( '{{' . $key . '}}', $value, $body );
		}
		
		return array(
			'subject' => $subject,
			'body' => $body,
		);
	}
	
	/**
	 * Get available template variables
	 *
	 * @return array Array of available variables.
	 */
	public function get_available_variables() {
		return array(
			'recipient_name' => __( 'Recipient Name', 'certificate-manager' ),
			'recipient_email' => __( 'Recipient Email', 'certificate-manager' ),
			'certificate_number' => __( 'Certificate Number', 'certificate-manager' ),
			'issue_date' => __( 'Issue Date', 'certificate-manager' ),
			'expiration_date' => __( 'Expiration Date', 'certificate-manager' ),
			'issuer_name' => __( 'Issuer Name', 'certificate-manager' ),
			'certificate_download_url' => __( 'Certificate Download URL', 'certificate-manager' ),
			'revocation_reason' => __( 'Revocation Reason', 'certificate-manager' ),
			'days_before' => __( 'Days Before Expiration', 'certificate-manager' ),
		);
	}
}
