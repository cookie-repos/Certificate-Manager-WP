<?php
/**
 * Certificate Manager Bootstrap
 *
 * @package CertificateManager
 */

namespace CertificateManager\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logging helper for activation diagnostics
 *
 * @param string $message  Log message.
 * @param string $log_file Optional log file path (not used, kept for backward compatibility).
 */
function cm_log( $message, $log_file = '' ) {
	// Use WordPress debug logging when WP_DEBUG is enabled.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[Certificate Manager] ' . $message );
	}
}



/**
 * Main Bootstrap class
 */
class Bootstrap {
	
	/**
	 * Single instance
	 *
	 * @var Bootstrap|null
	 */
	private static $instance = null;
	
	/**
	 * Plugin version
	 *
	 * @var string
	 */
	private $version = '1.4.3';
	
	/**
	 * Database schema version
	 *
	 * @var int
	 */
	private $schema_version = 8;

	/**
	 * Runtime components used by WordPress callbacks.
	 *
	 * @var array
	 */
	private $components = array();
	
	/**
	 * Constructor
	 */
	private function __construct() {}
	
	/**
	 * Get singleton instance
	 *
	 * @return Bootstrap
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Activate plugin
	 */
	public function activate() {
		$log_file = WP_CONTENT_DIR . '/cm-activation-log.txt';
		
		cm_log( 'Bootstrap::activate() - Starting', $log_file );
		
		// Load required files for activation
		cm_log( 'Loading files for activation...', $log_file );
		try {
			$this->include_files_for_activation();
			cm_log( 'Files loaded for activation', $log_file );
		} catch ( \Throwable $e ) {
			cm_log( 'File loading error: ' . $e->getMessage(), $log_file );
		}
		
		// Run database migrations
		cm_log( 'Running database migrations...', $log_file );
		try {
			$migration = new \CertificateManager\Database\MigrationManager( $this->version, $this->schema_version );
			$migration->run_migrations();
			cm_log( 'Migrations completed', $log_file );
		} catch ( \Throwable $e ) {
			cm_log( 'Migrations error: ' . $e->getMessage(), $log_file );
		}
		
		// Set default settings
		cm_log( 'Setting default settings...', $log_file );
		try {
			$this->set_default_settings();
			cm_log( 'Default settings set', $log_file );
		} catch ( \Throwable $e ) {
			cm_log( 'Settings error: ' . $e->getMessage(), $log_file );
		}
		
		// Schedule any pending jobs
		cm_log( 'Scheduling jobs...', $log_file );
		try {
			$this->schedule_jobs();
			cm_log( 'Jobs scheduled', $log_file );
		} catch ( \Throwable $e ) {
			cm_log( 'Scheduling error: ' . $e->getMessage(), $log_file );
		}
		
		// Log activation
		cm_log( 'Logging activation...', $log_file );
		try {
			$this->log_event( 'plugin_activated', array(
				'version' => $this->version,
				'php_version' => PHP_VERSION,
				'wp_version' => get_bloginfo( 'version' )
			) );
			cm_log( 'Activation logged', $log_file );
		} catch ( \Throwable $e ) {
			cm_log( 'Log event error: ' . $e->getMessage(), $log_file );
		}
		
		cm_log( 'Bootstrap::activate() - Complete', $log_file );
	}
	
	/**
	 * Deactivate plugin
	 */
	public function deactivate() {
		$log_file = WP_CONTENT_DIR . '/cm-activation-log.txt';
		
		cm_log( 'Bootstrap::deactivate() - Starting', $log_file );
		
		// Clear scheduled jobs
		cm_log( 'Clearing scheduled jobs...', $log_file );
		try {
			$this->clear_scheduled_jobs();
			cm_log( 'Scheduled jobs cleared', $log_file );
		} catch ( \Throwable $e ) {
			cm_log( 'Clear jobs error: ' . $e->getMessage(), $log_file );
		}
		
		// Log deactivation
		cm_log( 'Logging deactivation...', $log_file );
		try {
			$this->log_event( 'plugin_deactivated', array(
				'version' => $this->version
			) );
			cm_log( 'Deactivation logged', $log_file );
		} catch ( \Throwable $e ) {
			cm_log( 'Log event error: ' . $e->getMessage(), $log_file );
		}
		
		cm_log( 'Bootstrap::deactivate() - Complete', $log_file );
	}
	
