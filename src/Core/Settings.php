<?php
/**
 * Certificate Manager Settings
 *
 * @package CertificateManager
 */

namespace CertificateManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings manager
 */
class Settings {
	
	/**
	 * Settings key
	 *
	 * @var string
	 */
	const SETTINGS_KEY = 'certificate_manager_settings';
	
	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public function get_all(): array {
		return get_option( self::SETTINGS_KEY, $this->get_defaults() );
	}
	
	/**
	 * Get single setting
	 *
	 * @param string $key Setting key
	 * @param mixed $default Default value
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->get_all();
		return $settings[ $key ] ?? $default;
	}
	
	/**
	 * Update setting
	 *
	 * @param string $key Setting key
	 * @param mixed $value Setting value
	 */
	public function update( string $key, $value ) {
		$settings = $this->get_all();
		$settings[ $key ] = $value;
		update_option( self::SETTINGS_KEY, $settings );
	}
	
	/**
	 * Update multiple settings
	 *
	 * @param array $settings Settings array
	 */
	public function update_batch( array $settings ) {
		$existing = $this->get_all();
		$updated = array_merge( $existing, $settings );
		update_option( self::SETTINGS_KEY, $updated );
	}
	
	/**
	 * Get defaults
	 *
	 * @return array
	 */
	private function get_defaults(): array {
		return array(
			'pixabay_api_key' => '',
			'verification_page_id' => 0,
			'verification_page_url' => '',
			'verification_style' => 'editorial',
			'verification_accent' => 'indigo',
			'default_expiry_enabled' => false,
			'default_expiry_quantity' => 1,
			'default_expiry_unit' => 'years',
			'expiry_renewal_notice_days' => 30,
			'default_number_format' => '{year}{month}{day}-{seq}',
			'download_token_lifetime' => 900,
			'rate_limit_requests' => 10,
			'rate_limit_window' => 60,
			'audit_retention_enabled' => true,
			'audit_retention_days' => 365,
			'operational_retention_enabled' => true,
			'operational_retention_days' => 90,
			'default_email_enabled' => true,
			'default_email_subject' => __( 'Your Certificate is Ready', 'certificate-manager' ),
			'default_email_body' => __( 'Hello {{recipient_name}},', 'certificate-manager' ) . "\n\n" . __( 'Your certificate has been issued.', 'certificate-manager' ) . "\n\n" . __( 'Verify it here:', 'certificate-manager' ) . "\n{{verification_url}}",
			'default_reminder_enabled' => false,
			'default_reminder_days_before' => 7,
			'default_public_download_enabled' => false,
			'default_verification_count_visible' => false,
			'default_email_pdf_attached' => true,
			'default_email_download_link' => false,
			'default_email_verification_link' => true,
			'default_pdf_filename_format' => 'Certificate-{{certificate_number}}-{{recipient_name}}',
			'default_template_id' => 0,
			'default_template_orientation' => 'portrait',
			'verifiable_credentials_enabled' => true,
			'verifiable_credentials_include_recipient_name' => false,
			'wallet_issuance_enabled' => true,
			'wallet_link_delivery_mode' => 'email',
			'certificate_issuance_delivery_mode' => 'webhook',
			'multisite_network_templates_enabled' => false,
			'keep_data_on_uninstall' => true,
			'expiry_reminder_hours' => array( 168, 72, 24, 0 ), // 7 days, 3 days, 1 day, on expiry
			'public_download_enabled' => false,
		);
	}

	public function get_verification_base_url(): string {
		return add_query_arg( 'cm_verify', '1', home_url( '/' ) );
	}

	public function get_verification_destination_url(): string {
		$custom_url = esc_url_raw( (string) $this->get( 'verification_page_url', '' ) );
		if ( $custom_url && wp_http_validate_url( $custom_url ) ) {
			return $custom_url;
		}

		$page_id = absint( $this->get( 'verification_page_id', 0 ) );
		if ( ! $page_id ) {
			$page_id = absint( get_option( 'certificate_manager_verification_page_id', 0 ) );
		}

		return $page_id && get_post_status( $page_id ) ? get_permalink( $page_id ) : $this->get_verification_base_url();
	}
	
	/**
	 * Get template-specific settings
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_template_settings( int $template_id ): array {
		$defaults = $this->get_defaults();
		
		$template_settings = get_post_meta( $template_id, 'certificate_manager_template_settings', true );
		if ( ! $template_settings ) {
			return $defaults;
		}
		
		return array_merge( $defaults, $template_settings );
	}
	
	/**
	 * Update template settings
	 *
	 * @param int $template_id Template ID
	 * @param array $settings Settings to update
	 */
	public function update_template_settings( int $template_id, array $settings ) {
		$current = get_post_meta( $template_id, 'certificate_manager_template_settings', true );
		if ( ! $current ) {
			$current = array();
		}
		$updated = array_merge( $current, $settings );
		update_post_meta( $template_id, 'certificate_manager_template_settings', $updated );
	}
	
	/**
	 * Get expiry settings for template
	 *
	 * @param int $template_id Template ID
	 * @return array
	 */
	public function get_expiry_settings( int $template_id ): array {
		$settings = $this->get_template_settings( $template_id );
		
		return array(
			'enabled' => $settings['default_expiry_enabled'] ?? false,
			'quantity' => $settings['default_expiry_quantity'] ?? 1,
			'unit' => $settings['default_expiry_unit'] ?? 'years',
		);
	}
	
	/**
	 * Get number format for template
	 *
	 * @param int $template_id Template ID
	 * @return string
	 */
	public function get_number_format( int $template_id ): string {
		$settings = $this->get_template_settings( $template_id );
		return $settings['default_number_format'] ?? $this->get_all()['default_number_format'] ?? '{year}{month}{day}-{seq}';
	}
	
	/**
	 * Get certificate sequence ID for template
	 *
	 * @param int $template_id Template ID
	 * @return int
	 */
	public function get_sequence_id( int $template_id ): int {
		$settings = $this->get_template_settings( $template_id );
		return $settings['certificate_sequence_id'] ?? 0;
	}
}
