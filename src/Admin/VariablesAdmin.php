<?php
/**
 * Variables Admin Interface
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variables admin class
 */
class VariablesAdmin {
	
	/**
	 * Variable repository
	 *
	 * @var Repositories\VariableRepository
	 */
	private $variable_repo;
	
	/**
	 * Constructor
	 *
	 * @param Repositories\VariableRepository $variable_repo Variable repository.
	 */
	public function __construct( $variable_repo ) {
		$this->variable_repo = $variable_repo;
	}
	
	/**
	 * Initialize admin functionality
	 */
	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_cm_save_variable', array( $this, 'save_variable' ) );
		add_action( 'wp_ajax_cm_delete_variable', array( $this, 'delete_variable' ) );
		add_action( 'wp_ajax_cm_get_variable', array( $this, 'get_variable' ) );
	}
	
	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current hook.
	 */
	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		if ( 'cm_variables' !== $page ) {
			return;
		}
		
		wp_enqueue_media();
		wp_enqueue_style( 'certificate-manager-admin', plugins_url( '../Assets/css/admin.css', __FILE__ ), array(), CERTIFICATE_MANAGER_VERSION );
		wp_enqueue_script( 'certificate-manager-admin', plugins_url( '../Assets/js/admin.js', __FILE__ ), array( 'jquery' ), CERTIFICATE_MANAGER_VERSION, true );
		
		wp_localize_script( 'certificate-manager-admin', 'cmAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cm-admin-nonce' ),
			'l10n' => array(
				'saveSuccess' => __( 'Variable saved successfully', 'certificate-manager' ),
				'deleteSuccess' => __( 'Variable deleted successfully', 'certificate-manager' ),
				'error' => __( 'An error occurred', 'certificate-manager' ),
			),
		) );
	}
	
	/**
	 * Save variable via AJAX
	 */
	public function save_variable() {
		if ( ! current_user_can( 'cm_manage_variables' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$variable_id = isset( $_POST['variable_id'] ) ? intval( $_POST['variable_id'] ) : 0;
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$value = wp_kses_post_deep( wp_unslash( $_POST['value'] ?? '' ) );
		$field_type = sanitize_key( $_POST['field_type'] ?? 'text' );
		$allowed_field_types = array( 'text', 'textarea', 'email', 'number', 'date', 'url', 'image' );
		$field_options = json_decode( wp_unslash( $_POST['field_options'] ?? '[]' ), true );
		$field_options = is_array( $field_options ) ? $field_options : array();
		if ( ! in_array( $field_type, $allowed_field_types, true ) ) {
			$field_type = 'text';
		}
		if ( 'image' === $field_type ) {
			$value = (string) absint( $value );
			$field_options = array_values( array_unique( array_filter( array_map( 'absint', $field_options ), 'wp_attachment_is_image' ) ) );
		}
		
		$data = array(
			'label' => $name,
			'key' => $key,
			'default_value' => $value,
			'field_type' => $field_type,
		);
		if ( 'image' === $field_type ) {
			$data['field_options'] = $field_options;
		}
		
		if ( $variable_id ) {
			$result = $this->variable_repo->update_variable( $variable_id, $data );
		} else {
			$result = $this->variable_repo->create_variable( $data );
		}
		
		if ( $result ) {
			wp_send_json_success( array(
				'id' => is_array( $result ) ? $result['id'] : $result,
				'name' => $name,
			) );
		}
		
		wp_send_json_error( array( 'message' => __( 'Failed to save variable', 'certificate-manager' ) ), 500 );
	}
	
	/**
	 * Delete variable via AJAX
	 */
	public function delete_variable() {
		if ( ! current_user_can( 'cm_manage_variables' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$variable_id = intval( $_POST['variable_id'] );
		
		if ( $this->variable_repo->delete_variable( $variable_id ) ) {
			wp_send_json_success();
		}
		
		wp_send_json_error( array( 'message' => __( 'Failed to delete variable', 'certificate-manager' ) ), 500 );
	}
	
	/**
	 * Get variable via AJAX
	 */
	public function get_variable() {
		if ( ! current_user_can( 'cm_manage_variables' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'certificate-manager' ) ), 403 );
		}
		
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cm-admin-nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'certificate-manager' ) ), 400 );
		}
		
		$variable_id = intval( $_POST['variable_id'] );
		$variable = $this->variable_repo->get_variable( $variable_id );
		
		if ( ! $variable ) {
			wp_send_json_error( array( 'message' => __( 'Variable not found', 'certificate-manager' ) ), 404 );
		}
		
		wp_send_json_success( $variable );
	}
	
	/**
	 * Render admin page
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Certificate Variables', 'certificate-manager' ); ?></h1>
			<p><?php esc_html_e( 'Manage reusable variables for your certificate templates.', 'certificate-manager' ); ?></p>
			
			<div class="cm-variables-list">
				<?php $this->render_variable_list(); ?>
			</div>
			
			<div class="cm-variable-create">
				<div class="cm-variable-create-intro"><h2><?php esc_html_e( 'Create a variable', 'certificate-manager' ); ?></h2><p><?php esc_html_e( 'Choose “Image from Media Library” for a different image on each issued certificate.', 'certificate-manager' ); ?></p></div>
				<label><?php esc_html_e( 'Label', 'certificate-manager' ); ?><input type="text" id="cm-new-variable-name"></label>
				<label><?php esc_html_e( 'Key', 'certificate-manager' ); ?><input type="text" id="cm-new-variable-key"></label>
				<label><?php esc_html_e( 'Input type', 'certificate-manager' ); ?><select id="cm-new-variable-type"><option value="text"><?php esc_html_e( 'Single-line text', 'certificate-manager' ); ?></option><option value="textarea"><?php esc_html_e( 'Multi-line text', 'certificate-manager' ); ?></option><option value="email"><?php esc_html_e( 'Email', 'certificate-manager' ); ?></option><option value="number"><?php esc_html_e( 'Number', 'certificate-manager' ); ?></option><option value="date"><?php esc_html_e( 'Date', 'certificate-manager' ); ?></option><option value="url"><?php esc_html_e( 'Web address', 'certificate-manager' ); ?></option><option value="image"><?php esc_html_e( 'Image from Media Library', 'certificate-manager' ); ?></option></select></label>
				<div id="cm-image-variable-options" class="cm-image-variable-options" hidden><strong><?php esc_html_e( 'Approved images', 'certificate-manager' ); ?></strong><p><?php esc_html_e( 'Optional: choose the only images an issuer may use. Leave blank to let them choose any Media Library image.', 'certificate-manager' ); ?></p><button type="button" class="button cm-select-variable-image-options"><?php esc_html_e( 'Choose approved images', 'certificate-manager' ); ?></button><div class="cm-variable-image-options-list"></div></div>
				<button type="button" class="button button-primary cm-add-variable"><?php esc_html_e( 'Add variable', 'certificate-manager' ); ?></button>
				<span class="cm-variable-create-status" role="status"></span>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render variable list
	 */
	private function render_variable_list() {
		$variables = $this->variable_repo->get_variables( array( 'system' => true ) );
		
		if ( empty( $variables ) ) {
			?>
			<p><?php esc_html_e( 'No variables found. Create your first variable below.', 'certificate-manager' ); ?></p>
			<?php
			return;
		}
		
		?>
		<ul class="cm-variables">
			<?php foreach ( $variables as $variable ) : ?>
				<li class="cm-variable-item" data-variable-id="<?php echo esc_attr( $variable['id'] ); ?>">
					<div class="cm-variable-header">
					<h3><?php echo esc_html( $variable['label'] ); ?></h3>
					<span class="cm-variable-key">Key: <?php echo esc_html( $variable['variable_key'] ); ?></span>
					<span class="cm-variable-key"><?php echo esc_html( 'image' === $variable['field_type'] ? __( 'Image from Media Library', 'certificate-manager' ) : $variable['field_type'] ); ?></span>
						<div class="cm-variable-actions">
							<button type="button" class="button cm-edit-variable"><?php esc_html_e( 'Edit', 'certificate-manager' ); ?></button>
							<?php if ( 'image' === $variable['field_type'] ) : ?><button type="button" class="button cm-configure-variable-images"><?php esc_html_e( 'Approved images', 'certificate-manager' ); ?></button><?php endif; ?>
							<button type="button" class="button cm-delete-variable" style="background: #dc3232; border-color: #dc3232; color: #fff;"><?php esc_html_e( 'Delete', 'certificate-manager' ); ?></button>
						</div>
					</div>
					<div class="cm-variable-value">
					<?php echo wp_kses_post( $variable['default_value'] ?? '' ); ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
