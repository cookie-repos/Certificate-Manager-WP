<?php
/**
 * Certificate Manager Variable Repository
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
 * Variable repository
 */
class VariableRepository {

	/**
	 * Get a variable by ID.
	 *
	 * @param int $id Variable ID.
	 * @return array|false
	 */
	public function get_variable( int $id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ),
			ARRAY_A
		);
	}
	
	/**
	 * Get all variables
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_variables( array $args = array() ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		
		$where = array( 'is_system = 0' );
		$params = array();
		
		if ( isset( $args['system'] ) && $args['system'] ) {
			$where = array( '1=1' );
		}
		
		if ( ! empty( $args['field_type'] ) ) {
			$where[] = 'field_type = %s';
			$params[] = $args['field_type'];
		}
		
		$query = "SELECT * FROM {$table_name} WHERE " . implode( ' AND ', $where );
		$variables = $wpdb->get_results( $params ? $wpdb->prepare( $query, $params ) : $query, ARRAY_A );
		
		return $variables;
	}
	
	/**
	 * Get variable by key
	 *
	 * @param string $key Variable key
	 * @return array|false
	 */
	public function get_variable_by_key( string $key ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE variable_key = %s",
			$key
		), ARRAY_A );
	}
	
	/**
	 * Get system variable by key
	 *
	 * @param string $key Variable key
	 * @return array|false
	 */
	public function get_system_variable_by_key( string $key ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE variable_key = %s AND is_system = 1",
			$key
		), ARRAY_A );
	}
	
	/**
	 * Create variable
	 *
	 * @param array $data Variable data
	 * @return int|WP_Error Variable ID or error
	 */
	public function create_variable( array $data ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		
		$wpdb->insert( $table_name, array(
			'plugin_network_id' => $data['plugin_network_id'] ?? null,
			'variable_key' => sanitize_key( $data['key'] ),
			'label' => sanitize_text_field( $data['label'] ),
			'field_type' => sanitize_text_field( $data['field_type'] ),
			'field_options' => isset( $data['field_options'] ) ? wp_json_encode( $data['field_options'] ) : null,
			'is_required' => isset( $data['is_required'] ) ? (int) $data['is_required'] : 0,
			'default_value' => $data['default_value'] ?? null,
			'default_visible' => isset( $data['default_visible'] ) ? (int) $data['default_visible'] : 1,
			'is_system' => 0,
			'sort_order' => $data['sort_order'] ?? 0,
			'created_at' => current_time( 'mysql' ),
		) );
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update variable
	 *
	 * @param int $id Variable ID
	 * @param array $data Variable data
	 * @return bool
	 */
	public function update_variable( int $id, array $data ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		if ( isset( $data['key'] ) ) {
			$data['variable_key'] = sanitize_key( $data['key'] );
			unset( $data['key'] );
		}
		if ( isset( $data['field_options'] ) && is_array( $data['field_options'] ) ) {
			$data['field_options'] = wp_json_encode( $data['field_options'] );
		}
		
		return (bool) $wpdb->update( $table_name, $data, array( 'id' => $id ) );
	}
	
	/**
	 * Delete variable
	 *
	 * @param int $id Variable ID
	 * @return bool
	 */
	public function delete_variable( int $id ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		
		return (bool) $wpdb->delete( $table_name, array( 'id' => $id ) );
	}
	
	/**
	 * Get default values for template variables
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_template_variable_defaults( int $template_id ): array {
		global $wpdb;
		
		$tv_table = $wpdb->prefix . 'certificate_manager_template_variables';
		
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT v.variable_key, tv.default_value, tv.is_required
			 FROM {$tv_table} tv
			 JOIN {$wpdb->prefix}certificate_manager_variables v ON tv.variable_id = v.id
			 WHERE tv.template_id = %d",
			$template_id
		), ARRAY_A );
		
		$defaults = array();
		foreach ( $results as $row ) {
			$defaults[ $row['variable_key'] ] = array(
				'value' => $row['default_value'],
				'required' => (bool) $row['is_required'],
			);
		}
		
		return $defaults;
	}
}
