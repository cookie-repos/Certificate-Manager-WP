<?php
/**
 * Certificate Manager System Status
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System status admin controller
 */
class SystemStatus {
	
	/**
	 * Settings instance
	 *
	 * @var Core\Settings
	 */
	private $settings;
	
	/**
	 * Constructor
	 *
	 * @param Core\Settings $settings Settings instance
	 */
	public function __construct( \CertificateManager\Core\Settings $settings ) {
		$this->settings = $settings;
	}
	
	/**
	 * Initialize system status page
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_system_status_page' ) );
		add_action( 'admin_init', array( $this, 'system_status_init' ) );
	}
	
	/**
	 * Add system status page to menu
	 */
	public function add_system_status_page() {
		add_submenu_page(
			'cm_certificates',
			__( 'System Status', 'certificate-manager' ),
			__( 'System Status', 'certificate-manager' ),
			'cm_manage_settings',
			'cm_system_status',
			array( $this, 'render_system_status_page' )
		);
	}
	
	/**
	 * Register system status init
	 */
	public function system_status_init() {
		// Handle diagnostic actions
		if ( isset( $_GET['cm_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified inside handle_diagnostic_action.
			$this->handle_diagnostic_action();
		}
	}
	
	/**
	 * Render system status page
	 */
	public function render_system_status_page() {
		?>
		<div class="wrap certificate-manager-system-status">
			<h1><?php esc_html_e( 'Certificate Manager System Status', 'certificate-manager' ); ?></h1>
			
			<h2><?php esc_html_e( 'WordPress Environment', 'certificate-manager' ); ?></h2>
			<table class="widefat striped">
				<tr>
					<th><?php esc_html_e( 'WordPress Version', 'certificate-manager' ); ?></th>
					<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'PHP Version', 'certificate-manager' ); ?></th>
					<td><?php echo esc_html( PHP_VERSION ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'MySQL Version', 'certificate-manager' ); ?></th>
					<td><?php global $wpdb; echo esc_html( $wpdb->db_version() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Web Server', 'certificate-manager' ); ?></th>
					<td><?php echo esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Memory Limit', 'certificate-manager' ); ?></th>
					<td><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Upload Max Size', 'certificate-manager' ); ?></th>
					<td><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Post Max Size', 'certificate-manager' ); ?></th>
					<td><?php echo esc_html( ini_get( 'post_max_size' ) ); ?></td>
				</tr>
			</table>
			
			<h2><?php esc_html_e( 'Required PHP Extensions', 'certificate-manager' ); ?></h2>
			<table class="widefat striped">
				<tr>
					<th><?php esc_html_e( 'Extension', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'certificate-manager' ); ?></th>
				</tr>
				<tr>
					<td>PHP-GD</td>
					<td><?php echo extension_loaded( 'gd' ) ? '<span style="color:green;">' . esc_html__( 'Installed', 'certificate-manager' ) . '</span>' : '<span style="color:red;">' . esc_html__( 'Missing', 'certificate-manager' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<td>mbstring</td>
					<td><?php echo extension_loaded( 'mbstring' ) ? '<span style="color:green;">' . esc_html__( 'Installed', 'certificate-manager' ) . '</span>' : '<span style="color:red;">' . esc_html__( 'Missing', 'certificate-manager' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<td>json</td>
					<td><?php echo extension_loaded( 'json' ) ? '<span style="color:green;">' . esc_html__( 'Installed', 'certificate-manager' ) . '</span>' : '<span style="color:red;">' . esc_html__( 'Missing', 'certificate-manager' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<td>openssl</td>
					<td><?php echo extension_loaded( 'openssl' ) ? '<span style="color:green;">' . esc_html__( 'Installed', 'certificate-manager' ) . '</span>' : '<span style="color:red;">' . esc_html__( 'Missing', 'certificate-manager' ) . '</span>'; ?></td>
				</tr>
			</table>
			
			<h2><?php esc_html_e( 'PDF Generation Test', 'certificate-manager' ); ?></h2>
			<?php $this->test_pdf_generation(); ?>
			
			<h2><?php esc_html_e( 'QR Code Generation Test', 'certificate-manager' ); ?></h2>
			<?php $this->test_qr_generation(); ?>
			
			<h2><?php esc_html_e( 'Database Tables', 'certificate-manager' ); ?></h2>
			<?php $this->check_database_tables(); ?>
		</div>
		<?php
	}
	
	/**
	 * Test PDF generation
	 */
	private function test_pdf_generation() {
		if ( ! class_exists( '\Mpdf\Mpdf' ) && ! class_exists( 'Mpdf\Mpdf' ) ) {
			echo '<p><span style="color:red;">' . esc_html__( 'The bundled mPDF files are incomplete. Reinstall Certificate Manager from its official ZIP.', 'certificate-manager' ) . '</span></p>';
			return;
		}

		try {
			$upload_dir = wp_upload_dir();
			$temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'certificate-manager/mpdf-temp';
			wp_mkdir_p( $temp_dir );
			$pdf = new \Mpdf\Mpdf( array( 'tempDir' => $temp_dir, 'exposeVersion' => false ) );
			$pdf->WriteHTML( '<p>Certificate Manager PDF test</p>' );
			$output = $pdf->Output( '', 'S' );

			if ( 0 !== strpos( $output, '%PDF-' ) ) {
				throw new \RuntimeException( __( 'The PDF engine returned invalid output.', 'certificate-manager' ) );
			}

			/* translators: %s: mPDF version number */
			echo '<p><span style="color:green;">' . sprintf( esc_html__( 'Bundled mPDF %s generated a test PDF successfully.', 'certificate-manager' ), esc_html( \Mpdf\Mpdf::VERSION ) ) . '</span></p>';
			echo '<p class="description">' . esc_html__( 'PDF generation is self-contained; no Composer command or separate WordPress plugin is required.', 'certificate-manager' ) . '</p>';
		} catch ( \Throwable $error ) {
			/* translators: %s: error message */
			echo '<p><span style="color:red;">' . esc_html( sprintf( __( 'Bundled mPDF could not generate a PDF: %s', 'certificate-manager' ), $error->getMessage() ) ) . '</span></p>';
		}
	}
	
	/**
	 * Test QR code generation
	 */
	private function test_qr_generation() {
		$qr_wrapper = CERTIFICATE_MANAGER_PATH . 'lib/QRCode/qrlib.php';
		$qr_generator = CERTIFICATE_MANAGER_PATH . 'lib/QRCode/QRCode.php';
		
		if ( file_exists( $qr_wrapper ) && file_exists( $qr_generator ) ) {
			echo '<p><span style="color:green;">' . esc_html__( 'QR code library is available', 'certificate-manager' ) . '</span></p>';
		} else {
			echo '<p><span style="color:red;">' . esc_html__( 'QR code library not found', 'certificate-manager' ) . '</span></p>';
		}
	}
	
	/**
	 * Check database tables
	 */
	private function check_database_tables() {
		global $wpdb;
		
		$schema = new \CertificateManager\Database\Schema();
		$tables = $schema->get_table_names();
		
		echo '<table class="widefat striped">';
		echo '<tr><th>' . esc_html__( 'Table', 'certificate-manager' ) . '</th><th>' . esc_html__( 'Status', 'certificate-manager' ) . '</th></tr>';
		
		foreach ( $tables as $table_name => $full_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not appropriate.
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_name ) );
			echo '<tr>';
			echo '<td>' . esc_html( $table_name ) . '</td>';
			echo '<td>' . ( $exists ? '<span style="color:green;">' . esc_html__( 'Exists', 'certificate-manager' ) . '</span>' : '<span style="color:red;">' . esc_html__( 'Missing', 'certificate-manager' ) . '</span>' ) . '</td>';
			echo '</tr>';
		}
		
		echo '</table>';
	}
	
	/**
	 * Handle diagnostic action
	 */
	private function handle_diagnostic_action() {
		if ( ! current_user_can( 'cm_manage_settings' ) ) {
			return;
		}
		
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'cm_diagnostic' ) ) {
			return;
		}
		
		$cm_action = isset( $_GET['cm_action'] ) ? sanitize_key( wp_unslash( $_GET['cm_action'] ) ) : '';
		switch ( $cm_action ) {
			case 'test_email':
				$this->test_email();
				break;
			case 'test_pdf':
				$this->test_pdf();
				break;
			case 'test_qr':
				$this->test_qr();
				break;
		}
		
		wp_safe_redirect( add_query_arg( 'cm_test', 'complete' ) );
		exit;
	}
	
	/**
	 * Test email sending
	 */
	private function test_email() {
		$result = wp_mail( get_bloginfo( 'admin_email' ), 'Test Email', 'This is a test email from Certificate Manager.' );
		
		if ( $result ) {
			wp_die( '<p><span style="color:green;">' . esc_html__( 'Email sent successfully', 'certificate-manager' ) . '</span></p><p><a href="javascript:history.back()">' . esc_html__( 'Back', 'certificate-manager' ) . '</a></p>' );
		} else {
			wp_die( '<p><span style="color:red;">' . esc_html__( 'Email failed to send', 'certificate-manager' ) . '</span></p><p><a href="javascript:history.back()">' . esc_html__( 'Back', 'certificate-manager' ) . '</a></p>' );
		}
	}
}
