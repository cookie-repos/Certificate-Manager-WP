<?php
/**
 * Certificates Admin Interface
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificates admin class
 */
class CertificatesAdmin {
	
	/**
	 * Certificate repository
	 *
	 * @var Repositories\CertificateRepository
	 */
	private $cert_repo;
	
	/**
	 * Issuance service
	 *
	 * @var Services\IssuanceService
	 */
	private $issuance_service;
	
	/**
	 * Settings
	 *
	 * @var Core\Settings
	 */
	private $settings;

	/**
	 * Template repository
	 *
	 * @var Repositories\TemplateRepository
	 */
	private $template_repo;

	/**
	 * Rendering service
	 *
	 * @var Services\RenderingService
	 */
	private $rendering_service;

	private $webhook_service;

	private $webhook_repo;
	
	/**
	 * Constructor
	 *
	 * @param Repositories\CertificateRepository $cert_repo Certificate repository.
	 * @param Services\IssuanceService          $issuance_service Issuance service.
	 * @param Core\Settings                    $settings Settings instance.
	 * @param Repositories\TemplateRepository  $template_repo Template repository.
	 * @param Services\RenderingService        $rendering_service Rendering service.
	 * @param Services\WebhookService          $webhook_service Webhook service.
	 * @param Repositories\WebhookRepository   $webhook_repo Webhook repository.
	 */
	public function __construct( $cert_repo, $issuance_service, $settings, $template_repo, $rendering_service, $webhook_service, $webhook_repo ) {
		$this->cert_repo = $cert_repo;
		$this->issuance_service = $issuance_service;
		$this->settings = $settings;
		$this->template_repo = $template_repo;
		$this->rendering_service = $rendering_service;
		$this->webhook_service = $webhook_service;
		$this->webhook_repo = $webhook_repo;
	}
	
	/**
	 * Initialize admin functionality
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_cm_issue_certificate', array( $this, 'issue_certificate' ) );
		add_action( 'wp_ajax_cm_revoke_certificate', array( $this, 'revoke_certificate' ) );
		add_action( 'wp_ajax_cm_replace_certificate', array( $this, 'replace_certificate' ) );
		add_action( 'wp_ajax_cm_get_certificate', array( $this, 'get_certificate' ) );
		add_action( 'wp_ajax_cm_search_recipients', array( $this, 'search_recipients' ) );
		add_action( 'wp_ajax_cm_get_certificate_webhook_options', array( $this, 'get_certificate_webhook_options' ) );
		add_action( 'wp_ajax_cm_trigger_certificate_webhook', array( $this, 'trigger_certificate_webhook' ) );
		add_action( 'admin_post_cm_view_certificate', array( $this, 'view_certificate' ) );
		add_action( 'admin_post_cm_export_certificates_csv', array( $this, 'export_certificates_csv' ) );
		add_action( 'admin_post_cm_download_template_csv', array( $this, 'download_template_csv' ) );
		add_action( 'wp_ajax_cm_import_certificates_csv', array( $this, 'import_certificates_csv' ) );
	}

	/**
	 * Display a generated certificate PDF in the browser.
	 */
	public function view_certificate() {
		if ( ! current_user_can( 'cm_view_certificates' ) ) {
			wp_die( esc_html__( 'Permission denied', 'certificate-manager' ), '', array( 'response' => 403 ) );
		}
		$this->cert_repo->expire_due_certificates();

		$certificate_id = absint( wp_unslash( $_GET['certificate_id'] ?? 0 ) );
		check_admin_referer( 'cm_view_certificate_' . $certificate_id );
		$variant = sanitize_key( wp_unslash( $_GET['variant'] ?? 'current' ) );
		if ( ! in_array( $variant, array( 'current', 'original' ), true ) ) {
			$variant = 'current';
		}
		if ( 'original' === $variant && ! current_user_can( 'cm_manage_settings' ) ) {
			wp_die( esc_html__( 'Only administrators can view the original certificate record.', 'certificate-manager' ), '', array( 'response' => 403 ) );
		}
		$certificate = $this->cert_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			wp_die( esc_html__( 'Certificate not found', 'certificate-manager' ), '', array( 'response' => 404 ) );
		}

		$file_path = $this->rendering_service->regenerate_pdf( $certificate_id, null, 'current' === $variant );
		if ( is_wp_error( $file_path ) ) {
			wp_die( esc_html( $file_path->get_error_message() ), '', array( 'response' => 500 ) );
		}

