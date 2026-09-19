<?php
/**
 * Templates Admin Interface
 *
 * All AJAX handlers in this file call verify_request() first, which internally
 * calls check_ajax_referer() to verify the nonce. The phpcs nonce-verification
 * sniff cannot detect this indirect check, so the warnings below are suppressed.
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TemplatesAdmin {

	private $template_repo;
	private $designer_service;
	private $settings;
	private $variable_repo;
	private $webhook_repo;

	public function __construct( $template_repo, $designer_service, $settings, $variable_repo, $webhook_repo ) {
		$this->template_repo = $template_repo;
		$this->designer_service = $designer_service;
		$this->settings = $settings;
		$this->variable_repo = $variable_repo;
		$this->webhook_repo = $webhook_repo;
	}

	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_cm_save_template', array( $this, 'save_template' ) );
		add_action( 'wp_ajax_cm_delete_template', array( $this, 'delete_template' ) );
		add_action( 'wp_ajax_cm_duplicate_template', array( $this, 'duplicate_template' ) );
		add_action( 'wp_ajax_cm_get_template', array( $this, 'get_template' ) );
		add_action( 'wp_ajax_cm_set_default_template', array( $this, 'set_default_template' ) );
		add_action( 'wp_ajax_cm_search_pixabay', array( $this, 'search_pixabay' ) );
		add_action( 'wp_ajax_cm_import_pixabay_image', array( $this, 'import_pixabay_image' ) );
	}

	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		if ( 'cm_templates' !== $page ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'certificate-manager-admin', plugins_url( '../Assets/css/admin.css', __FILE__ ), array(), CERTIFICATE_MANAGER_VERSION );
		wp_enqueue_script( 'certificate-manager-admin', plugins_url( '../Assets/js/admin.js', __FILE__ ), array( 'jquery' ), CERTIFICATE_MANAGER_VERSION, true );
		wp_localize_script( 'certificate-manager-admin', 'cmAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cm-admin-nonce' ),
			'pixabayConfigured' => '' !== trim( (string) $this->settings->get( 'pixabay_api_key', '' ) ),
			'settingsUrl' => admin_url( 'admin.php?page=cm_settings' ),
			'qrBadges' => $this->get_qr_badges(),
			'l10n' => array(
				'saveSuccess' => __( 'Template saved and published.', 'certificate-manager' ),
				'deleteSuccess' => __( 'Template deleted successfully.', 'certificate-manager' ),
				'duplicateSuccess' => __( 'Template duplicated successfully.', 'certificate-manager' ),
				'defaultSuccess' => __( 'Default template updated.', 'certificate-manager' ),
				'error' => __( 'An error occurred.', 'certificate-manager' ),
			),
		) );
	}

	/**
	 * Bundled trust badges available as an attached QR presentation.
	 *
	 * The compact artwork is used at every size. Ratios are intrinsic width /
	 * height values.
	 */
	private function get_qr_badges(): array {
		$badges = array(
			'heritage-green' => array( 'label' => __( 'Heritage Green', 'certificate-manager' ), 'file' => 'Heritage-Green-Min.png', 'ratio' => 1 ),
			'citrus-burst' => array( 'label' => __( 'Citrus Burst', 'certificate-manager' ), 'file' => 'Citrus-Burst-Min.png', 'ratio' => 1 ),
			'obsidian-gold' => array( 'label' => __( 'Obsidian Gold', 'certificate-manager' ), 'file' => 'Obsidian-Gold-Min.png', 'ratio' => 1 ),
			'mint-candy' => array( 'label' => __( 'Mint Candy', 'certificate-manager' ), 'file' => 'Mint-Candy-Min.png', 'ratio' => 1 ),
			'midnight-navy' => array( 'label' => __( 'Midnight Navy', 'certificate-manager' ), 'file' => 'Midnight-Navy-Min.png', 'ratio' => 1 ),
			'burgundy-rose' => array( 'label' => __( 'Burgundy Rose', 'certificate-manager' ), 'file' => 'Burgundy-Rose-Min.png', 'ratio' => 1 ),
			'signal-blue' => array( 'label' => __( 'Signal Blue', 'certificate-manager' ), 'file' => 'Signal-Blue-Min.png', 'ratio' => 1 ),
			'ultraviolet-pop' => array( 'label' => __( 'Ultraviolet Pop', 'certificate-manager' ), 'file' => 'Ultraviolet-Pop-Min.png', 'ratio' => 1 ),
		);
		foreach ( $badges as $key => &$badge ) {
			$badge['id'] = $key;
			$badge['url'] = CERTIFICATE_MANAGER_URL . 'src/QR/Badges/' . rawurlencode( $badge['file'] );
			unset( $badge['file'] );
		}
		unset( $badge );
		return $badges;
	}

	public function search_pixabay() {
		$this->verify_request( 'cm_edit_templates' );
		$api_key = trim( (string) $this->settings->get( 'pixabay_api_key', '' ) );
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Add a Pixabay API key in Certificate Manager Settings before searching.', 'certificate-manager' ) ), 400 );
		}

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		$query = mb_substr( $query, 0, 100 );
		$page = min( 500, max( 1, absint( $_POST['page'] ?? 1 ) ) );
		$target = sanitize_key( wp_unslash( $_POST['target'] ?? 'background' ) );
		$target = in_array( $target, array( 'background', 'badge' ), true ) ? $target : 'background';
		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => __( 'Enter something to search for.', 'certificate-manager' ) ), 400 );
		}

		$cache_key = 'cm_pixabay_' . md5( $api_key . '|' . $query . '|' . $page . '|' . $target );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		$args = array(
			'key' => $api_key,
			'q' => $query,
			'image_type' => 'all',
			'safesearch' => 'true',
			'page' => $page,
			'per_page' => 24,
		);
		if ( 'background' === $target ) {
			$args['orientation'] = 'horizontal';
		}
		$response = wp_safe_remote_get( add_query_arg( $args, 'https://pixabay.com/api/' ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ), 502 );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $body ) ) {
			$message = is_string( $body ) ? $body : __( 'Pixabay could not complete the search. Check the API key and try again.', 'certificate-manager' );
			wp_send_json_error( array( 'message' => sanitize_text_field( $message ) ), 502 );
		}

		$items = array();
		foreach ( $body['hits'] ?? array() as $hit ) {
			$download_url = esc_url_raw( $hit['largeImageURL'] ?? $hit['webformatURL'] ?? '' );
			$preview_url = esc_url_raw( $hit['webformatURL'] ?? $hit['previewURL'] ?? '' );
			$page_url = esc_url_raw( $hit['pageURL'] ?? '' );
			if ( ! $download_url || ! $preview_url || ! $this->is_pixabay_url( $download_url ) || ! $this->is_pixabay_url( $page_url ) ) {
				continue;
			}
			$items[] = array(
				'id' => absint( $hit['id'] ?? 0 ),
				'preview_url' => $preview_url,
				'download_url' => $download_url,
				'page_url' => $page_url,
				'user' => sanitize_text_field( $hit['user'] ?? __( 'Pixabay contributor', 'certificate-manager' ) ),
				'tags' => sanitize_text_field( $hit['tags'] ?? '' ),
			);
		}

		$result = array(
			'items' => $items,
			'total' => absint( $body['totalHits'] ?? count( $items ) ),
			'page' => $page,
			'has_more' => ( $page * 24 ) < absint( $body['totalHits'] ?? 0 ),
		);
		set_transient( $cache_key, $result, DAY_IN_SECONDS );
		wp_send_json_success( $result );
	}

	public function import_pixabay_image() {
		$this->verify_request( 'cm_edit_templates' );
		$image_url = esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) );
		$page_url = esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) );
		$author = sanitize_text_field( wp_unslash( $_POST['author'] ?? '' ) );
		$pixabay_id = absint( $_POST['pixabay_id'] ?? 0 );
		if ( ! $image_url || ! $page_url || ! $this->is_pixabay_url( $image_url ) || ! $this->is_pixabay_url( $page_url ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected Pixabay image URL is invalid.', 'certificate-manager' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp_file = download_url( $image_url, 30 );
		if ( is_wp_error( $temp_file ) ) {
			wp_send_json_error( array( 'message' => $temp_file->get_error_message() ), 502 );
		}

		$path = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$extension = in_array( $extension, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ? $extension : 'jpg';
		$file = array(
			'name' => 'pixabay-' . ( $pixabay_id ?: wp_generate_uuid4() ) . '.' . $extension,
			'tmp_name' => $temp_file,
		);
		$attachment_id = media_handle_sideload( $file, 0, sprintf(
			/* translators: %s: Pixabay image author name */
			__( 'Pixabay image by %s', 'certificate-manager' ),
			$author
		) );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $temp_file );
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 500 );
		}

		update_post_meta( $attachment_id, '_cm_pixabay_id', $pixabay_id );
		update_post_meta( $attachment_id, '_cm_pixabay_source_url', $page_url );
		update_post_meta( $attachment_id, '_cm_pixabay_author', $author );
		wp_send_json_success( array(
			'id' => $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
			'source_url' => $page_url,
			'author' => $author,
		) );
	}

	public function save_template() {
		$this->verify_request( 'cm_edit_templates' );
		if ( ! current_user_can( 'cm_publish_templates' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'certificate-manager' ) ), 403 );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- JSON-decoded and sanitized element-by-element via sanitize_element().
		$elements = $this->decode_json_array( $_POST['content'] ?? '[]' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- JSON-decoded array of safe background config values.
		$background = $this->decode_json_array( $_POST['background'] ?? '{}' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- JSON-decoded array of variable keys.
		$variable_keys = $this->decode_json_array( $_POST['variables'] ?? '[]' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- JSON-decoded array of webhook IDs, cast to int below.
		$webhook_ids = $this->decode_json_array( $_POST['webhook_ids'] ?? '[]' );
		$orientation = sanitize_key( wp_unslash( $_POST['orientation'] ?? 'landscape' ) );

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Enter a template name.', 'certificate-manager' ) ), 400 );
		}

		if ( ! is_array( $elements ) || ! is_array( $background ) || ! is_array( $variable_keys ) || ! is_array( $webhook_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'The design data is invalid.', 'certificate-manager' ) ), 400 );
		}
		if ( current_user_can( 'manage_options' ) ) {
			$available_webhook_ids = array_map( 'absint', array_column( $this->webhook_repo->get_all(), 'id' ) );
			$webhook_ids = array_values( array_intersect( $available_webhook_ids, array_map( 'absint', $webhook_ids ) ) );
		} elseif ( $template_id ) {
			$existing_template = $this->template_repo->get_template( $template_id );
			$webhook_ids = $existing_template ? json_decode( $existing_template['webhook_ids'] ?? '[]', true ) : array();
		} else {
			$webhook_ids = array();
		}

		$orientation = in_array( $orientation, array( 'portrait', 'landscape' ), true ) ? $orientation : 'landscape';
		$page_width = 'landscape' === $orientation ? 297 : 210;
		$page_height = 'landscape' === $orientation ? 210 : 297;
		$elements = array_map( array( $this, 'sanitize_element' ), $elements );
		$elements = array_map( function ( $element ) use ( $page_width, $page_height ) {
			$element['width'] = min( $page_width, max( 2, $element['width'] ) );
			$element['height'] = min( $page_height, max( 1, $element['height'] ) );
			$centre_x = $element['x'] + $element['width'] / 2;
			$centre_y = $element['y'] + $element['height'] / 2;
			$radians = deg2rad( $element['rotation'] );
			$bounds_width = abs( $element['width'] * cos( $radians ) ) + abs( $element['height'] * sin( $radians ) );
			$bounds_height = abs( $element['width'] * sin( $radians ) ) + abs( $element['height'] * cos( $radians ) );
			$can_overflow = in_array( $element['type'], array( 'image', 'rectangle', 'rounded_rectangle', 'ellipse', 'circle', 'triangle', 'diamond', 'star', 'hexagon', 'ribbon', 'line' ), true );
			if ( $can_overflow ) {
				$visible_width = min( 4, $bounds_width );
				$visible_height = min( 4, $bounds_height );
				$centre_x = min( $page_width - $visible_width + $bounds_width / 2, max( $visible_width - $bounds_width / 2, $centre_x ) );
				$centre_y = min( $page_height - $visible_height + $bounds_height / 2, max( $visible_height - $bounds_height / 2, $centre_y ) );
				$element['x'] = $centre_x - $element['width'] / 2;
				$element['y'] = $centre_y - $element['height'] / 2;
				return $element;
			}
			if ( $bounds_width > $page_width || $bounds_height > $page_height ) {
				$scale = min( $page_width / $bounds_width, $page_height / $bounds_height );
				$element['width'] *= $scale;
				$element['height'] *= $scale;
				$bounds_width *= $scale;
				$bounds_height *= $scale;
			}
			$centre_x = min( $page_width - $bounds_width / 2, max( $bounds_width / 2, $centre_x ) );
			$centre_y = min( $page_height - $bounds_height / 2, max( $bounds_height / 2, $centre_y ) );
			$element['x'] = $centre_x - $element['width'] / 2;
			$element['y'] = $centre_y - $element['height'] / 2;
			return $element;
		}, $elements );
		$background = array(
			'color' => sanitize_hex_color( $background['color'] ?? '#ffffff' ) ?: '#ffffff',
			'image_id' => absint( $background['image_id'] ?? 0 ),
			'image_url' => esc_url_raw( $background['image_url'] ?? '' ),
			'opacity' => min( 1, max( 0, (float) ( $background['opacity'] ?? 1 ) ) ),
		);
		$elements_json = wp_json_encode( $elements );
		$background_json = wp_json_encode( $background );
		$data = array(
			'title' => $name,
			'description' => $description,
			'orientation' => $orientation,
			'page_width' => $page_width,
			'page_height' => $page_height,
			'elements' => $elements_json,
			'background_config' => $background_json,
			'webhook_ids' => wp_json_encode( $webhook_ids ),
		);

		if ( $template_id ) {
			$result = $this->template_repo->update_template( $template_id, $data );
		} else {
			$template_id = $this->template_repo->create_template( $data );
			$result = $template_id;
		}

		if ( ! $result || is_wp_error( $result ) ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to save template.', 'certificate-manager' );
			wp_send_json_error( array( 'message' => $message ), 500 );
		}

		$variable_keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $variable_keys ) ) ) );
		$version_id = $this->template_repo->create_template_version( $template_id, array(
			'elements' => $elements_json,
			'variables' => wp_json_encode( $variable_keys ),
			'background_config' => $background_json,
		) );
		$this->template_repo->sync_template_variables( $template_id, $variable_keys );
		if ( ! absint( $this->settings->get( 'default_template_id', 0 ) ) ) {
			$this->settings->update( 'default_template_id', $template_id );
		}

		wp_send_json_success( array(
			'id' => $template_id,
			'version_id' => $version_id,
			'name' => $name,
		) );
	}

	public function delete_template() {
		$this->verify_request( 'cm_delete_templates' );
		$template_id = absint( $_POST['template_id'] ?? 0 );

		if ( $template_id && $this->template_repo->delete_template( $template_id ) ) {
			if ( $template_id === absint( $this->settings->get( 'default_template_id', 0 ) ) ) {
				$this->settings->update( 'default_template_id', 0 );
			}
			wp_send_json_success();
		}

		wp_send_json_error( array( 'message' => __( 'Failed to delete template.', 'certificate-manager' ) ), 500 );
	}

	public function duplicate_template() {
		$this->verify_request( 'cm_edit_templates' );
		$template_id = absint( $_POST['template_id'] ?? 0 );
		$template = $this->template_repo->get_template_with_version( $template_id );

		if ( ! $template ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'certificate-manager' ) ), 404 );
		}

		/* translators: %s: original template name */
		$new_name = sprintf( __( '%s (Copy)', 'certificate-manager' ), $template['title'] );
		$data = array(
			'title' => $new_name,
			'description' => $template['description'],
			'orientation' => $template['orientation'],
			'page_width' => $template['page_width'],
			'page_height' => $template['page_height'],
			'elements' => $template['elements'] ?: '[]',
			'background_config' => $template['background_config'],
			'webhook_ids' => $template['webhook_ids'] ?? '[]',
		);
		$new_id = $this->template_repo->create_template( $data );

		if ( ! $new_id || is_wp_error( $new_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to duplicate template.', 'certificate-manager' ) ), 500 );
		}

		$variable_keys = array_column( $this->template_repo->get_template_variables( $template_id ), 'variable_key' );
		$this->template_repo->create_template_version( $new_id, array(
			'elements' => $data['elements'],
			'variables' => wp_json_encode( $variable_keys ),
			'background_config' => $data['background_config'],
		) );
		$this->template_repo->sync_template_variables( $new_id, $variable_keys );

		wp_send_json_success( array( 'id' => $new_id, 'name' => $new_name ) );
	}

	public function get_template() {
		$this->verify_request( 'cm_view_template' );
		$template = $this->template_repo->get_template_with_version( absint( $_POST['template_id'] ?? 0 ) );

		if ( ! $template ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'certificate-manager' ) ), 404 );
		}

		$template['template_variables'] = array_column( $this->template_repo->get_template_variables( (int) $template['id'] ), 'variable_key' );
		wp_send_json_success( $template );
	}

	public function set_default_template() {
		$this->verify_request( 'cm_publish_templates' );
		$template_id = absint( $_POST['template_id'] ?? 0 );
		$template = $this->template_repo->get_template( $template_id );

		if ( ! $template || empty( $template['published_version_id'] ) || ! empty( $template['is_archived'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a published template.', 'certificate-manager' ) ), 400 );
		}

		$this->settings->update( 'default_template_id', $template_id );
		wp_send_json_success( array( 'id' => $template_id ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'cm_view_template' ) ) {
			wp_die( esc_html__( 'You do not have permission to view certificate templates.', 'certificate-manager' ) );
		}

		$variables = $this->variable_repo->get_variables( array( 'system' => true ) );
		$webhooks = current_user_can( 'manage_options' ) ? $this->webhook_repo->get_all() : array();
		?>
		<div class="wrap cm-template-admin">
			<div class="cm-page-heading">
				<div>
					<h1><?php esc_html_e( 'Certificate Templates', 'certificate-manager' ); ?></h1>
					<p><?php esc_html_e( 'Build reusable certificates with text, variables, media, shapes and verification QR codes.', 'certificate-manager' ); ?></p>
				</div>
				<?php if ( current_user_can( 'cm_edit_templates' ) && current_user_can( 'cm_publish_templates' ) ) : ?><button type="button" class="button button-primary button-hero cm-add-template"><?php esc_html_e( 'Create template', 'certificate-manager' ); ?></button><?php endif; ?>
			</div>

			<div class="cm-templates-list"><?php $this->render_template_list(); ?></div>

			<section class="cm-designer" hidden aria-label="<?php esc_attr_e( 'Certificate designer', 'certificate-manager' ); ?>">
				<div class="cm-designer-topbar">
					<button type="button" class="button cm-close-designer"><?php esc_html_e( 'Back to templates', 'certificate-manager' ); ?></button>
					<input type="text" id="cm-template-name" class="cm-template-name" placeholder="<?php esc_attr_e( 'Template name', 'certificate-manager' ); ?>">
					<span class="cm-save-state" role="status"></span>
					<button type="button" class="button cm-toggle-preview"><span class="dashicons dashicons-visibility"></span><span class="cm-preview-button-label"><?php esc_html_e( 'Preview', 'certificate-manager' ); ?></span></button>
					<?php if ( current_user_can( 'cm_edit_templates' ) && current_user_can( 'cm_publish_templates' ) ) : ?><button type="button" class="button button-primary cm-save-template"><?php esc_html_e( 'Save & publish', 'certificate-manager' ); ?></button><?php endif; ?>
				</div>

				<div class="cm-designer-layout">
					<aside class="cm-designer-sidebar cm-toolbox">
						<?php if ( current_user_can( 'cm_edit_templates' ) ) : ?>
						<h2><?php esc_html_e( 'Add content', 'certificate-manager' ); ?></h2>
						<div class="cm-tool-grid">
							<button type="button" class="cm-tool" data-type="text"><span class="dashicons dashicons-editor-textcolor"></span><?php esc_html_e( 'Text', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="image"><span class="dashicons dashicons-format-image"></span><?php esc_html_e( 'Media', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="qr"><span class="dashicons dashicons-grid-view"></span><?php esc_html_e( 'QR code', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool cm-open-catalog" data-catalog="shapes"><span class="dashicons dashicons-screenoptions"></span><?php esc_html_e( 'Shapes', 'certificate-manager' ); ?></button>
						</div>
						<button type="button" class="button cm-open-catalog cm-variable-catalog-button" data-catalog="variables"><span class="dashicons dashicons-editor-code"></span><?php esc_html_e( 'Browse variables', 'certificate-manager' ); ?></button>

						<h2><?php esc_html_e( 'Page', 'certificate-manager' ); ?></h2>
						<label for="cm-template-orientation"><?php esc_html_e( 'Orientation', 'certificate-manager' ); ?></label>
						<select id="cm-template-orientation"><option value="landscape"><?php esc_html_e( 'Landscape', 'certificate-manager' ); ?></option><option value="portrait"><?php esc_html_e( 'Portrait', 'certificate-manager' ); ?></option></select>
						<label for="cm-page-color"><?php esc_html_e( 'Background colour', 'certificate-manager' ); ?></label>
						<input type="color" id="cm-page-color" value="#ffffff">
						<button type="button" class="button cm-background-media"><?php esc_html_e( 'Choose background image', 'certificate-manager' ); ?></button>
						<button type="button" class="button cm-open-assets" data-target="background"><?php esc_html_e( 'Search Pixabay backgrounds', 'certificate-manager' ); ?></button>
						<label for="cm-background-opacity" class="cm-background-opacity-label"><?php esc_html_e( 'Background image opacity', 'certificate-manager' ); ?> <output id="cm-background-opacity-value">100%</output></label>
						<input type="range" id="cm-background-opacity" min="0" max="100" step="1" value="100">
						<button type="button" class="button-link-delete cm-clear-background" hidden><?php esc_html_e( 'Remove background', 'certificate-manager' ); ?></button>
						<h2><?php esc_html_e( 'Badges & seals', 'certificate-manager' ); ?></h2>
						<button type="button" class="button cm-open-assets" data-target="badge"><?php esc_html_e( 'Search Pixabay badges', 'certificate-manager' ); ?></button>
						<?php endif; ?>
					</aside>

					<main class="cm-designer-stage-wrap">
						<div class="cm-designer-help"><span class="cm-editing-help"><?php esc_html_e( 'Drag items to move them. Nearby edges and centres snap together using alignment guides. Shapes and media can extend beyond the certificate edge.', 'certificate-manager' ); ?></span><span class="cm-preview-help" hidden><?php esc_html_e( 'Sample preview — template variables are filled with example data and nothing will be saved.', 'certificate-manager' ); ?></span></div>
						<div class="cm-canvas" id="cm-certificate-canvas" tabindex="0"></div>
					</main>

					<aside class="cm-designer-sidebar cm-inspector">
						<h2><?php esc_html_e( 'Properties', 'certificate-manager' ); ?></h2>
						<div class="cm-empty-inspector"><?php esc_html_e( 'Select an item on the certificate to edit it.', 'certificate-manager' ); ?></div>
						<div class="cm-element-fields" hidden>
							<label class="cm-field-text"><?php esc_html_e( 'Text', 'certificate-manager' ); ?><textarea data-property="text" rows="2"></textarea></label>
							<label class="cm-field-qr"><?php esc_html_e( 'QR content', 'certificate-manager' ); ?><input type="text" data-property="value"></label>
							<label class="cm-field-qr"><?php esc_html_e( 'QR presentation', 'certificate-manager' ); ?><select data-property="qr_presentation"><option value="plain"><?php esc_html_e( 'Normal QR code', 'certificate-manager' ); ?></option><option value="badge"><?php esc_html_e( 'QR code with verification badge', 'certificate-manager' ); ?></option></select></label>
							<div class="cm-property-grid cm-field-qr-badge"><label><?php esc_html_e( 'Badge', 'certificate-manager' ); ?><select data-property="qr_badge"><?php foreach ( $this->get_qr_badges() as $badge ) : ?><option value="<?php echo esc_attr( $badge['id'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Badge side', 'certificate-manager' ); ?><select data-property="qr_badge_side"><option value="right"><?php esc_html_e( 'Right of QR code', 'certificate-manager' ); ?></option><option value="left"><?php esc_html_e( 'Left of QR code', 'certificate-manager' ); ?></option></select></label></div>
							<p class="description cm-field-qr-badge cm-qr-badge-variant"><strong><?php esc_html_e( 'Badge shown:', 'certificate-manager' ); ?></strong> <span></span></p>
							<p class="description cm-qr-size-note"><?php esc_html_e( 'For attached badges, the QR is 60% of the badge height and remains at least 24 mm wide for reliable scanning.', 'certificate-manager' ); ?></p>
							<div class="cm-property-grid cm-font-grid"><label class="cm-field-font"><?php esc_html_e( 'Font', 'certificate-manager' ); ?><select data-property="font_family"><optgroup label="<?php esc_attr_e( 'Signature', 'certificate-manager' ); ?>"><option value="greatvibes"><?php esc_html_e( 'Great Vibes', 'certificate-manager' ); ?></option><option value="allura"><?php esc_html_e( 'Allura', 'certificate-manager' ); ?></option></optgroup><optgroup label="<?php esc_attr_e( 'Serif', 'certificate-manager' ); ?>"><option value="Georgia">Georgia</option><option value="Times New Roman">Times New Roman</option><option value="dejavuserif">DejaVu Serif</option><option value="freeserif">FreeSerif</option></optgroup><optgroup label="<?php esc_attr_e( 'Sans serif', 'certificate-manager' ); ?>"><option value="Arial">Arial</option><option value="Helvetica">Helvetica</option><option value="Verdana">Verdana</option><option value="dejavusans">DejaVu Sans</option><option value="freesans">FreeSans</option></optgroup></select></label><label class="cm-field-font"><?php esc_html_e( 'Size', 'certificate-manager' ); ?><input type="number" min="6" max="96" data-property="font_size"></label><label class="cm-field-font"><?php esc_html_e( 'Weight', 'certificate-manager' ); ?><select data-property="font_weight"><option value="400"><?php esc_html_e( 'Normal', 'certificate-manager' ); ?></option><option value="600"><?php esc_html_e( 'Semi-bold', 'certificate-manager' ); ?></option><option value="700"><?php esc_html_e( 'Bold', 'certificate-manager' ); ?></option></select></label></div>
							<div class="cm-property-grid"><label><?php esc_html_e( 'Colour', 'certificate-manager' ); ?><input type="color" data-property="color"></label><label class="cm-field-font"><?php esc_html_e( 'Text alignment', 'certificate-manager' ); ?><select data-property="text_align"><option value="left"><?php esc_html_e( 'Left', 'certificate-manager' ); ?></option><option value="center"><?php esc_html_e( 'Centre', 'certificate-manager' ); ?></option><option value="right"><?php esc_html_e( 'Right', 'certificate-manager' ); ?></option></select></label></div>
							<label class="cm-field-fill"><?php esc_html_e( 'Fill', 'certificate-manager' ); ?><input type="color" data-property="background_color"></label>
							<div class="cm-field-rotation"><label for="cm-element-rotation"><?php esc_html_e( 'Rotation', 'certificate-manager' ); ?> <output id="cm-rotation-value">0&deg;</output></label><input type="range" id="cm-element-rotation" min="-180" max="180" step="1" value="0" data-property="rotation"><div class="cm-rotation-presets"><button type="button" class="button cm-rotation-preset" data-rotation="-90">-90&deg;</button><button type="button" class="button cm-rotation-preset" data-rotation="0">0&deg;</button><button type="button" class="button cm-rotation-preset" data-rotation="90">90&deg;</button></div></div>
							<div class="cm-alignment-controls">
								<span><?php esc_html_e( 'Align to certificate', 'certificate-manager' ); ?></span>
								<div>
									<button type="button" class="button cm-align-element" data-align="horizontal" title="<?php esc_attr_e( 'Centre horizontally', 'certificate-manager' ); ?>"><span class="dashicons dashicons-align-center"></span><span><?php esc_html_e( 'Horizontal', 'certificate-manager' ); ?></span></button>
									<button type="button" class="button cm-align-element" data-align="vertical" title="<?php esc_attr_e( 'Centre vertically', 'certificate-manager' ); ?>"><span class="dashicons dashicons-align-center cm-rotate-icon"></span><span><?php esc_html_e( 'Vertical', 'certificate-manager' ); ?></span></button>
									<button type="button" class="button cm-align-element" data-align="both" title="<?php esc_attr_e( 'Centre on page', 'certificate-manager' ); ?>"><span class="dashicons dashicons-editor-expand"></span><span><?php esc_html_e( 'Both', 'certificate-manager' ); ?></span></button>
								</div>
							</div>
							<label class="cm-square-control cm-field-image"><input type="checkbox" data-property="square"> <?php esc_html_e( 'Keep image frame square', 'certificate-manager' ); ?></label>
							<div class="cm-media-opacity cm-field-image"><label for="cm-element-opacity"><?php esc_html_e( 'Media opacity', 'certificate-manager' ); ?> <output id="cm-element-opacity-value">100%</output></label><input type="range" id="cm-element-opacity" min="0" max="100" step="1" value="100" data-property="opacity"></div>
							<label class="cm-lock-control"><input type="checkbox" data-property="locked"> <?php esc_html_e( 'Lock position and size', 'certificate-manager' ); ?></label>
							<details class="cm-inspector-section"><summary><?php esc_html_e( 'Size & position', 'certificate-manager' ); ?></summary><div class="cm-position-grid"><label>X (mm)<input type="number" step="0.1" data-property="x"></label><label>Y (mm)<input type="number" step="0.1" data-property="y"></label><label>W (mm)<input type="number" step="0.1" data-property="width"></label><label>H (mm)<input type="number" step="0.1" data-property="height"></label></div></details>
							<button type="button" class="button cm-change-media cm-field-image"><?php esc_html_e( 'Change media', 'certificate-manager' ); ?></button>
							<button type="button" class="button-link-delete cm-delete-element"><?php esc_html_e( 'Delete selected item', 'certificate-manager' ); ?></button>
						</div>
						<details class="cm-layers-section" open><summary><?php esc_html_e( 'Layers', 'certificate-manager' ); ?></summary><p class="description"><?php esc_html_e( 'Select an item to edit it. The top item is shown above the others.', 'certificate-manager' ); ?></p><div class="cm-layers"></div></details>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<details class="cm-template-webhooks" open><summary><?php esc_html_e( 'Issue webhooks', 'certificate-manager' ); ?></summary>
							<p class="description"><?php esc_html_e( 'Choose the endpoints that receive an event when this template issues a certificate. Leave all unchecked to send nothing.', 'certificate-manager' ); ?></p>
							<?php if ( $webhooks ) : ?><div class="cm-template-webhook-list"><?php foreach ( $webhooks as $webhook ) : ?><label class="cm-template-webhook-option"><input type="checkbox" class="cm-template-webhook" value="<?php echo esc_attr( absint( $webhook['id'] ) ); ?>"><span><strong><?php echo esc_html( $webhook['name'] ); ?></strong><small><?php echo esc_html( $webhook['url'] ); ?><?php if ( empty( $webhook['is_active'] ) ) { esc_html_e( ' — disabled', 'certificate-manager' ); } ?></small></span></label><?php endforeach; ?></div><?php else : ?><p class="description"><?php esc_html_e( 'No webhooks have been configured yet.', 'certificate-manager' ); ?></p><?php endif; ?>
						</details>
						<?php endif; ?>
					</aside>
				</div>
			</section>
			<div class="cm-asset-modal" hidden>
				<div class="cm-asset-dialog" role="dialog" aria-modal="true" aria-labelledby="cm-asset-title">
					<div class="cm-asset-heading"><div><h2 id="cm-asset-title"><?php esc_html_e( 'Certificate image library', 'certificate-manager' ); ?></h2><p class="cm-asset-subtitle"></p></div><button type="button" class="button-link cm-close-assets" aria-label="<?php esc_attr_e( 'Close image library', 'certificate-manager' ); ?>"><span class="dashicons dashicons-no-alt"></span></button></div>
					<form class="cm-pixabay-search" hidden><label for="cm-pixabay-query" class="screen-reader-text"><?php esc_html_e( 'Search Pixabay', 'certificate-manager' ); ?></label><input type="search" id="cm-pixabay-query" placeholder="<?php esc_attr_e( 'e.g. elegant certificate border', 'certificate-manager' ); ?>"><button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'certificate-manager' ); ?></button></form>
					<p class="cm-asset-notice"></p>
					<div class="cm-asset-results"></div>
					<div class="cm-asset-footer"><button type="button" class="button cm-pixabay-more" hidden><?php esc_html_e( 'Load more', 'certificate-manager' ); ?></button><span class="cm-asset-status" role="status"></span></div>
				</div>
			</div>
			<div class="cm-catalog-modal" hidden>
				<div class="cm-catalog-dialog" role="dialog" aria-modal="true" aria-labelledby="cm-catalog-title">
					<div class="cm-asset-heading"><div><h2 id="cm-catalog-title"></h2><p><?php esc_html_e( 'Choose an item to add it to the certificate.', 'certificate-manager' ); ?></p></div><button type="button" class="button-link cm-close-catalog" aria-label="<?php esc_attr_e( 'Close catalogue', 'certificate-manager' ); ?>"><span class="dashicons dashicons-no-alt"></span></button></div>
					<div class="cm-catalog-section cm-shapes-catalog" hidden>
						<div class="cm-catalog-grid">
							<button type="button" class="cm-tool" data-type="rectangle"><span class="cm-shape-icon is-rectangle"></span><?php esc_html_e( 'Rectangle', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="rounded_rectangle"><span class="cm-shape-icon is-rounded"></span><?php esc_html_e( 'Rounded box', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="ellipse"><span class="cm-shape-icon is-ellipse"></span><?php esc_html_e( 'Circle / oval', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="triangle"><span class="cm-shape-icon is-triangle"></span><?php esc_html_e( 'Triangle', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="diamond"><span class="cm-shape-icon is-diamond"></span><?php esc_html_e( 'Diamond', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="star"><span class="cm-shape-icon is-star">&#9733;</span><?php esc_html_e( 'Star', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="hexagon"><span class="cm-shape-icon is-hexagon"></span><?php esc_html_e( 'Hexagon', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="ribbon"><span class="cm-shape-icon is-ribbon"></span><?php esc_html_e( 'Ribbon', 'certificate-manager' ); ?></button>
							<button type="button" class="cm-tool" data-type="line"><span class="dashicons dashicons-minus"></span><?php esc_html_e( 'Line', 'certificate-manager' ); ?></button>
						</div>
					</div>
					<div class="cm-catalog-section cm-variables-catalog" hidden>
						<label for="cm-variable-search" class="screen-reader-text"><?php esc_html_e( 'Search variables', 'certificate-manager' ); ?></label>
						<input type="search" id="cm-variable-search" placeholder="<?php esc_attr_e( 'Search variables...', 'certificate-manager' ); ?>">
						<div class="cm-variable-palette">
							<?php foreach ( $variables as $variable ) : ?>
								<button type="button" class="cm-variable-chip" data-key="<?php echo esc_attr( $variable['variable_key'] ); ?>" data-type="<?php echo esc_attr( $variable['field_type'] ?? 'text' ); ?>"><?php echo esc_html( $variable['label'] . ( 'image' === ( $variable['field_type'] ?? '' ) ? ' (' . __( 'image', 'certificate-manager' ) . ')' : '' ) ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_template_list() {
		$result = $this->template_repo->get_templates( array( 'per_page' => 100 ) );
		$templates = $result['data'];
		$default_template_id = absint( $this->settings->get( 'default_template_id', 0 ) );

		if ( empty( $templates ) ) {
			?>
			<div class="cm-empty-state"><span class="dashicons dashicons-awards"></span><h2><?php esc_html_e( 'Create your first certificate', 'certificate-manager' ); ?></h2><p><?php esc_html_e( 'Start with a blank canvas and add text, variables, images and a QR code.', 'certificate-manager' ); ?></p></div>
			<?php
			return;
		}
		?>
		<div class="cm-template-grid">
			<?php foreach ( $templates as $template ) : ?>
				<?php $is_default = $default_template_id === (int) $template['id']; ?>
				<article class="cm-template-item<?php echo $is_default ? ' is-default' : ''; ?>" data-template-id="<?php echo esc_attr( $template['id'] ); ?>">
					<div class="cm-template-preview"><span class="dashicons dashicons-awards"></span></div>
					<div class="cm-template-card-body">
						<h2><?php echo esc_html( $template['title'] ); ?></h2>
						<p><?php echo esc_html( $template['description'] ?: __( 'Reusable certificate design', 'certificate-manager' ) ); ?></p>
						<span class="cm-template-status <?php echo $template['version_id'] ? 'is-published' : 'is-draft'; ?>"><?php echo esc_html( $template['version_id'] ? __( 'Published', 'certificate-manager' ) : __( 'Draft', 'certificate-manager' ) ); ?></span>
						<?php if ( $is_default ) : ?><span class="cm-template-status is-default"><?php esc_html_e( 'Global default', 'certificate-manager' ); ?></span><?php endif; ?>
						<div class="cm-template-actions"><?php if ( current_user_can( 'cm_edit_templates' ) ) : ?><button type="button" class="button button-primary cm-edit-template"><?php esc_html_e( 'Open designer', 'certificate-manager' ); ?></button><?php endif; ?><button type="button" class="button cm-preview-template"><?php esc_html_e( 'Preview', 'certificate-manager' ); ?></button><?php if ( current_user_can( 'cm_edit_templates' ) ) : ?><button type="button" class="button cm-duplicate-template"><?php esc_html_e( 'Duplicate', 'certificate-manager' ); ?></button><?php endif; ?><?php if ( $template['version_id'] && ! $is_default && current_user_can( 'cm_publish_templates' ) ) : ?><button type="button" class="button cm-set-default-template"><?php esc_html_e( 'Make default', 'certificate-manager' ); ?></button><?php endif; ?><?php if ( current_user_can( 'cm_delete_templates' ) ) : ?><button type="button" class="button-link-delete cm-delete-template"><?php esc_html_e( 'Delete', 'certificate-manager' ); ?></button><?php endif; ?></div>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function verify_request( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'certificate-manager' ) ), 403 );
		}

		if ( ! check_ajax_referer( 'cm-admin-nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'certificate-manager' ) ), 400 );
		}
	}

	private function is_pixabay_url( $url ) {
		if ( ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'pixabay.com' === $host || '.pixabay.com' === substr( $host, -12 );
	}

	private function decode_json_array( $value ) {
		$decoded = json_decode( wp_unslash( $value ), true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}

	private function sanitize_element( $element ) {
		if ( ! is_array( $element ) ) {
			return array();
		}

		$type = sanitize_key( $element['type'] ?? 'text' );
		$type = in_array( $type, array( 'text', 'image', 'qr', 'rectangle', 'rounded_rectangle', 'ellipse', 'circle', 'triangle', 'diamond', 'star', 'hexagon', 'ribbon', 'line' ), true ) ? $type : 'text';
		$font_weight = (string) ( $element['font_weight'] ?? '400' );
		$sanitized = array(
			'id' => sanitize_key( $element['id'] ?? wp_unique_id( 'element_' ) ),
			'type' => $type,
			'x' => (float) ( $element['x'] ?? 10 ),
			'y' => (float) ( $element['y'] ?? 10 ),
			'width' => max( 2, (float) ( $element['width'] ?? 50 ) ),
			'height' => max( 1, (float) ( $element['height'] ?? 15 ) ),
			'color' => sanitize_hex_color( $element['color'] ?? '#1d2327' ) ?: '#1d2327',
			'background_color' => sanitize_hex_color( $element['background_color'] ?? '#ffffff' ) ?: '#ffffff',
			'font_family' => sanitize_text_field( $element['font_family'] ?? 'Arial' ),
			'font_size' => min( 96, max( 6, (float) ( $element['font_size'] ?? 18 ) ) ),
			'font_weight' => in_array( $font_weight, array( '400', '600', '700' ), true ) ? $font_weight : '400',
			'text_align' => in_array( $element['text_align'] ?? 'left', array( 'left', 'center', 'right' ), true ) ? $element['text_align'] : 'left',
			'text' => sanitize_textarea_field( $element['text'] ?? '' ),
			'value' => sanitize_text_field( $element['value'] ?? '{{verification_url}}' ),
			'image_id' => absint( $element['image_id'] ?? 0 ),
			'image_url' => esc_url_raw( $element['image_url'] ?? '' ),
			'image_variable' => sanitize_key( $element['image_variable'] ?? '' ),
			'square' => ! empty( $element['square'] ),
			'locked' => ! empty( $element['locked'] ),
			'opacity' => min( 1, max( 0, (float) ( $element['opacity'] ?? 1 ) ) ),
			'rotation' => min( 180, max( -180, (float) ( $element['rotation'] ?? 0 ) ) ),
		);
		if ( 'qr' === $type ) {
			$badge_definitions = $this->get_qr_badges();
			$sanitized['qr_presentation'] = 'badge' === ( $element['qr_presentation'] ?? 'plain' ) ? 'badge' : 'plain';
			$sanitized['qr_badge'] = sanitize_key( $element['qr_badge'] ?? 'heritage-green' );
			if ( ! isset( $badge_definitions[ $sanitized['qr_badge'] ] ) ) {
				$sanitized['qr_badge'] = 'heritage-green';
			}
			$sanitized['qr_badge_side'] = 'left' === ( $element['qr_badge_side'] ?? 'right' ) ? 'left' : 'right';
			if ( 'badge' === $sanitized['qr_presentation'] ) {
				$badge_height = max( 40, $sanitized['height'] );
				$qr_size = max( 24, $badge_height * 0.6 );
				$gap = max( 1.2, $qr_size * 0.04 );
				$badge_ratio = (float) $badge_definitions[ $sanitized['qr_badge'] ]['ratio'];
				$sanitized['width'] = $qr_size + $gap + ( $badge_height * $badge_ratio );
				$sanitized['height'] = $badge_height;
			} else {
				$size = max( 24, min( $sanitized['width'], $sanitized['height'] ) );
				$sanitized['x'] += ( $sanitized['width'] - $size ) / 2;
				$sanitized['y'] += ( $sanitized['height'] - $size ) / 2;
				$sanitized['width'] = $size;
				$sanitized['height'] = $size;
			}
		}
		return $sanitized;
	}
}
