<?php
/**
 * Certificate Manager Template Repository
 *
 * @package CertificateManager
 */

namespace CertificateManager\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template repository
 */
class TemplateRepository {
	
	/**
	 * Get template by ID
	 *
	 * @param int $id Template ID
	 * @return array|false
	 */
	public function get_template( int $id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT *, last_version_id AS published_version_id FROM {$table_name} WHERE id = %d AND is_deleted = 0",
			$id
		), ARRAY_A );
	}
	
	/**
	 * Get template with published version
	 *
	 * @param int $id Template ID
	 * @return array|false
	 */
	public function get_template_with_version( int $id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		
		$template = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.*, v.id as published_version_id, v.version_number, v.elements, v.conditions, v.repeating_blocks, v.variables, v.qr_config, v.background_config
			 FROM {$table_name} t
			 LEFT JOIN {$wpdb->prefix}certificate_manager_template_versions v ON t.last_version_id = v.id
			 WHERE t.id = %d AND t.is_deleted = 0",
			$id
		), ARRAY_A );
		
		return $template;
	}
	
	/**
	 * Get template by slug
	 *
	 * @param string $slug Template slug
	 * @return array|false
	 */
	public function get_template_by_slug( string $slug ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE slug = %s AND is_deleted = 0",
			$slug
		), ARRAY_A );
	}
	
	/**
	 * Get all templates
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_templates( array $args = array() ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		
		// Parse arguments
		$page = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$offset = ( $page - 1 ) * $per_page;
		
		$where = array( 't.is_deleted = 0' );
		
		// Filter by archived status
		if ( isset( $args['archived'] ) ) {
			$where[] = 't.is_archived = ' . ( $args['archived'] ? 1 : 0 );
		}
		
		// Filter by status
		if ( ! empty( $args['status'] ) ) {
			if ( $args['status'] === 'draft' ) {
				$where[] = 't.last_version_id IS NULL';
			} else {
				$where[] = 't.last_version_id IS NOT NULL';
			}
		}
		
		// Build WHERE clause
		$where_sql = implode( ' AND ', $where );
		
		// Get total count
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} t WHERE {$where_sql}" );
		
		// Get templates
		$templates = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.*, v.id as version_id, v.version_number, v.elements, v.conditions, v.repeating_blocks
			 FROM {$table_name} t
			 LEFT JOIN {$wpdb->prefix}certificate_manager_template_versions v ON t.last_version_id = v.id
			 WHERE {$where_sql}
			 ORDER BY t.title ASC
			 LIMIT %d OFFSET %d",
			$per_page, $offset
		), ARRAY_A );
		
		return array(
			'data' => $templates,
			'total' => (int) $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}
	
	/**
	 * Get templates with tags
	 *
	 * @param array $tags Tags array
	 * @return array
	 */
	public function get_templates_by_tags( array $tags ): array {
		global $wpdb;
		
		$tt_table = $wpdb->prefix . 'certificate_manager_template_tags';
		
		$placeholders = array_map( function() { return '%s'; }, $tags );
		$in_clause = implode( ', ', $placeholders );
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT t.* FROM {$wpdb->prefix}certificate_manager_templates t
			 INNER JOIN {$tt_table} tt ON t.id = tt.template_id
			 WHERE tt.tag IN ({$in_clause})
			 AND t.is_deleted = 0",
			$tags
		), ARRAY_A );
	}
	
	/**
	 * Get template tags
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_template_tags( int $template_id ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_tags';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT tag FROM {$table_name} WHERE template_id = %d",
			$template_id
		), ARRAY_A );
	}
	
	/**
	 * Add tag to template
	 *
	 * @param int $template_id Template ID
	 * @param string $tag Tag
	 * @return bool
	 */
	public function add_template_tag( int $template_id, string $tag ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_tags';
		
		return (bool) $wpdb->insert( $table_name, array(
			'template_id' => $template_id,
			'tag' => sanitize_text_field( $tag ),
			'created_at' => current_time( 'mysql' ),
		) );
	}
	
	/**
	 * Remove tag from template
	 *
	 * @param int $template_id Template ID
	 * @param string $tag Tag
	 * @return bool
	 */
	public function remove_template_tag( int $template_id, string $tag ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_tags';
		
		return (bool) $wpdb->delete( $table_name, array(
			'template_id' => $template_id,
			'tag' => sanitize_text_field( $tag ),
		) );
	}
	
	/**
	 * Create template
	 *
	 * @param array $data Template data
	 * @return int|WP_Error Template ID or error
	 */
	public function create_template( array $data ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		$wp_post_id = isset( $data['wp_post_id'] ) ? (int) $data['wp_post_id'] : 0;

		if ( ! $wp_post_id ) {
			$wp_post_id = wp_insert_post(
				array(
					'post_type'   => 'cm_template',
					'post_title'  => $data['title'],
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $wp_post_id ) ) {
				return $wp_post_id;
			}
		}
		
		$inserted = $wpdb->insert( $table_name, array(
			'wp_post_id' => $wp_post_id,
			'title' => $data['title'],
			'slug' => sanitize_title( $data['title'] ),
			'description' => $data['description'] ?? null,
			'orientation' => $data['orientation'] ?? 'portrait',
			'page_width' => $data['page_width'] ?? 210.00,
			'page_height' => $data['page_height'] ?? 297.00,
			'elements' => $data['elements'] ?? '[]',
			'conditions' => $data['conditions'] ?? null,
			'repeating_blocks' => $data['repeating_blocks'] ?? null,
			'qr_config' => $data['qr_config'] ?? null,
			'background_config' => $data['background_config'] ?? null,
			'webhook_ids' => $data['webhook_ids'] ?? null,
			'is_archived' => $data['is_archived'] ?? 0,
			'is_deleted' => 0,
			'last_version_id' => null,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		) );

		if ( false === $inserted ) {
			wp_delete_post( $wp_post_id, true );
			return new \WP_Error( 'cm_template_create_failed', __( 'Unable to save the template.', 'certificate-manager' ) );
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Update template
	 *
	 * @param int $id Template ID
	 * @param array $data Template data
	 * @return bool
	 */
	public function update_template( int $id, array $data ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		$template = $this->get_template( $id );

		if ( ! $template ) {
			return false;
		}

		if ( isset( $data['title'] ) ) {
			$data['slug'] = sanitize_title( $data['title'] );
			if ( ! empty( $template['wp_post_id'] ) ) {
				wp_update_post( array(
					'ID' => (int) $template['wp_post_id'],
					'post_title' => $data['title'],
				) );
			}
		}

		$data['updated_at'] = current_time( 'mysql' );
		$result = $wpdb->update( $table_name, $data, array( 'id' => $id ) );

		return false !== $result;
	}
	
	/**
	 * Archive template
	 *
	 * @param int $id Template ID
	 * @return bool
	 */
	public function archive_template( int $id ): bool {
		return $this->update_template( $id, array( 'is_archived' => 1 ) );
	}
	
	/**
	 * Unarchive template
	 *
	 * @param int $id Template ID
	 * @return bool
	 */
	public function unarchive_template( int $id ): bool {
		return $this->update_template( $id, array( 'is_archived' => 0 ) );
	}
	
	/**
	 * Delete template (soft delete)
	 *
	 * @param int $id Template ID
	 * @return bool
	 */
	public function delete_template( int $id ): bool {
		return $this->update_template( $id, array( 'is_deleted' => 1 ) );
	}

	public function purge_all() {
		global $wpdb;

		$prefix = $wpdb->prefix . 'certificate_manager_';
		$templates_table = $prefix . 'templates';
		$template_posts = $wpdb->get_col( "SELECT wp_post_id FROM {$templates_table} WHERE wp_post_id > 0" );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$templates_table}" );
		$wpdb->query( 'START TRANSACTION' );
		$queries = array(
			"DELETE FROM {$prefix}template_tags",
			"DELETE FROM {$prefix}template_variables",
			"DELETE FROM {$prefix}template_versions",
			"DELETE FROM {$templates_table}",
		);
		foreach ( $queries as $query ) {
			if ( false === $wpdb->query( $query ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'template_purge_failed', __( 'The templates could not be cleared.', 'certificate-manager' ) );
			}
		}
		$wpdb->query( 'COMMIT' );
		foreach ( $template_posts as $post_id ) {
			wp_delete_post( absint( $post_id ), true );
		}

		return array( 'count' => $count );
	}
	
	/**
	 * Restore template
	 *
	 * @param int $id Template ID
	 * @return bool
	 */
	public function restore_template( int $id ): bool {
		return $this->update_template( $id, array( 'is_deleted' => 0 ) );
	}
	
	/**
	 * Create template version
	 *
	 * @param int $template_id Template ID
	 * @param array $data Version data
	 * @return int Version ID
	 */
	public function create_template_version( int $template_id, array $data ): int {
		global $wpdb;
		
		// Get current version number
		$version_table = $wpdb->prefix . 'certificate_manager_template_versions';
		
		$version_number = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(version_number), 0) + 1 FROM {$version_table} WHERE template_id = %d",
			$template_id
		) );
		
		// Insert new version
		$wpdb->insert( $version_table, array(
			'template_id' => $template_id,
			'version_number' => $version_number,
			'elements' => $data['elements'] ?? '[]',
			'conditions' => $data['conditions'] ?? null,
			'repeating_blocks' => $data['repeating_blocks'] ?? null,
			'variables' => $data['variables'] ?? null,
			'qr_config' => $data['qr_config'] ?? null,
			'background_config' => $data['background_config'] ?? null,
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
		) );
		
		$version_id = $wpdb->insert_id;
		
		// Update template's last version
		$template_table = $wpdb->prefix . 'certificate_manager_templates';
		$wpdb->update( $template_table, array(
			'last_version_id' => $version_id,
		), array( 'id' => $template_id ) );
		
		return $version_id;
	}
	
	/**
	 * Get template version
	 *
	 * @param int $version_id Version ID
	 * @return array|false
	 */
	public function get_template_version( int $version_id ) {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_versions';
		
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d",
			$version_id
		), ARRAY_A );
	}
	
	/**
	 * Get template versions
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_template_versions( int $template_id ): array {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_versions';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE template_id = %d ORDER BY version_number DESC",
			$template_id
		), ARRAY_A );
	}
	
	/**
	 * Get template variables
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_template_variables( int $template_id ): array {
		global $wpdb;
		
		$tv_table = $wpdb->prefix . 'certificate_manager_template_variables';
		$v_table = $wpdb->prefix . 'certificate_manager_variables';
		
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT v.id, v.variable_key, v.variable_key AS `key`, v.label, v.label AS variable_label, v.field_type, v.field_options,
				CASE WHEN v.is_required = 1 OR tv.is_required = 1 THEN 1 ELSE 0 END AS is_required, tv.default_value
			 FROM {$tv_table} tv
			 JOIN {$v_table} v ON tv.variable_id = v.id
			 WHERE tv.template_id = %d
			 ORDER BY tv.sort_order, v.sort_order",
			$template_id
		), ARRAY_A );
	}
	
	/**
	 * Add variable to template
	 *
	 * @param int $template_id Template ID
	 * @param int $variable_id Variable ID
	 * @param bool $is_required Is required
	 * @param string $default_value Default value
	 * @return bool
	 */
	public function add_template_variable( int $template_id, int $variable_id, bool $is_required = false, string $default_value = '' ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_variables';
		
		return (bool) $wpdb->insert( $table_name, array(
			'template_id' => $template_id,
			'variable_id' => $variable_id,
			'is_required' => $is_required ? 1 : 0,
			'default_value' => $default_value,
			'sort_order' => 0,
			'created_at' => current_time( 'mysql' ),
		) );
	}
	
	/**
	 * Remove variable from template
	 *
	 * @param int $template_id Template ID
	 * @param int $variable_id Variable ID
	 * @return bool
	 */
	public function remove_template_variable( int $template_id, int $variable_id ): bool {
		global $wpdb;
		
		$table_name = $wpdb->prefix . 'certificate_manager_template_variables';
		
		return (bool) $wpdb->delete( $table_name, array(
			'template_id' => $template_id,
			'variable_id' => $variable_id,
		) );
	}

	/**
	 * Synchronize the variables used by a template design.
	 *
	 * @param int   $template_id Template ID.
	 * @param array $variable_keys Variable keys found in the design.
	 * @return void
	 */
	public function sync_template_variables( int $template_id, array $variable_keys ) {
		global $wpdb;

		$link_table = $wpdb->prefix . 'certificate_manager_template_variables';
		$variable_table = $wpdb->prefix . 'certificate_manager_variables';
		$keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $variable_keys ) ) ) );
		$wpdb->delete( $link_table, array( 'template_id' => $template_id ), array( '%d' ) );

		foreach ( $keys as $sort_order => $key ) {
			$variable = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, is_required, default_value FROM {$variable_table} WHERE variable_key = %s",
				$key
			), ARRAY_A );

			if ( ! $variable ) {
				continue;
			}

			$wpdb->insert( $link_table, array(
				'template_id' => $template_id,
				'variable_id' => (int) $variable['id'],
				'is_required' => (int) $variable['is_required'],
				'default_value' => $variable['default_value'],
				'sort_order' => $sort_order,
				'created_at' => current_time( 'mysql' ),
			) );
		}
	}
	
	/**
	 * Count certificates for template
	 *
	 * @param int $template_id Template ID
	 * @return int
	 */
	public function count_template_certificates( int $template_id ): int {
		global $wpdb;
		
		$cert_table = $wpdb->prefix . 'certificate_manager_certificates';
		
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$cert_table} WHERE template_id = %d",
			$template_id
		) );
	}
}
