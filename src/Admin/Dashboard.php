<?php
/**
 * Certificate Manager Dashboard
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard admin controller
 */
class Dashboard {
	
	/**
	 * Certificate repository
	 *
	 * @var mixed
	 */
	private $certificate_repo;
	
	/**
	 * Settings instance
	 *
	 * @var Core\Settings
	 */
	private $settings;
	
	/**
	 * Constructor
	 *
	 * @param mixed $certificate_repo Certificate repository
	 * @param Core\Settings $settings Settings instance
	 */
	public function __construct( $certificate_repo, \CertificateManager\Core\Settings $settings ) {
		$this->certificate_repo = $certificate_repo;
		$this->settings = $settings;
	}
	
	/**
	 * Initialize dashboard widgets
	 */
	public function init() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widgets' ) );
	}
	
	/**
	 * Add dashboard widgets
	 */
	public function add_dashboard_widgets() {
		wp_add_dashboard_widget(
			'cm_dashboard_overview',
			__( 'Certificate Manager Overview', 'certificate-manager' ),
			array( $this, 'render_overview_widget' )
		);
		
		wp_add_dashboard_widget(
			'cm_dashboard_recent',
			__( 'Recent Certificates', 'certificate-manager' ),
			array( $this, 'render_recent_widget' )
		);
		
		wp_add_dashboard_widget(
			'cm_dashboard_status',
			__( 'System Status', 'certificate-manager' ),
			array( $this, 'render_status_widget' )
		);
	}
	
	/**
	 * Render overview widget
	 */
	public function render_overview_widget() {
		$stats = $this->certificate_repo->get_statistics();
		
		?>
		<div class="cm-dashboard-grid">
			<div class="cm-stat-card cm-stat-total">
				<span class="cm-stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
				<span class="cm-stat-label"><?php esc_html_e( 'Total Certificates', 'certificate-manager' ); ?></span>
			</div>
			<div class="cm-stat-card cm-stat-active">
				<span class="cm-stat-number"><?php echo esc_html( $stats['active'] ); ?></span>
				<span class="cm-stat-label"><?php esc_html_e( 'Active', 'certificate-manager' ); ?></span>
			</div>
			<div class="cm-stat-card cm-stat-expired">
				<span class="cm-stat-number"><?php echo esc_html( $stats['expired'] ); ?></span>
				<span class="cm-stat-label"><?php esc_html_e( 'Expired', 'certificate-manager' ); ?></span>
			</div>
			<div class="cm-stat-card cm-stat-revoked">
				<span class="cm-stat-number"><?php echo esc_html( $stats['revoked'] ); ?></span>
				<span class="cm-stat-label"><?php esc_html_e( 'Revoked', 'certificate-manager' ); ?></span>
			</div>
			<div class="cm-stat-card cm-stat-expiring">
				<span class="cm-stat-number"><?php echo esc_html( $stats['expiring_soon'] ); ?></span>
				<span class="cm-stat-label"><?php esc_html_e( 'Expiring Soon (30 days)', 'certificate-manager' ); ?></span>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render recent certificates widget
	 */
	public function render_recent_widget() {
		$certs = $this->certificate_repo->get_certificates( array( 'per_page' => 5, 'paged' => 1 ) );
		
		if ( empty( $certs['data'] ) ) {
			echo '<p>' . esc_html__( 'No certificates found.', 'certificate-manager' ) . '</p>';
			return;
		}
		
		?>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Certificate #', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Recipient', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'certificate-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $certs['data'] as $cert ) : ?>
				<tr>
					<td><?php echo esc_html( $cert['certificate_number'] ); ?></td>
					<td><?php echo esc_html( $cert['recipient_name'] ); ?></td>
					<td><span class="cm-status cm-status-<?php echo esc_attr( $cert['status'] ); ?>"><?php echo esc_html( $cert['status'] ); ?></span></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cm_certificates' ) ); ?>"><?php esc_html_e( 'View All Certificates', 'certificate-manager' ); ?></a></p>
		<?php
	}
	
	/**
	 * Render system status widget
	 */
	public function render_status_widget() {
		?>
		<ul>
			<li><?php esc_html_e( 'Plugin Version:', 'certificate-manager' ); ?> <strong><?php echo esc_html( CERTIFICATE_MANAGER_VERSION ); ?></strong></li>
			<li><?php esc_html_e( 'Schema Version:', 'certificate-manager' ); ?> <strong><?php echo esc_html( $this->get_schema_version() ); ?></strong></li>
			<li><?php esc_html_e( 'Table Prefix:', 'certificate-manager' ); ?> <strong><?php global $wpdb; echo esc_html( $wpdb->prefix ); ?></strong></li>
			<li><?php esc_html_e( 'Upload Directory:', 'certificate-manager' ); ?> <strong><?php echo esc_html( wp_upload_dir()['basedir'] ); ?></strong></li>
		</ul>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cm_system_status' ) ); ?>"><?php esc_html_e( 'View Full System Status', 'certificate-manager' ); ?></a></p>
		<?php
	}
	
	/**
	 * Get schema version
	 *
	 * @return int
	 */
	private function get_schema_version(): int {
		return (int) get_option( 'certificate_manager_schema_version', 0 );
	}
}
