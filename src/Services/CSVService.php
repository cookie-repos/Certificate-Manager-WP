<?php
/**
 * CSV Service
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV service class
 */
class CSVService {
	
	/**
	 * Issuance service
	 *
	 * @var IssuanceService
	 */
	private $issuance_service;
	
	/**
	 * Template repository
	 *
	 * @var Repositories\TemplateRepository
	 */
	private $template_repo;
	
	/**
	 * Constructor
	 *
	 * @param IssuanceService                $issuance_service Issuance service.
	 * @param Repositories\TemplateRepository $template_repo Template repository.
	 */
	public function __construct( $issuance_service, $template_repo ) {
		$this->issuance_service = $issuance_service;
		$this->template_repo = $template_repo;
	}
	
	/**
	 * Export certificates to CSV
	 *
	 * @param array $args Export arguments.
	 * @return string CSV content.
	 */
	public function export( $args = array() ) {
		$defaults = array(
			'fields' => array( 'certificate_number', 'recipient_name', 'recipient_email', 'status', 'created_at' ),
			'status' => 'all',
			'template_id' => 0,
			'date_from' => '',
			'date_to' => '',
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		// Get certificates
		$certificates = $this->get_certificates_for_export( $args );
		
		// Build CSV
		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output required for streaming CSV.
		fputcsv( $output, $args['fields'] );
		
		foreach ( $certificates as $cert ) {
			$row = array();
			foreach ( $args['fields'] as $field ) {
				$row[] = $cert[ $field ] ?? '';
			}
			fputcsv( $output, $row );
		}
		
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream.
	}
	
	/**
	 * Get certificates for export
	 *
	 * @param array $args Export arguments.
	 * @return array Certificates data.
	 */
	private function get_certificates_for_export( $args ) {
		global $wpdb;
		
		$query = "SELECT 
			cm_certificates.id,
			cm_certificates.certificate_number,
			cm_certificates.recipient_name,
			cm_certificates.recipient_email,
			cm_certificates.status,
			cm_certificates.created_at,
			cm_templates.title as template_name
		FROM {$wpdb->prefix}certificate_manager_certificates cm_certificates
		LEFT JOIN {$wpdb->prefix}certificate_manager_templates cm_templates ON cm_certificates.template_id = cm_templates.id
		WHERE 1=1";
		
		if ( 'all' !== $args['status'] ) {
			$query .= $wpdb->prepare( " AND cm_certificates.status = %s", $args['status'] );
		}
		
		if ( $args['template_id'] ) {
			$query .= $wpdb->prepare( " AND cm_certificates.template_id = %d", $args['template_id'] );
		}
		
		if ( $args['date_from'] ) {
			$query .= $wpdb->prepare( " AND cm_certificates.created_at >= %s", $args['date_from'] );
		}
		
		if ( $args['date_to'] ) {
			$query .= $wpdb->prepare( " AND cm_certificates.created_at <= %s", $args['date_to'] );
		}
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic query built from validated inputs; table names use WP prefix.
		return $wpdb->get_results( $query, ARRAY_A );
	}
	
	/**
	 * Import certificates from CSV
	 *
	 * @param string $file_path Path to CSV file.
	 * @param int    $template_id Template ID for imported certificates.
	 * @return array Import results.
	 */
	public function import( $file_path, $template_id ) {
		$results = array(
			'success' => 0,
			'failed' => 0,
			'errors' => array(),
		);
		
		if ( ! file_exists( $file_path ) ) {
			$results['errors'][] = __( 'File not found', 'certificate-manager' );
			return $results;
		}
		
		$file = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading CSV file for import.
		if ( ! $file ) {
			$results['errors'][] = __( 'Could not open file', 'certificate-manager' );
			return $results;
		}
		
		$header = fgetcsv( $file );
		if ( ! $header ) {
			$results['errors'][] = __( 'Invalid CSV format', 'certificate-manager' );
			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file.
			return $results;
		}
		
		// Map header to expected fields
		$field_map = array();
		$expected_fields = array( 'recipient_name', 'recipient_email', 'variables' );
		
		foreach ( $header as $index => $field ) {
			$field_lower = strtolower( trim( $field ) );
			if ( in_array( $field_lower, $expected_fields ) ) {
				$field_map[ $field_lower ] = $index;
			}
		}
		
		// Process rows
		while ( ( $row = fgetcsv( $file ) ) !== false ) {
			$cert_data = array();
			
			foreach ( $field_map as $field => $index ) {
				$cert_data[ $field ] = $row[ $index ] ?? '';
			}
			
			// Validate required fields
			if ( empty( $cert_data['recipient_name'] ) || empty( $cert_data['recipient_email'] ) ) {
				$results['failed']++;
				$results['errors'][] = __( 'Missing required fields', 'certificate-manager' );
				continue;
			}
			
			// Issue certificate
			$variables = isset( $cert_data['variables'] ) ? json_decode( $cert_data['variables'], true ) : array();
			
			$certificate_id = $this->issuance_service->issue_certificate( array(
				'template_id' => $template_id,
				'recipient_name' => $cert_data['recipient_name'],
				'recipient_email' => $cert_data['recipient_email'],
				'variables' => $variables,
			) );
			
			if ( $certificate_id ) {
				$results['success']++;
			} else {
				$results['failed']++;
				$results['errors'][] = __( 'Failed to issue certificate', 'certificate-manager' );
			}
		}
		
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file opened for import.
		
		return $results;
	}
	
	/**
	 * Get sample CSV template
	 *
	 * @return string Sample CSV content.
	 */
	public function get_sample_csv() {
		$sample = array(
			array( 'recipient_name', 'recipient_email', 'variables' ),
			array( 'John Doe', 'john@example.com', json_encode( array( 'course_title' => 'Sample Course' ) ) ),
			array( 'Jane Smith', 'jane@example.com', json_encode( array( 'course_title' => 'Another Course' ) ) ),
		);
		
		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output required for streaming CSV.
		
		foreach ( $sample as $row ) {
			fputcsv( $output, $row );
		}
		
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream.
	}
}