	/**
	 * Uninstall plugin
	 */
	public function uninstall() {
		// Check if user wants to keep data
		$keep_data = get_option( 'certificate_manager_keep_data_on_uninstall', true );
		
		if ( $keep_data ) {
			// Just clear options but keep tables
			delete_option( 'certificate_manager_version' );
			delete_option( 'certificate_manager_schema_version' );
			delete_option( 'certificate_manager_settings' );
			delete_option( 'certificate_manager_templates' );
			delete_option( 'certificate_manager_issuances' );
			delete_option( 'certificate_manager_logs' );
		} else {
			// Drop tables and delete all data
			$migration = new \CertificateManager\Database\MigrationManager( $this->version, $this->schema_version );
			$migration->drop_tables();
			
			// Delete all options
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall cleanup, no caching needed.
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'certificate_manager_%'" );
		}
	}
	
	/**
	 * Initialize plugin
	 */
	public function init() {
		// Register post types and taxonomies
		try {
			$this->register_post_types();
		} catch ( \Throwable $e ) {
			// Silently fail registration errors to avoid fatal errors on init
		}

		// Include all classes
		try {
			$this->include_files();
		} catch ( \Throwable $e ) {
			// Silently fail include errors
		}

		if ( (int) get_option( 'certificate_manager_schema_version', 0 ) < $this->schema_version ) {
			$migration = new \CertificateManager\Database\MigrationManager( $this->version, $this->schema_version );
			$migration->run_migrations();
		}

		// Initialize components
		try {
			$this->initialize_components();
		} catch ( \Throwable $e ) {
			// Silently fail component init errors
		}

		// Hook into WordPress
		try {
			$this->hook_into_wordpress();
		} catch ( \Throwable $e ) {
			// Silently fail hook registration errors
		}
	}
	
	/**
	 * Load text domain
	 */
	private function load_textdomain() {
		// WordPress automatically loads translations for plugins hosted on WordPress.org since version 4.6.
		// Manual loading is no longer needed.
	}
	
	/**
	 * Register post types
	 */
	private function register_post_types() {
		// Certificate template post type
		register_post_type( 'cm_template', array(
			'label'  => __( 'Certificate Templates', 'certificate-manager' ),
			'public' => false,
			'show_ui' => false,
			'rewrite' => false,
			'query_var' => false,
			'capability_type' => 'cm_template',
			'map_meta_cap' => true,
			'supports' => array( 'title', 'author' ),
		) );
		
		// Certificate post type
		register_post_type( 'cm_certificate', array(
			'label'  => __( 'Certificates', 'certificate-manager' ),
			'public' => false,
			'show_ui' => false,
			'rewrite' => false,
			'query_var' => false,
			'capability_type' => 'cm_certificate',
			'map_meta_cap' => true,
			'supports' => array( 'title', 'author', 'custom-fields' ),
		) );
	}
	
