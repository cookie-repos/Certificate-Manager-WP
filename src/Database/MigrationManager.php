<?php
/**
 * Certificate Manager Migration Manager
 *
 * @package CertificateManager
 */

namespace CertificateManager\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration manager
 */
class MigrationManager {
	
	/**
	 * Plugin version
	 *
	 * @var string
	 */
	private $version;
	
	/**
	 * Schema version
	 *
	 * @var int
	 */
	private $schema_version;
	
	/**
	 * Constructor
	 *
	 * @param string $version Plugin version
	 * @param int $schema_version Schema version
	 */
	public function __construct( string $version, int $schema_version ) {
		$this->version = $version;
		$this->schema_version = $schema_version;
	}
	
	/**
	 * Run migrations
	 */
	public function run_migrations() {
		try {
			global $wpdb;
			
			$current_version = get_option( 'certificate_manager_version', '0' );
			$current_schema = get_option( 'certificate_manager_schema_version', 0 );
			
			// Create tables if they don't exist
			$this->create_tables();
			
			// Run schema migrations if needed
			if ( $current_schema < $this->schema_version ) {
				$this->migrate_schema( $current_schema );
				update_option( 'certificate_manager_schema_version', $this->schema_version );
			}
			
			// Update plugin version
			update_option( 'certificate_manager_version', $this->version );
		} catch ( \Exception $e ) {
			// Log error but don't fail activation
			error_log( 'Certificate Manager migration error: ' . $e->getMessage() );
		}
	}
	
