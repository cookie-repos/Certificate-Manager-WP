<?php
/**
 * Certificate Manager Verification Service
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

use CertificateManager\Repositories\CertificateRepository;

/**
 * Certificate verification service
 */
class VerificationService {
	
	/**
	 * Certificate repository
	 *
	 * @var CertificateRepository
	 */
	private $certificate_repo;
	
	/**
	 * Constructor
	 *
	 * @param CertificateRepository $certificate_repo Certificate repository
	 */
	public function __construct( CertificateRepository $certificate_repo ) {
		$this->certificate_repo = $certificate_repo;
	}
	
	/**
	 * Verify certificate by verification token
	 *
	 * @param string $token Verification token
	 * @return array|WP_Error Certificate data or error
	 */
	public function verify_by_token( string $token ) {
		$certificate = $this->certificate_repo->get_certificate_by_verification_token( $token );
		
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		return $this->build_verification_response( $certificate );
	}
	
	/**
	 * Verify certificate by certificate number
	 *
	 * @param string $certificate_number Certificate number
	 * @return array|WP_Error Certificate data or error
	 */
	public function verify_by_number( string $certificate_number ) {
		$certificate = $this->certificate_repo->get_certificate_by_number( $certificate_number );
		
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		return $this->build_verification_response( $certificate );
	}

