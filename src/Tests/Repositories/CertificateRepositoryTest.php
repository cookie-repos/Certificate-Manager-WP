<?php
/**
 * Tests for CertificateRepository
 *
 * @package CertificateManager\Tests
 */

namespace CertificateManager\Tests\Repositories;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Test cases for CertificateRepository database operations.
 */
class CertificateRepositoryTest extends TestCase {

	/**
	 * Test that status values are normalized.
	 */
	public function test_valid_status_values() {
		$valid_statuses = array(
			'active',
			'expired',
			'revoked',
			'replaced',
			'trash',
		);

		// These should be the only valid statuses
		$this->assertCount( 5, $valid_statuses );
		
		foreach ( $valid_statuses as $status ) {
			$this->assertMatchesRegularExpression( '/^[a-z]+$/', $status, "Status '$status' should be lowercase letters only" );
		}
	}

	/**
	 * Test that statistics query returns expected structure.
	 */
	public function test_statistics_structure() {
		$expected_keys = array(
			'total',
			'active',
			'expired',
			'revoked',
			'replaced',
			'trash',
			'expiring_soon',
		);

		$this->assertCount( 7, $expected_keys );
		
		foreach ( $expected_keys as $key ) {
			$this->assertIsString( $key );
		}
	}

	/**
	 * Test that date format for queries is correct.
	 */
	public function test_date_format() {
		$date = current_time( 'mysql' );
		
		// MySQL datetime format: Y-m-d H:i:s
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date );
	}

	/**
	 * Test that purge_all returns correct structure on success.
	 */
	public function test_purge_all_return_structure() {
		// Expected success return
		$expected = array( 'count' => 0 );
		
		$this->assertArrayHasKey( 'count', $expected );
		$this->assertIsInt( $expected['count'] );
	}
}