	/**
	 * Create database tables
	 */
	public function create_tables() {
		global $wpdb;
		
		try {
			$schema = new Schema();
			$tables = $schema->get_create_statements();
			$charset_collate = $wpdb->get_charset_collate();
			
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			
			foreach ( $tables as $table_name => $sql ) {
				// Check if table exists
				$table_name_db = $wpdb->prefix . 'certificate_manager_' . $table_name;
				$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name_db ) );
				
				if ( ! $exists ) {
					dbDelta( $sql );
				}
			}
		} catch ( \Exception $e ) {
			error_log( 'Certificate Manager table creation error: ' . $e->getMessage() );
		}
	}
	
	/**
	 * Migrate schema to latest version
	 *
	 * @param int $current_version Current schema version
	 */
	private function migrate_schema( int $current_version ) {
		// Version 1: Initial schema
		if ( $current_version < 1 ) {
			$this->migrate_to_v1();
		}

		if ( $current_version < 2 ) {
			$this->migrate_to_v2();
		}

		if ( $current_version < 3 ) {
			$this->migrate_to_v3();
		}

		if ( $current_version < 4 ) {
			$this->migrate_to_v4();
		}

		if ( $current_version < 5 ) {
			$this->migrate_to_v5();
		}

		if ( $current_version < 6 ) {
			$this->migrate_to_v6();
		}

		if ( $current_version < 7 ) {
			$this->migrate_to_v7();
		}

		if ( $current_version < 8 ) {
			$this->migrate_to_v8();
		}
		
		// Future version migrations can be added here
		// Version 2: ...
		// Version 3: ...
	}
	
	/**
	 * Migrate to version 1
	 */
	private function migrate_to_v1() {
		// V1 is the initial schema, tables are created in create_tables()
		// Add initial data if needed
		$this->insert_default_variables();
		$this->insert_default_sequences();
	}

	private function migrate_to_v2() {
		$this->insert_default_variables();
	}

	private function migrate_to_v3() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = ( new Schema() )->get_create_statements();
		if ( isset( $tables['certificates'] ) ) {
			dbDelta( $tables['certificates'] );
		}
	}

	private function migrate_to_v4() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = ( new Schema() )->get_create_statements();
		if ( isset( $tables['templates'] ) ) {
			dbDelta( $tables['templates'] );
		}
	}

	private function migrate_to_v5() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = ( new Schema() )->get_create_statements();
		if ( isset( $tables['webhooks'] ) ) {
			dbDelta( $tables['webhooks'] );
		}
	}

	private function migrate_to_v6() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = ( new Schema() )->get_create_statements();
		if ( isset( $tables['verifiable_credentials'] ) ) {
			dbDelta( $tables['verifiable_credentials'] );
		}
	}

	/**
	 * Bring the API-key table into line with the current credential model.
	 */
	private function migrate_to_v7() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = ( new Schema() )->get_create_statements();
		if ( isset( $tables['api_keys'] ) ) {
			dbDelta( $tables['api_keys'] );
		}
	}

	/**
	 * Ensure credential storage exists for sites that previously recorded a
	 * schema version without creating the signed-credential table.
	 */
	private function migrate_to_v8() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = ( new Schema() )->get_create_statements();
		if ( isset( $tables['verifiable_credentials'] ) ) {
			dbDelta( $tables['verifiable_credentials'] );
		}
	}
	
	/**
	 * Insert default system variables
	 */
	private function insert_default_variables() {
		global $wpdb;
		
		$variables = array(
			// Recipient variables
			array(
				'key' => 'recipient_name',
				'label' => __( 'Recipient Name', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 1,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'recipient_email',
				'label' => __( 'Recipient Email', 'certificate-manager' ),
				'field_type' => 'email',
				'is_required' => 0,
				'default_visible' => 0,
				'is_system' => 1,
			),
			// Certificate variables
			array(
				'key' => 'certificate_number',
				'label' => __( 'Certificate Number', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'issue_date',
				'label' => __( 'Issue Date', 'certificate-manager' ),
				'field_type' => 'date',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'expiry_date',
				'label' => __( 'Expiry Date', 'certificate-manager' ),
				'field_type' => 'date',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			// Content variables
			array(
				'key' => 'certificate_name',
				'label' => __( 'Certificate Name', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'course_name',
				'label' => __( 'Course Name', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'learning_outcomes',
				'label' => __( 'Outcomes / Learning Objectives', 'certificate-manager' ),
				'field_type' => 'textarea',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'completion_date',
				'label' => __( 'Completion Date', 'certificate-manager' ),
				'field_type' => 'date',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			// Issuer variables
			array(
				'key' => 'issuer_name',
				'label' => __( 'Issuer Name', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'issuer_title',
				'label' => __( 'Issuer Title', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			// System variables
			array(
				'key' => 'site_name',
				'label' => __( 'Site Name', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'site_url',
				'label' => __( 'Site URL', 'certificate-manager' ),
				'field_type' => 'url',
				'is_required' => 0,
				'default_visible' => 0,
				'is_system' => 1,
			),
			array(
				'key' => 'verification_url',
				'label' => __( 'Verification URL', 'certificate-manager' ),
				'field_type' => 'url',
				'is_required' => 0,
				'default_visible' => 1,
				'is_system' => 1,
			),
			array(
				'key' => 'template_name',
				'label' => __( 'Template Name', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 0,
				'is_system' => 1,
			),
			array(
				'key' => 'current_year',
				'label' => __( 'Current Year', 'certificate-manager' ),
				'field_type' => 'text',
				'is_required' => 0,
				'default_visible' => 0,
				'is_system' => 1,
			),
		);
		
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		
		foreach ( $variables as $variable ) {
			// Check if variable already exists
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE variable_key = %s",
				$variable['key']
			) );
			
			if ( ! $exists ) {
				$wpdb->insert( $table_name, array(
					'plugin_network_id' => null,
					'variable_key' => $variable['key'],
					'label' => $variable['label'],
					'field_type' => $variable['field_type'],
					'is_required' => $variable['is_required'],
					'default_visible' => $variable['default_visible'],
					'is_system' => $variable['is_system'],
					'sort_order' => 0,
					'created_at' => current_time( 'mysql' ),
				) );
			}
		}
	}
	
	/**
	 * Insert default sequences
	 */
	private function insert_default_sequences() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		// Check if global sequence exists
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE name = %s",
			'global'
		) );
		
		if ( ! $exists ) {
			$wpdb->insert( $table_name, array(
				'plugin_network_id' => null,
				'name' => 'global',
				'current_value' => 0,
				'padding' => 4,
				'is_global' => 1,
				'created_at' => current_time( 'mysql' ),
			) );
		}
	}
	
	/**
	 * Drop all tables
	 */
	public function drop_tables() {
		global $wpdb;
		
		$schema = new Schema();
		$table_names = $schema->get_table_names();
		
		foreach ( $table_names as $table_name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}
		
		// Delete all options
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'certificate_manager_%'" );
	}
	
	/**
	 * Get current schema version
	 *
	 * @return int
	 */
	public function get_current_schema_version(): int {
		return (int) get_option( 'certificate_manager_schema_version', 0 );
	}
	
	/**
	 * Update schema version
	 *
	 * @param int $version New version
	 */
	public function update_schema_version( int $version ) {
		update_option( 'certificate_manager_schema_version', $version );
	}
}