	/**
	 * Include files needed for activation only
	 */
	private function include_files_for_activation() {
		$log_file = WP_CONTENT_DIR . '/cm-activation-log.txt';
		
		cm_log( 'include_files_for_activation() - Starting', $log_file );
		
		// Database files for migrations
		cm_log( 'Loading Database files for activation...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Database/Schema.php';
		cm_log( 'Schema.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Database/MigrationManager.php';
		cm_log( 'MigrationManager.php loaded', $log_file );
		
		// Audit repository for logging activation
		cm_log( 'Loading Audit repository for activation logging...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/AuditRepository.php';
		cm_log( 'AuditRepository.php loaded', $log_file );
		
		cm_log( 'include_files_for_activation() - Complete', $log_file );
	}
	
	/**
	 * Include required files
	 */
	private function include_files() {
		$log_file = WP_CONTENT_DIR . '/cm-activation-log.txt';
		
		cm_log( 'include_files() - Starting', $log_file );
		
		// Core
		cm_log( 'Loading Core files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Core/Settings.php';
		cm_log( 'Settings.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Core/Capabilities.php';
		cm_log( 'Capabilities.php loaded', $log_file );
		
		// Database
		cm_log( 'Loading Database files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Database/Schema.php';
		cm_log( 'Schema.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Database/MigrationManager.php';
		cm_log( 'MigrationManager.php loaded', $log_file );
		
		// Repositories
		cm_log( 'Loading Repositories files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/CertificateRepository.php';
		cm_log( 'CertificateRepository.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/TemplateRepository.php';
		cm_log( 'TemplateRepository.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/VariableRepository.php';
		cm_log( 'VariableRepository.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/SequenceRepository.php';
		cm_log( 'SequenceRepository.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/AuditRepository.php';
		cm_log( 'AuditRepository.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/LogRepository.php';
		cm_log( 'LogRepository.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/WebhookRepository.php';
		cm_log( 'WebhookRepository.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Repositories/APIKeyRepository.php';
		cm_log( 'APIKeyRepository.php loaded', $log_file );
		
		// Services
		cm_log( 'Loading Services files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/IssuanceService.php';
		cm_log( 'IssuanceService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/RenderingService.php';
		cm_log( 'RenderingService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/VerificationService.php';
		cm_log( 'VerificationService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/EmailService.php';
		cm_log( 'EmailService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/QRService.php';
		cm_log( 'QRService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/DesignerService.php';
		cm_log( 'DesignerService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/CSVService.php';
		cm_log( 'CSVService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/WebhookService.php';
		cm_log( 'WebhookService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/IdempotencyService.php';
		cm_log( 'IdempotencyService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/SchedulingService.php';
		cm_log( 'SchedulingService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/RetentionPolicyService.php';
		cm_log( 'RetentionPolicyService.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Services/VerifiableCredentialService.php';
		cm_log( 'VerifiableCredentialService.php loaded', $log_file );
		
		// QR library
		cm_log( 'Loading QR library...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'lib/QRCode/qrlib.php';
		cm_log( 'qrlib.php loaded', $log_file );
		
		// mPDF library
		if ( file_exists( CERTIFICATE_MANAGER_PATH . 'vendor/autoload.php' ) ) {
			cm_log( 'Loading mPDF autoloader...', $log_file );
			require_once CERTIFICATE_MANAGER_PATH . 'vendor/autoload.php';
			cm_log( 'mPDF autoloader loaded', $log_file );
		}
		
		// Admin
		cm_log( 'Loading Admin files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/Dashboard.php';
		cm_log( 'Dashboard.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/TemplatesAdmin.php';
		cm_log( 'TemplatesAdmin.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/CertificatesAdmin.php';
		cm_log( 'CertificatesAdmin.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/VariablesAdmin.php';
		cm_log( 'VariablesAdmin.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/SettingsAdmin.php';
		cm_log( 'SettingsAdmin.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/LogsAdmin.php';
		cm_log( 'LogsAdmin.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/SystemStatus.php';
		cm_log( 'SystemStatus.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Admin/MultisiteAdmin.php';
		cm_log( 'MultisiteAdmin.php loaded', $log_file );
		
		// API
		cm_log( 'Loading API files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/API/RESTController.php';
		cm_log( 'RESTController.php loaded', $log_file );
		
		// Email
		cm_log( 'Loading Email files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Email/EmailTemplate.php';
		cm_log( 'EmailTemplate.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Email/EmailQueue.php';
		cm_log( 'EmailQueue.php loaded', $log_file );
		
		// Webhooks
		cm_log( 'Loading Webhooks files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Webhooks/WebhookDispatcher.php';
		cm_log( 'WebhookDispatcher.php loaded', $log_file );
		
		// Designer frontend
		cm_log( 'Loading Designer files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Designer/Controller.php';
		cm_log( 'Controller.php loaded', $log_file );
		
		// Verification frontend
		cm_log( 'Loading Verification files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Verification/Shortcode.php';
		cm_log( 'Shortcode.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Verification/ViewController.php';
		cm_log( 'ViewController.php loaded', $log_file );
		
		// Multisite
		cm_log( 'Loading Multisite files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Multisite/NetworkTemplates.php';
		cm_log( 'NetworkTemplates.php loaded', $log_file );
		
		// Audit
		cm_log( 'Loading Audit files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/Audit/AuditLogger.php';
		cm_log( 'AuditLogger.php loaded', $log_file );
		
		// CSV
		cm_log( 'Loading CSV files...', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/CSV/Importer.php';
		cm_log( 'Importer.php loaded', $log_file );
		require_once CERTIFICATE_MANAGER_PATH . 'src/CSV/Exporter.php';
		cm_log( 'Exporter.php loaded', $log_file );
		
		cm_log( 'include_files() - Complete', $log_file );
	}
	
	/**
	 * Initialize components
	 */
	private function initialize_components() {
		// Initialize settings
		$settings = new Settings();
		
		// Initialize capabilities
		$capabilities = new Capabilities();
		$capabilities->init();
		
		// Initialize repositories
		$repo = new \CertificateManager\Repositories\CertificateRepository();
		$template_repo = new \CertificateManager\Repositories\TemplateRepository();
		$variable_repo = new \CertificateManager\Repositories\VariableRepository();
		$sequence_repo = new \CertificateManager\Repositories\SequenceRepository();
		$audit_repo = new \CertificateManager\Repositories\AuditRepository();
		$log_repo = new \CertificateManager\Repositories\LogRepository();
		$webhook_repo = new \CertificateManager\Repositories\WebhookRepository();
		$api_key_repo = new \CertificateManager\Repositories\APIKeyRepository();
		
		// Initialize services
		$issuance_service = new \CertificateManager\Services\IssuanceService( $template_repo, $sequence_repo, $audit_repo, $log_repo );
		$rendering_service = new \CertificateManager\Services\RenderingService( $issuance_service );
		$verification_service = new \CertificateManager\Services\VerificationService( $repo );
		$qr_service = new \CertificateManager\Services\QRService();
		$designer_service = new \CertificateManager\Services\DesignerService( $template_repo );
		$csv_service = new \CertificateManager\Services\CSVService( $issuance_service, $template_repo );
		$webhook_service = new \CertificateManager\Services\WebhookService( $webhook_repo, $log_repo );
		$email_service = new \CertificateManager\Services\EmailService( $log_repo );
		$idempotency_service = new \CertificateManager\Services\IdempotencyService();
		$scheduling_service = new \CertificateManager\Services\SchedulingService( $issuance_service, $log_repo );
		$retention_service = new \CertificateManager\Services\RetentionPolicyService( $audit_repo, $log_repo );
		$verifiable_credential_service = new \CertificateManager\Services\VerifiableCredentialService( $repo, $template_repo, $settings );
		
		// Initialize admin components
		$dashboard = new \CertificateManager\Admin\Dashboard( $repo, $settings );
		$templates_admin = new \CertificateManager\Admin\TemplatesAdmin( $template_repo, $designer_service, $settings, $variable_repo, $webhook_repo );
		$certificates_admin = new \CertificateManager\Admin\CertificatesAdmin( $repo, $issuance_service, $settings, $template_repo, $rendering_service, $webhook_service, $webhook_repo );
		$variables_admin = new \CertificateManager\Admin\VariablesAdmin( $variable_repo );
		$settings_admin = new \CertificateManager\Admin\SettingsAdmin( $settings, $capabilities, $repo, $template_repo, $webhook_repo );
		$logs_admin = new \CertificateManager\Admin\LogsAdmin( $audit_repo, $log_repo, $settings );
		$system_status = new \CertificateManager\Admin\SystemStatus( $settings );
		
		// Initialize API
		$rest_controller = new \CertificateManager\API\RESTController( $issuance_service, $verification_service, $template_repo, $api_key_repo, $settings, $verifiable_credential_service, $idempotency_service, $webhook_repo, $webhook_service );
		
		// Initialize webhooks
		$webhook_dispatcher = new \CertificateManager\Webhooks\WebhookDispatcher( $webhook_service, $log_repo );
		
		// Initialize verification
		$shortcode = new \CertificateManager\Verification\Shortcode( $verification_service, $settings );
		$view_controller = new \CertificateManager\Verification\ViewController( $verification_service, $verifiable_credential_service );
		
		// Initialize multisite
		$network_templates = new \CertificateManager\Multisite\NetworkTemplates( $template_repo );
		
		// Initialize audit
		$audit_logger = new \CertificateManager\Audit\AuditLogger( $audit_repo, $log_repo );
		
		// Initialize designer
		$designer_controller = new \CertificateManager\Designer\Controller( $designer_service, $settings );

		// Keep controllers and services alive for callbacks registered after this
		// method returns.
		$this->components = array(
			'certificates_admin' => $certificates_admin,
			'templates_admin'    => $templates_admin,
			'variables_admin'    => $variables_admin,
			'settings_admin'     => $settings_admin,
			'logs_admin'         => $logs_admin,
			'system_status'      => $system_status,
			'api_key_repo'       => $api_key_repo,
			'webhook_repo'       => $webhook_repo,
			'rest_controller'    => $rest_controller,
			'shortcode'          => $shortcode,
			'scheduling_service' => $scheduling_service,
			'webhook_service'    => $webhook_service,
			'email_service'      => $email_service,
			'csv_service'        => $csv_service,
			'retention_service'  => $retention_service,
			'issuance_service'   => $issuance_service,
			'verifiable_credential_service' => $verifiable_credential_service,
		);

		// Enable controller hooks. Menus remain centralized in add_admin_menu(),
		// avoiding duplicate entries from the individual settings controllers.
		$dashboard->init();
		$templates_admin->init();
		$certificates_admin->init();
		$variables_admin->init();
		$logs_admin->init();
		$designer_controller->init();
		$view_controller->init();
		add_action( 'admin_init', array( $settings_admin, 'settings_init' ) );
		add_action( 'admin_post_cm_purge_data', array( $settings_admin, 'purge_data' ) );
		add_action( 'admin_post_cm_save_issue_webhook', array( $settings_admin, 'save_issue_webhook' ) );
		add_action( 'admin_post_cm_update_issue_webhook_payload', array( $settings_admin, 'update_issue_webhook_payload' ) );
		add_action( 'admin_post_cm_create_verification_page', array( $settings_admin, 'create_verification_page' ) );
		add_action( 'admin_post_cm_delete_issue_webhook', array( $settings_admin, 'delete_issue_webhook' ) );
		add_action( 'certificate_manager_certificate_issued', function ( $certificate_id ) use ( $verifiable_credential_service ) { try { $verifiable_credential_service->issue( (int) $certificate_id ); } catch ( \Throwable $error ) { } }, 5, 1 );
		add_action( 'certificate_manager_certificate_issued', function ( $certificate_id, $certificate_data ) use ( $webhook_service, $settings ) { try { $mode = $settings->get( 'certificate_issuance_delivery_mode', 'webhook' ); if ( in_array( $mode, array( 'webhook', 'both' ), true ) ) { $webhook_service->dispatch_webhook( 'certificate_issued', $certificate_data, $certificate_id, $certificate_data['webhook_ids'] ?? array() ); } } catch ( \Throwable $error ) { } }, 10, 2 );
		add_action( 'certificate_manager_certificate_issued', function ( $certificate_id, $certificate_data ) use ( $email_service, $settings ) { try { $mode = $settings->get( 'certificate_issuance_delivery_mode', 'webhook' ); if ( in_array( $mode, array( 'email', 'both' ), true ) ) { $email_service->send_certificate_email( (int) $certificate_id, absint( $certificate_data['template_id'] ?? 0 ), array( 'attach_pdf' => (bool) $settings->get( 'default_email_pdf_attached', true ) ) ); } } catch ( \Throwable $error ) { } }, 15, 2 );
		add_action( 'certificate_manager_certificates_requested', function ( $request_data ) use ( $webhook_service ) { try { $webhook_service->dispatch_webhook( 'certificate_request_valid', $request_data, 0, null ); } catch ( \Throwable $error ) { } }, 10, 1 );
		add_action( 'certificate_manager_wallet_link_requested', function ( $claim ) use ( $email_service ) { try { $email_service->send_wallet_claim_email( is_array( $claim ) ? $claim : array() ); } catch ( \Throwable $error ) { } }, 10, 1 );
		add_action( 'certificate_manager_wallet_link_webhook_requested', function ( $request_data ) use ( $webhook_service ) { try { $webhook_service->dispatch_webhook( 'wallet_link_requested', is_array( $request_data ) ? $request_data : array(), absint( $request_data['certificate_id'] ?? 0 ), null ); } catch ( \Throwable $error ) { } }, 10, 1 );
		add_action( 'admin_init', array( $system_status, 'system_status_init' ) );

		// Network template storage is only available when a repository provides
		// the required network-aware implementation. Avoid exposing an admin page
		// that would otherwise call methods absent from this installation.
		if ( is_multisite() && method_exists( $template_repo, 'get_network_templates' ) ) {
			$multisite_admin = new \CertificateManager\Admin\MultisiteAdmin( $template_repo );
			$multisite_admin->init();
			$this->components['multisite_admin'] = $multisite_admin;
		}
	}
	
	/**
	 * Hook into WordPress
	 */
	private function hook_into_wordpress() {
		// Add admin menu
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		
		// Add settings link
		$plugin_basename = defined( 'CERTIFICATE_MANAGER_BASENAME' ) ? CERTIFICATE_MANAGER_BASENAME : plugin_basename( __FILE__ );
		add_filter( 'plugin_action_links_' . $plugin_basename, array( $this, 'add_plugin_actions' ) );
		
		// Add verification shortcode
		add_shortcode( 'certificate_verification', array( $this, 'render_verification_shortcode' ) );
		
		// Register cron events
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		
		// Handle scheduled jobs
		add_action( 'certificate_manager_scheduled_job', array( $this, 'handle_scheduled_job' ), 10, 2 );
		add_action( 'certificate_manager_expiry_check', array( $this, 'handle_expiry_check' ) );
		add_action( 'certificate_manager_expiry_reminder', array( $this, 'handle_expiry_reminder' ), 10, 1 );
		
		// Handle webhook retries
		add_action( 'certificate_manager_webhook_retry', array( $this, 'handle_webhook_retry' ), 10, 1 );
		
		// Handle cleanup
		add_action( 'certificate_manager_cleanup', array( $this, 'handle_cleanup' ) );
		
		// Add body class for admin
		add_filter( 'admin_body_class', array( $this, 'add_admin_body_class' ) );
		
		// Handle REST API pre-flight checks
		add_action( 'rest_api_init', array( $this, 'setup_rest_api' ) );
	}
	
	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		// Main menu
		add_menu_page(
			__( 'Certificate Manager', 'certificate-manager' ),
			__( 'Certificates', 'certificate-manager' ),
			'cm_view_certificates',
			'cm_certificates',
			array( $this->components['certificates_admin'], 'render_page' ),
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzAwMDAwMCI+PHBhdGggZD0iTTE5IDNINWMtMS4xIDAtMiAuOS0yIDJ2MTRhMiAyIDAgMCAwIDIgMmgxNGExIDIgMCAwIDAgMi0yVjVjMC0xLjEtLjktMi0yLTJ6bS0xIDFoLTExdjJoMTF2LTJ6bS0xIDFoLTl2Mmg5di0yeiIvPjwvc3ZnPg=='
		);
		
		// Submenu: Certificates
		add_submenu_page(
			'cm_certificates',
			__( 'All Certificates', 'certificate-manager' ),
			__( 'Certificates', 'certificate-manager' ),
			'cm_view_certificates',
			'cm_certificates',
			array( $this->components['certificates_admin'], 'render_page' )
		);
		
		// Submenu: Templates
		add_submenu_page(
			'cm_certificates',
			__( 'Templates', 'certificate-manager' ),
			__( 'Templates', 'certificate-manager' ),
			'cm_view_template',
			'cm_templates',
			array( $this->components['templates_admin'], 'render_page' )
		);
		
		// Submenu: Variables
		add_submenu_page(
			'cm_certificates',
			__( 'Variables', 'certificate-manager' ),
			__( 'Variables', 'certificate-manager' ),
			'cm_manage_variables',
			'cm_variables',
			array( $this->components['variables_admin'], 'render_page' )
		);
		
		// Submenu: Integrations
		add_submenu_page(
			'cm_certificates',
			__( 'Webhooks', 'certificate-manager' ),
			__( 'Webhooks', 'certificate-manager' ),
			'cm_manage_integrations',
			'cm_integrations',
			array( $this->components['settings_admin'], 'render_webhooks_page' )
		);
		
		// Submenu: Settings
		add_submenu_page(
			'cm_certificates',
			__( 'Settings', 'certificate-manager' ),
			__( 'Settings', 'certificate-manager' ),
			'cm_manage_settings',
			'cm_settings',
			array( $this->components['settings_admin'], 'render_settings_page' )
		);
		
		// Submenu: Logs
		add_submenu_page(
			'cm_certificates',
			__( 'Logs', 'certificate-manager' ),
			__( 'Logs', 'certificate-manager' ),
			'cm_manage_settings',
			'cm_logs',
			array( $this->components['logs_admin'], 'render_page' )
		);
		
		// Submenu: System Status
		add_submenu_page(
			'cm_certificates',
			__( 'System Status', 'certificate-manager' ),
			__( 'System Status', 'certificate-manager' ),
			'cm_manage_settings',
			'cm_system_status',
			array( $this->components['system_status'], 'render_system_status_page' )
		);
		
		// Network menu for Multisite
		if ( is_multisite() ) {
			add_menu_page(
				__( 'Certificate Manager', 'certificate-manager' ),
				__( 'Certificates', 'certificate-manager' ),
				'manage_network',
				'cm_network',
				'__return_false',
				'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzAwMDAwMCI+PHBhdGggZD0iTTE5IDNINWMtMS4xIDAtMiAuOS0yIDJ2MTRhMiAyIDAgMCAwIDIgMmgxNGExIDIgMCAwIDAgMi0yVjVjMC0xLjEtLjktMi0yLTJ6bS0xIDFoLTExdjJoMTF2LTJ6bS0xIDFoLTl2Mmg5di0yeiIvPjwvc3ZnPg=='
			);
			add_menu_page(
				__( 'Network Templates', 'certificate-manager' ),
				__( 'Network Templates', 'certificate-manager' ),
				'manage_network',
				'cm_network_templates',
				'__return_false',
				'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzAwMDAwMCI+PHBhdGggZD0iTTE5IDNINWMtMS4xIDAtMiAuOS0yIDJ2MTRhMiAyIDAgMCAwIDIgMmgxNGExIDIgMCAwIDAgMi0yVjVjMC0xLjEtLjktMi0yLTJ6bS0xIDFoLTExdjJoMTF2LTJ6bS0xIDFoLTl2Mmg5di0yeiIvPjwvc3ZnPg=='
			);
		}
	}
	
	/**
	 * Render admin placeholder (will be replaced with actual admin classes)
	 */
	public function render_admin_placeholder() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Certificate Manager', 'certificate-manager' ) . '</h1><p>' . esc_html__( 'Loading...', 'certificate-manager' ) . '</p></div>';
	}

	/**
	 * Add plugin actions link
	 */
	public function add_plugin_actions( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=cm_settings' ) . '">' . esc_html__( 'Settings', 'certificate-manager' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
	
	/**
	 * Render verification shortcode
	 */
	public function render_verification_shortcode( $atts ) {
		return $this->components['shortcode']->render( $atts );
	}
	
	/**
	 * Register cron schedules
	 */
	public function register_cron_schedules( $schedules ) {
		$schedules['every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'certificate-manager' ),
		);
		$schedules['every_5_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'certificate-manager' ),
		);
		$schedules['every_hour'] = array(
			'interval' => 3600,
			'display'  => __( 'Every Hour', 'certificate-manager' ),
		);
		$schedules['every_day'] = array(
			'interval' => 86400,
			'display'  => __( 'Every Day', 'certificate-manager' ),
		);
		return $schedules;
	}
	
	/**
	 * Handle scheduled job with locking to prevent concurrent execution.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $args   Job arguments.
	 */
	public function handle_scheduled_job( $job_id, $args ) {
		$lock_key = 'cm_cron_lock_job_' . (int) $job_id;
		$lock_timeout = 300; // 5 minutes max execution time
		
		// Try to acquire lock
		if ( get_transient( $lock_key ) ) {
			// Another process is already handling this job
			return;
		}
		
		set_transient( $lock_key, time(), $lock_timeout );
		
		try {
			$this->components['scheduling_service']->process_scheduled_job( (int) $job_id, is_array( $args ) ? $args : array() );
		} finally {
			// Always release lock
			delete_transient( $lock_key );
		}
	}

	/**
	 * Handle expiry check with locking.
	 */
	public function handle_expiry_check() {
		$lock_key = 'cm_cron_lock_expiry_check';
		
		if ( get_transient( $lock_key ) ) {
			return;
		}
		
		set_transient( $lock_key, time(), 60 );
		
		try {
			( new \CertificateManager\Repositories\CertificateRepository() )->expire_due_certificates();
		} finally {
			delete_transient( $lock_key );
		}
	}
	
	/**
	 * Handle expiry reminder.
	 *
	 * @param int $certificate_id Certificate ID.
	 */
	public function handle_expiry_reminder( $certificate_id ) {
		$lock_key = 'cm_cron_lock_reminder_' . (int) $certificate_id;
		
		if ( get_transient( $lock_key ) ) {
			return;
		}
		
		set_transient( $lock_key, time(), 120 );
		
		try {
			$this->components['email_service']->send_expiry_reminder( (int) $certificate_id );
		} finally {
			delete_transient( $lock_key );
		}
	}
	
	/**
	 * Handle webhook retry with locking.
	 *
	 * @param int $delivery_id Delivery ID.
	 */
	public function handle_webhook_retry( $delivery_id = 0 ) {
		if ( ! $delivery_id ) {
			return;
		}
		
		$lock_key = 'cm_cron_lock_webhook_' . (int) $delivery_id;
		
		if ( get_transient( $lock_key ) ) {
			return;
		}
		
		set_transient( $lock_key, time(), 120 );
		
		try {
			$this->components['webhook_service']->retry_delivery( (int) $delivery_id );
		} finally {
			delete_transient( $lock_key );
		}
	}
	
	/**
	 * Handle cleanup with locking.
	 */
	public function handle_cleanup() {
		$lock_key = 'cm_cron_lock_cleanup';
		
		if ( get_transient( $lock_key ) ) {
			return;
		}
		
		set_transient( $lock_key, time(), 300 );
		
		try {
			$this->components['retention_service']->cleanup_old_data();
		} finally {
			delete_transient( $lock_key );
		}
	}
	
	/**
	 * Add admin body class
	 */
	public function add_admin_body_class( $classes ) {
		return $classes . ' certificate-manager-admin';
	}
	
	/**
	 * Setup REST API
	 */
	public function setup_rest_api() {
		$this->components['rest_controller']->register_routes();
	}
	
	/**
	 * Set default settings
	 */
	private function set_default_settings() {
		$defaults = array(
			'pixabay_api_key' => '',
			'verification_page_id' => 0,
			'verification_page_url' => '',
			'verification_style' => 'editorial',
			'verification_accent' => 'indigo',
			'enable_certificate_requests' => false,
			'default_expiry_enabled' => false,
			'default_expiry_quantity' => 1,
			'default_expiry_unit' => 'years',
			'default_number_format' => '{year}{month}{day}-{seq}',
			'download_token_lifetime' => 900,
			'rate_limit_requests' => 10,
			'rate_limit_window' => 60,
			'auditor_retention_enabled' => true,
			'auditor_retention_days' => 365,
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
		);
		
		$existing = get_option( 'certificate_manager_settings', false );
		if ( ! $existing ) {
			update_option( 'certificate_manager_settings', $defaults );
		}
	}
	
	/**
	 * Schedule jobs
	 */
	private function schedule_jobs() {
		// Schedule expiry checks (every hour)
		if ( ! wp_next_scheduled( 'certificate_manager_expiry_check' ) ) {
			wp_schedule_event( time(), 'every_hour', 'certificate_manager_expiry_check' );
		}
		
		// Schedule cleanup (every day)
		if ( ! wp_next_scheduled( 'certificate_manager_cleanup' ) ) {
			wp_schedule_event( time(), 'every_day', 'certificate_manager_cleanup' );
		}
	}
	
	/**
	 * Clear scheduled jobs
	 */
	private function clear_scheduled_jobs() {
		wp_clear_scheduled_hook( 'certificate_manager_expiry_check' );
		wp_clear_scheduled_hook( 'certificate_manager_webhook_retry' );
		wp_clear_scheduled_hook( 'certificate_manager_cleanup' );
		wp_clear_scheduled_hook( 'certificate_manager_scheduled_job' );
		wp_clear_scheduled_hook( 'certificate_manager_expiry_reminder' );
		wp_clear_scheduled_hook( 'certificate_manager_webhook_retry' );
		wp_clear_scheduled_hook( 'certificate_manager_csv_import' );
		wp_clear_scheduled_hook( 'certificate_manager_bulk_operation' );
	}
	
	/**
	 * Create verification page
	 */
	private function create_verification_page() {
		// Check if page already exists
		$page_id = absint( get_option( 'certificate_manager_verification_page_id' ) );
		if ( $page_id && get_post_status( $page_id ) ) {
			$settings = new \CertificateManager\Core\Settings();
			$settings->update( 'verification_page_id', $page_id );
			return;
		}
		
		// Check if verification page exists
		$args = array(
			'post_type' => 'page',
			'post_title' => __( 'Verify Certificate', 'certificate-manager' ),
			'post_content' => '[certificate_verification]',
			'post_status' => 'publish',
			'post_author' => 1,
		);
		
		// Search for an existing page by its exact title without using the
		// get_page_by_title() API deprecated in WordPress 6.2.
		$existing = get_posts( array(
			'post_type'              => 'page',
			'post_status'            => 'any',
			'title'                  => $args['post_title'],
			'numberposts'            => 1,
			'orderby'                => 'post_date ID',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		if ( ! empty( $existing ) ) {
			$page_id = (int) $existing[0]->ID;
			update_option( 'certificate_manager_verification_page_id', $page_id );
			$settings = new \CertificateManager\Core\Settings();
			$settings->update( 'verification_page_id', $page_id );
			return;
		}
		
		// Create new page
		$page_id = wp_insert_post( $args );
		if ( $page_id ) {
			update_option( 'certificate_manager_verification_page_id', $page_id );
			$settings = new \CertificateManager\Core\Settings();
			$settings->update( 'verification_page_id', $page_id );
		}
	}
	
	/**
	 * Log event to audit trail
	 */
	private function log_event( $event, $data = array() ) {
		$audit_repo = new \CertificateManager\Repositories\AuditRepository();
		
		// Get current user for performer info
		$performer = array(
			'type' => 'system',
			'id' => null,
			'details' => array()
		);
		
		if ( function_exists( 'get_current_user_id' ) ) {
			$user_id = get_current_user_id();
			if ( $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					$performer = array(
						'type' => 'user',
						'id' => $user_id,
						'details' => array(
							'username' => $user->user_login,
							'email' => $user->user_email,
							'display_name' => $user->display_name
						)
					);
				}
			}
		}
		
		// For system events, record_id is 0 since not related to specific certificate
		$audit_repo->log( 'system', $event, 0, $data, $performer );
	}
}
