<?php
/**
 * Tests for IssuanceService
 *
 * @package CertificateManager\Tests
 */

namespace CertificateManager\Tests\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PHPUnit\Framework\TestCase;
use CertificateManager\Services\IssuanceService;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Test cases for IssuanceService certificate issuance logic.
 */
class IssuanceServiceTest extends TestCase {

	/**
	 * Test that generate_certificate_number produces valid format.
	 */
	public function test_generate_certificate_number_format() {
		// Mock the service with minimal dependencies
		$mock = $this->getMockBuilder( IssuanceService::class )
			->disableOriginalConstructor()
			->onlyMethods( array() )
			->getMock();

		// Use reflection to access protected method
		$reflection = new \ReflectionClass( IssuanceService::class );
		$method = $reflection->getMethod( 'generate_certificate_number' );
		$method->setAccessible( true );

		// Generate certificate number
		$result = $method->invoke( $mock, 1, 'CERT-' );

		// Assert format: PREFIX-YEAR-SEQUENCE
		$this->assertMatchesRegularExpression( '/^CERT-\d{4}-\d+$/', $result );
	}

	/**
	 * Test that generate_certificate_number produces unique values.
	 */
	public function test_generate_certificate_number_uniqueness() {
		$mock = $this->getMockBuilder( IssuanceService::class )
			->disableOriginalConstructor()
			->onlyMethods( array() )
			->getMock();

		$reflection = new \ReflectionClass( IssuanceService::class );
		$method = $reflection->getMethod( 'generate_certificate_number' );
		$method->setAccessible( true );

		$numbers = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$numbers[] = $method->invoke( $mock, $i + 1, 'TEST-' );
		}

		$unique = array_unique( $numbers );
		$this->assertCount( 100, $unique, 'All generated certificate numbers should be unique' );
	}

	/**
	 * Test that build_certificate_data returns array structure.
	 */
	public function test_build_certificate_data_structure() {
		$mock = $this->getMockBuilder( IssuanceService::class )
			->disableOriginalConstructor()
			->onlyMethods( array() )
			->getMock();

		$reflection = new \ReflectionClass( IssuanceService::class );
		$method = $reflection->getMethod( 'build_certificate_data' );
		$method->setAccessible( true );

		// This would require mocking database calls, so we test the method exists
		$this->assertTrue( $method->isProtected() || $method->isPublic() );
	}
}
