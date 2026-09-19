<?php
/**
 * QR Code Generator for Certificate Manager
 * Bundles Apirone/php-qr-code 1.0.1 (MIT).
 * https://github.com/Apirone/php-qr-code
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bundled Apirone/php-qr-code 1.0.1 (MIT); see QRCode.php for license.
require_once __DIR__ . '/QRCode.php';

/**
 * QR Code wrapper class
 */
class CertificateManagerQRCode {
	
	/**
	 * Generate QR code image and return URL
	 *
	 * @param string $data Data to encode
	 * @param array $config Configuration
	 * @return string|false QR code URL or false
	 */
	public static function generate( string $data, array $config = array() ) {
		$error_levels = array(
			'L' => 'qrl',
			'M' => 'qrm',
			'Q' => 'qrq',
			'H' => 'qrh',
		);
		$error_correction = strtoupper( $config['error_correction'] ?? 'H' );
		
		$generator = \Apirone\Lib\PhpQRCode\QRCode::init( $data, array(
			's' => $error_levels[ $error_correction ] ?? 'qrh',
			'sf' => $config['scale'] ?? 4,
			'w' => $config['width'] ?? 150,
			'h' => $config['height'] ?? 150,
			'p' => $config['margin'] ?? 1,
			'wq' => $config['quiet_zone'] ?? 4,
			'bc' => $config['bg_color'] ?? 'FFFFFF',
			'fc' => $config['color'] ?? '000000',
		) );
		
		// Generate filename
		$filename = 'qr_' . md5( $data . microtime() ) . '.png';
		$upload_dir = wp_upload_dir();
		$qr_dir = $upload_dir['basedir'] . '/certificate-manager/qr';
		
		// Create directory if needed
		wp_mkdir_p( $qr_dir );
		
		$file_path = $config['file_path'] ?? $qr_dir . '/' . $filename;
		$filename = basename( $file_path );
		
		// Check if file already exists
		if ( file_exists( $file_path ) ) {
			return $upload_dir['baseurl'] . '/certificate-manager/qr/' . $filename;
		}
		
		// Generate and save QR code
		$image = $generator->render_image();
		if ( ! $image ) {
			return false;
		}
		$written = imagepng( $image, $file_path );
		imagedestroy( $image );
		if ( ! $written ) {
			return false;
		}
		
		return $upload_dir['baseurl'] . '/certificate-manager/qr/' . $filename;
	}
	
	/**
	 * Validate QR code
	 *
	 * @param string $data Data to encode
	 * @param array $config Configuration
	 * @return array Validation results
	 */
	public static function validate( string $data, array $config = array() ): array {
		$results = array(
			'valid' => true,
			'warnings' => array(),
			'errors' => array(),
		);
		
		// Check data length
		if ( strlen( $data ) > 4000 ) {
			$results['valid'] = false;
			$results['errors'][] = __( 'Data exceeds maximum QR code capacity', 'certificate-manager' );
		}
		
		// Check size
		$size = $config['width'] ?? 150;
		if ( $size < 50 ) {
			$results['warnings'][] = __( 'QR code size is small and may be difficult to scan', 'certificate-manager' );
		}
		
		// Check contrast
		$color = $config['color'] ?? '000000';
		$bg_color = $config['bg_color'] ?? 'FFFFFF';
		
		$color_lum = self::get_luminance( $color );
		$bg_lum = self::get_luminance( $bg_color );
		
		if ( abs( $color_lum - $bg_lum ) < 0.3 ) {
			$results['warnings'][] = __( 'Low contrast between QR code and background', 'certificate-manager' );
		}
		
		return $results;
	}
	
	/**
	 * Get color luminance
	 *
	 * @param string $hex Hex color
	 * @return float Luminance value
	 */
	private static function get_luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		
		if ( strlen( $hex ) === 3 ) {
			$hex = str_repeat( substr( $hex, 0, 1 ), 2 ) . str_repeat( substr( $hex, 1, 1 ), 2 ) . str_repeat( substr( $hex, 2, 1 ), 2 );
		}
		
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		
		$r = $r / 255;
		$g = $g / 255;
		$b = $b / 255;
		
		$r = $r <= 0.03928 ? $r / 12.92 : pow( ( $r + 0.055 ) / 1.055, 2.4 );
		$g = $g <= 0.03928 ? $g / 12.92 : pow( ( $g + 0.055 ) / 1.055, 2.4 );
		$b = $b <= 0.03928 ? $b / 12.92 : pow( ( $b + 0.055 ) / 1.055, 2.4 );
		
		return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
	}
}
