<?php
/**
 * Plugin Name: Certificate Manager
 * Plugin URI: https://github.com/cookie-repos/Certificate-Manager-WP
 * Description: Design, issue, manage and verify certificates from WordPress.
 * Version: 1.4.3
 * Author: Adam Cooke
 * Author URI: https://cookieapps.uk
 * License: GPL v2 or later
 * Text Domain: certificate-manager
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CERTIFICATE_MANAGER_VERSION', '1.4.3' );
define( 'CERTIFICATE_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
define( 'CERTIFICATE_MANAGER_URL', plugin_dir_url( __FILE__ ) );
define( 'CERTIFICATE_MANAGER_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', 'certificate_manager_php_version_notice' );
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '6.0', '<' ) ) {
	add_action( 'admin_notices', 'certificate_manager_wp_version_notice' );
	return;
}

if ( file_exists( CERTIFICATE_MANAGER_PATH . 'vendor/autoload.php' ) ) {
	require_once CERTIFICATE_MANAGER_PATH . 'vendor/autoload.php';
}

require_once CERTIFICATE_MANAGER_PATH . 'src/bootstrap.php';

register_activation_hook( __FILE__, 'certificate_manager_activate' );
register_deactivation_hook( __FILE__, 'certificate_manager_deactivate' );
register_uninstall_hook( __FILE__, 'certificate_manager_uninstall' );
add_action( 'init', 'certificate_manager_init', 0 );

function certificate_manager_activate() {
	certificate_manager_bootstrap()->activate();
}

function certificate_manager_deactivate() {
	certificate_manager_bootstrap()->deactivate();
}

function certificate_manager_uninstall() {
	certificate_manager_bootstrap()->uninstall();
}

function certificate_manager_init() {
	certificate_manager_bootstrap()->init();
}

function certificate_manager_bootstrap() {
	return \CertificateManager\Core\Bootstrap::instance();
}

function certificate_manager_php_version_notice() {
	?>
	<div class="notice notice-error"><p><?php esc_html_e( 'Certificate Manager requires PHP 7.4 or later.', 'certificate-manager' ); ?></p></div>
	<?php
}

function certificate_manager_wp_version_notice() {
	?>
	<div class="notice notice-error"><p><?php esc_html_e( 'Certificate Manager requires WordPress 6.0 or later.', 'certificate-manager' ); ?></p></div>
	<?php
}
