<?php
/**
 * Certificate Manager Issuance Service
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
use CertificateManager\Repositories\SequenceRepository;
use CertificateManager\Repositories\AuditRepository;
use CertificateManager\Repositories\LogRepository;
use CertificateManager\Database\Schema;

/**
 * Certificate issuance service
 */
class IssuanceService {
	
	/**
	 * Certificate repository
	 *
	 * @var CertificateRepository
	 */
	private $certificate_repo;
	
	/**
	 * Sequence repository
	 *
	 * @var SequenceRepository
	 */
	private $sequence_repo;
	
	/**
	 * Audit repository
	 *
	 * @var AuditRepository
	 */
	private $audit_repo;
	
	/**
	 * Log repository
	 *
	 * @var LogRepository
	 */
	private $log_repo;
	
	/**
	 * Template repository
	 *
	 * @var mixed
	 */
	private $template_repo;
	
	/**
	 * Constructor
	 *
	 * @param mixed $template_repo Template repository
	 * @param SequenceRepository $sequence_repo Sequence repository
	 * @param AuditRepository $audit_repo Audit repository
	 * @param LogRepository $log_repo Log repository
	 */
	public function __construct( $template_repo, SequenceRepository $sequence_repo, AuditRepository $audit_repo, LogRepository $log_repo ) {
		$this->template_repo = $template_repo;
		$this->certificate_repo = new CertificateRepository();
		$this->sequence_repo = $sequence_repo;
		$this->audit_repo = $audit_repo;
		$this->log_repo = $log_repo;
	}

	public function get_certificate_repository() {
		return $this->certificate_repo;
	}

	public function get_template_repository() {
		return $this->template_repo;
	}

	public function get_audit_repository() {
		return $this->audit_repo;
	}
	
