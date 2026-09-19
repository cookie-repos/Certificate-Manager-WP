<?php
/**
 * Certificate Manager Sequence Repository
 *
 * @package CertificateManager
 */

namespace CertificateManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sequence repository
 */
class SequenceRepository {
	
	/**
	 * Get global sequence
	 *
	 * @return array|false
	 */
	public function get_global_sequence() {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		return $wpdb->get_row( "SELECT * FROM {$table_name} WHERE is_global = 1 LIMIT 1", ARRAY_A );
	}
	
	/**
	 * Get sequence by ID
	 *
	 * @param int $id Sequence ID
	 * @return array|false
	 */
	public function get_sequence( int $id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$id
		), ARRAY_A );
	}
	
	/**
	 * Get sequence for update (for atomic increment)
	 *
	 * @param int $id Sequence ID
	 * @return array|false
	 */
	public function get_sequence_for_update( int $id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		// Use FOR UPDATE for locking (InnoDB only)
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d FOR UPDATE",
			$id
		), ARRAY_A );
	}
	
	/**
	 * Create sequence
	 *
	 * @param array $data Sequence data
	 * @return int|WP_Error Sequence ID or error
	 */
	public function create_sequence( array $data ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		$wpdb->insert( $table_name, array(
			'plugin_network_id' => $data['plugin_network_id'] ?? null,
			'name' => sanitize_title( $data['name'] ),
			'current_value' => isset( $data['current_value'] ) ? (int) $data['current_value'] : 0,
			'padding' => isset( $data['padding'] ) ? (int) $data['padding'] : 1,
			'is_global' => isset( $data['is_global'] ) ? (int) $data['is_global'] : 0,
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update sequence
	 *
	 * @param int $id Sequence ID
	 * @param array $data Sequence data
	 * @return bool
	 */
	public function update_sequence( int $id, array $data ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		return (bool) $wpdb->update( $table_name, $data, array( 'id' => $id ) );
	}
	
	/**
	 * Delete sequence
	 *
	 * @param int $id Sequence ID
	 * @return bool
	 */
	public function delete_sequence( int $id ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		return (bool) $wpdb->delete( $table_name, array( 'id' => $id ) );
	}
	
	/**
	 * Check if sequence name exists
	 *
	 * @param string $name Sequence name
	 * @param int $exclude_id Sequence ID to exclude
	 * @return bool
	 */
	public function sequence_name_exists( string $name, int $exclude_id = 0 ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		if ( $exclude_id ) {
			return (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE name = %s AND id != %d",
				$name, $exclude_id
			) );
		}
		
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE name = %s",
			$name
		) );
	}
	
	/**
	 * Get sequences by network
	 *
	 * @param int|null $network_id Network ID (null for current site)
	 * @return array
	 */
	public function get_sequences_by_network( ?int $network_id = null ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		if ( $network_id !== null ) {
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE plugin_network_id = %d",
				$network_id
			), ARRAY_A );
		}
		
		return $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY is_global DESC, name ASC", ARRAY_A );
	}
}
