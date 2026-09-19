<?php
/**
 * Logs Admin Interface
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs admin class
 */
class LogsAdmin {
	
	/**
	 * Audit repository
	 *
	 * @var Repositories\AuditRepository
	 */
	private $audit_repo;
	
	/**
	 * Log repository
	 *
	 * @var Repositories\LogRepository
	 */
	private $log_repo;
	
	/**
	 * Settings
	 *
	 * @var Core\Settings
	 */
	private $settings;
	
	/**
	 * Constructor
	 *
	 * @param Repositories\AuditRepository $audit_repo Audit repository.
	 * @param Repositories\LogRepository  $log_repo Log repository.
	 * @param Core\Settings             $settings Settings instance.
	 */
	public function __construct( $audit_repo, $log_repo, $settings ) {
		$this->audit_repo = $audit_repo;
		$this->log_repo = $log_repo;
		$this->settings = $settings;
	}
	
	/**
	 * Initialize admin functionality
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_cm_clear_logs', array( $this, 'clear_logs' ) );
	}
	
	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current hook.
	 */
	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'cm_logs' !== $page ) {
			return;
		}
		
		wp_enqueue_style( 'certificate-manager-admin', plugins_url( '../Assets/css/admin.css', __FILE__ ), array(), CERTIFICATE_MANAGER_VERSION );
		wp_enqueue_script( 'certificate-manager-admin', plugins_url( '../Assets/js/admin.js', __FILE__ ), array( 'jquery' ), CERTIFICATE_MANAGER_VERSION, true );
		
		wp_localize_script( 'certificate-manager-admin', 'cmAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cm-admin-nonce' ),
			'l10n' => array(
				'clearSuccess' => __( 'Logs cleared successfully', 'certificate-manager' ),
				'error' => __( 'An error occurred', 'certificate-manager' ),
			),
		) );
	}
	
	/**
	 * Clear logs via AJAX
	 */
	public function clear_logs() {
		if ( ! current_user_can( 'cm_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$logs_type = sanitize_text_field( $_POST['type'] ?? '' );
		
		if ( 'audit' === $logs_type ) {
			$this->audit_repo->clear();
		} else {
			$this->log_repo->clear();
		}
		
		wp_send_json_success();
	}
	
	/**
	 * Render admin page
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Certificates Logs', 'certificate-manager' ); ?></h1>
			
			<h2><?php esc_html_e( 'Audit Trail', 'certificate-manager' ); ?></h2>
			<p><?php esc_html_e( 'Record of all certificate operations and system events.', 'certificate-manager' ); ?></p>
			
			<div class="cm-audit-logs">
				<?php $this->render_audit_logs(); ?>
			</div>
			
			<button type="button" class="button button-secondary cm-clear-logs" data-type="audit">
				<?php esc_html_e( 'Clear Audit Logs', 'certificate-manager' ); ?>
			</button>
			
			<h2 style="margin-top: 30px;"><?php esc_html_e( 'Operational Logs', 'certificate-manager' ); ?></h2>
			<p><?php esc_html_e( 'Technical logs for debugging and monitoring.', 'certificate-manager' ); ?></p>
			
			<div class="cm-operational-logs">
				<?php $this->render_operational_logs(); ?>
			</div>
			
			<button type="button" class="button button-secondary cm-clear-logs" data-type="operational">
				<?php esc_html_e( 'Clear Operational Logs', 'certificate-manager' ); ?>
			</button>
		</div>
		<?php
	}
	
	/**
	 * Render audit logs
	 */
	private function render_audit_logs() {
		$result = $this->audit_repo->get_audit_entries( array( 'per_page' => 20 ) );
		$logs = $result['data'];
		
		if ( empty( $logs ) ) {
			?>
			<p><?php esc_html_e( 'No audit logs found.', 'certificate-manager' ); ?></p>
			<?php
			return;
		}
		
		?>
		<table class="wp-list-table widefat fixed striped cm-log-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'User', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Event', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Details', 'certificate-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['created_at'] ); ?></td>
						<td><?php echo esc_html( $this->format_performer( $log ) ); ?></td>
						<td><?php echo esc_html( $log['event'] ); ?></td>
						<td>
							<?php if ( ! empty( $log['changed_data'] ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'View details', 'certificate-manager' ); ?></summary>
								<pre class="cm-log-details-content"><?php echo esc_html( $this->format_log_details( $log['changed_data'] ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function format_performer( array $log ): string {
		$type = sanitize_key( $log['performer_type'] ?? 'system' );
		$user_id = absint( $log['performer_id'] ?? 0 );
		$details = json_decode( $log['performer_details'] ?? '', true );
		$details = is_array( $details ) ? $details : array();

		if ( 'user' !== $type ) {
			return 'api' === $type ? __( 'API', 'certificate-manager' ) : __( 'System', 'certificate-manager' );
		}

		$user = $user_id ? get_userdata( $user_id ) : false;
		$display_name = $user ? $user->display_name : ( $details['display_name'] ?? '' );
		$username = $user ? $user->user_login : ( $details['username'] ?? '' );
		if ( $display_name && $username && $display_name !== $username ) {
			return sprintf( '%1$s (%2$s)', $display_name, $username );
		}
		if ( $display_name || $username ) {
			return (string) ( $display_name ?: $username );
		}

		return $user_id ? sprintf( __( 'Deleted user #%d', 'certificate-manager' ), $user_id ) : __( 'Unknown user', 'certificate-manager' );
	}

	private function format_log_details( $details ): string {
		if ( is_string( $details ) ) {
			$decoded = json_decode( $details, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$details = $decoded;
			}
		}

		if ( is_array( $details ) || is_object( $details ) ) {
			return (string) wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		return (string) $details;
	}
	
	/**
	 * Render operational logs
	 */
	private function render_operational_logs() {
		$result = $this->log_repo->get_logs( array( 'per_page' => 20 ) );
		$logs = $result['data'];
		
		if ( empty( $logs ) ) {
			?>
			<p><?php esc_html_e( 'No operational logs found.', 'certificate-manager' ); ?></p>
			<?php
			return;
		}
		
		?>
		<table class="wp-list-table widefat fixed striped cm-log-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Level', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Message', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Context', 'certificate-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['created_at'] ); ?></td>
						<td>
							<span class="cm-log-level cm-log-level-<?php echo esc_attr( $log['log_level'] ); ?>">
								<?php echo esc_html( strtoupper( $log['log_level'] ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $log['message'] ); ?></td>
						<td>
							<?php if ( ! empty( $log['context'] ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'View context', 'certificate-manager' ); ?></summary>
								<pre class="cm-log-details-content"><?php echo esc_html( $this->format_log_details( $log['context'] ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
