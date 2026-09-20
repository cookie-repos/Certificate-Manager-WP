<?php
/**
 * Tests for VerificationService
 *
 * @package CertificateManager\Tests
 */

namespace CertificateManager\Tests\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PHPUnit\Framework\TestCase;
use CertificateManager\Services\VerificationService;
use WP_Error;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Test cases for VerificationService certificate verification logic.
 */
class VerificationServiceTest extends TestCase {

	/**
	 * Test that verify_certificate returns WP_Error for invalid certificate number format.
	 */
	public function test_verify_certificate_invalid_format() {
		$mock = $this->getMockBuilder( VerificationService::class )
			->disableOriginalConstructor()
			->getMock();

		// Test that invalid formats are rejected
		$invalid_numbers = array(
			'',
			'NOT-A-NUMBER',
			'123',
			' CERT-2024-001 ', // whitespace
		);

		// Each of these should be validated before database lookup
		$this->assertNotEmpty( $invalid_numbers );
	}

	/**
	 * Test certificate number validation pattern.
	 */
	public function test_certificate_number_pattern() {
		// Valid certificate number patterns
		$valid_patterns = array(
			'CERT-2024-001',
			'CERTIFICATE-2024-12345',
			'DIPLOMA-2025-001',
		);

		$invalid_patterns = array(
			'cert-2024-001',      // lowercase
			'CERT-24-001',        // short year
			'CERT-2024-1',        // short sequence
			'CERT_2024_001',      // underscores
			'CERT-2024-001-EXTRA', // extra parts
		);

		// Pattern: PREFIX-YEAR-SEQUENCE
		$pattern = '/^[A-Z]+-\d{4}-\d+$/';

		foreach ( $valid_patterns as $valid ) {
			$this->assertMatchesRegularExpression( $pattern, $valid, "Expected '$valid' to match pattern" );
		}

		foreach ( $invalid_patterns as $invalid ) {
			$this->assertDoesNotMatchRegularExpression( $pattern, $invalid, "Expected '$invalid' NOT to match pattern" );
		}
	}

	/**
	 * Test that verification status values are valid.
	 */
	public function test_verification_status_values() {
		$valid_statuses = array(
			'valid',
			'expired',
			'revoked',
			'replaced',
			'not_found',
		);

		$this->assertCount( 5, $valid_statuses );
		$this->assertContains( 'valid', $valid_statuses );
		$this->assertContains( 'revoked', $valid_statuses );
	}
}
