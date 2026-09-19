<?php
/**
 * CSV Importer
 *
 * @package CertificateManager
 */

namespace CertificateManager\CSV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV importer class
 */
class Importer {
	
	/**
	 * Import job
	 *
	 * @var int
	 */
	private $import_id;
	
	/**
	 * Settings
	 *
	 * @var Core\Settings
	 */
	private $settings;
	
	/**
	 * Constructor
	 *
	 * @param int           $import_id Import job ID.
	 * @param Core\Settings $settings Settings instance.
	 */
	public function __construct( $import_id, $settings ) {
		$this->import_id = $import_id;
		$this->settings = $settings;
	}
	
	/**
	 * Import certificates from CSV file
	 *
	 * @param string $file_path Path to CSV file.
	 * @param int    $template_id Template ID for imported certificates.
	 * @return array Import results.
	 */
	public function import( $file_path, $template_id ) {
		$results = array(
			'success' => 0,
			'failed' => 0,
			'total' => 0,
			'errors' => array(),
		);
		
		if ( ! file_exists( $file_path ) ) {
			$results['errors'][] = __( 'File not found', 'certificate-manager' );
			return $results;
		}
		
		$file = fopen( $file_path, 'r' );
		if ( ! $file ) {
			$results['errors'][] = __( 'Could not open file', 'certificate-manager' );
			return $results;
		}
		
		$header = fgetcsv( $file );
		if ( ! $header ) {
			$results['errors'][] = __( 'Invalid CSV format', 'certificate-manager' );
			fclose( $file );
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
		$row_num = 0;
		while ( ( $row = fgetcsv( $file ) ) !== false ) {
			$row_num++;
			$cert_data = array();
			
			foreach ( $field_map as $field => $index ) {
				$cert_data[ $field ] = $row[ $index ] ?? '';
			}
			
			// Validate required fields
			if ( empty( $cert_data['recipient_name'] ) || empty( $cert_data['recipient_email'] ) ) {
				$results['failed']++;
				$results['errors'][] = sprintf( __( 'Row %d: Missing required fields', 'certificate-manager' ), $row_num );
				continue;
			}
			
			// Issue certificate
			$variables = isset( $cert_data['variables'] ) ? json_decode( $cert_data['variables'], true ) : array();
			
			$certificate_id = wp_insert_post( array(
				'post_type' => 'cm_certificate',
				'post_status' => 'publish',
				'post_title' => $cert_data['recipient_name'],
				'meta_input' => array(
					'_certificate_template_id' => $template_id,
					'_certificate_recipient_name' => $cert_data['recipient_name'],
					'_certificate_recipient_email' => $cert_data['recipient_email'],
					'_certificate_variables' => $variables,
				),
			) );
			
			if ( $certificate_id ) {
				$results['success']++;
			} else {
				$results['failed']++;
				$results['errors'][] = sprintf( __( 'Row %d: Failed to issue certificate', 'certificate-manager' ), $row_num );
			}
			
			$results['total']++;
		}
		
		fclose( $file );
		
		return $results;
	}
}