	/**
	 * Verify certificate by a shortened verification token.
	 *
	 * @param string $token Verification token prefix.
	 * @return array|WP_Error Certificate data or error.
	 */
	public function verify_by_short_token( string $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,32}$/', $token ) ) {
			return new \WP_Error( 'invalid_verification_token', __( 'The verification link is invalid.', 'certificate-manager' ) );
		}

		$certificate_id = $this->resolve_verification_token( $token );
		$certificate = $certificate_id ? $this->certificate_repo->get_certificate( $certificate_id ) : false;
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}

		return $this->build_verification_response( $certificate );
	}
	
	/**
	 * Build verification response
	 *
	 * @param array $certificate Certificate data
	 * @return array
	 */
	private function build_verification_response( array $certificate ): array {
		// Update verification count
		$this->increment_verification_count( $certificate['id'] );
		
		// Determine status
		$status = $this->determine_status( $certificate );
		
		// Get template
		$template = $this->certificate_repo->get_template( $certificate['template_id'] );
		if ( ! is_array( $template ) ) {
			$template = array();
		}
		
		// Get template variables
		$template_vars = $this->certificate_repo->get_template_variables( $certificate['template_id'] );
		
		// Get public fields
		$public_fields = $this->get_public_fields( $certificate, $template, $template_vars );
		
		return array(
			'status' => $status,
			'certificate' => array(
				'id' => $certificate['id'],
				'certificate_number' => $certificate['certificate_number'],
				'verification_token_short' => substr( $certificate['verification_token'], 0, 16 ),
				'status' => $certificate['status'],
				'issue_date' => $certificate['issue_date'],
				'expiry_date' => $certificate['expiry_date'],
				'recipient_name' => $certificate['recipient_name'],
			),
			'public_fields' => $public_fields,
			'template' => array(
				'id' => $template['id'] ?? null,
				'name' => $template['title'] ?? null,
			),
		);
	}
	
	/**
	 * Determine certificate status
	 *
	 * @param array $certificate Certificate data
	 * @return string
	 */
	private function determine_status( array $certificate ): string {
		$status = $certificate['status'];
		
		// Replaced takes precedence
		if ( $status === 'replaced' && ! empty( $certificate['replaced_by'] ) ) {
			return 'replaced';
		}
		
		// Revoked status
		if ( $status === 'revoked' ) {
			return 'revoked';
		}
		
		// Check expiry
		if ( $certificate['expiry_date'] ) {
			$expiry_time = strtotime( $certificate['expiry_date'] );
			if ( $expiry_time < time() ) {
				return 'expired';
			}
		}
		
		return 'valid';
	}
	
	/**
	 * Increment verification count
	 *
	 * @param int $certificate_id Certificate ID
	 */
	private function increment_verification_count( int $certificate_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table_name} SET verification_count = verification_count + 1 WHERE id = %d",
			$certificate_id
		) );
	}
	
	/**
	 * Get public fields
	 *
	 * @param array $certificate Certificate data
	 * @param array $template Template data
	 * @param array $template_vars Template variables
	 * @return array
	 */
	private function get_public_fields( array $certificate, array $template, array $template_vars ): array {
		// Get public visibility settings
		$visibility_settings = $template['public_visibility'] ?? array();
		if ( is_string( $visibility_settings ) ) {
			$visibility_settings = json_decode( $visibility_settings, true );
		}
		$visibility_settings = is_array( $visibility_settings ) ? $visibility_settings : array();

		// Hidden-by-default and email fields are never suitable for the public
		// verification page, even if a template contains stale visibility data.
		$public_variables = array();
		foreach ( $template_vars as $variable ) {
			$key = $variable['variable_key'] ?? '';
			if ( '' !== $key && ! empty( $variable['default_visible'] ) && 'email' !== ( $variable['field_type'] ?? '' ) ) {
				$public_variables[ $key ] = true;
			}
		}
		
		// Get certificate fields
		$fields = $this->certificate_repo->get_certificate_fields( $certificate['id'] );
		
		$result = array();
		
		foreach ( $fields as $field ) {
			$key = $field['variable_key'];
			
			// Check whether this template field is safe to place on a public page.
			if ( empty( $public_variables[ $key ] ) || ( isset( $visibility_settings[ $key ] ) && ! $visibility_settings[ $key ] ) ) {
				continue;
			}
			
			$result[ $key ] = array(
				'label' => $field['variable_label'],
				'value' => $field['value'],
			);
		}
		
		// Add standard fields
		if ( ! isset( $visibility_settings['certificate_number'] ) || $visibility_settings['certificate_number'] ) {
			$result['certificate_number'] = array(
				'label' => __( 'Certificate Number', 'certificate-manager' ),
				'value' => $certificate['certificate_number'],
			);
		}
		
		if ( ! isset( $visibility_settings['issue_date'] ) || $visibility_settings['issue_date'] ) {
			$result['issue_date'] = array(
				'label' => __( 'Issue Date', 'certificate-manager' ),
				'value' => $certificate['issue_date'],
			);
		}
		
		if ( ! isset( $visibility_settings['expiry_date'] ) || $visibility_settings['expiry_date'] ) {
			if ( $certificate['expiry_date'] ) {
				$result['expiry_date'] = array(
					'label' => __( 'Expiry Date', 'certificate-manager' ),
					'value' => $certificate['expiry_date'],
				);
			}
		}
		
		if ( ! isset( $visibility_settings['recipient_name'] ) || $visibility_settings['recipient_name'] ) {
			$result['recipient_name'] = array(
				'label' => __( 'Recipient Name', 'certificate-manager' ),
				'value' => $certificate['recipient_name'],
			);
		}
		
		return $result;
	}
	
	/**
	 * Create temporary download token
	 *
	 * @param int $certificate_id Certificate ID
	 * @return string Token
	 */
	public function create_download_token( int $certificate_id ): string {
		global $wpdb;
		
		$token = bin2hex( random_bytes( 32 ) );
		
		// Get download token lifetime from settings
		$settings = new \CertificateManager\Core\Settings();
		$lifetime = $settings->get( 'download_token_lifetime', 900 );
		
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $lifetime );
		
		$table_name = $wpdb->prefix . 'certificate_manager_download_tokens';
		$wpdb->insert( $table_name, array(
			'certificate_id' => $certificate_id,
			'token' => $token,
			'expires_at' => $expires_at,
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $token;
	}
	
	/**
	 * Validate download token
	 *
	 * @param string $token Token
	 * @return array|WP_Error Certificate ID or error
	 */
	public function validate_download_token( string $token ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_download_tokens';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed.
		$token_data = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE token = %s AND expires_at > %s AND used = 0",
			$token, current_time( 'mysql' )
		), ARRAY_A );
		
		if ( ! $token_data ) {
			return new \WP_Error( 'invalid_token', __( 'Invalid or expired download token', 'certificate-manager' ) );
		}
		
		return $token_data;
	}
	
	/**
	 * Mark token as used
	 *
	 * @param int $token_id Token ID
	 */
	public function mark_token_as_used( int $token_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_download_tokens';
		$wpdb->update( $table_name, array(
			'used' => 1,
			'used_at' => current_time( 'mysql' ),
		), array( 'id' => $token_id ), array( '%d', '%s' ), array( '%d' ) );
	}
	
	/**
	 * Generate verification QR code data
	 *
	 * @param int $certificate_id Certificate ID
	 * @return string Verification URL
	 */
	public function get_verification_url( int $certificate_id ): string {
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return '';
		}
		
		$settings = new \CertificateManager\Core\Settings();
		$token = $this->create_verification_short_token( $certificate_id );
		return $token ? add_query_arg( 'v', $token, $settings->get_verification_base_url() ) : '';
	}
	
	/**
	 * Create short verification token
	 *
	 * @param int $certificate_id Certificate ID
	 * @return string
	 */
	private function create_verification_short_token( int $certificate_id ): string {
		global $wpdb;
		
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return '';
		}
		
		$token = substr( $certificate['verification_token'], 0, 16 );
		
		// Cache token mapping
		set_transient( 'cm_verification_token_' . $token, $certificate_id, HOUR_IN_SECONDS );
		
		return $token;
	}
	
	/**
	 * Resolve short token to certificate ID
	 *
	 * @param string $token Token
	 * @return int|false Certificate ID or false
	 */
	public function resolve_verification_token( string $token ) {
		$certificate_id = get_transient( 'cm_verification_token_' . $token );
		
		if ( $certificate_id ) {
			return (int) $certificate_id;
		}
		
		// Fallback: search by partial token
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed.
		$cert = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE verification_token LIKE %s LIMIT 1",
			$wpdb->esc_like( $token ) . '%'
		), ARRAY_A );
		
		if ( $cert ) {
			return $cert['id'];
		}
		
		return false;
	}
	
	/**
	 * Get verification statistics
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array
	 */
	public function get_verification_stats( int $certificate_id ): array {
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return array();
		}
		
		return array(
			'total_verifications' => $certificate['verification_count'] ?? 0,
			'qr_verifications' => $certificate['qr_verification_count'] ?? 0,
			'manual_verifications' => $certificate['manual_verification_count'] ?? 0,
		);
	}
}
