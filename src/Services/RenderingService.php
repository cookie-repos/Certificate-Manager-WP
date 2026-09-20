<?php
/**
 * Certificate Manager Rendering Service
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PDF rendering service
 */
class RenderingService {
	
	/**
	 * Issuance service
	 *
	 * @var IssuanceService
	 */
	private $issuance_service;
	
	/**
	 * Constructor
	 *
	 * @param IssuanceService $issuance_service Issuance service
	 */
	public function __construct( IssuanceService $issuance_service ) {
		$this->issuance_service = $issuance_service;
	}
	
	/**
	 * Render certificate to PDF
	 *
	 * @param int $certificate_id Certificate ID
	 * @param int|null $template_version_id Template version ID (optional, uses certificate's version)
	 * @return string|WP_Error PDF content or error
	 */
	public function render_pdf( int $certificate_id, ?int $template_version_id = null, bool $include_status_watermark = true ) {
		global $wpdb;
		
		// Get certificate
		$certificate = $this->issuance_service->get_certificate_repository()->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		// Get template version
		$version_id = $template_version_id ?: $certificate['template_version_id'];
		$version = $this->get_template_version( $version_id );
		if ( ! $version ) {
			return new \WP_Error( 'template_version_not_found', __( 'Template version not found', 'certificate-manager' ) );
		}
		
		// Get variables
		$variables = $this->get_certificate_variables( $certificate_id );
		$variables = array_merge( $variables, $this->get_system_variables( $certificate, $version ) );
		
		// Render PDF
		$watermark = $include_status_watermark ? $this->get_status_watermark( $certificate['status'] ?? 'active' ) : '';
		$pdf = $this->create_pdf( $version, $variables, $watermark );
		
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		
		return $pdf;
	}
	