	/**
	 * Issue a certificate
	 *
	 * @param array $data Certificate data
	 * @return array|WP_Error Certificate data or error
	 */
	public function issue_certificate( array $data ) {
		global $wpdb;
		
		// Validate template
		$template = $this->template_repo->get_template( $data['template_id'] );
		if ( ! $template || $template['is_archived'] || $template['is_deleted'] ) {
			return new \WP_Error( 'invalid_template', __( 'Invalid or archived template', 'certificate-manager' ) );
		}
		
		if ( ! $template['published_version_id'] ) {
			return new \WP_Error( 'unpublished_template', __( 'Template is not published', 'certificate-manager' ) );
		}
		
		// Validate required fields
		$errors = $this->validate_required_fields( $data );
		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'validation_failed', __( 'Validation failed', 'certificate-manager' ), array( 'errors' => $errors ) );
		}
		
		// Validate template variables
		$template_vars = $this->template_repo->get_template_variables( $data['template_id'] );
		$validation_errors = $this->validate_template_fields( $data, $template_vars );
		if ( ! empty( $validation_errors ) ) {
			return new \WP_Error( 'field_validation_failed', __( 'Field validation failed', 'certificate-manager' ), array( 'errors' => $validation_errors ) );
		}
		
		// Get or create sequence
		$sequence_id = $this->get_sequence_id( $data );
		if ( is_wp_error( $sequence_id ) ) {
			return $sequence_id;
		}
		
		// Generate certificate number (concurrency-safe)
		$certificate_number = $this->generate_certificate_number( $sequence_id );
		if ( is_wp_error( $certificate_number ) ) {
			return $certificate_number;
		}
		
		// Generate internal ID (UUID)
		$internal_id = $this->generate_internal_id();
		
		// Generate verification token
		$verification_token = $this->generate_verification_token();
		
		// Set issue date
		$issue_date = $this->normalize_issue_date( $data['issue_date'] ?? '' );
		
		// Calculate expiry date if enabled
		$expiry_date = null;
		if ( isset( $data['expiry_enabled'] ) && $data['expiry_enabled'] ) {
			$expiry_date = $this->calculate_expiry_date( $issue_date, $data['expiry_quantity'], $data['expiry_unit'] );
		}

		$data['certificate_number'] = $certificate_number;
		$data['issue_date'] = $issue_date;
		$data['expiry_date'] = $expiry_date;
		
		// Save certificate data snapshot
		$data_snapshot = $this->prepare_data_snapshot( $data, $template );
		
		// Insert certificate
		$cert_data = array(
			'internal_id' => $internal_id,
			'certificate_number' => $certificate_number,
			'verification_token' => $verification_token,
			'template_id' => $data['template_id'],
			'template_version_id' => $template['published_version_id'],
			'recipient_name' => $data['recipient_name'],
			'recipient_email' => isset( $data['recipient_email'] ) ? $data['recipient_email'] : null,
			'status' => 'active',
			'issue_date' => $issue_date,
			'expiry_date' => $expiry_date,
			'data_snapshot' => wp_json_encode( $data_snapshot ),
			'tags' => isset( $data['tags'] ) ? wp_json_encode( $data['tags'] ) : null,
			'private_notes' => isset( $data['private_notes'] ) ? $data['private_notes'] : null,
			'internal_notes' => isset( $data['internal_notes'] ) ? $data['internal_notes'] : null,
			'source' => $data['source'] ?? 'manual',
			'source_details' => isset( $data['source_details'] ) ? wp_json_encode( $data['source_details'] ) : null,
			'issuer_id' => isset( $data['issuer_id'] ) ? $data['issuer_id'] : get_current_user_id(),
			'api_key_id' => isset( $data['api_key_id'] ) ? $data['api_key_id'] : null,
			'replaced_by' => null,
			'trashed_at' => null,
			'deleted_at' => null,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);
		
		$wpdb->insert( $wpdb->prefix . 'certificate_manager_certificates', $cert_data );
		$certificate_id = $wpdb->insert_id;
		
		if ( ! $certificate_id ) {
			return new \WP_Error( 'database_error', __( 'Failed to create certificate', 'certificate-manager' ) );
		}
		
		// Save certificate fields
		$this->save_certificate_fields( $certificate_id, $data, $template_vars );
		
		// Log audit event
		$this->audit_repo->log( 'certificate', 'Issued', $certificate_id, array(
			'source' => $data['source'] ?? 'manual',
			'template_id' => $data['template_id'],
			'certificate_number' => $certificate_number,
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		$webhook_ids = json_decode( $template['webhook_ids'] ?? '[]', true );
		$webhook_ids = is_array( $webhook_ids ) ? array_values( array_filter( array_map( 'absint', $webhook_ids ) ) ) : array();
		$template_field_keys = $this->get_template_variable_keys( $template );
		$template_fields = array_intersect_key( $data, array_flip( $template_field_keys ) );
		do_action( 'certificate_manager_certificate_issued', $certificate_id, array(
			'internal_id' => $internal_id,
			'certificate_number' => $certificate_number,
			'recipient_name' => $data['recipient_name'],
			'recipient_email' => $data['recipient_email'] ?? '',
			'status' => 'active',
			'issue_date' => $issue_date,
			'expiry_date' => $expiry_date,
			'webhook_ids' => $webhook_ids,
			'template_id' => absint( $data['template_id'] ),
			'template_name' => $template['title'] ?? '',
			'template_fields' => $template_fields,
		) );
		
		// Return certificate data
		return array(
			'id' => $certificate_id,
			'internal_id' => $internal_id,
			'certificate_number' => $certificate_number,
			'verification_token' => $verification_token,
			'status' => 'active',
			'issue_date' => $issue_date,
			'expiry_date' => $expiry_date,
		);
	}
	
	/**
	 * Validate required fields
	 *
	 * @param array $data Certificate data
	 * @return array Validation errors
	 */
	private function validate_required_fields( array $data ): array {
		$errors = array();
		
		if ( empty( $data['template_id'] ) ) {
			$errors[] = __( 'Template ID is required', 'certificate-manager' );
		}
		
		if ( empty( $data['recipient_name'] ) ) {
			$errors[] = __( 'Recipient name is required', 'certificate-manager' );
		}
		
		return $errors;
	}
	
	/**
	 * Validate template fields
	 *
	 * @param array $data Certificate data
	 * @param array $template_vars Template variables
	 * @return array Validation errors
	 */
	private function validate_template_fields( array $data, array $template_vars ): array {
		$errors = array();
		
		foreach ( $template_vars as $var ) {
			$key = $var['variable_key'];
			$value = $data[ $key ] ?? null;
			
			if ( $var['is_required'] && empty( $value ) ) {
				$errors[] = sprintf(
					/* translators: %s: field label */
					__( '%s is required', 'certificate-manager' ),
					$var['variable_label']
				);
				continue;
			}
			
			if ( ! empty( $value ) ) {
				$field_type = $var['field_type'];
				if ( ! $this->validate_field_value( $value, $field_type ) ) {
					$errors[] = sprintf(
						/* translators: %s: field label */
						__( '%s is invalid', 'certificate-manager' ),
						$var['variable_label']
					);
				} elseif ( 'image' === $field_type ) {
					$options = json_decode( $var['field_options'] ?? '[]', true );
					$options = is_array( $options ) ? array_map( 'absint', $options ) : array();
					if ( $options && ! in_array( absint( $value ), $options, true ) ) {
						$errors[] = sprintf(
							/* translators: %s: field label */
							__( '%s must use one of the approved images', 'certificate-manager' ),
							$var['variable_label']
						);
					}
				}
			}
		}
		
		return $errors;
	}
	
	/**
	 * Validate field value by type
	 *
	 * @param mixed $value Field value
	 * @param string $field_type Field type
	 * @return bool
	 */
	private function validate_field_value( $value, string $field_type ): bool {
		switch ( $field_type ) {
			case 'email':
				return is_email( $value );
			case 'number':
			case 'integer':
			case 'decimal':
				return is_numeric( $value );
			case 'url':
				return filter_var( $value, FILTER_VALIDATE_URL ) !== false;
			case 'date':
				return (bool) strtotime( $value );
			case 'image':
				return absint( $value ) > 0 && wp_attachment_is_image( absint( $value ) );
			case 'checkbox':
				return in_array( $value, array( true, false, 1, 0, 'true', 'false' ), true );
			default:
				return true;
		}
	}
	
	/**
	 * Get sequence ID for certificate
	 *
	 * @param array $data Certificate data
	 * @return int|WP_Error Sequence ID
	 */
	private function get_sequence_id( array $data ) {
		$template = $this->template_repo->get_template( $data['template_id'] );
		if ( ! $template ) {
			return new \WP_Error( 'template_not_found', __( 'Template not found', 'certificate-manager' ) );
		}
		
		$sequence_id = $template['certificate_sequence_id'] ?? 0;
		
		if ( ! $sequence_id ) {
			$sequence = $this->sequence_repo->get_global_sequence();
			if ( ! $sequence ) {
				$this->sequence_repo->create_sequence( array(
					'name' => 'global',
					'current_value' => 0,
					'padding' => 4,
					'is_global' => 1,
				) );
				$sequence = $this->sequence_repo->get_global_sequence();
			}
			if ( ! $sequence ) {
				return new \WP_Error( 'no_sequence', __( 'Certificate numbering could not be initialized', 'certificate-manager' ) );
			}
			return $sequence['id'];
		}
		
		return $sequence_id;
	}
	
	/**
	 * Generate certificate number
	 *
	 * @param int $sequence_id Sequence ID
	 * @return string|WP_Error Certificate number
	 */
	private function generate_certificate_number( int $sequence_id ) {
		global $wpdb;
		
		// Get sequence with lock
		$sequence = $this->sequence_repo->get_sequence_for_update( $sequence_id );
		if ( ! $sequence ) {
			return new \WP_Error( 'sequence_not_found', __( 'Certificate sequence not found', 'certificate-manager' ) );
		}
		
		// Increment sequence
		$new_value = $sequence['current_value'] + 1;
		
		$wpdb->update(
			$wpdb->prefix . 'certificate_manager_sequences',
			array(
				'current_value' => $new_value,
				'last_allocated_at' => current_time( 'mysql' ),
				'last_allocated_by' => get_current_user_id(),
			),
			array( 'id' => $sequence_id ),
			array( '%d', '%s', '%d' ),
			array( '%d' )
		);
		
		// Generate formatted number
		$format = $this->get_number_format( $sequence_id );
		$number = $this->format_certificate_number( $format, $sequence_id, $new_value );
		
		// Check for uniqueness (extra safety)
		if ( $this->certificate_repo->certificate_number_exists( $number ) ) {
			return $this->generate_certificate_number( $sequence_id );
		}
		
		return $number;
	}
	
	/**
	 * Get number format for sequence
	 *
	 * @param int $sequence_id Sequence ID
	 * @return string
	 */
	private function get_number_format( int $sequence_id ): string {
		$settings = new \CertificateManager\Core\Settings();
		return $settings->get( 'default_number_format', '{year}{month}{day}-{seq}' );
	}
	
	/**
	 * Format certificate number
	 *
	 * @param string $format Number format
	 * @param int $sequence_id Sequence ID
	 * @param int $value Current sequence value
	 * @return string
	 */
	private function format_certificate_number( string $format, int $sequence_id, int $value ): string {
		$sequence = $this->sequence_repo->get_sequence( $sequence_id );
		$padding = $sequence['padding'] ?? 1;
		
		// Get current date
		$now = current_time( 'timestamp' );
		
		// Replace tokens
		$replacements = array(
			'{year}' => gmdate( 'Y', $now ),
			'{month}' => gmdate( 'm', $now ),
			'{day}' => gmdate( 'd', $now ),
			'{seq}' => str_pad( (string) $value, $padding, '0', STR_PAD_LEFT ),
			'{seq_unpadded}' => (string) $value,
			'{sequence}' => $sequence['name'],
		);
		
		return str_replace( array_keys( $replacements ), array_values( $replacements ), $format );
	}
	
	/**
	 * Generate internal ID (UUID)
	 *
	 * @return string
	 */
	private function generate_internal_id(): string {
		if ( function_exists( 'uuid_create' ) ) {
			return uuid_create( UUID_TYPE_RANDOM );
		}
		
		// Fallback UUID generation
		$chars = md5( uniqid( (string) wp_rand(), true ) );
		$uuid = substr( $chars, 0, 8 ) . '-';
		$uuid .= substr( $chars, 8, 4 ) . '-';
		$uuid .= substr( $chars, 12, 4 ) . '-';
		$uuid .= substr( $chars, 16, 4 ) . '-';
		$uuid .= substr( $chars, 20, 12 );
		
		return $uuid;
	}
	
	/**
	 * Generate verification token
	 *
	 * @return string
	 */
	private function generate_verification_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
	
	/**
	 * Calculate expiry date
	 *
	 * @param string $issue_date Issue date
	 * @param int $quantity Quantity
	 * @param string $unit Unit (hours|days|months|years)
	 * @return string|false Expiry date or false
	 */
	private function calculate_expiry_date( string $issue_date, int $quantity, string $unit ) {
		$datetime = new \DateTime( $issue_date );
		
		switch ( $unit ) {
			case 'hours':
				$datetime->modify( '+' . $quantity . ' hours' );
				break;
			case 'days':
				$datetime->modify( '+' . $quantity . ' days' );
				break;
			case 'months':
				$datetime->modify( '+' . $quantity . ' months' );
				break;
			case 'years':
				$datetime->modify( '+' . $quantity . ' years' );
				break;
			default:
				return false;
		}
		
		return $datetime->format( 'Y-m-d H:i:s' );
	}

	private function normalize_issue_date( $issue_date ): string {
		$issue_date = trim( (string) $issue_date );
		if ( '' === $issue_date ) {
			return current_time( 'mysql' );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date ) ) {
			return $issue_date . ' ' . current_time( 'H:i:s' );
		}

		try {
			return ( new \DateTime( $issue_date, wp_timezone() ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $error ) {
			return current_time( 'mysql' );
		}
	}
	
	/**
	 * Prepare data snapshot
	 *
	 * @param array $data Certificate data
	 * @param array $template Template data
	 * @return array
	 */
	private function prepare_data_snapshot( array $data, array $template ): array {
		return array(
			'recipient_name' => $data['recipient_name'],
			'recipient_email' => $data['recipient_email'] ?? null,
			'certificate_number' => $data['certificate_number'] ?? null,
			'issue_date' => $data['issue_date'] ?? null,
			'expiry_date' => $data['expiry_date'] ?? null,
			'template_name' => $template['title'] ?? null,
			'variables' => array_intersect_key( $data, array_flip( $this->get_template_variable_keys( $template ) ) ),
		);
	}
	
	/**
	 * Get template variable keys
	 *
	 * @param array $template Template data
	 * @return array
	 */
	private function get_template_variable_keys( array $template ): array {
		$keys = array();
		
		$variables = $this->template_repo->get_template_variables( $template['id'] ?? 0 );
		foreach ( $variables as $var ) {
			$keys[] = $var['variable_key'];
		}
		
		return $keys;
	}
	
	/**
	 * Save certificate fields
	 *
	 * @param int $certificate_id Certificate ID
	 * @param array $data Certificate data
	 * @param array $template_vars Template variables
	 */
	private function save_certificate_fields( int $certificate_id, array $data, array $template_vars ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificate_fields';
		
		foreach ( $template_vars as $var ) {
			$key = $var['variable_key'];
			$value = $data[ $key ] ?? null;
			
			if ( $value !== null && $value !== '' ) {
				$wpdb->insert( $table_name, array(
					'certificate_id' => $certificate_id,
					'variable_key' => $key,
					'variable_label' => $var['variable_label'],
					'value' => $value,
					'created_at' => current_time( 'mysql' ),
				) );
			}
		}
	}
	
	/**
	 * Update certificate
	 *
	 * @param int $certificate_id Certificate ID
	 * @param array $data Certificate data
	 * @return array|WP_Error Certificate data or error
	 */
	public function update_certificate( int $certificate_id, array $data ) {
		global $wpdb;
		
		// Get certificate
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		// Get template variables
		$template_vars = $this->template_repo->get_template_variables( $certificate['template_id'] );
		
		// Validate fields
		$validation_errors = $this->validate_template_fields( $data, $template_vars );
		if ( ! empty( $validation_errors ) ) {
			return new \WP_Error( 'field_validation_failed', __( 'Field validation failed', 'certificate-manager' ), array( 'errors' => $validation_errors ) );
		}
		
		// Prepare update data
		$update_data = array();
		
		if ( isset( $data['recipient_name'] ) ) {
			$update_data['recipient_name'] = $data['recipient_name'];
		}
		
		if ( isset( $data['recipient_email'] ) ) {
			$update_data['recipient_email'] = $data['recipient_email'];
		}
		
		if ( isset( $data['expiry_date'] ) ) {
			$update_data['expiry_date'] = $data['expiry_date'];
		}
		
		if ( isset( $data['tags'] ) ) {
			$update_data['tags'] = wp_json_encode( $data['tags'] );
		}
		
		if ( isset( $data['private_notes'] ) ) {
			$update_data['private_notes'] = $data['private_notes'];
		}
		
		if ( isset( $data['internal_notes'] ) ) {
			$update_data['internal_notes'] = $data['internal_notes'];
		}
		
		if ( ! empty( $update_data ) ) {
			$wpdb->update(
				$wpdb->prefix . 'certificate_manager_certificates',
				$update_data,
				array( 'id' => $certificate_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}
		
		// Update certificate fields
		$this->update_certificate_fields( $certificate_id, $data, $template_vars );
		
		// Log audit event
		$this->audit_repo->log( 'certificate', 'Edited', $certificate_id, array(
			'changes' => $update_data,
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return $this->certificate_repo->get_certificate( $certificate_id );
	}
	
	/**
	 * Update certificate fields
	 *
	 * @param int $certificate_id Certificate ID
	 * @param array $data Certificate data
	 * @param array $template_vars Template variables
	 */
	private function update_certificate_fields( int $certificate_id, array $data, array $template_vars ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificate_fields';
		
		// Delete existing fields
		$wpdb->delete( $table_name, array( 'certificate_id' => $certificate_id ), array( '%d' ) );
		
		// Insert updated fields
		foreach ( $template_vars as $var ) {
			$key = $var['variable_key'];
			$value = $data[ $key ] ?? null;
			
			if ( $value !== null && $value !== '' ) {
				$wpdb->insert( $table_name, array(
					'certificate_id' => $certificate_id,
					'variable_key' => $key,
					'variable_label' => $var['variable_label'],
					'value' => $value,
					'created_at' => current_time( 'mysql' ),
				) );
			}
		}
	}
	
	/**
	 * Revoke certificate
	 *
	 * @param int $certificate_id Certificate ID
	 * @param string $reason Revocation reason
	 * @param bool $public Make reason public
	 * @return array|WP_Error Certificate data or error
	 */
	public function revoke_certificate( int $certificate_id, string $reason = '', bool $public = false ) {
		global $wpdb;
		
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		if ( $certificate['status'] !== 'active' ) {
			return new \WP_Error( 'invalid_status', __( 'Certificate is not active', 'certificate-manager' ) );
		}
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		$user_id = get_current_user_id();
		
		$update_data = array(
			'status' => 'revoked',
			'revoked_at' => current_time( 'mysql' ),
			'revocation_reason' => $reason,
			'revocation_reason_public' => $public ? 1 : 0,
		);
		
		// Only set revoked_by if we have a valid user ID
		if ( $user_id > 0 ) {
			$update_data['revoked_by'] = $user_id;
		}
		
		$updated = $wpdb->update(
			$table_name,
			$update_data,
			array( 'id' => $certificate_id ),
			null, // Let WordPress infer the format
			array( '%d' )
		);

		if ( false === $updated ) {
			// Try simpler update with just status
			$updated = $wpdb->update(
				$table_name,
				array( 'status' => 'revoked' ),
				array( 'id' => $certificate_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		if ( false === $updated || 0 === $updated ) {
			$message = $wpdb->last_error ?: __( 'The certificate status was not changed', 'certificate-manager' );
			return new \WP_Error( 'revocation_failed', $message );
		}

		$revoked_certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $revoked_certificate || 'revoked' !== $revoked_certificate['status'] ) {
			return new \WP_Error( 'revocation_not_persisted', __( 'The certificate could not be marked as revoked', 'certificate-manager' ) );
		}
		
		// Log audit event
		$this->audit_repo->log( 'certificate', 'Revoked', $certificate_id, array(
			'reason' => $reason,
			'public' => $public,
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return $revoked_certificate;
	}
	
	/**
	 * Reinstate certificate
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array|WP_Error Certificate data or error
	 */
	public function reinstate_certificate( int $certificate_id ) {
		global $wpdb;
		
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		if ( $certificate['status'] !== 'revoked' ) {
			return new \WP_Error( 'invalid_status', __( 'Certificate is not revoked', 'certificate-manager' ) );
		}
		
		// Update status
		$wpdb->update(
			$wpdb->prefix . 'certificate_manager_certificates',
			array(
				'status' => 'active',
				'reinstated_at' => current_time( 'mysql' ),
				'reinstated_by' => get_current_user_id(),
			),
			array( 'id' => $certificate_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
		
		// Log audit event
		$this->audit_repo->log( 'certificate', 'Reinstated', $certificate_id, array(), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return $this->certificate_repo->get_certificate( $certificate_id );
	}
	
	/**
	 * Replace certificate
	 *
	 * @param int $original_certificate_id Original certificate ID
	 * @param int $replacement_certificate_id Replacement certificate ID
	 * @return array|WP_Error Replacement data or error
	 */
	public function replace_certificate( int $original_certificate_id, int $replacement_certificate_id ) {
		global $wpdb;
		
		// Get certificates
		$original = $this->certificate_repo->get_certificate( $original_certificate_id );
		$replacement = $this->certificate_repo->get_certificate( $replacement_certificate_id );
		
		if ( ! $original ) {
			return new \WP_Error( 'original_not_found', __( 'Original certificate not found', 'certificate-manager' ) );
		}
		
		if ( ! $replacement ) {
			return new \WP_Error( 'replacement_not_found', __( 'Replacement certificate not found', 'certificate-manager' ) );
		}
		
		// Prevent circular replacement
		if ( $original['replaced_by'] == $replacement_certificate_id ) {
			return new \WP_Error( 'circular_replacement', __( 'Circular replacement detected', 'certificate-manager' ) );
		}
		
		// Mark original as replaced
		$wpdb->update(
			$wpdb->prefix . 'certificate_manager_certificates',
			array(
				'status' => 'replaced',
				'replaced_by' => $replacement_certificate_id,
			),
			array( 'id' => $original_certificate_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
		
		// Log audit events
		$this->audit_repo->log( 'certificate', 'Replaced', $original_certificate_id, array(
			'replacement_id' => $replacement_certificate_id,
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		$this->audit_repo->log( 'certificate', 'ReplacedOriginal', $replacement_certificate_id, array(
			'original_id' => $original_certificate_id,
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return array(
			'original_id' => $original_certificate_id,
			'replacement_id' => $replacement_certificate_id,
		);
	}
	
	/**
	 * Trash certificate
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array|WP_Error Certificate data or error
	 */
	public function trash_certificate( int $certificate_id ) {
		global $wpdb;
		
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		$wpdb->update(
			$wpdb->prefix . 'certificate_manager_certificates',
			array(
				'status' => 'trash',
				'trashed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $certificate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		
		// Log audit event
		$this->audit_repo->log( 'certificate', 'Trashed', $certificate_id, array(), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return $this->certificate_repo->get_certificate( $certificate_id );
	}
	
	/**
	 * Restore certificate from trash
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array|WP_Error Certificate data or error
	 */
	public function restore_certificate( int $certificate_id ) {
		global $wpdb;
		
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		if ( $certificate['status'] !== 'trash' ) {
			return new \WP_Error( 'invalid_status', __( 'Certificate is not in trash', 'certificate-manager' ) );
		}
		
		// Restore status based on expiry
		$status = 'active';
		if ( $certificate['expiry_date'] && strtotime( $certificate['expiry_date'] ) < time() ) {
			$status = 'expired';
		}
		
		$wpdb->update(
			$wpdb->prefix . 'certificate_manager_certificates',
			array(
				'status' => $status,
				'trashed_at' => null,
			),
			array( 'id' => $certificate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		
		// Log audit event
		$this->audit_repo->log( 'certificate', 'Restored', $certificate_id, array(
			'new_status' => $status,
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return $this->certificate_repo->get_certificate( $certificate_id );
	}
	
	/**
	 * Delete certificate permanently
	 *
	 * @param int $certificate_id Certificate ID
	 * @return bool
	 */
	public function delete_certificate( int $certificate_id ): bool {
		global $wpdb;
		
		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return false;
		}
		
		// Delete certificate fields
		$wpdb->delete(
			$wpdb->prefix . 'certificate_manager_certificate_fields',
			array( 'certificate_id' => $certificate_id ),
			array( '%d' )
		);
		
		// Delete certificate
		$wpdb->delete(
			$wpdb->prefix . 'certificate_manager_certificates',
			array( 'id' => $certificate_id ),
			array( '%d' )
		);
		
		// Log audit event
		$this->audit_repo->log( 'certificate', 'Deleted', $certificate_id, array(
			'permanent' => true,
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return true;
	}
	
	/**
	 * Issue certificate with CSV data
	 *
	 * @param array $data Certificate data from CSV
	 * @param int $import_job_id Import job ID
	 * @return array|WP_Error Certificate data or error
	 */
	public function issue_certificate_from_csv( array $data, int $import_job_id ) {
		// Add source info
		$data['source'] = 'csv';
		$data['source_details'] = array(
			'import_job_id' => $import_job_id,
			'csv_row' => $data['csv_row'] ?? null,
		);
		
		return $this->issue_certificate( $data );
	}
	
	/**
	 * Issue certificate via API
	 *
	 * @param array $data Certificate data from API
	 * @param int $api_key_id API key ID
	 * @return array|WP_Error Certificate data or error
	 */
	public function issue_certificate_from_api( array $data, int $api_key_id ) {
		// Add source info
		$data['source'] = 'api';
		$data['api_key_id'] = $api_key_id;
		$data['source_details'] = array(
			'api_key_id' => $api_key_id,
		);
		
		return $this->issue_certificate( $data );
	}
	
	/**
	 * Issue scheduled certificate
	 *
	 * @param int $scheduled_job_id Scheduled job ID
	 * @return array|WP_Error Certificate data or error
	 */
	public function issue_scheduled_certificate( int $scheduled_job_id ) {
		global $wpdb;
		
		// Get scheduled job
		$table_name = $wpdb->prefix . 'certificate_manager_scheduled_jobs';
		$job = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d AND is_completed = 0 AND failed = 0",
			$scheduled_job_id
		), ARRAY_A );
		
		if ( ! $job ) {
			return new \WP_Error( 'job_not_found', __( 'Scheduled job not found', 'certificate-manager' ) );
		}
		
		// Parse arguments
		$args = json_decode( $job['arguments'], true );
		if ( ! $args ) {
			return new \WP_Error( 'invalid_arguments', __( 'Invalid job arguments', 'certificate-manager' ) );
		}
		
		// Mark job as in progress
		$wpdb->update(
			$table_name,
			array( 'status' => 'in_progress' ),
			array( 'id' => $scheduled_job_id ),
			array( '%s' ),
			array( '%d' )
		);
		
		// Add source info
		$args['source'] = 'scheduled';
		$args['source_details'] = array(
			'scheduled_job_id' => $scheduled_job_id,
			'scheduled_at' => $job['scheduled_at'],
			'actual_execution_at' => current_time( 'mysql' ),
		);
		
		// Issue certificate
		$result = $this->issue_certificate( $args );
		
		// Mark job as completed
		if ( ! is_wp_error( $result ) ) {
			$wpdb->update(
				$table_name,
				array(
					'is_completed' => 1,
					'completed_at' => current_time( 'mysql' ),
				),
				array( 'id' => $scheduled_job_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->update(
				$table_name,
				array(
					'failed' => 1,
					'failure_reason' => $result->get_error_message(),
				),
				array( 'id' => $scheduled_job_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
		
		return $result;
	}
	
	/**
	 * Get certificate issuance statistics
	 *
	 * @return array
	 */
	public function get_statistics(): array {
		return $this->certificate_repo->get_statistics();
	}
}
