<?php
/**
 * Certificate Manager Capabilities
 *
 * @package CertificateManager
 */

namespace CertificateManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities manager
 */
class Capabilities {
	
	/**
	 * Capability base
	 *
	 * @var string
	 */
	const CAP_BASE = 'cm_';
	
	/**
	 * Plugin capabilities
	 *
	 * @var array
	 */
	private $capabilities = array();
	
	/**
	 * Initialize capabilities
	 */
	public function init() {
		// Define capabilities
		$this->capabilities = array(
			'view_certificates' => array(
				'label' => __( 'View Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'issue_certificates' => array(
				'label' => __( 'Issue Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'edit_certificates' => array(
				'label' => __( 'Edit Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'revoke_certificates' => array(
				'label' => __( 'Revoke Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'reinstate_certificates' => array(
				'label' => __( 'Reinstate Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'replace_certificates' => array(
				'label' => __( 'Replace Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'delete_certificates' => array(
				'label' => __( 'Delete Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'export_certificates' => array(
				'label' => __( 'Export Certificates', 'certificate-manager' ),
				'group' => 'certificates',
			),
			'view_template' => array(
				'label' => __( 'View Templates', 'certificate-manager' ),
				'group' => 'templates',
			),
			'edit_templates' => array(
				'label' => __( 'Edit Templates', 'certificate-manager' ),
				'group' => 'templates',
			),
			'delete_templates' => array(
				'label' => __( 'Delete Templates', 'certificate-manager' ),
				'group' => 'templates',
			),
			'publish_templates' => array(
				'label' => __( 'Publish Templates', 'certificate-manager' ),
				'group' => 'templates',
			),
			'manage_templates' => array(
				'label' => __( 'Manage Templates', 'certificate-manager' ),
				'group' => 'templates',
			),
			'manage_variables' => array(
				'label' => __( 'Manage Variables', 'certificate-manager' ),
				'group' => 'variables',
			),
			'manage_integrations' => array(
				'label' => __( 'Manage Integrations', 'certificate-manager' ),
				'group' => 'integrations',
			),
			'manage_settings' => array(
				'label' => __( 'Manage Settings', 'certificate-manager' ),
				'group' => 'settings',
			),
		);
		
		// Register capabilities
		$this->register_capabilities();
		
		// Capabilities are assigned directly to roles above. Do not filter
		// user_has_cap here: doing so would run inside every WordPress capability
		// check and must never call user_can() or WP_User::has_cap() recursively.
	}
	
	/**
	 * Get all capabilities
	 *
	 * @return array
	 */
	public function get_all(): array {
		return $this->capabilities;
	}
	
	/**
	 * Get capability base
	 *
	 * @return string
	 */
	public function get_base(): string {
		return self::CAP_BASE;
	}
	
	/**
	 * Register capabilities
	 */
	private function register_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( $this->capabilities as $cap_key => $cap_data ) {
				$role->add_cap( self::CAP_BASE . $cap_key );
			}
		}
		
		// Create Certificate Manager role if it doesn't exist
		if ( ! get_role( 'certificate_manager' ) ) {
			add_role(
				'certificate_manager',
				__( 'Certificate Manager', 'certificate-manager' ),
				array(
					'read' => true,
					self::CAP_BASE . 'view_certificates' => true,
					self::CAP_BASE . 'issue_certificates' => true,
					self::CAP_BASE . 'edit_certificates' => true,
					self::CAP_BASE . 'revoke_certificates' => true,
					self::CAP_BASE . 'reinstate_certificates' => true,
					self::CAP_BASE . 'replace_certificates' => true,
					self::CAP_BASE . 'delete_certificates' => true,
					self::CAP_BASE . 'export_certificates' => true,
					self::CAP_BASE . 'view_template' => true,
					self::CAP_BASE . 'edit_templates' => true,
					self::CAP_BASE . 'delete_templates' => true,
					self::CAP_BASE . 'publish_templates' => true,
					self::CAP_BASE . 'manage_templates' => true,
					self::CAP_BASE . 'manage_variables' => true,
					self::CAP_BASE . 'manage_integrations' => true,
				)
			);
		}

		if ( ! get_role( 'certificate_issuer' ) ) {
			add_role(
				'certificate_issuer',
				__( 'Certificate Issuer', 'certificate-manager' ),
				array(
					'read' => true,
					self::CAP_BASE . 'view_certificates' => true,
					self::CAP_BASE . 'issue_certificates' => true,
				)
			);
		}
	}
	
	/**
	 * Filter user capabilities
	 *
	 * @param array $allcaps All capabilities
	 * @param array $caps Required primitive capabilities
	 * @param array $args Capability check arguments
	 * @param mixed $user WordPress user object
	 * @return array
	 */
	public function filter_user_capabilities( array $allcaps, array $caps, array $args, $user ): array {
		return $allcaps;
	}
	
	/**
	 * Check if capability is certificate-related
	 *
	 * @param string $cap Capability name
	 * @return bool
	 */
	private function is_certificate_capability( string $cap ): bool {
		return strpos( $cap, self::CAP_BASE ) === 0 && strpos( $cap, 'cm_certificate_' ) !== false;
	}
	
	/**
	 * Check if capability is template-related
	 *
	 * @param string $cap Capability name
	 * @return bool
	 */
	private function is_template_capability( string $cap ): bool {
		return strpos( $cap, self::CAP_BASE ) === 0 && strpos( $cap, 'cm_template_' ) !== false;
	}
	
	/**
	 * Check if capability is variable-related
	 *
	 * @param string $cap Capability name
	 * @return bool
	 */
	private function is_variable_capability( string $cap ): bool {
		return strpos( $cap, self::CAP_BASE ) === 0 && strpos( $cap, 'cm_variable_' ) !== false;
	}
	
	/**
	 * Check if capability is integration-related
	 *
	 * @param string $cap Capability name
	 * @return bool
	 */
	private function is_integration_capability( string $cap ): bool {
		return strpos( $cap, self::CAP_BASE ) === 0 && strpos( $cap, 'cm_integration_' ) !== false;
	}
	
	/**
	 * Check if capability is settings-related
	 *
	 * @param string $cap Capability name
	 * @return bool
	 */
	private function is_settings_capability( string $cap ): bool {
		return strpos( $cap, self::CAP_BASE ) === 0 && strpos( $cap, 'cm_settings_' ) !== false;
	}
	
	/**
	 * Check certificate capability
	 *
	 * @param string $cap Capability name
	 * @param int $user_id User ID
	 * @param array $args Extra arguments
	 * @return bool
	 */
	private function check_certificate_capability( string $cap, int $user_id, array $args ): bool {
		$user = wp_get_current_user();
		
		if ( ! $user->exists() ) {
			return false;
		}
		
		if ( $user->has_cap( 'administrator' ) ) {
			return true;
		}
		
		// Check for specific certificate capability
		$base_cap = str_replace( 'cm_certificate_', '', $cap );
		
		switch ( $base_cap ) {
			case 'view':
			case 'edit':
			case 'revoke':
			case 'reinstate':
			case 'replace':
			case 'delete':
			case 'export':
				return $user->has_cap( self::CAP_BASE . $base_cap . '_certificates' );
		}
		
		return false;
	}
	
	/**
	 * Check template capability
	 *
	 * @param string $cap Capability name
	 * @param int $user_id User ID
	 * @param array $args Extra arguments
	 * @return bool
	 */
	private function check_template_capability( string $cap, int $user_id, array $args ): bool {
		$user = wp_get_current_user();
		
		if ( ! $user->exists() ) {
			return false;
		}
		
		if ( $user->has_cap( 'administrator' ) ) {
			return true;
		}
		
		$base_cap = str_replace( 'cm_template_', '', $cap );
		
		switch ( $base_cap ) {
			case 'view':
			case 'edit':
			case 'delete':
			case 'publish':
			case 'manage':
				return $user->has_cap( self::CAP_BASE . 'manage_' . $base_cap );
		}
		
		return false;
	}
	
	/**
	 * Check if user can issue certificates
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_issue_certificates( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'issue_certificates' );
	}
	
	/**
	 * Check if user can view certificates
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_view_certificates( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'view_certificates' );
	}
	
	/**
	 * Check if user can edit certificates
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_edit_certificates( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'edit_certificates' );
	}
	
	/**
	 * Check if user can revoke certificates
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_revoke_certificates( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'revoke_certificates' );
	}
	
	/**
	 * Check if user can delete certificates
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_delete_certificates( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'delete_certificates' );
	}
	
	/**
	 * Check if user can export certificates
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_export_certificates( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'export_certificates' );
	}
	
	/**
	 * Check if user can manage templates
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_manage_templates( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'manage_templates' );
	}
	
	/**
	 * Check if user can manage variables
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_manage_variables( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'manage_variables' );
	}
	
	/**
	 * Check if user can manage integrations
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_manage_integrations( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'manage_integrations' );
	}
	
	/**
	 * Check if user can manage settings
	 *
	 * @param int $user_id User ID
	 * @return bool
	 */
	public function can_manage_settings( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		
		return $user->has_cap( self::CAP_BASE . 'manage_settings' );
	}
}
