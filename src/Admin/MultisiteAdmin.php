<?php
/**
 * Multisite Admin Interface
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multisite admin class
 */
class MultisiteAdmin {
	
	/**
	 * Template repository
	 *
	 * @var Repositories\TemplateRepository
	 */
	private $template_repo;
	
	/**
	 * Constructor
	 *
	 * @param Repositories\TemplateRepository $template_repo Template repository.
	 */
	public function __construct( $template_repo ) {
		$this->template_repo = $template_repo;
	}
	
	/**
	 * Initialize admin functionality
	 */
	public function init() {
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
	}
	
	/**
	 * Add network admin menu
	 */
	public function add_network_menu() {
		add_menu_page(
			__( 'Certificate Manager', 'certificate-manager' ),
			__( 'Certificates', 'certificate-manager' ),
			'manage_network',
			'cm_network',
			'__return_false',
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzAwMDAwMCI+PHBhdGggZD0iTTE5IDNINWMtMS4xIDAtMiAuOS0yIDJ2MTRhMiAyIDAgMCAwIDIgMmgxNGExIDIgMCAwIDAgMi0yVjVjMC0xLjEtLjktMi0yLTJ6bS0xIDFoLTExdjJoMTF2LTJ6bS0xIDFoLTl2Mmg5di0yeiIvPjwvc3ZnPg=='
		);
		
		add_submenu_page(
			'cm_network',
			__( 'Network Templates', 'certificate-manager' ),
			__( 'Network Templates', 'certificate-manager' ),
			'manage_network',
			'cm_network_templates',
			array( $this, 'render_network_templates_page' )
		);
	}
	
	/**
	 * Render network templates page
	 */
	public function render_network_templates_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Network Templates', 'certificate-manager' ); ?></h1>
			<p><?php esc_html_e( 'Manage templates that are available across the entire network.', 'certificate-manager' ); ?></p>
			
			<div class="cm-network-templates">
				<?php $this->render_network_template_list(); ?>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render network template list
	 */
	private function render_network_template_list() {
		// Get templates marked as network-wide
		$templates = $this->template_repo->get_network_templates();
		
		if ( empty( $templates ) ) {
			?>
			<p><?php esc_html_e( 'No network templates found.', 'certificate-manager' ); ?></p>
			<?php
			return;
		}
		
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Template', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Sites', 'certificate-manager' ); ?></th>
					<th><?php esc_html_e( 'Last Updated', 'certificate-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $template ) : ?>
					<tr>
						<td><?php echo esc_html( $template['name'] ); ?></td>
						<td>
							<?php
							$count = count( $template['sites'] );
							echo esc_html( $count );
							if ( $count > 5 ) {
								echo ' ' . sprintf( __( '+ %d more', 'certificate-manager' ), $count - 5 );
							}
							?>
						</td>
						<td><?php echo esc_html( $template['updated_at'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
