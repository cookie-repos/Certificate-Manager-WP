<?php
/**
 * Certificate Manager Email Service
 *
 * All direct database queries in this file use table names set by WordPress via
 * $wpdb->prefix. phpcs suppresses below address the interpolated-table-name and
 * caching warnings for queries that fetch live certificate data on every request.
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

use CertificateManager\Repositories\LogRepository;

/**
 * Email delivery service
 */
class EmailService {
	
	/**
	 * Log repository
	 *
	 * @var LogRepository
	 */
	private $log_repo;
	
	/**
	 * Constructor
	 *
	 * @param LogRepository $log_repo Log repository
	 */
	public function __construct( ?LogRepository $log_repo = null ) {
		$this->log_repo = $log_repo;
	}
	
	/**
	 * Send certificate email
	 *
	 * @param int $certificate_id Certificate ID
	 * @param int $template_id Template ID
	 * @param array $config Email configuration
	 * @return bool|WP_Error Success or error
	 */
	public function send_certificate_email( int $certificate_id, int $template_id, array $config = array() ) {
		global $wpdb;
		
		// Get certificate
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$certificate = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$cert_table} WHERE id = %d",
			$certificate_id
		), ARRAY_A );
		
		if ( ! $certificate ) {
			$this->log( 'error', __( 'Certificate not found', 'certificate-manager' ), array(
				'certificate_id' => $certificate_id,
			) );
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		// Get recipient email
		$recipient_email = $certificate['recipient_email'];
		if ( ! $recipient_email ) {
			$this->log( 'warning', __( 'Recipient email not provided', 'certificate-manager' ), array(
				'certificate_id' => $certificate_id,
			) );
			return new \WP_Error( 'no_recipient_email', __( 'Recipient email not provided', 'certificate-manager' ) );
		}
		
		// Validate email
		if ( ! is_email( $recipient_email ) ) {
			$this->log( 'error', __( 'Invalid recipient email address', 'certificate-manager' ), array(
				'certificate_id' => $certificate_id,
				'email' => $recipient_email,
			) );
			return new \WP_Error( 'invalid_email', __( 'Invalid recipient email address', 'certificate-manager' ) );
		}
		
		// Get email template content
		$email_content = $this->get_email_content( $template_id, $config );
		
		// Replace variables in subject and body
		$subject = $this->replace_email_variables( $email_content['subject'], $certificate );
		$body = $this->replace_email_variables( $email_content['body'], $certificate );
		
		// Build email headers
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		
		// Add attachments if configured
		$attachments = array();
		if ( ! empty( $config['attach_pdf'] ) && $config['attach_pdf'] ) {
			$attachments = $this->get_pdf_attachments( $certificate_id );
		}
		
		// Send email
		$result = wp_mail( $recipient_email, $subject, $body, $headers, $attachments );
		
		// Log result
		if ( $result ) {
			$this->log( 'info', __( 'Certificate email sent successfully', 'certificate-manager' ), array(
				'certificate_id' => $certificate_id,
				'recipient' => $recipient_email,
			) );
		} else {
			$this->log( 'error', __( 'Failed to send certificate email', 'certificate-manager' ), array(
				'certificate_id' => $certificate_id,
				'recipient' => $recipient_email,
			) );
		}
		
		return $result;
	}

	/**
	 * Email a private, short-lived wallet-claim link. This is intentionally a
	 * separate message from the public certificate verification experience.
	 *
	 * @param array $claim Claim data.
	 * @return bool|\WP_Error
	 */
	public function send_wallet_claim_email( array $claim ) {
		$recipient_email = sanitize_email( $claim['recipient_email'] ?? '' );
		if ( ! $recipient_email || ! is_email( $recipient_email ) || empty( $claim['claim_url'] ) ) {
			return new \WP_Error( 'invalid_wallet_claim_email', __( 'A valid email address and wallet link are required.', 'certificate-manager' ) );
		}
		$recipient_name = sanitize_text_field( $claim['recipient_name'] ?? '' );
		$certificate_number = sanitize_text_field( $claim['certificate_number'] ?? '' );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your private wallet link from %s', 'certificate-manager' ), get_bloginfo( 'name' ) );
		$body = sprintf(
			'<p>%s</p><p>%s</p><p><a href="%s">%s</a></p><p><small>%s</small></p>',
			/* translators: %s: recipient name */
			esc_html( $recipient_name ? sprintf( __( 'Hello %s,', 'certificate-manager' ), $recipient_name ) : __( 'Hello,', 'certificate-manager' ) ),
			/* translators: %s: certificate number */
			esc_html( $certificate_number ? sprintf( __( 'Use this private link to add certificate %s to a compatible wallet.', 'certificate-manager' ), $certificate_number ) : __( 'Use this private link to add your certificate to a compatible wallet.', 'certificate-manager' ) ),
			esc_url( $claim['claim_url'] ),
			esc_html__( 'Add certificate to wallet', 'certificate-manager' ),
			esc_html__( 'This link expires in 15 minutes. Do not forward it.', 'certificate-manager' )
		);
		$sent = wp_mail( $recipient_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		$this->log( $sent ? 'info' : 'error', $sent ? __( 'Private wallet link sent successfully', 'certificate-manager' ) : __( 'Failed to send private wallet link', 'certificate-manager' ), array( 'certificate_id' => absint( $claim['certificate_id'] ?? 0 ), 'recipient' => $recipient_email ) );
		return $sent;
	}
	
	/**
	 * Get email content from template
	 *
	 * @param int $template_id Template ID
	 * @param array $config Email configuration
	 * @return array
	 */
	private function get_email_content( int $template_id, array $config = array() ): array {
		$settings = new \CertificateManager\Core\Settings();
		
		$defaults = array(
			'subject' => $settings->get( 'default_email_subject', __( 'Your Certificate is Ready', 'certificate-manager' ) ),
			'body' => $settings->get( 'default_email_body', __( 'Hello {{recipient_name}},', 'certificate-manager' ) . "\n\n" . __( 'Your certificate has been issued.', 'certificate-manager' ) ),
			'format' => 'html',
		);
		
		return array_merge( $defaults, $config );
	}
	
	/**
	 * Replace variables in email content
	 *
	 * @param string $content Email content
	 * @param array $certificate Certificate data
	 * @return string
	 */
	private function replace_email_variables( string $content, array $certificate ): string {
		$settings = new \CertificateManager\Core\Settings();
		$variables = array(
			'recipient_name' => $certificate['recipient_name'],
			'recipient_email' => $certificate['recipient_email'] ?? '',
			'certificate_number' => $certificate['certificate_number'] ?? '',
			'issue_date' => $certificate['issue_date'] ?? '',
			'expiry_date' => $certificate['expiry_date'] ?? '',
			'certificate_name' => '',
			'course_name' => '',
			'completion_date' => '',
			'issuer_name' => '',
			'issuer_title' => '',
			'site_name' => get_bloginfo( 'name' ),
			'site_url' => home_url(),
			'verification_url' => ! empty( $certificate['verification_token'] ) ? add_query_arg( 'v', substr( $certificate['verification_token'], 0, 16 ), $settings->get_verification_base_url() ) : $settings->get_verification_base_url(),
		);
		
		// Replace variables
		foreach ( $variables as $key => $value ) {
			$content = str_replace( '{{' . $key . '}}', $value, $content );
		}
		
		return $content;
	}
	
	/**
	 * Get PDF attachments
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array
	 */
	private function get_pdf_attachments( int $certificate_id ): array {
		global $wpdb;
		
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$certificate = $wpdb->get_row( $wpdb->prepare(
			"SELECT pdf_file_path FROM {$cert_table} WHERE id = %d",
			$certificate_id
		), ARRAY_A );
		
		if ( ! $certificate || ! $certificate['pdf_file_path'] || ! file_exists( $certificate['pdf_file_path'] ) ) {
			return array();
		}
		
		return array( $certificate['pdf_file_path'] );
	}
	
	/**
	 * Send expiry reminder
	 *
	 * @param int $certificate_id Certificate ID
	 * @return bool|WP_Error Success or error
	 */
	public function send_expiry_reminder( int $certificate_id ) {
		global $wpdb;
		
		// Get certificate
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$certificate = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$cert_table} WHERE id = %d",
			$certificate_id
		), ARRAY_A );
		
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		// Check if certificate has expiry
		if ( ! $certificate['expiry_date'] ) {
			$this->log( 'info', __( 'Certificate has no expiry date', 'certificate-manager' ), array(
				'certificate_id' => $certificate_id,
			) );
			return false;
		}
		
		// Get template
		$template = $this->get_template_for_certificate( $certificate_id );
		if ( ! $template ) {
			return new \WP_Error( 'template_not_found', __( 'Template not found', 'certificate-manager' ) );
		}
		
		// Get expiry reminder settings
		$reminders = $this->get_expiry_reminders( $template );
		
		if ( empty( $reminders ) ) {
			return false;
		}
		
		// Send reminder emails
		$sent = false;
		foreach ( $reminders as $reminder ) {
			$success = $this->send_reminder_email( $certificate_id, $reminder );
			if ( $success ) {
				$sent = true;
			}
		}
		
		return $sent;
	}
	
	/**
	 * Get template for certificate
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array|false
	 */
	private function get_template_for_certificate( int $certificate_id ) {
		global $wpdb;
		
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$template_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT template_id FROM {$cert_table} WHERE id = %d",
			$certificate_id
		) );
		
		if ( ! $template_id ) {
			return false;
		}
		
		$template_table = $wpdb->prefix . 'certificate_manager_templates';
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$template_table} WHERE id = %d",
			$template_id
		), ARRAY_A );
	}
	
	/**
	 * Get expiry reminders for template
	 *
	 * @param array $template Template data
	 * @return array
	 */
	private function get_expiry_reminders( array $template ): array {
		$settings = new \CertificateManager\Core\Settings();
		
		$default_reminders = $settings->get( 'expiry_reminder_hours', array( 168, 72, 24, 0 ) ); // 7 days, 3 days, 1 day, on expiry
		
		// Get template-specific settings
		$template_settings = get_post_meta( $template['wp_post_id'], 'certificate_manager_template_settings', true );
		
		if ( ! empty( $template_settings['expiry_reminder_hours'] ) ) {
			return $template_settings['expiry_reminder_hours'];
		}
		
		return $default_reminders;
	}
	
	/**
	 * Send reminder email
	 *
	 * @param int $certificate_id Certificate ID
	 * @param int $hours_before Hours before expiry
	 * @return bool
	 */
	private function send_reminder_email( int $certificate_id, int $hours_before ): bool {
		// Calculate if this is the right time to send reminder
		// This is a simplified implementation
		// In production, you'd check if the certificate is exactly $hours_before days from expiry
		
		return true;
	}
	
	/**
	 * Resend certificate email
	 *
	 * @param int $certificate_id Certificate ID
	 * @return bool|WP_Error Success or error
	 */
	public function resend_email( int $certificate_id ) {
		global $wpdb;
		
		// Get certificate
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		$certificate = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$cert_table} WHERE id = %d",
			$certificate_id
		), ARRAY_A );
		
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		// Get template
		$template = $this->get_template_for_certificate( $certificate_id );
		if ( ! $template ) {
			return new \WP_Error( 'template_not_found', __( 'Template not found', 'certificate-manager' ) );
		}
		
		// Send email
		return $this->send_certificate_email( $certificate_id, $template['id'] );
	}
	
	/**
	 * Send test email
	 *
	 * @param string $to Recipient email
	 * @param int $template_id Template ID
	 * @return bool|WP_Error Success or error
	 */
	public function send_test_email( string $to, int $template_id ) {
		$settings = new \CertificateManager\Core\Settings();
		
		$defaults = array(
			'subject' => sprintf(
				/* translators: %s: site name */
				__( 'Test Email - %s', 'certificate-manager' ),
				get_bloginfo( 'name' )
			),
			'body' => __( 'This is a test email from Certificate Manager.', 'certificate-manager' ) . "\n\n" . __( 'If you received this, your email configuration is working correctly.', 'certificate-manager' ),
			'format' => 'html',
		);
		
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		
		$result = wp_mail( $to, $defaults['subject'], $defaults['body'], $headers );
		
		if ( $result ) {
			$this->log( 'info', sprintf(
				/* translators: %s: recipient email address */
				__( 'Test email sent to %s', 'certificate-manager' ),
				$to
			) );
		} else {
			$this->log( 'error', sprintf(
				/* translators: %s: recipient email address */
				__( 'Failed to send test email to %s', 'certificate-manager' ),
				$to
			) );
		}
		
		return $result;
	}

	/**
	 * Log email delivery where a log repository is available.
	 */
	private function log( string $level, string $message, array $context = array() ): void {
		if ( $this->log_repo ) {
			$this->log_repo->add_log( $level, 'Email', $message, $context );
		}
	}
	
	/**
	 * Get email configuration for template
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_email_config( int $template_id ): array {
		$settings = new \CertificateManager\Core\Settings();
		
		$defaults = array(
			'enabled' => $settings->get( 'default_email_enabled', true ),
			'subject' => $settings->get( 'default_email_subject', __( 'Your Certificate is Ready', 'certificate-manager' ) ),
			'body' => $settings->get( 'default_email_body', '' ),
			'format' => 'html',
			'attach_pdf' => $settings->get( 'default_email_pdf_attached', true ),
			'include_download_link' => $settings->get( 'default_email_download_link', false ),
			'include_verification_link' => $settings->get( 'default_email_verification_link', true ),
		);
		
		// Get template-specific settings
		$template_settings = get_post_meta( $template_id, 'certificate_manager_template_settings', true );
		
		if ( $template_settings ) {
			$defaults = array_merge( $defaults, array_intersect_key( $template_settings, $defaults ) );
		}
		
		return $defaults;
	}
}
