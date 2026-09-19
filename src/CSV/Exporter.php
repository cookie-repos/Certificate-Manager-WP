<?php
/**
 * CSV Exporter
 *
 * @package CertificateManager
 */

namespace CertificateManager\CSV;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV exporter class
 */
class Exporter {
	
	/**
	 * Export job
	 *
	 * @var int
	 */
	private $export_id;
	
	/**
	 * Settings
	 *
	 * @var Core\Settings
	 */
	private $settings;
	
	/**
	 * Constructor
	 *
	 * @param int           $export_id Export job ID.
	 * @param Core\Settings $settings Settings instance.
	 */
	public function __construct( $export_id, $settings ) {
		$this->export_id = $export_id;
		$this->settings = $settings;
	}
	
	/**
	 * Export certificates to CSV file
	 *
	 * @param array $args Export arguments.
	 * @param string $file_path Output file path.
	 * @return bool True on success, false on failure.
	 */
	public function export( $args, $file_path ) {
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
		
		if ( empty( $certificates ) ) {
			return false;
		}
		
		// Write CSV
		$file = fopen( $file_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing temp CSV file.
		if ( ! $file ) {
			return false;
		}
		
		// Write header
		fputcsv( $file, $args['fields'] );
		
		// Write rows
		foreach ( $certificates as $cert ) {
			$row = array();
			foreach ( $args['fields'] as $field ) {
				$row[] = $cert[ $field ] ?? '';
			}
			fputcsv( $file, $row );
		}
		
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing temp CSV file.
		
		return true;
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
}
