<?php
/**
 * API Key Repository
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 *
 * @package CertificateManager
 */

namespace CertificateManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Key repository class
 */
class APIKeyRepository {
	
	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table_name;
	
	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'certificate_manager_api_keys';
	}
	
	/**
	 * Create API key
	 *
	 * @param array $data API key data.
	 * @return array|false API key details (including its one-time secret) or false on failure.
	 */
	public function create( $data ) {
		global $wpdb;
		
		$secret = $this->generate_secret();
		$permissions = isset( $data['permissions'] ) ? (array) $data['permissions'] : (array) ( $data['caps'] ?? array( 'read' ) );
		$template_ids = isset( $data['template_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $data['template_ids'] ) ) ) : array();
		
		$result = $wpdb->insert( $this->table_name, array(
			'secret_hash' => wp_hash_password( $secret ),
			'name' => $data['name'] ?? '',
			'secret_prefix' => substr( $secret, 0, 8 ),
			'description' => $data['description'] ?? '',
			'permissions' => wp_json_encode( $permissions ),
			'template_ids' => wp_json_encode( $template_ids ),
			'is_active' => $data['enabled'] ?? 1,
			'created_by' => $data['created_by'] ?? get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		) );
		
		if ( ! $result ) {
			return false;
		}
		
		// Return the full key for display only once
		return array(
			'id' => $wpdb->insert_id,
			'secret_prefix' => substr( $secret, 0, 8 ),
			'secret' => $secret,
		);
	}

	/**
	 * Get an API key by its public display prefix.
	 *
	 * @param string $prefix API key prefix.
	 * @return array|false API key data or false if not found.
	 */
	public function get_by_prefix( $prefix ) {
		global $wpdb;
		
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE secret_prefix = %s", $prefix ),
			ARRAY_A
		);
		
		if ( ! $row ) {
			return false;
		}
		
		return $this->normalize_row( $row );
	}

	/**
	 * Find and verify an API key supplied as a bearer token.
	 *
	 * @param string $token Full API key secret.
	 * @return array|false Normalized key row, or false when invalid.
	 */
	public function get_by_token( string $token ) {
		if ( strlen( $token ) < 8 ) {
			return false;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE secret_prefix = %s AND is_active = 1", substr( $token, 0, 8 ) ), ARRAY_A );
		foreach ( $rows as $row ) {
			if ( wp_check_password( $token, $row['secret_hash'] ) ) {
				return $this->normalize_row( $row );
			}
		}
		return false;
	}
	
	/**
	 * Get all API keys
	 *
	 * @param array $args Query arguments.
	 * @return array Array of API key data.
	 */
	public function get_all( $args = array() ) {
		global $wpdb;
		
		$defaults = array(
			'number' => -1,
			'offset' => 0,
			'orderby' => 'id',
			'order' => 'DESC',
			'is_active' => null,
			'created_by' => null,
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$query = "SELECT * FROM {$this->table_name} WHERE 1=1";
		
		if ( $args['is_active'] !== null ) {
			$query .= $wpdb->prepare( " AND is_active = %d", $args['is_active'] );
		}
		if ( $args['created_by'] !== null ) {
			$query .= $wpdb->prepare( " AND created_by = %d", $args['created_by'] );
		}
		
		$allowed_orderby = array( 'id', 'name', 'created_at', 'last_used_at' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$query .= " ORDER BY {$orderby} {$order}";
		
		if ( $args['number'] > 0 ) {
			$query .= $wpdb->prepare( " LIMIT %d OFFSET %d", $args['number'], $args['offset'] );
		}
		
		$rows = $wpdb->get_results( $query, ARRAY_A );
		
		return array_map( array( $this, 'normalize_row' ), $rows );
	}
	
	/**
	 * Update API key
	 *
	 * @param int   $id API key ID.
	 * @param array $data API key data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( $id, $data ) {
		global $wpdb;
		
		$update_data = array();
		
		if ( isset( $data['name'] ) ) {
			$update_data['name'] = $data['name'];
		}
		if ( isset( $data['permissions'] ) || isset( $data['caps'] ) ) {
			$update_data['permissions'] = wp_json_encode( (array) ( $data['permissions'] ?? $data['caps'] ) );
		}
		if ( isset( $data['template_ids'] ) ) {
			$update_data['template_ids'] = wp_json_encode( array_values( array_filter( array_map( 'absint', (array) $data['template_ids'] ) ) ) );
		}
		if ( isset( $data['enabled'] ) ) {
			$update_data['is_active'] = $data['enabled'];
		}
		
		$update_data['updated_at'] = current_time( 'mysql' );
		
		$result = $wpdb->update(
			$this->table_name,
			$update_data,
			array( 'id' => $id )
		);
		
		return $result !== false;
	}
	
	/**
	 * Delete API key
	 *
	 * @param int $id API key ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $id ) {
		global $wpdb;
		
		$result = $wpdb->delete(
			$this->table_name,
			array( 'id' => $id )
		);
		
		return $result !== false;
	}
	
	/**
	 * Regenerate API key secret
	 *
	 * @param int $id API key ID.
	 * @return array|false New key data or false on failure.
	 */
	public function regenerate_secret( $id ) {
		$existing = $this->get_by_id( $id );
		
		if ( ! $existing ) {
			return false;
		}
		
		$new_secret = $this->generate_secret();
		
		global $wpdb;
		
		$result = $wpdb->update(
			$this->table_name,
			array(
				'secret_hash' => wp_hash_password( $new_secret ),
				'secret_prefix' => substr( $new_secret, 0, 8 ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
		
		if ( ! $result ) {
			return false;
		}
		
		return array(
			'id' => $id,
			'secret_prefix' => substr( $new_secret, 0, 8 ),
			'secret' => $new_secret,
		);
	}
	
	/**
	 * Get API key by ID
	 *
	 * @param int $id API key ID.
	 * @return array|false API key data or false if not found.
	 */
	public function get_by_id( $id ) {
		global $wpdb;
		
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $id ),
			ARRAY_A
		);
		
		if ( ! $row ) {
			return false;
		}
		
		return $this->normalize_row( $row );
	}
	
	/**
	 * Record successful use of an API key.
	 *
	 * @param int $id API key ID.
	 * @return bool
	 */
	public function update_last_used( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->table_name, array( 'last_used_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Check if API key has permission.
	 *
	 * @param array  $key API key data.
	 * @param string $cap Capability to check.
	 * @return bool True if key has capability, false otherwise.
	 */
	public function has_permission( array $key, string $permission ): bool {
		$permissions = $key['permissions'] ?? array();
		
		if ( in_array( 'all', $permissions, true ) ) {
			return true;
		}
		
		return in_array( $permission, $permissions, true );
	}
	
	/**
	 * Generate secret.
	 *
	 * @return string Generated secret.
	 */
	private function generate_secret() {
		return 'cm_' . bin2hex( random_bytes( 32 ) );
	}
	
	/**
	 * Normalize database row
	 *
	 * @param array $row Database row.
	 * @return array Normalized API key data.
	 */
	private function normalize_row( $row ) {
		$row['permissions'] = json_decode( $row['permissions'] ?? '[]', true ) ?: array();
		$row['template_ids'] = array_values( array_filter( array_map( 'absint', json_decode( $row['template_ids'] ?? '[]', true ) ?: array() ) ) );
		unset( $row['secret_hash'] );
		$row['secret'] = ''; // Never return the actual secret.
		return $row;
	}
}
