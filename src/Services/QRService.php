<?php
/**
 * Certificate Manager QR Service
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QR code generation service
 */
class QRService {
	
	/**
	 * QR code library path
	 *
	 * @var string
	 */
	private $library_path;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->library_path = CERTIFICATE_MANAGER_PATH . 'lib/QRCode/qrlib.php';
	}
	
	/**
	 * Generate QR code
	 *
	 * @param string $data Data to encode
	 * @param array $config QR configuration
	 * @return string|false QR code data URI or false
	 */
	public function generate_qr( string $data, array $config = array() ) {
		// Ensure QR code library is loaded
		if ( ! file_exists( $this->library_path ) ) {
			return false;
		}
		
		require_once $this->library_path;
		
		// Parse configuration
		$physical_size = max( 24, min( (float) ( $config['width'] ?? 30 ), (float) ( $config['height'] ?? 30 ) ) );
		$size = max( 288, (int) ceil( $physical_size * 12 ) );
		$margin = $config['margin'] ?? 0;
		$quiet_zone = 0;
		$style = $config['style'] ?? 'standard';
		$color = $config['color'] ?? '#000000';
		$bg_color = $config['bg_color'] ?? '#ffffff';
		$logo = $config['logo'] ?? null;
		$data_length = strlen( $data );
		$error_correction = $config['error_correction'] ?? ( $physical_size < 28 || $data_length > 140 ? 'L' : ( $physical_size < 34 || $data_length > 80 ? 'M' : 'Q' ) );
		
		// Validate error correction level
		if ( ! in_array( $error_correction, array( 'L', 'M', 'Q', 'H' ) ) ) {
			$error_correction = 'H';
		}
		
		// Generate QR code filename
		$filename = 'qr_' . md5( $data . '|' . wp_json_encode( array( $size, $margin, $quiet_zone, $style, $color, $bg_color, $logo, $error_correction ) ) ) . '.png';
		$upload_dir = wp_upload_dir();
		$qr_dir = $upload_dir['basedir'] . '/certificate-manager/qr';
		
		// Create directory if needed
		if ( ! wp_mkdir_p( $qr_dir ) && ! is_dir( $qr_dir ) ) {
			return false;
		}
		
		$file_path = $qr_dir . '/' . $filename;
		
		// Check if file already exists
		if ( is_readable( $file_path ) ) {
			$image_data = file_get_contents( $file_path );
			if ( false !== $image_data && '' !== $image_data ) {
				return 'data:image/png;base64,' . base64_encode( $image_data );
			}
		}
		
		// Generate through the bundled library before embedding the PNG in the PDF.
		try {
			$qr_url = \CertificateManagerQRCode::generate( $data, array(
				'width' => (int) $size,
				'height' => (int) $size,
				'margin' => (int) $margin,
				'quiet_zone' => $quiet_zone,
				'color' => $color,
				'bg_color' => $bg_color,
				'error_correction' => $error_correction,
				'file_path' => $file_path,
			) );
		} catch ( \Throwable $error ) {
			return false;
		}
		
		if ( ! $qr_url ) {
			return false;
		}
		
		// Add logo if configured
		if ( $logo && is_numeric( $logo ) ) {
			$file_path = $this->add_logo_to_qr( $file_path, $logo, $style );
		}
		
		if ( ! is_readable( $file_path ) ) {
			return false;
		}

		$image_data = file_get_contents( $file_path );
		if ( false === $image_data || '' === $image_data ) {
			return false;
		}

		return 'data:image/png;base64,' . base64_encode( $image_data );
	}
	
	/**
	 * Add logo to QR code
	 *
	 * @param string $qr_path QR code image path
	 * @param int $logo_id Logo image ID
	 * @param string $style QR style
	 * @return string Modified QR code path
	 */
	private function add_logo_to_qr( string $qr_path, int $logo_id, string $style ): string {
		$logo_url = wp_get_attachment_url( $logo_id );
		if ( ! $logo_url ) {
			return $qr_path;
		}
		
		$logo_file = download_url( $logo_url );
		if ( is_wp_error( $logo_file ) ) {
			return $qr_path;
		}
		
		// Get image info
		$qri = getimagesize( $qr_path );
		$qr_width = $qri[0];
		$qr_height = $qri[1];
		
		// Calculate logo size (15-20% of QR size)
		$logo_size = $qr_width * 0.18;
		
		// Create QR image resource
		$qr_image = imagecreatefrompng( $qr_path );
		if ( ! $qr_image ) {
			wp_delete_file( $logo_file );
			return $qr_path;
		}
		
		// Get logo image
		$logo_image = $this->create_image_from_file( $logo_file );
		if ( ! $logo_image ) {
			imagedestroy( $qr_image );
			wp_delete_file( $logo_file );
			return $qr_path;
		}
		
		// Get logo dimensions
		$logo_width = imagesx( $logo_image );
		$logo_height = imagesy( $logo_image );
		
		// Resize logo if needed
		if ( $logo_width > $logo_size || $logo_height > $logo_size ) {
			$ratio = $logo_width / $logo_height;
			
			if ( $logo_width > $logo_height ) {
				$new_width = $logo_size;
				$new_height = $logo_size / $ratio;
			} else {
				$new_height = $logo_size;
				$new_width = $logo_size * $ratio;
			}
			
			$resized = imagecreatetruecolor( $new_width, $new_height );
			imagealphablending( $resized, false );
			imagesavealpha( $resized, true );
			imagecopyresampled( $resized, $logo_image, 0, 0, 0, 0, $new_width, $new_height, $logo_width, $logo_height );
			imagedestroy( $logo_image );
			$logo_image = $resized;
		}
		
		// Calculate logo position (center)
		$logo_x = ( $qr_width - imagesx( $logo_image ) ) / 2;
		$logo_y = ( $qr_height - imagesy( $logo_image ) ) / 2;
		
		// Merge logo with QR code
		imagealphablending( $qr_image, true );
		imagesavealpha( $qr_image, true );
		imagecopy( $qr_image, $logo_image, $logo_x, $logo_y, 0, 0, imagesx( $logo_image ), imagesy( $logo_image ) );
		
		// Save modified QR code
		imagepng( $qr_image, $qr_path );
		
		// Cleanup
		imagedestroy( $qr_image );
		imagedestroy( $logo_image );
		wp_delete_file( $logo_file );
		
		return $qr_path;
	}
	
	/**
	 * Create image from file
	 *
	 * @param string $file_path File path
	 * @return resource|false
	 */
	private function create_image_from_file( string $file_path ) {
		$info = getimagesize( $file_path );
		if ( ! $info ) {
			return false;
		}
		
		switch ( $info['mime'] ) {
			case 'image/png':
				return imagecreatefrompng( $file_path );
			case 'image/jpeg':
				return imagecreatefromjpeg( $file_path );
			case 'image/gif':
				return imagecreatefromgif( $file_path );
			default:
				return false;
		}
	}
	
	/**
	 * Validate QR code scanability
	 *
	 * @param string $data Data to encode
	 * @param array $config QR configuration
	 * @return array Validation results
	 */
	public function validate_qr_scanability( string $data, array $config = array() ): array {
		$results = array(
			'valid' => true,
			'warnings' => array(),
			'errors' => array(),
		);
		
		// Check data length
		$data_length = strlen( $data );
		$max_length = 4296; // Maximum for QR version 40, error correction L
		
		if ( $data_length > $max_length ) {
			$results['valid'] = false;
			$results['errors'][] = sprintf(
				/* translators: %d: maximum number of characters allowed in a QR code */
				__( 'Data exceeds maximum QR code capacity (%d characters)', 'certificate-manager' ),
				$max_length
			);
		}
		
		// Check QR size
		$size = $config['size'] ?? 150;
		if ( $size < 50 ) {
			$results['warnings'][] = __( 'QR code size is small and may be difficult to scan', 'certificate-manager' );
		}
		
		// Check logo presence
		if ( isset( $config['logo'] ) && $config['logo'] ) {
			// Logo in center can reduce scanability
			$results['warnings'][] = __( 'Logo in center of QR code may reduce scanability', 'certificate-manager' );
		}
		
		// Check contrast
		$color = $config['color'] ?? '#000000';
		$bg_color = $config['bg_color'] ?? '#ffffff';
		
		if ( $this->get_color_luminance( $color ) < 0.1 && $this->get_color_luminance( $bg_color ) > 0.9 ) {
			// Good contrast
		} else {
			$results['warnings'][] = __( 'Low contrast between QR code and background', 'certificate-manager' );
		}
		
		return $results;
	}
	
	/**
	 * Get color luminance
	 *
	 * @param string $hex Hex color
	 * @return float Luminance value (0-1)
	 */
	private function get_color_luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		
		if ( strlen( $hex ) === 3 ) {
			$hex = str_repeat( substr( $hex, 0, 1 ), 2 ) . str_repeat( substr( $hex, 1, 1 ), 2 ) . str_repeat( substr( $hex, 2, 1 ), 2 );
		}
		
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		
		// Calculate relative luminance
		$r = $r / 255;
		$g = $g / 255;
		$b = $b / 255;
		
		$r = $r <= 0.03928 ? $r / 12.92 : pow( ( $r + 0.055 ) / 1.055, 2.4 );
		$g = $g <= 0.03928 ? $g / 12.92 : pow( ( $g + 0.055 ) / 1.055, 2.4 );
		$b = $b <= 0.03928 ? $b / 12.92 : pow( ( $b + 0.055 ) / 1.055, 2.4 );
		
		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	}
	
	/**
	 * Get available QR styles
	 *
	 * @return array
	 */
	public function get_styles(): array {
		return array(
			'standard' => array(
				'name' => __( 'Standard', 'certificate-manager' ),
				'description' => __( 'Standard QR code with square modules', 'certificate-manager' ),
			),
			'rounded' => array(
				'name' => __( 'Rounded', 'certificate-manager' ),
				'description' => __( 'QR code with rounded modules for a softer look', 'certificate-manager' ),
			),
			'minimal' => array(
				'name' => __( 'Minimal', 'certificate-manager' ),
				'description' => __( 'Minimal QR code with simple design', 'certificate-manager' ),
			),
			'branded' => array(
				'name' => __( 'Branded', 'certificate-manager' ),
				'description' => __( 'Professional QR code with optional logo center', 'certificate-manager' ),
			),
		);
	}
	
	/**
	 * Get default QR configuration
	 *
	 * @return array
	 */
	public function get_default_config(): array {
		return array(
			'size' => 150,
			'margin' => 1,
			'style' => 'standard',
			'color' => '#000000',
			'bg_color' => '#ffffff',
			'logo' => null,
			'error_correction' => 'M',
		);
	}
}