		$this->rendering_service->stream_pdf_file( $file_path, false );
	}

	/**
	 * Download the complete certificate register and stored variable values as CSV.
	 */
	public function export_certificates_csv() {
		if ( ! current_user_can( 'cm_export_certificates' ) ) {
			wp_die( esc_html__( 'Permission denied', 'certificate-manager' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'cm_export_certificates_csv' );
		$certificates = $this->cert_repo->get_certificates_for_export();
		$certificate_ids = array_map( 'absint', array_column( $certificates, 'id' ) );
		$fields = $this->cert_repo->get_certificate_fields_for_export( $certificate_ids );
		$field_values = array();
		$field_columns = array();

		foreach ( $fields as $field ) {
			$certificate_id = absint( $field['certificate_id'] );
			$key = (string) $field['variable_key'];
			$field_values[ $certificate_id ][ $key ] = (string) $field['value'];
			if ( ! isset( $field_columns[ $key ] ) ) {
				$field_columns[ $key ] = (string) ( $field['variable_label'] ?: $key );
			}
		}

		uksort( $field_columns, 'strnatcasecmp' );
		$core_columns = array(
			'id', 'internal_id', 'certificate_number', 'verification_token', 'template_id', 'template_title',
			'template_version_id', 'recipient_name', 'recipient_email', 'status', 'issue_date', 'expiry_date',
			'source', 'source_details', 'issuer_id', 'api_key_id', 'pdf_file_path', 'revoked_at', 'revoked_by',
			'revocation_reason', 'revocation_reason_public', 'reinstated_at', 'reinstated_by', 'replaced_by',
			'trashed_at', 'deleted_at', 'verification_count', 'qr_verification_count', 'manual_verification_count',
			'tags', 'private_notes', 'internal_notes', 'data_snapshot', 'created_at', 'updated_at',
		);
		$headers = $core_columns;
		$headers[] = 'custom_fields_json';
		foreach ( $field_columns as $key => $label ) {
			$headers[] = sprintf( 'custom_%s (%s)', $label, $key );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="certificate-manager-certificates-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output required for streaming CSV.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Used for BOM on php://output stream.
		fputcsv( $output, $headers );

		foreach ( $certificates as $certificate ) {
			$certificate_id = absint( $certificate['id'] );
			$stored_fields = $field_values[ $certificate_id ] ?? array();
			$row = array();
			foreach ( $core_columns as $column ) {
				$row[] = $certificate[ $column ] ?? '';
			}
			$row[] = wp_json_encode( $stored_fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			foreach ( $field_columns as $key => $label ) {
				$row[] = $stored_fields[ $key ] ?? '';
			}
			fputcsv( $output, $row );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream.
		exit;
	}

	public function download_template_csv() {
		if ( ! current_user_can( 'cm_issue_certificates' ) ) {
			wp_die( esc_html__( 'Permission denied', 'certificate-manager' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'cm_download_template_csv' );
		$template = $this->template_repo->get_template( absint( $_GET['template_id'] ?? 0 ) );
		if ( ! $template || empty( $template['published_version_id'] ) || ! empty( $template['is_archived'] ) || ! empty( $template['is_deleted'] ) ) {
			wp_die( esc_html__( 'Choose a published template.', 'certificate-manager' ), '', array( 'response' => 400 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $template['title'] . '-certificate-import.csv' ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output required for streaming CSV.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Used for BOM on php://output stream.
		fputcsv( $output, $this->get_template_csv_headers( $template ) );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream.
		exit;
	}

	public function import_certificates_csv() {
		if ( ! current_user_can( 'cm_issue_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}

		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}

		$template = $this->template_repo->get_template( absint( $_POST['template_id'] ?? 0 ) );
		if ( ! $template || empty( $template['published_version_id'] ) || ! empty( $template['is_archived'] ) || ! empty( $template['is_deleted'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a published template.', 'certificate-manager' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Individual fields sanitized below before use.
		$file = $_FILES['csv_file'] ?? array();
		if ( empty( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( sanitize_text_field( wp_unslash( (string) $file['tmp_name'] ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid CSV file to import.', 'certificate-manager' ) ), 400 );
		}
		if ( strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) ) !== 'csv' || (int) ( $file['size'] ?? 0 ) > 5 * MB_IN_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'Upload a CSV file smaller than 5 MB.', 'certificate-manager' ) ), 400 );
		}

		$handle = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading uploaded tmp file, WP_Filesystem not suitable.
		$header_row = $handle ? fgetcsv( $handle ) : false;
		if ( ! $handle || ! is_array( $header_row ) ) {
			if ( $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing uploaded tmp file handle.
			}
			wp_send_json_error( array( 'message' => __( 'The CSV must begin with a header row.', 'certificate-manager' ) ), 400 );
		}

		$headers = array();
		foreach ( $header_row as $index => $header ) {
			$key = sanitize_key( ltrim( (string) $header, "\xEF\xBB\xBF" ) );
			if ( '' !== $key && ! isset( $headers[ $key ] ) ) {
				$headers[ $key ] = $index;
			}
		}
		$template_fields = $this->get_template_csv_fields( $template );
		if ( ! isset( $headers['recipient_name'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing uploaded tmp file handle.
			wp_send_json_error( array( 'message' => __( 'The CSV must include a recipient_name column.', 'certificate-manager' ) ), 400 );
		}
		foreach ( $template_fields as $field ) {
			if ( ! empty( $field['required'] ) && ! isset( $headers[ $field['key'] ] ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing uploaded tmp file handle.
				/* translators: %s: CSV column key name */
				wp_send_json_error( array( 'message' => sprintf( __( 'The CSV must include the required %s column.', 'certificate-manager' ), $field['key'] ) ), 400 );
			}
		}

		$results = array( 'issued' => 0, 'failed' => 0, 'total' => 0, 'partial' => false, 'errors' => array() );
		$row_number = 1;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$row_number++;
			if ( $results['total'] >= 500 ) {
				$results['partial'] = true;
				$results['errors'][] = __( 'Import stopped after 500 rows. Split larger files into separate uploads.', 'certificate-manager' );
				break;
			}
			if ( ! array_filter( $row, static function ( $value ) { return '' !== trim( (string) $value ); } ) ) {
				continue;
			}
			$results['total']++;
			$values = array();
			foreach ( $template_fields as $field ) {
				$value = isset( $headers[ $field['key'] ] ) ? (string) ( $row[ $headers[ $field['key'] ] ] ?? '' ) : '';
				$values[ $field['key'] ] = 'image' === $field['type'] ? (string) absint( $value ) : sanitize_textarea_field( $value );
			}
			$issue_data = array_merge( $values, array(
				'template_id' => (int) $template['id'],
				'recipient_name' => sanitize_text_field( isset( $headers['recipient_name'] ) ? (string) ( $row[ $headers['recipient_name'] ] ?? '' ) : '' ),
				'recipient_email' => sanitize_email( isset( $headers['recipient_email'] ) ? (string) ( $row[ $headers['recipient_email'] ] ?? '' ) : '' ),
				'issue_date' => sanitize_text_field( isset( $headers['issue_date'] ) ? (string) ( $row[ $headers['issue_date'] ] ?? '' ) : '' ),
				'expiry_enabled' => in_array( strtolower( trim( (string) ( isset( $headers['expiry_enabled'] ) ? ( $row[ $headers['expiry_enabled'] ] ?? '' ) : '' ) ) ), array( '1', 'true', 'yes', 'y' ), true ),
				'expiry_quantity' => max( 1, absint( isset( $headers['expiry_quantity'] ) ? ( $row[ $headers['expiry_quantity'] ] ?? 1 ) : 1 ) ),
				'expiry_unit' => in_array( isset( $headers['expiry_unit'] ) ? $row[ $headers['expiry_unit'] ] : '', array( 'days', 'months', 'years' ), true ) ? $row[ $headers['expiry_unit'] ] : 'years',
				'source' => 'csv',
				'source_details' => array( 'csv_row' => $row_number, 'template_id' => (int) $template['id'] ),
			) );
			$result = $this->issuance_service->issue_certificate( $issue_data );
			if ( is_wp_error( $result ) ) {
				$results['failed']++;
				if ( count( $results['errors'] ) < 10 ) {
					$error_data = $result->get_error_data();
					$message = $result->get_error_message();
					if ( ! empty( $error_data['errors'] ) && is_array( $error_data['errors'] ) ) {
						$message .= ': ' . implode( ', ', $error_data['errors'] );
					}
					/* translators: 1: row number, 2: error message */
					$results['errors'][] = sprintf( __( 'Row %1$d: %2$s', 'certificate-manager' ), $row_number, $message );
				}
			} else {
				$results['issued']++;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing uploaded tmp file handle.
		if ( 0 === $results['total'] ) {
			wp_send_json_error( array( 'message' => __( 'The CSV does not contain any certificate rows.', 'certificate-manager' ) ), 400 );
		}

		wp_send_json_success( $results );
	}
	
	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current hook.
	 */
	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check, no form data processed.
		if ( 'cm_certificates' !== $page ) {
			return;
		}
		
		wp_enqueue_media();
		wp_enqueue_style( 'certificate-manager-admin', plugins_url( '../Assets/css/admin.css', __FILE__ ), array(), CERTIFICATE_MANAGER_VERSION );
		wp_enqueue_script( 'certificate-manager-admin', plugins_url( '../Assets/js/admin.js', __FILE__ ), array( 'jquery' ), CERTIFICATE_MANAGER_VERSION, true );
		
		wp_localize_script( 'certificate-manager-admin', 'cmAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cm-admin-nonce' ),
			'csvTemplateUrl' => wp_nonce_url( admin_url( 'admin-post.php?action=cm_download_template_csv' ), 'cm_download_template_csv' ),
			'l10n' => array(
				'issueSuccess' => __( 'Certificate issued successfully', 'certificate-manager' ),
				'revokeSuccess' => __( 'Certificate revoked successfully', 'certificate-manager' ),
				'replaceSuccess' => __( 'Certificate replaced successfully', 'certificate-manager' ),
				'error' => __( 'An error occurred', 'certificate-manager' ),
			),
		) );
	}
	
	/**
	 * Issue certificate via AJAX
	 */
	public function issue_certificate() {
		if ( ! current_user_can( 'cm_issue_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$template_id = absint( $_POST['template_id'] ?? $this->settings->get( 'default_template_id', 0 ) );
		$recipient_name = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
		$recipient_email = sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) );
		$variables = array();
		if ( isset( $_POST['variables'] ) && is_array( $_POST['variables'] ) ) {
			foreach ( wp_unslash( $_POST['variables'] ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element sanitized individually below via sanitize_key/sanitize_textarea_field.
				if ( is_scalar( $value ) ) {
					$variables[ sanitize_key( $key ) ] = sanitize_textarea_field( (string) $value );
				}
			}
		}
		$issue_date = sanitize_text_field( wp_unslash( $_POST['issue_date'] ?? '' ) );
		$issue_data = array_merge( $variables, array(
			'template_id' => $template_id,
			'recipient_name' => $recipient_name,
			'recipient_email' => $recipient_email,
			'issue_date' => $issue_date ?: current_time( 'mysql' ),
			'expiry_enabled' => ! empty( $_POST['expiry_enabled'] ),
			'expiry_quantity' => max( 1, absint( $_POST['expiry_quantity'] ?? 1 ) ),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_key and in_array whitelist applied.
			'expiry_unit' => in_array( $_POST['expiry_unit'] ?? '', array( 'days', 'months', 'years' ), true ) ? sanitize_key( wp_unslash( $_POST['expiry_unit'] ) ) : 'years',
		) );
		$result = $this->issuance_service->issue_certificate( $issue_data );
		
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$message = $result->get_error_message();
			if ( ! empty( $data['errors'] ) ) {
				$message .= ': ' . implode( ', ', $data['errors'] );
			}
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		if ( is_array( $result ) && ! empty( $result['id'] ) ) {
			wp_send_json_success( $result );
		}
		
		wp_send_json_error( array( 'message' => __( 'Failed to issue certificate', 'certificate-manager' ) ), 500 );
	}
	
	/**
	 * Revoke certificate via AJAX
	 */
	public function revoke_certificate() {
		if ( ! current_user_can( 'cm_revoke_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$certificate_id = absint( $_POST['certificate_id'] ?? 0 );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		
		$result = $this->issuance_service->revoke_certificate( $certificate_id, $reason );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		
		if ( is_array( $result ) && 'revoked' === ( $result['status'] ?? '' ) ) {
			wp_send_json_success( array( 'status' => 'revoked' ) );
		}
		
		wp_send_json_error( array( 'message' => __( 'Failed to revoke certificate', 'certificate-manager' ) ), 500 );
	}
	
	/**
	 * Replace certificate via AJAX
	 */
	public function replace_certificate() {
		if ( ! current_user_can( 'cm_replace_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$certificate_id = absint( $_POST['certificate_id'] ?? 0 );
		
		$replacement_id = isset( $_POST['replacement_id'] ) ? intval( $_POST['replacement_id'] ) : 0;
		if ( ! $replacement_id ) {
			wp_send_json_error( array( 'message' => __( 'A replacement certificate ID is required', 'certificate-manager' ) ), 400 );
		}

		$new_certificate_id = $this->issuance_service->replace_certificate( $certificate_id, $replacement_id );
		
		if ( $new_certificate_id ) {
			wp_send_json_success( array( 'id' => $new_certificate_id ) );
		}
		
		wp_send_json_error( array( 'message' => __( 'Failed to replace certificate', 'certificate-manager' ) ), 500 );
	}
	
	/**
	 * Get certificate details via AJAX
	 */
	public function get_certificate() {
		if ( ! current_user_can( 'cm_view_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$certificate_id = absint( $_POST['certificate_id'] ?? 0 );
		$certificate = $this->cert_repo->get_certificate( $certificate_id );
		
		if ( ! $certificate ) {
			wp_send_json_error( array( 'message' => __( 'Certificate not found', 'certificate-manager' ) ), 404 );
		}
		
		wp_send_json_success( $certificate );
	}
	
	/**
	 * Search recipients via AJAX
	 */
	public function search_recipients() {
		if ( ! current_user_can( 'cm_issue_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		$search = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Search term only, no state change.
		
		$users = get_users( array(
			'search' => '*' . $search . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'number' => 10,
		) );
		
		$results = array();
		
		foreach ( $users as $user ) {
			$results[] = array(
				'id' => $user->ID,
				'text' => $user->display_name . ' (' . $user->user_email . ')',
				'email' => $user->user_email,
			);
		}
		
		wp_send_json_success( array( 'results' => $results ) );
	}

	public function get_certificate_webhook_options() {
		if ( ! current_user_can( 'cm_issue_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'cm-admin-nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}

		$certificate = $this->cert_repo->get_certificate( absint( $_POST['certificate_id'] ?? 0 ) );
		if ( ! $certificate ) {
			wp_send_json_error( array( 'message' => __( 'Certificate not found', 'certificate-manager' ) ), 404 );
		}

		wp_send_json_success( $this->get_webhook_options_for_certificate( $certificate ) );
	}

	public function trigger_certificate_webhook() {
		if ( ! current_user_can( 'cm_issue_certificates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'cm-admin-nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}

		$certificate = $this->cert_repo->get_certificate( absint( $_POST['certificate_id'] ?? 0 ) );
		$webhook_id = absint( $_POST['webhook_id'] ?? 0 );
		if ( ! $certificate || ! $webhook_id ) {
			wp_send_json_error( array( 'message' => __( 'Choose a certificate and webhook.', 'certificate-manager' ) ), 400 );
		}

		$options = $this->get_webhook_options_for_certificate( $certificate );
		$available_ids = array_map( 'absint', array_column( $options['available'], 'id' ) );
		if ( ! in_array( $webhook_id, $available_ids, true ) ) {
			wp_send_json_error( array( 'message' => __( 'That webhook is not available for certificate events.', 'certificate-manager' ) ), 400 );
		}

		if ( empty( $options['linked'] ) && ! $this->cert_repo->set_manual_webhook_ids( (int) $certificate['id'], array( $webhook_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'The webhook could not be linked to this certificate.', 'certificate-manager' ) ), 500 );
		}

		$results = $this->webhook_service->dispatch_webhook( 'certificate_issued', $this->get_certificate_webhook_data( $certificate ), (int) $certificate['id'], array( $webhook_id ) );
		if ( empty( $results ) ) {
			wp_send_json_error( array( 'message' => __( 'The webhook could not be triggered.', 'certificate-manager' ) ), 500 );
		}

		$this->issuance_service->get_audit_repository()->log( 'certificate', 'WebhookTriggered', (int) $certificate['id'], array( 'webhook_id' => $webhook_id, 'delivery' => $results[0] ), array( 'type' => 'user', 'id' => get_current_user_id() ) );
		wp_send_json_success( array( 'message' => __( 'Webhook triggered. Delivery status is available in the logs.', 'certificate-manager' ), 'results' => $results ) );
	}
	
	/**
	 * Render admin page
	 */
	public function render_page() {
		$template_result = $this->template_repo->get_templates( array( 'per_page' => 100, 'status' => 'published', 'archived' => false ) );
		$templates = $template_result['data'];
		$default_template_id = absint( $this->settings->get( 'default_template_id', 0 ) );
		$published_template_ids = array_map( 'intval', array_column( $templates, 'id' ) );
		if ( ! in_array( $default_template_id, $published_template_ids, true ) ) {
			$default_template_id = 0;
		}
		?>
		<div class="wrap cm-certificates-admin">
			<div class="cm-page-heading">
				<div><h1><?php esc_html_e( 'Certificates', 'certificate-manager' ); ?></h1><p><?php esc_html_e( 'Issue a certificate from a published template and manage every certificate in one place.', 'certificate-manager' ); ?></p></div>
				<div class="cm-page-actions">
					<?php if ( current_user_can( 'cm_export_certificates' ) ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cm_export_certificates_csv' ), 'cm_export_certificates_csv' ) ); ?>"><?php esc_html_e( 'Export all CSV', 'certificate-manager' ); ?></a><?php endif; ?>
					<?php if ( ! empty( $templates ) && current_user_can( 'cm_issue_certificates' ) ) : ?><button type="button" class="button button-primary button-hero cm-open-issue-form"><?php esc_html_e( 'Issue certificate', 'certificate-manager' ); ?></button><?php endif; ?>
				</div>
			</div>

			<?php if ( current_user_can( 'cm_issue_certificates' ) ) : ?>
				<?php $this->render_issue_form( $templates, $default_template_id ); ?>
			<?php endif; ?>
			
			<div class="cm-certificates-list">
				<?php $this->render_certificate_list(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the manual certificate issuance form and its template fields.
	 *
	 * @param array $templates Published templates.
	 * @param int   $default_template_id Global default template ID.
	 */
	private function render_issue_form( array $templates, int $default_template_id ) {
		if ( empty( $templates ) ) {
			?>
			<div class="notice notice-info inline cm-no-templates"><p><?php esc_html_e( 'You need a published template before you can issue a certificate.', 'certificate-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=cm_templates' ) ); ?>"><?php esc_html_e( 'Create a template', 'certificate-manager' ); ?></a></p></div>
			<?php
			return;
		}
		?>
		<section class="cm-issue-panel" hidden>
			<div class="cm-panel-heading"><div><h2><?php esc_html_e( 'Issue a certificate', 'certificate-manager' ); ?></h2><p><?php esc_html_e( 'Choose the design, then complete the recipient and template fields.', 'certificate-manager' ); ?></p></div><button type="button" class="button-link cm-close-issue-form" aria-label="<?php esc_attr_e( 'Close', 'certificate-manager' ); ?>">&times;</button></div>
			<div class="cm-issue-mode-switch" role="tablist" aria-label="<?php esc_attr_e( 'Issuance method', 'certificate-manager' ); ?>"><button type="button" class="button is-active" role="tab" aria-selected="true" data-issue-mode="manual"><?php esc_html_e( 'Create one certificate', 'certificate-manager' ); ?></button><button type="button" class="button" role="tab" aria-selected="false" data-issue-mode="csv"><?php esc_html_e( 'Import CSV', 'certificate-manager' ); ?></button></div>
			<form id="cm-issue-certificate-form" class="cm-issue-mode" data-issue-mode-panel="manual">
				<div class="cm-form-grid">
					<label><?php esc_html_e( 'Template', 'certificate-manager' ); ?><select name="template_id" id="cm-issue-template" required><option value=""><?php esc_html_e( 'Select a template', 'certificate-manager' ); ?></option><?php foreach ( $templates as $template ) : ?><option value="<?php echo esc_attr( $template['id'] ); ?>" <?php selected( $default_template_id, (int) $template['id'] ); ?>><?php echo esc_html( $template['title'] . ( $default_template_id === (int) $template['id'] ? ' (' . __( 'Global default', 'certificate-manager' ) . ')' : '' ) ); ?></option><?php endforeach; ?></select></label>
					<label><?php esc_html_e( 'Recipient name', 'certificate-manager' ); ?><input type="text" name="recipient_name" required></label>
					<label><?php esc_html_e( 'Recipient email', 'certificate-manager' ); ?><input type="email" name="recipient_email"></label>
					<label><?php esc_html_e( 'Issue date', 'certificate-manager' ); ?><input type="date" name="issue_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></label>
				</div>

				<?php foreach ( $templates as $template ) : ?>
					<div class="cm-template-variable-fields" data-template-id="<?php echo esc_attr( $template['id'] ); ?>" hidden>
						<h3><?php esc_html_e( 'Certificate details', 'certificate-manager' ); ?></h3>
						<div class="cm-form-grid">
							<?php foreach ( $this->template_repo->get_template_variables( (int) $template['id'] ) as $variable ) : ?>
								<?php if ( in_array( $variable['variable_key'], array( 'recipient_name', 'recipient_email', 'certificate_number', 'issue_date', 'expiry_date', 'verification_url', 'site_name', 'site_url', 'template_name', 'current_year' ), true ) ) { continue; } ?>
								<?php $this->render_variable_field( $variable ); ?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<div class="cm-expiry-fields"><label class="cm-checkbox"><input type="checkbox" name="expiry_enabled" value="1"> <?php esc_html_e( 'This certificate expires', 'certificate-manager' ); ?></label><div class="cm-expiry-duration" hidden><input type="number" name="expiry_quantity" min="1" value="1"><select name="expiry_unit"><option value="days"><?php esc_html_e( 'days', 'certificate-manager' ); ?></option><option value="months"><?php esc_html_e( 'months', 'certificate-manager' ); ?></option><option value="years" selected><?php esc_html_e( 'years', 'certificate-manager' ); ?></option></select></div></div>
				<div class="cm-form-actions"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Issue certificate', 'certificate-manager' ); ?></button><span class="cm-issue-status" role="status"></span></div>
			</form>
			<form id="cm-import-certificates-form" class="cm-issue-mode" data-issue-mode-panel="csv" enctype="multipart/form-data" hidden>
				<div class="cm-form-grid"><label><?php esc_html_e( 'Template', 'certificate-manager' ); ?><select name="template_id" id="cm-import-template" required><option value=""><?php esc_html_e( 'Select a template', 'certificate-manager' ); ?></option><?php foreach ( $templates as $template ) : ?><option value="<?php echo esc_attr( $template['id'] ); ?>"><?php echo esc_html( $template['title'] ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Completed CSV file', 'certificate-manager' ); ?><input type="file" name="csv_file" accept=".csv,text/csv" required></label></div>
				<div class="cm-csv-import-help"><p><?php esc_html_e( 'Download the layout after choosing a template. Keep the first row of column names unchanged, then add one certificate per row. Use YYYY-MM-DD for issue dates and yes/no for expiry_enabled.', 'certificate-manager' ); ?></p><a class="button cm-download-template-csv" href="#" aria-disabled="true"><?php esc_html_e( 'Download template CSV', 'certificate-manager' ); ?></a><?php foreach ( $templates as $template ) : ?><p class="description cm-csv-template-fields" data-template-id="<?php echo esc_attr( $template['id'] ); ?>" hidden><?php
					/* translators: %s: comma-separated list of CSV column names */
					printf( esc_html__( 'Columns: %s', 'certificate-manager' ), esc_html( implode( ', ', $this->get_template_csv_headers( $template ) ) ) ); ?></p><?php endforeach; ?></div>
				<div class="cm-form-actions"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Import and issue certificates', 'certificate-manager' ); ?></button><span class="cm-csv-import-status" role="status"></span></div>
			</form>
		</section>
		<?php
	}

	/**
	 * Render one variable using its configured input type.
	 *
	 * @param array $variable Variable configuration.
	 */
	private function render_variable_field( array $variable ) {
		$key = $variable['variable_key'];
		$type = $variable['field_type'];
		$required = ! empty( $variable['is_required'] );
		$options = json_decode( $variable['field_options'] ?? '[]', true );
		$is_multiline = in_array( $type, array( 'text', 'textarea' ), true ) || false !== strpos( $key, 'address' );
		?>
		<label><?php echo esc_html( $variable['variable_label'] ); ?><?php if ( $required ) : ?> <span class="required">*</span><?php endif; ?>
			<?php if ( $is_multiline ) : ?>
				<textarea name="variables[<?php echo esc_attr( $key ); ?>]" <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea( $variable['default_value'] ?? '' ); ?></textarea>
				<?php if ( 'learning_outcomes' === $key ) : ?><span class="description"><?php esc_html_e( 'Enter one outcome per line. Each outcome will be displayed as a bullet point.', 'certificate-manager' ); ?></span><?php elseif ( false !== strpos( $key, 'address' ) ) : ?><span class="description"><?php esc_html_e( 'Press Enter to add a new line.', 'certificate-manager' ); ?></span><?php endif; ?>
			<?php elseif ( 'select' === $type && is_array( $options ) ) : ?>
				<select name="variables[<?php echo esc_attr( $key ); ?>]" <?php echo $required ? 'required' : ''; ?>><option value=""><?php esc_html_e( 'Select', 'certificate-manager' ); ?></option><?php foreach ( $options as $option ) : ?><option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select>
			<?php elseif ( 'image' === $type ) : ?>
				<?php $image_options = is_array( $options ) ? array_values( array_filter( array_map( 'absint', $options ), 'wp_attachment_is_image' ) ) : array(); ?>
				<?php if ( $image_options ) : ?>
					<select name="variables[<?php echo esc_attr( $key ); ?>]" <?php echo $required ? 'required' : ''; ?>><option value=""><?php esc_html_e( 'Choose an approved image', 'certificate-manager' ); ?></option><?php foreach ( $image_options as $image_id ) : ?><option value="<?php echo esc_attr( $image_id ); ?>" <?php selected( absint( $variable['default_value'] ?? 0 ), $image_id ); ?>><?php
					/* translators: %d: media attachment ID */
					echo esc_html( get_the_title( $image_id ) ?: sprintf( __( 'Image %d', 'certificate-manager' ), $image_id ) ); ?></option><?php endforeach; ?></select>
				<?php else : ?>
					<input type="hidden" class="cm-image-variable-input" name="variables[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( absint( $variable['default_value'] ?? 0 ) ); ?>" data-required="<?php echo $required ? '1' : '0'; ?>">
					<button type="button" class="button cm-select-variable-media"><?php esc_html_e( 'Choose image', 'certificate-manager' ); ?></button>
					<span class="cm-image-variable-selection"><?php echo ! empty( $variable['default_value'] ) ? esc_html__( 'Image selected', 'certificate-manager' ) : esc_html__( 'No image selected', 'certificate-manager' ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<input type="<?php echo esc_attr( in_array( $type, array( 'email', 'number', 'date', 'url' ), true ) ? $type : 'text' ); ?>" name="variables[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $variable['default_value'] ?? '' ); ?>" <?php echo $required ? 'required' : ''; ?>>
			<?php endif; ?>
		</label>
		<?php
	}

	private function get_template_csv_headers( array $template ): array {
		$headers = array( 'recipient_name', 'recipient_email', 'issue_date', 'expiry_enabled', 'expiry_quantity', 'expiry_unit' );
		foreach ( $this->get_template_csv_fields( $template ) as $field ) {
			$headers[] = $field['key'];
		}
		return array_values( array_unique( $headers ) );
	}

	private function get_template_csv_fields( array $template ): array {
		$fields = array();
		$core_keys = array( 'recipient_name', 'recipient_email', 'certificate_number', 'issue_date', 'expiry_date', 'verification_url', 'site_name', 'site_url', 'template_name', 'current_year' );
		foreach ( $this->template_repo->get_template_variables( (int) $template['id'] ) as $variable ) {
			$key = sanitize_key( $variable['variable_key'] ?? '' );
			if ( '' === $key || in_array( $key, $core_keys, true ) ) {
				continue;
			}
			$fields[] = array( 'key' => $key, 'type' => $variable['field_type'] ?? 'text', 'required' => ! empty( $variable['is_required'] ) );
		}
		return $fields;
	}
	
	/**
	 * Render certificate list
	 */
	private function render_certificate_list() {
		$this->cert_repo->expire_due_certificates();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter/search params, no state change.
		$search = sanitize_text_field( wp_unslash( $_GET['cm_search'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter param, no state change.
		$filter = sanitize_key( wp_unslash( $_GET['cm_status'] ?? 'all' ) );
		$filter = in_array( $filter, array( 'all', 'expiring', 'expired', 'revoked', 'replaced' ), true ) ? $filter : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination param, no state change.
		$current_page = max( 1, absint( wp_unslash( $_GET['cm_certificates_page'] ?? 1 ) ) );
		$query_args = array( 'per_page' => 50, 'paged' => $current_page, 'search' => $search );
		if ( 'expiring' === $filter ) {
			$query_args['expiring_soon'] = true;
			$query_args['expiry_days'] = max( 1, (int) $this->settings->get( 'expiry_renewal_notice_days', 30 ) );
		} elseif ( 'all' !== $filter ) {
			$query_args['status'] = $filter;
		}
		$result = $this->cert_repo->get_certificates( $query_args );
		$certificates = $result['data'];
		?>
		<nav class="cm-certificate-filters" aria-label="<?php esc_attr_e( 'Certificate status filters', 'certificate-manager' ); ?>">
			<?php foreach ( array( 'all' => __( 'All certificates', 'certificate-manager' ), 'expiring' => __( 'Expiring soon', 'certificate-manager' ), 'expired' => __( 'Expired', 'certificate-manager' ), 'revoked' => __( 'Revoked', 'certificate-manager' ), 'replaced' => __( 'Replaced', 'certificate-manager' ) ) as $key => $label ) : ?>
				<?php $filter_url = add_query_arg( array_filter( array( 'page' => 'cm_certificates', 'cm_status' => 'all' === $key ? null : $key, 'cm_search' => '' !== $search ? $search : null ) ), admin_url( 'admin.php' ) ); ?>
				<a class="button<?php echo $filter === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $filter_url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<form class="cm-certificate-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="cm_certificates">
			<?php if ( 'all' !== $filter ) : ?><input type="hidden" name="cm_status" value="<?php echo esc_attr( $filter ); ?>"><?php endif; ?>
			<label class="screen-reader-text" for="cm-certificate-search-input"><?php esc_html_e( 'Search certificates', 'certificate-manager' ); ?></label>
			<input id="cm-certificate-search-input" type="search" name="cm_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by certificate ID, recipient or email', 'certificate-manager' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'certificate-manager' ); ?></button>
			<?php if ( '' !== $search ) : ?><a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=cm_certificates' ) ); ?>"><?php esc_html_e( 'Clear', 'certificate-manager' ); ?></a><?php endif; ?>
		</form>
		<?php
		if ( empty( $certificates ) ) {
			?>
			<p><?php echo esc_html( '' === $search ? __( 'No certificates found.', 'certificate-manager' ) : __( 'No matching certificates found.', 'certificate-manager' ) ); ?></p>
			<?php
			return;
		}
		
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Recipient', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Template', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Date Issued', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Expiry', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'certificate-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $certificates as $cert ) : ?>
					<?php $expiry_state = $this->get_expiry_state( $cert ); ?>
					<?php $status = $expiry_state['expired'] && 'active' === $cert['status'] ? 'expired' : $cert['status']; ?>
					<?php $is_active = 'active' === $status; ?>
					<?php $view_url = wp_nonce_url( admin_url( 'admin-post.php?action=cm_view_certificate&certificate_id=' . absint( $cert['id'] ) . '&variant=current' ), 'cm_view_certificate_' . absint( $cert['id'] ) ); ?>
					<?php $original_url = wp_nonce_url( admin_url( 'admin-post.php?action=cm_view_certificate&certificate_id=' . absint( $cert['id'] ) . '&variant=original' ), 'cm_view_certificate_' . absint( $cert['id'] ) ); ?>
					<?php $verification_url = ! empty( $cert['verification_token'] ) ? add_query_arg( 'v', substr( (string) $cert['verification_token'], 0, 16 ), $this->settings->get_verification_destination_url() ) : ''; ?>
					<tr>
						<td>#<?php echo esc_html( $cert['certificate_number'] ); ?></td>
						<td><?php echo esc_html( $cert['recipient_name'] ); ?></td>
						<td><?php
						/* translators: %d: template database ID */
						echo esc_html( $cert['template_title'] ?: sprintf( __( 'Template #%d', 'certificate-manager' ), $cert['template_id'] ) ); ?></td>
						<td>
							<span class="cm-status cm-status-<?php echo esc_attr( $status ); ?>">
								<?php echo esc_html( ucfirst( $status ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $cert['issue_date'] ?? $cert['created_at'] ); ?></td>
						<td><span class="cm-expiry-state <?php echo esc_attr( $expiry_state['class'] ); ?>"><?php echo esc_html( $expiry_state['label'] ); ?></span><?php if ( $expiry_state['date'] ) : ?><span class="cm-expiry-date"><?php
						/* translators: %s: formatted expiry date */
						echo esc_html( sprintf( __( 'Expires %s', 'certificate-manager' ), $expiry_state['date'] ) ); ?></span><?php endif; ?></td>
						<td>
							<details class="cm-certificate-actions">
								<summary class="button<?php echo $is_active && $expiry_state['is_soon'] ? ' button-primary' : ''; ?>"><?php echo esc_html( $is_active && $expiry_state['is_soon'] ? __( 'Replace before expiry', 'certificate-manager' ) : __( 'Actions', 'certificate-manager' ) ); ?></summary>
								<div class="cm-certificate-actions-menu">
									<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View current', 'certificate-manager' ); ?></a>
									<?php if ( $verification_url ) : ?><button type="button" class="cm-view-verification-page" data-verification-url="<?php echo esc_url( $verification_url ); ?>"><?php esc_html_e( 'View verification page', 'certificate-manager' ); ?></button><?php endif; ?>
									<?php if ( current_user_can( 'cm_manage_settings' ) ) : ?><a href="<?php echo esc_url( $original_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View original', 'certificate-manager' ); ?></a><?php endif; ?>
									<?php if ( current_user_can( 'cm_issue_certificates' ) ) : ?><button type="button" class="cm-trigger-certificate-webhook" data-id="<?php echo esc_attr( $cert['id'] ); ?>"><?php esc_html_e( 'Trigger webhook', 'certificate-manager' ); ?></button><?php endif; ?>
									<?php if ( $is_active && current_user_can( 'cm_replace_certificates' ) ) : ?><button type="button" class="cm-replace-certificate" data-id="<?php echo esc_attr( $cert['id'] ); ?>"><?php esc_html_e( 'Replace', 'certificate-manager' ); ?></button><?php endif; ?>
									<?php if ( $is_active && current_user_can( 'cm_revoke_certificates' ) ) : ?><button type="button" class="cm-revoke-certificate is-destructive" data-id="<?php echo esc_attr( $cert['id'] ); ?>"><?php esc_html_e( 'Revoke', 'certificate-manager' ); ?></button><?php endif; ?>
								</div>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $result['total_pages'] > 1 ) : ?>
			<?php $pagination_url = str_replace( '999999999', '%#%', add_query_arg( array( 'page' => 'cm_certificates', 'cm_status' => 'all' === $filter ? null : $filter, 'cm_search' => $search, 'cm_certificates_page' => 999999999 ), admin_url( 'admin.php' ) ) ); ?>
			<nav class="cm-certificate-pagination" aria-label="<?php esc_attr_e( 'Certificate pages', 'certificate-manager' ); ?>"><?php echo wp_kses_post( paginate_links( array( 'base' => $pagination_url, 'format' => '', 'current' => $current_page, 'total' => $result['total_pages'], 'type' => 'list', 'prev_text' => __( 'Previous', 'certificate-manager' ), 'next_text' => __( 'Next', 'certificate-manager' ) ) ) ); ?></nav>
		<?php endif; ?>
		<?php
	}

	private function get_expiry_state( array $certificate ): array {
		if ( empty( $certificate['expiry_date'] ) ) {
			return array( 'label' => __( 'Does not expire', 'certificate-manager' ), 'date' => '', 'class' => 'is-none', 'expired' => false, 'is_soon' => false );
		}

		try {
			$expiry = new \DateTimeImmutable( $certificate['expiry_date'], wp_timezone() );
		} catch ( \Exception $error ) {
			return array( 'label' => __( 'Expiry date unavailable', 'certificate-manager' ), 'date' => '', 'class' => 'is-none', 'expired' => false, 'is_soon' => false );
		}

		$seconds_remaining = $expiry->getTimestamp() - time();
		if ( $seconds_remaining <= 0 ) {
			return array( 'label' => __( 'Expired', 'certificate-manager' ), 'date' => wp_date( get_option( 'date_format' ), $expiry->getTimestamp() ), 'class' => 'is-expired', 'expired' => true, 'is_soon' => false );
		}

		$days_remaining = (int) ceil( $seconds_remaining / DAY_IN_SECONDS );
		$renewal_window = max( 1, (int) $this->settings->get( 'expiry_renewal_notice_days', 30 ) );
		return array(
			/* translators: %d: number of days remaining until expiry */
			'label' => sprintf( _n( '%d day left', '%d days left', $days_remaining, 'certificate-manager' ), $days_remaining ),
			'date' => wp_date( get_option( 'date_format' ), $expiry->getTimestamp() ),
			'class' => $days_remaining <= $renewal_window ? 'is-soon' : 'is-active',
			'expired' => false,
			'is_soon' => $days_remaining <= $renewal_window,
		);
	}

	private function get_webhook_options_for_certificate( array $certificate ): array {
		$available = array();
		foreach ( $this->webhook_repo->get_all( array( 'is_active' => 1 ) ) as $webhook ) {
			if ( in_array( 'certificate_issued', $webhook['events'] ?? array(), true ) ) {
				$available[] = array( 'id' => absint( $webhook['id'] ), 'name' => $webhook['name'] );
			}
		}

		$snapshot = json_decode( $certificate['data_snapshot'] ?? '', true );
		$manual_ids = is_array( $snapshot ) ? array_values( array_filter( array_map( 'absint', $snapshot['manual_webhook_ids'] ?? array() ) ) ) : array();
		$template = $this->template_repo->get_template( (int) $certificate['template_id'] );
		$template_ids = $template ? json_decode( $template['webhook_ids'] ?? '[]', true ) : array();
		$template_ids = is_array( $template_ids ) ? array_values( array_filter( array_map( 'absint', $template_ids ) ) ) : array();
		$linked_ids = $manual_ids ? $manual_ids : $template_ids;
		$linked = array_values( array_filter( $available, function ( $webhook ) use ( $linked_ids ) {
			return in_array( (int) $webhook['id'], $linked_ids, true );
		} ) );

		return array( 'available' => $available, 'linked' => $linked );
	}

	private function get_certificate_webhook_data( array $certificate ): array {
		$template = $this->template_repo->get_template( (int) $certificate['template_id'] );
		$template_fields = array();
		foreach ( $this->cert_repo->get_certificate_fields( (int) $certificate['id'] ) as $field ) {
			$template_fields[ $field['variable_key'] ] = $field['value'];
		}

		return array(
			'internal_id' => $certificate['internal_id'],
			'certificate_number' => $certificate['certificate_number'],
			'recipient_name' => $certificate['recipient_name'],
			'recipient_email' => $certificate['recipient_email'],
			'status' => $certificate['status'],
			'issue_date' => $certificate['issue_date'],
			'expiry_date' => $certificate['expiry_date'],
			'template_id' => (int) $certificate['template_id'],
			'template_name' => $template['title'] ?? '',
			'template_fields' => $template_fields,
		);
	}
}