	/**
	 * Get template version
	 *
	 * @param int $version_id Template version ID
	 * @return array|false
	 */
	private function get_template_version( int $version_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_versions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are WP-prefixed.
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT v.*, t.title AS template_title, t.orientation, t.page_width, t.page_height
			 FROM {$table_name} v
			 JOIN {$wpdb->prefix}certificate_manager_templates t ON v.template_id = t.id
			 WHERE v.id = %d",
			$version_id
		), ARRAY_A );
	}

	private function get_system_variables( array $certificate, array $version ): array {
		$settings = new \CertificateManager\Core\Settings();
		$verification_base = $settings->get_verification_base_url();
		$short_token = substr( (string) $certificate['verification_token'], 0, 16 );
		$verification_url = $short_token
			? add_query_arg( 'v', $short_token, $verification_base )
			: add_query_arg( 'certificate_number', $certificate['certificate_number'], $verification_base );
		$values = array(
			'recipient_name' => $certificate['recipient_name'],
			'recipient_email' => $certificate['recipient_email'],
			'certificate_number' => $certificate['certificate_number'],
			'issue_date' => mysql2date( get_option( 'date_format' ), $certificate['issue_date'] ),
			'expiry_date' => $certificate['expiry_date'] ? mysql2date( get_option( 'date_format' ), $certificate['expiry_date'] ) : '',
			'verification_url' => $verification_url,
			'template_name' => $version['template_title'],
			'site_name' => get_bloginfo( 'name' ),
			'site_url' => home_url( '/' ),
			'current_year' => current_time( 'Y' ),
		);
		$variables = array();

		foreach ( $values as $key => $value ) {
			$variables[] = array( 'variable_key' => $key, 'variable_label' => $key, 'value' => $value );
		}

		return $variables;
	}
	
	/**
	 * Get certificate variables
	 *
	 * @param int $certificate_id Certificate ID
	 * @return array
	 */
	private function get_certificate_variables( int $certificate_id ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_certificate_fields';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT variable_key, variable_label, value FROM {$table_name} WHERE certificate_id = %d",
			$certificate_id
		), ARRAY_A );
		
		return $results;
	}
	
	/**
	 * Create PDF using mPDF
	 *
	 * @param array $version Template version
	 * @param array $variables Certificate variables
	 * @return string|WP_Error PDF content or error
	 */
	private function create_pdf( array $version, array $variables, string $watermark = '' ) {
		// Load mPDF if available
		if ( ! class_exists( '\Mpdf\Mpdf' ) && ! class_exists( 'Mpdf\Mpdf' ) ) {
			return new \WP_Error( 'mpdf_not_found', __( 'mPDF library not found. Please install it.', 'certificate-manager' ) );
		}
		
		// Render each positioned element independently. mPDF applies its PCRE
		// parser to every WriteHTML() call, so a single document containing many
		// images must not be handed to it as one oversized string.
		$html_parts = $this->build_html_parts( $version, $variables, $watermark );
		
		// Configure mPDF
		$upload_dir = wp_upload_dir();
		$temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'certificate-manager/mpdf-temp';
		wp_mkdir_p( $temp_dir );
		$config = array(
			'mode' => 'utf-8',
			'format' => 'A4',
			'orientation' => 'landscape' === ( $version['orientation'] ?? 'portrait' ) ? 'L' : 'P',
			'tempDir' => $temp_dir,
			'exposeVersion' => false,
			'autoScriptToLang' => true,
			'autoLangToFont' => true,
			'margin_left' => 0,
			'margin_right' => 0,
			'margin_top' => 0,
			'margin_bottom' => 0,
			'margin_header' => 0,
			'margin_footer' => 0,
		);
		$config_variables = new \Mpdf\Config\ConfigVariables();
		$font_variables = new \Mpdf\Config\FontVariables();
		$font_data = $font_variables->getDefaults()['fontdata'];
		$font_data['greatvibes'] = array( 'R' => 'GreatVibes-Regular.ttf', 'B' => 'GreatVibes-Regular.ttf', 'I' => 'GreatVibes-Regular.ttf', 'BI' => 'GreatVibes-Regular.ttf' );
		$font_data['allura'] = array( 'R' => 'Allura-Regular.ttf', 'B' => 'Allura-Regular.ttf', 'I' => 'Allura-Regular.ttf', 'BI' => 'Allura-Regular.ttf' );
		$config['fontDir'] = array_merge( $config_variables->getDefaults()['fontDir'], array( CERTIFICATE_MANAGER_PATH . 'src/Assets/fonts' ) );
		$config['fontdata'] = $font_data;
		
		try {
			$mpdf = new \Mpdf\Mpdf( $config );
			$mpdf->WriteHTML( $html_parts['css'], \Mpdf\HTMLParserMode::HEADER_CSS );
			foreach ( $html_parts['body'] as $html_part ) {
				$mpdf->WriteHTML( $html_part );
			}
			$pdf = $mpdf->Output( '', 'S' );
			
			return $pdf;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'pdf_generation_error', $e->getMessage() );
		}
	}
	
	/**
	 * Build HTML from template
	 *
	 * @param array $version Template version
	 * @param array $variables Certificate variables
	 * @return array{css:string,body:array}
	 */
	private function build_html_parts( array $version, array $variables, string $watermark = '' ): array {
		$elements = json_decode( $version['elements'], true );
		if ( ! $elements ) {
			$elements = array();
		}
		
		$variables_map = $this->build_variable_map( $variables );
		
		$body = array();
		
		// Add elements
		foreach ( $elements as $element ) {
			$body[] = $this->render_element( $element, $variables_map );
		}
		if ( '' !== $watermark ) {
			foreach ( array( 8, 33, 58, 83 ) as $position ) {
				$body[] = '<div class="cm-status-watermark" style="top:' . esc_attr( $position ) . '%">' . esc_html( $watermark ) . '</div>';
			}
		}

		return array( 'css' => $this->get_css( $version ), 'body' => array_filter( $body ) );
	}
	
	/**
	 * Build variables map
	 *
	 * @param array $variables Certificate variables
	 * @return array
	 */
	private function build_variable_map( array $variables ): array {
		$map = array();
		foreach ( $variables as $var ) {
			$map[ $var['variable_key'] ] = $var['value'];
		}
		return $map;
	}
	
	/**
	 * Get CSS styles
	 *
	 * @param array $version Template version
	 * @return string
	 */
	private function get_css( array $version ): string {
		$background = json_decode( $version['background_config'] ?? '{}', true );
		$color = sanitize_hex_color( $background['color'] ?? '#ffffff' ) ?: '#ffffff';
		$image = wp_get_attachment_url( absint( $background['image_id'] ?? 0 ) );
		$opacity = min( 1, max( 0, (float) ( $background['opacity'] ?? 1 ) ) );
		$style = 'html, body { margin: 0; padding: 0; width: 100%; height: 100%; } body { position: relative; background-color: ' . $color . ';';
		if ( $image ) {
			$style .= ' background-image: url("' . $image . '"); background-image-opacity: ' . $opacity . '; background-position: center; background-repeat: no-repeat; background-size: cover;';
		}
		return $style . ' }.cm-status-watermark { color:#b42318; font-family:Arial,sans-serif; font-size:38pt; font-weight:bold; left:-18%; letter-spacing:4pt; opacity:.26; position:fixed; text-align:center; text-transform:uppercase; transform:rotate(-28deg); width:136%; z-index:9999; }';
	}

	private function get_status_watermark( string $status ): string {
		$labels = array(
			'revoked' => __( 'Revoked', 'certificate-manager' ),
			'replaced' => __( 'Replaced', 'certificate-manager' ),
			'expired' => __( 'Expired', 'certificate-manager' ),
			'trash' => __( 'Void', 'certificate-manager' ),
		);
		return $labels[ $status ] ?? '';
	}
	
	/**
	 * Render a single element
	 *
	 * @param array $element Element data
	 * @param array $variables Variables map
	 * @return string
	 */
	private function render_element( array $element, array $variables ): string {
		switch ( $element['type'] ?? 'text' ) {
			case 'text':
				return $this->render_text_element( $element, $variables );
			case 'image':
				return $this->render_image_element( $element, $variables );
			case 'rectangle':
				return $this->render_rectangle_element( $element );
			case 'rounded_rectangle':
			case 'ellipse':
			case 'circle':
			case 'triangle':
			case 'diamond':
			case 'star':
			case 'hexagon':
			case 'ribbon':
				return $this->render_svg_shape_element( $element );
			case 'line':
				return $this->render_line_element( $element );
			case 'qr':
				return $this->render_qr_element( $element, $variables );
			case 'group':
				return $this->render_group_element( $element, $variables );
			default:
				return '';
		}
	}
	
	/**
	 * Render text element
	 *
	 * @param array $element Element data
	 * @param array $variables Variables map
	 * @return string
	 */
	private function render_text_element( array $element, array $variables ): string {
		$text = $element['text'] ?? '';
		$is_learning_outcomes = false;
		
		// Replace variables
		foreach ( $variables as $key => $value ) {
			$placeholder = '{{' . $key . '}}';
			if ( 'learning_outcomes' === $key && false !== strpos( $text, $placeholder ) ) {
				$value = $this->format_learning_outcomes( $value );
				$is_learning_outcomes = true;
			}
			$text = str_replace( $placeholder, $value, $text );
		}
		
		// Escape HTML
		$text = nl2br( esc_html( $text ) );
		if ( (int) ( $element['font_weight'] ?? 400 ) >= 600 ) {
			$text = '<strong>' . $text . '</strong>';
		}
		
		$style_element = $element;
		unset( $style_element['height'] );
		$style = $this->get_element_style( $style_element ) . 'line-height: 1.2; overflow: visible;';
		if ( $is_learning_outcomes ) {
			$style .= 'text-align: left;';
		}
		
		return '<div style="' . $style . '">' . $text . '</div>';
	}

	private function format_learning_outcomes( $value ): string {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$outcomes = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			$line = preg_replace( '/^(?:[\x{2022}\-*]\s*)+/u', '', $line );
			if ( '' !== $line ) {
				$outcomes[] = "\xE2\x80\xA2 " . $line;
			}
		}

		return implode( "\n", $outcomes );
	}
	
	/**
	 * Render image element
	 *
	 * @param array $element Element data
	 * @param array $variables Variables map
	 * @return string
	 */
	private function render_image_element( array $element, array $variables ): string {
		$image_variable = sanitize_key( $element['image_variable'] ?? '' );
		$image_id = $image_variable && isset( $variables[ $image_variable ] )
			? absint( $variables[ $image_variable ] )
			: absint( $element['image_id'] ?? 0 );
		$image_url = wp_get_attachment_url( $image_id );
		
		if ( ! $image_url ) {
			return '';
		}

		$metadata = wp_get_attachment_metadata( $image_id );
		$intrinsic_size = is_array( $metadata ) && ! empty( $metadata['width'] ) && ! empty( $metadata['height'] )
			? array( (float) $metadata['width'], (float) $metadata['height'] )
			: null;

		return $this->render_positioned_image( $element, $image_url, $intrinsic_size );
	}

	/**
	 * Render rectangle element
	 *
	 * @param array $element Element data
	 * @return string
	 */
	private function render_rectangle_element( array $element ): string {
		$fill = sanitize_hex_color( $element['background_color'] ?? '#ffffff' ) ?: '#ffffff';
		return $this->render_svg_image( $element, '<rect width="100" height="100" fill="' . esc_attr( $fill ) . '" />' );
	}
	
	/**
	 * Render circle element
	 *
	 * @param array $element Element data
	 * @return string
	 */
	private function render_circle_element( array $element ): string {
		$width = $element['width'] ?? 50;
		$height = $element['height'] ?? 50;
		$bg_color = $element['background_color'] ?? '#ffffff';
		
		$style = $this->get_element_style( $element ) . 'background-color: ' . sanitize_hex_color( $bg_color ) . '; border-radius: 50%;';
		
		return '<div style="' . $style . '"></div>';
	}

	private function render_svg_shape_element( array $element ): string {
		$shapes = array(
			'rounded_rectangle' => '<rect x="1" y="1" width="98" height="98" rx="12" ry="12" />',
			'ellipse' => '<ellipse cx="50" cy="50" rx="49" ry="49" />',
			'circle' => '<ellipse cx="50" cy="50" rx="49" ry="49" />',
			'triangle' => '<polygon points="50,1 99,99 1,99" />',
			'diamond' => '<polygon points="50,1 99,50 50,99 1,50" />',
			'star' => '<polygon points="50,1 61,36 98,36 68,57 79,94 50,72 21,94 32,57 2,36 39,36" />',
			'hexagon' => '<polygon points="25,1 75,1 99,50 75,99 25,99 1,50" />',
			'ribbon' => '<polygon points="1,18 20,18 20,5 80,5 80,18 99,18 88,50 99,82 80,82 80,95 20,95 20,82 1,82 12,50" />',
		);
		$type = $element['type'] ?? 'ellipse';
		$shape = $shapes[ $type ] ?? $shapes['ellipse'];
		$fill = sanitize_hex_color( $element['background_color'] ?? '#e8eef7' ) ?: '#e8eef7';

		return $this->render_svg_image( $element, '<g fill="' . esc_attr( $fill ) . '">' . $shape . '</g>' );
	}
	
	/**
	 * Render line element
	 *
	 * @param array $element Element data
	 * @return string
	 */
	private function render_line_element( array $element ): string {
		$fill = sanitize_hex_color( $element['color'] ?? '#000000' ) ?: '#000000';
		return $this->render_svg_image( $element, '<rect width="100" height="100" fill="' . esc_attr( $fill ) . '" />' );
	}

	private function render_svg_image( array $element, string $content ): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none">' . $content . '</svg>';
		$source = 'data:image/svg+xml;base64,' . base64_encode( $svg );
		return $this->render_positioned_image( $element, $source );
	}

	private function render_positioned_image( array $element, string $source, ?array $intrinsic_size = null ): string {
		$box_width = max( 0.1, (float) ( $element['width'] ?? 1 ) );
		$box_height = max( 0.1, (float) ( $element['height'] ?? 1 ) );
		$image_width = $box_width;
		$image_height = $box_height;
		$offset_x = 0.0;
		$offset_y = 0.0;

		if ( $intrinsic_size && $intrinsic_size[0] > 0 && $intrinsic_size[1] > 0 ) {
			$image_ratio = $intrinsic_size[0] / $intrinsic_size[1];
			$box_ratio = $box_width / $box_height;
			if ( $image_ratio > $box_ratio ) {
				$image_height = $box_width / $image_ratio;
				$offset_y = ( $box_height - $image_height ) / 2;
			} else {
				$image_width = $box_height * $image_ratio;
				$offset_x = ( $box_width - $image_width ) / 2;
			}
		}

		$wrapper_element = $element;
		$wrapper_element['rotation'] = 0;
		unset( $wrapper_element['opacity'] );
		$wrapper_style = $this->get_element_style( $wrapper_element ) . 'overflow: visible;';
		$image_style = 'display: block; width: ' . $image_width . 'mm; height: ' . $image_height . 'mm; margin-left: ' . $offset_x . 'mm; margin-top: ' . $offset_y . 'mm; padding: 0; border: 0;';
		$opacity = min( 1, max( 0, (float) ( $element['opacity'] ?? 1 ) ) );
		if ( $opacity < 1 ) {
			$image_style .= ' opacity: ' . $opacity . ';';
		}
		$rotation = min( 180, max( -180, (float) ( $element['rotation'] ?? 0 ) ) );
		if ( $rotation ) {
			$image_style .= ' transform: rotate(' . $rotation . 'deg); transform-origin: center;';
		}

		$width_attribute = max( 1, (int) round( $image_width * 3.7795275591 ) );
		$height_attribute = max( 1, (int) round( $image_height * 3.7795275591 ) );
		return '<div style="' . $wrapper_style . '"><img src="' . esc_attr( $source ) . '" width="' . $width_attribute . '" height="' . $height_attribute . '" style="' . $image_style . 'max-width: none; max-height: none;"></div>';
	}
	
	/**
	 * Render QR element
	 *
	 * @param array $element Element data
	 * @param array $variables Variables map
	 * @return string
	 */
	private function render_qr_element( array $element, array $variables ): string {
		$verification_url = $element['value'] ?? '{{verification_url}}';
		foreach ( $variables as $key => $value ) {
			$verification_url = str_replace( '{{' . $key . '}}', $value, $verification_url );
		}
		if ( ! $verification_url ) {
			return '';
		}
		
		$qr_element = $element;
		$width = max( 0.1, (float) ( $qr_element['width'] ?? 30 ) );
		$height = max( 0.1, (float) ( $qr_element['height'] ?? 30 ) );
		$is_badged = 'badge' === ( $element['qr_presentation'] ?? 'plain' );
		$badge_height = $is_badged ? max( 40, $height ) : 0;
		$size = $is_badged ? max( 24, $badge_height * 0.6 ) : max( 24, min( $width, $height ) );
		$badge = $is_badged ? $this->get_qr_badge( $element['qr_badge'] ?? '' ) : false;
		if ( ! $is_badged ) {
			$qr_element['x'] = (float) ( $qr_element['x'] ?? 0 ) + ( $width - $size ) / 2;
			$qr_element['y'] = (float) ( $qr_element['y'] ?? 0 ) + ( $height - $size ) / 2;
		}
		$qr_element['width'] = $size;
		$qr_element['height'] = $size;

		// Generate QR code
		$qr_service = new QRService();
		$qr_image = $qr_service->generate_qr( $verification_url, $qr_element );
		
		if ( ! $qr_image ) {
			return '';
		}
		
		if ( ! $badge ) {
			return $this->render_positioned_image( $qr_element, $qr_image );
		}

		$badge_image = $this->get_qr_badge_image( $badge['file'] );
		if ( ! $badge_image ) {
			return $this->render_positioned_image( $qr_element, $qr_image );
		}
		$gap = max( 1.2, $size * 0.04 );
		$badge_width = $badge_height * $badge['ratio'];
		$group_width = $badge_width + $gap + $size;
		$group_element = $element;
		$group_element['width'] = $group_width;
		$group_element['height'] = $badge_height;
		$group_style = $this->get_element_style( $group_element ) . 'overflow: visible;';
		$qr_offset = 'left' === ( $element['qr_badge_side'] ?? 'right' ) ? $badge_width + $gap : 0;
		$badge_offset = 'left' === ( $element['qr_badge_side'] ?? 'right' ) ? 0 : $size + $gap;
		$qr_top_offset = ( $badge_height - $size ) / 2;
		$qr_width_attribute = max( 1, (int) round( $size * 3.7795275591 ) );
		$badge_width_attribute = max( 1, (int) round( $badge_width * 3.7795275591 ) );
		$height_attribute = max( 1, (int) round( $size * 3.7795275591 ) );
		$badge_height_attribute = max( 1, (int) round( $badge_height * 3.7795275591 ) );

		return '<div style="' . $group_style . '">'
			. '<img src="' . esc_attr( $qr_image ) . '" width="' . $qr_width_attribute . '" height="' . $height_attribute . '" style="position:absolute;left:' . $qr_offset . 'mm;top:' . $qr_top_offset . 'mm;width:' . $size . 'mm;height:' . $size . 'mm;max-width:none;max-height:none;">'
			. '<img src="' . esc_attr( $badge_image ) . '" width="' . $badge_width_attribute . '" height="' . $badge_height_attribute . '" style="position:absolute;left:' . $badge_offset . 'mm;top:0;width:' . $badge_width . 'mm;height:' . $badge_height . 'mm;max-width:none;max-height:none;">'
			. '</div>';
	}

	/**
	 * Whitelist the compact QR-attached badge assets.
	 */
	private function get_qr_badge( string $badge_id ) {
		$badges = array(
			'heritage-green' => array( 'file' => 'Heritage-Green-Min.png', 'ratio' => 1 ),
			'citrus-burst' => array( 'file' => 'Citrus-Burst-Min.png', 'ratio' => 1 ),
			'obsidian-gold' => array( 'file' => 'Obsidian-Gold-Min.png', 'ratio' => 1 ),
			'mint-candy' => array( 'file' => 'Mint-Candy-Min.png', 'ratio' => 1 ),
			'midnight-navy' => array( 'file' => 'Midnight-Navy-Min.png', 'ratio' => 1 ),
			'burgundy-rose' => array( 'file' => 'Burgundy-Rose-Min.png', 'ratio' => 1 ),
			'signal-blue' => array( 'file' => 'Signal-Blue-Min.png', 'ratio' => 1 ),
			'ultraviolet-pop' => array( 'file' => 'Ultraviolet-Pop-Min.png', 'ratio' => 1 ),
		);
		return $badges[ sanitize_key( $badge_id ) ] ?? $badges['heritage-green'];
	}

	private function get_qr_badge_image( string $filename ): string {
		$path = CERTIFICATE_MANAGER_PATH . 'src/QR/Badges/' . $filename;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		// Keep the large bundled badge out of the HTML string. mPDF supports
		// local file URIs and embeds the asset directly into the final PDF.
		return 'file://' . wp_normalize_path( $path );
	}
	
	/**
	 * Render group element
	 *
	 * @param array $element Element data
	 * @param array $variables Variables map
	 * @return string
	 */
	private function render_group_element( array $element, array $variables ): string {
		$elements = $element['elements'] ?? array();
		$output = '';
		
		foreach ( $elements as $child ) {
			$output .= $this->render_element( $child, $variables );
		}
		
		return $output;
	}
	
	/**
	 * Get element styles
	 *
	 * @param array $element Element data
	 * @param bool $include_position Include position styles
	 * @return string
	 */
	private function get_element_style( array $element, bool $include_position = true ): string {
		$style = '';
		
		if ( $include_position ) {
			$x = $element['x'] ?? 0;
			$y = $element['y'] ?? 0;
			$style .= 'position: absolute; left: ' . $x . 'mm; top: ' . $y . 'mm; ';
		}
		
		$width = $element['width'] ?? null;
		$height = $element['height'] ?? null;
		
		if ( $width !== null ) {
			$style .= 'width: ' . $width . 'mm; ';
		}
		
		if ( $height !== null ) {
			$style .= 'height: ' . $height . 'mm; ';
		}
		
		$color = $element['color'] ?? null;
		if ( $color ) {
			$style .= 'color: ' . $color . '; ';
		}
		
		$font_family = $element['font_family'] ?? null;
		if ( $font_family ) {
			$style .= 'font-family: ' . esc_attr( $font_family ) . '; ';
		}
		
		$font_size = $element['font_size'] ?? null;
		if ( $font_size ) {
			$style .= 'font-size: ' . round( (float) $font_size * 0.51, 3 ) . 'pt; ';
		}
		
		$font_weight = (int) ( $element['font_weight'] ?? 400 );
		if ( $font_weight >= 600 ) {
			$style .= 'font-weight: bold; ';
		} elseif ( $font_weight ) {
			$style .= 'font-weight: normal; ';
		}
		
		$text_align = $element['text_align'] ?? null;
		if ( $text_align ) {
			$style .= 'text-align: ' . $text_align . '; ';
		}
		
		$opacity = $element['opacity'] ?? null;
		if ( $opacity !== null ) {
			$style .= 'opacity: ' . $opacity . '; ';
		}
		
		$rotation = min( 180, max( -180, (float) ( $element['rotation'] ?? 0 ) ) );
		if ( $rotation ) {
			$style .= 'transform: rotate(' . $rotation . 'deg); transform-origin: center; ';
		}
		
		return $style;
	}
	
	/**
	 * Save PDF to storage
	 *
	 * @param string $pdf_content PDF content
	 * @param int $certificate_id Certificate ID
	 * @return string|WP_Error File path or error
	 */
	public function save_pdf( string $pdf_content, int $certificate_id, string $status_suffix = '' ) {
		// Get certificate
		$certificate = $this->issuance_service->get_certificate_repository()->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		
		// Generate filename
		$filename = $this->generate_pdf_filename( $certificate );
		if ( '' !== $status_suffix ) {
			$filename = preg_replace( '/\.pdf$/i', '-' . sanitize_key( $status_suffix ) . '.pdf', $filename );
		}
		
		// Create uploads directory if needed
		$upload_dir = wp_upload_dir();
		$pdf_dir = $upload_dir['basedir'] . '/certificate-manager';
		
		if ( ! wp_mkdir_p( $pdf_dir ) ) {
			return new \WP_Error( 'directory_creation_failed', __( 'Failed to create uploads directory', 'certificate-manager' ) );
		}
		
		// Save PDF
		$file_path = $pdf_dir . '/' . $filename;
		$result = file_put_contents( $file_path, $pdf_content );
		
		if ( $result === false ) {
			return new \WP_Error( 'file_save_failed', __( 'Failed to save PDF file', 'certificate-manager' ) );
		}
		
		if ( '' !== $status_suffix ) {
			return $file_path;
		}

		// Store file reference in database
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		$stored = $wpdb->update(
			$table_name,
			array( 'pdf_file_path' => $file_path ),
			array( 'id' => $certificate_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $stored ) {
			return new \WP_Error( 'pdf_reference_failed', __( 'The PDF was created, but its file reference could not be saved.', 'certificate-manager' ) );
		}
		
		return $file_path;
	}
	
	/**
	 * Generate PDF filename
	 *
	 * @param array $certificate Certificate data
	 * @return string
	 */
	private function generate_pdf_filename( array $certificate ): string {
		$settings = new \CertificateManager\Core\Settings();
		$format = trim( (string) $settings->get( 'default_pdf_filename_format', 'Certificate-{{certificate_number}}-{{recipient_name}}' ) );
		if ( '' === $format ) {
			$format = 'Certificate-{{certificate_number}}-{{recipient_name}}';
		}
		
		$variables = $this->get_certificate_variables( $certificate['id'] );
		$map = array();
		foreach ( $variables as $var ) {
			$map[ $var['variable_key'] ] = $var['value'];
		}
		$map = array_merge( $map, array(
			'certificate_number' => $certificate['certificate_number'] ?? '',
			'recipient_name' => $certificate['recipient_name'] ?? '',
			'recipient_email' => $certificate['recipient_email'] ?? '',
			'issue_date' => $certificate['issue_date'] ?? '',
			'expiry_date' => $certificate['expiry_date'] ?? '',
		) );
		
		foreach ( $map as $key => $value ) {
			$format = str_replace( '{{' . $key . '}}', $value, $format );
		}
		
		$filename = sanitize_file_name( $format );
		$filename = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $filename );
		$filename = preg_replace( '/\.pdf$/i', '', $filename );
		$filename .= '.pdf';
		
		return $filename;
	}
	
	/**
	 * Download PDF
	 *
	 * @param int $certificate_id Certificate ID
	 * @param bool $force_download Force download
	 */
	public function download_pdf( int $certificate_id, bool $force_download = true ) {
		// Get certificate
		$certificate = $this->issuance_service->get_certificate_repository()->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			wp_die( esc_html__( 'Certificate not found', 'certificate-manager' ) );
		}
		
		// Get PDF file path
		$file_path = $certificate['pdf_file_path'] ?? null;
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'PDF not found', 'certificate-manager' ) );
		}
		
		$this->stream_pdf_file( $file_path, $force_download );
	}

	public function stream_pdf_file( string $file_path, bool $force_download = true ) {
		if ( ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'PDF not found', 'certificate-manager' ) );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $force_download ? 'attachment' : 'inline' ) . '; filename="' . basename( $file_path ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: private, max-age=0, must-revalidate' );
		
		// Output file
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming binary PDF; WP_Filesystem does not support streaming output.
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
	
	/**
	 * Generate preview PDF
	 *
	 * @param array $data Preview data
	 * @param int $template_id Template ID
	 * @return string|WP_Error PDF content or error
	 */
	public function generate_preview_pdf( array $data, int $template_id ) {
		// Get template version
		$template = $this->issuance_service->get_template_repository()->get_template( $template_id );
		if ( ! $template || ! $template['published_version_id'] ) {
			return new \WP_Error( 'invalid_template', __( 'Invalid template', 'certificate-manager' ) );
		}
		
		$version = $this->get_template_version( $template['published_version_id'] );
		if ( ! $version ) {
			return new \WP_Error( 'version_not_found', __( 'Template version not found', 'certificate-manager' ) );
		}
		
		// Create preview variables
		$variables = array();
		$variable_keys = array(
			'recipient_name', 'recipient_email', 'certificate_number', 'issue_date',
			'expiry_date', 'certificate_name', 'course_name', 'learning_outcomes', 'completion_date',
			'issuer_name', 'issuer_title', 'site_name', 'site_url', 'verification_url',
			'template_name', 'current_year'
		);
		
		foreach ( $variable_keys as $key ) {
			$variables[] = array(
				'variable_key' => $key,
				'variable_label' => $key,
				'value' => $data[ $key ] ?? '',
			);
		}
		
		// Create preview PDF
		$pdf = $this->create_pdf( $version, $variables );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		
		// Add preview watermark
		$pdf = $this->add_preview_watermark( $pdf );
		
		return $pdf;
	}
	
	/**
	 * Add preview watermark to PDF
	 *
	 * @param string $pdf_content PDF content
	 * @return string
	 */
	private function add_preview_watermark( string $pdf_content ): string {
		// Create a new PDF with watermark
		// This is a simplified implementation - in production, you'd use a proper PDF library
		// to add the watermark text on each page
		
		return $pdf_content;
	}
	
	/**
	 * Regenerate certificate PDF
	 *
	 * @param int $certificate_id Certificate ID
	 * @param int|null $template_version_id Template version ID
	 * @return string|WP_Error PDF file path or error
	 */
	public function regenerate_pdf( int $certificate_id, ?int $template_version_id = null, bool $current_status = true ) {
		$certificate = $this->issuance_service->get_certificate_repository()->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found', 'certificate-manager' ) );
		}
		$watermark = $current_status ? $this->get_status_watermark( $certificate['status'] ?? 'active' ) : '';
		$status_suffix = $watermark ? sanitize_key( $certificate['status'] ?? '' ) : '';
		// Render PDF
		$pdf = $this->render_pdf( $certificate_id, $template_version_id, $current_status );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		
		// Save PDF
		$file_path = $this->save_pdf( $pdf, $certificate_id, $status_suffix );
		if ( is_wp_error( $file_path ) ) {
			return $file_path;
		}
		
		// Log audit event
		$this->issuance_service->get_audit_repository()->log( 'certificate', 'PDFRegenerated', $certificate_id, array(
			'template_version_id' => $template_version_id ?: 'original',
			'variant' => $watermark ? 'current_status' : 'original',
		), array(
			'type' => 'user',
			'id' => get_current_user_id(),
		) );
		
		return $file_path;
	}
}
