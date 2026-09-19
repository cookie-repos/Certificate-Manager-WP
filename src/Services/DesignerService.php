<?php
/**
 * Designer Service
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Designer service class
 */
class DesignerService {
	
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
	 * Get template designer data
	 *
	 * @param int $template_id Template ID.
	 * @return array Designer data.
	 */
	public function get_designer_data( $template_id ) {
		$template = $this->template_repo->get_template_with_version( (int) $template_id );
		
		if ( ! $template ) {
			return array();
		}
		
		// Get default settings
		$defaults = array(
			'paper_size' => 'A4',
			'orientation' => 'portrait',
			'margins' => array(
				'top' => 20,
				'bottom' => 20,
				'left' => 20,
				'right' => 20,
			),
			'background' => array(
				'type' => 'color',
				'color' => '#ffffff',
				'image' => '',
			),
			'font' => array(
				'family' => 'Helvetica',
				'size' => 12,
				'color' => '#000000',
			),
			'elements' => array(),
		);
		
		$settings = array(
			'orientation' => $template['orientation'] ?? 'portrait',
			'page_width' => (float) ( $template['page_width'] ?? 210 ),
			'page_height' => (float) ( $template['page_height'] ?? 297 ),
			'elements' => json_decode( $template['elements'] ?? '[]', true ) ?: array(),
			'background' => json_decode( $template['background_config'] ?? '{}', true ) ?: $defaults['background'],
		);

		return wp_parse_args( $settings, $defaults );
	}
	
	/**
	 * Render template preview
	 *
	 * @param int   $template_id Template ID.
	 * @param array $variables Variable values for preview.
	 * @return string Rendered HTML preview.
	 */
	public function render_preview( $template_id, $variables = array() ) {
		$template = $this->template_repo->get_template_with_version( (int) $template_id );
		
		if ( ! $template ) {
			return '';
		}
		
		$elements = json_decode( $template['elements'] ?? '[]', true );
		if ( ! is_array( $elements ) ) {
			return '';
		}

		$output = '<div class="cm-designer-preview" style="position:relative;min-height:360px;background:#fff;overflow:hidden">';
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || empty( $element['type'] ) ) {
				continue;
			}
			$style = 'position:absolute;left:' . (float) ( $element['x'] ?? 0 ) . 'mm;top:' . (float) ( $element['y'] ?? 0 ) . 'mm;';
			if ( isset( $element['width'] ) ) {
				$style .= 'width:' . (float) $element['width'] . 'mm;';
			}
			if ( 'image' === $element['type'] && ! empty( $element['src'] ) ) {
				$output .= '<img src="' . esc_url( $element['src'] ) . '" alt="" style="' . esc_attr( $style ) . 'max-width:100%;height:auto">';
				continue;
			}
			if ( 'text' !== $element['type'] ) {
				continue;
			}
			$content = (string) ( $element['content'] ?? $element['text'] ?? '' );
			foreach ( $variables as $key => $value ) {
				$content = str_replace( '{{' . sanitize_key( $key ) . '}}', esc_html( (string) $value ), $content );
			}
			$output .= '<div style="' . esc_attr( $style ) . '">' . wp_kses_post( $content ) . '</div>';
		}

		return $output . '</div>';
	}
	
	/**
	 * Get available template variables
	 *
	 * @return array Array of available variables.
	 */
	public function get_available_variables() {
		return array(
			'recipient_name' => __( 'Recipient Name', 'certificate-manager' ),
			'recipient_email' => __( 'Recipient Email', 'certificate-manager' ),
			'certificate_number' => __( 'Certificate Number', 'certificate-manager' ),
			'issue_date' => __( 'Issue Date', 'certificate-manager' ),
			'expiration_date' => __( 'Expiration Date', 'certificate-manager' ),
			'template_title' => __( 'Template Title', 'certificate-manager' ),
			'issuer_name' => __( 'Issuer Name', 'certificate-manager' ),
			'issuer_signature' => __( 'Issuer Signature', 'certificate-manager' ),
			'course_title' => __( 'Course Title', 'certificate-manager' ),
			'grade' => __( 'Grade', 'certificate-manager' ),
			'hours_completed' => __( 'Hours Completed', 'certificate-manager' ),
			'points_earned' => __( 'Points Earned', 'certificate-manager' ),
		);
	}
	
	/**
	 * Export template to JSON
	 *
	 * @param int $template_id Template ID.
	 * @return string JSON export.
	 */
	public function export_template( $template_id ) {
		$template = $this->template_repo->get_template_with_version( (int) $template_id );
		
		if ( ! $template ) {
			return '';
		}
		
		$export = array(
			'name' => $template['title'],
			'description' => $template['description'] ?? '',
			'orientation' => $template['orientation'] ?? 'portrait',
			'page_width' => (float) ( $template['page_width'] ?? 210 ),
			'page_height' => (float) ( $template['page_height'] ?? 297 ),
			'elements' => json_decode( $template['elements'] ?? '[]', true ) ?: array(),
			'qr_config' => json_decode( $template['qr_config'] ?? '{}', true ) ?: array(),
			'background_config' => json_decode( $template['background_config'] ?? '{}', true ) ?: array(),
			'created_at' => $template['created_at'],
			'updated_at' => $template['updated_at'],
		);
		
		return wp_json_encode( $export, JSON_PRETTY_PRINT );
	}
	
	/**
	 * Import template from JSON
	 *
	 * @param string $json JSON string.
	 * @return int|false Template ID or false on failure.
	 */
	public function import_template( $json ) {
		$data = json_decode( $json, true );
		
		if ( ! $data ) {
			return false;
		}
		
		$title = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $title ) {
			return false;
		}
		$template = array(
			'title' => $title,
			'description' => sanitize_textarea_field( $data['description'] ?? '' ),
			'orientation' => in_array( $data['orientation'] ?? '', array( 'portrait', 'landscape' ), true ) ? $data['orientation'] : 'portrait',
			'page_width' => max( 1, (float) ( $data['page_width'] ?? 210 ) ),
			'page_height' => max( 1, (float) ( $data['page_height'] ?? 297 ) ),
			'elements' => wp_json_encode( is_array( $data['elements'] ?? null ) ? $data['elements'] : array() ),
			'qr_config' => wp_json_encode( is_array( $data['qr_config'] ?? null ) ? $data['qr_config'] : array() ),
			'background_config' => wp_json_encode( is_array( $data['background_config'] ?? null ) ? $data['background_config'] : array() ),
		);
		
		return $this->template_repo->create_template( $template );
	}
}
