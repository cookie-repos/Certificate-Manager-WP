<?php
/**
 * Certificate Manager Database Schema
 *
 * @package CertificateManager
 */

namespace CertificateManager\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema manager
 */
class Schema {
	
	/**
	 * Schema version
	 *
	 * @var int
	 */
	const VERSION = 8;
	
	/**
	 * Get schema version
	 *
	 * @return int
	 */
	public function get_version(): int {
		return get_option( 'certificate_manager_schema_version', 0 );
	}
	
	/**
	 * Get table names with prefixes
	 *
	 * @return array
	 */
	public function get_table_names(): array {
		global $wpdb;
		
		$prefix = $wpdb->prefix;
		
		return array(
			'certificates' => $prefix . 'certificate_manager_certificates',
			'templates' => $prefix . 'certificate_manager_templates',
			'template_versions' => $prefix . 'certificate_manager_template_versions',
			'variables' => $prefix . 'certificate_manager_variables',
			'template_variables' => $prefix . 'certificate_manager_template_variables',
			'sequences' => $prefix . 'certificate_manager_sequences',
			'certificate_fields' => $prefix . 'certificate_manager_certificate_fields',
			'audit_log' => $prefix . 'certificate_manager_audit_log',
			'operational_logs' => $prefix . 'certificate_manager_operational_logs',
			'webhooks' => $prefix . 'certificate_manager_webhooks',
			'webhook_deliveries' => $prefix . 'certificate_manager_webhook_deliveries',
			'api_keys' => $prefix . 'certificate_manager_api_keys',
			'scheduled_jobs' => $prefix . 'certificate_manager_scheduled_jobs',
			'download_tokens' => $prefix . 'certificate_manager_download_tokens',
			'idempotency_keys' => $prefix . 'certificate_manager_idempotency_keys',
			'verifiable_credentials' => $prefix . 'certificate_manager_verifiable_credentials',
			'template_tags' => $prefix . 'certificate_manager_template_tags',
			'certificate_tags' => $prefix . 'certificate_manager_certificate_tags',
		);
	}
	
	/**
	 * Get table creation SQL
	 *
	 * @return array
	 */
	public function get_create_statements(): array {
		global $wpdb;
		
		$charset_collate = $wpdb->get_charset_collate();
		
		return array(
			'certificates' => $this->get_certificates_table_sql( $charset_collate ),
			'templates' => $this->get_templates_table_sql( $charset_collate ),
			'template_versions' => $this->get_template_versions_table_sql( $charset_collate ),
			'variables' => $this->get_variables_table_sql( $charset_collate ),
			'template_variables' => $this->get_template_variables_table_sql( $charset_collate ),
			'sequences' => $this->get_sequences_table_sql( $charset_collate ),
			'certificate_fields' => $this->get_certificate_fields_table_sql( $charset_collate ),
			'audit_log' => $this->get_audit_log_table_sql( $charset_collate ),
			'operational_logs' => $this->get_operational_logs_table_sql( $charset_collate ),
			'webhooks' => $this->get_webhooks_table_sql( $charset_collate ),
			'webhook_deliveries' => $this->get_webhook_deliveries_table_sql( $charset_collate ),
			'api_keys' => $this->get_api_keys_table_sql( $charset_collate ),
			'scheduled_jobs' => $this->get_scheduled_jobs_table_sql( $charset_collate ),
			'download_tokens' => $this->get_download_tokens_table_sql( $charset_collate ),
			'idempotency_keys' => $this->get_idempotency_keys_table_sql( $charset_collate ),
			'verifiable_credentials' => $this->get_verifiable_credentials_table_sql( $charset_collate ),
			'template_tags' => $this->get_template_tags_table_sql( $charset_collate ),
			'certificate_tags' => $this->get_certificate_tags_table_sql( $charset_collate ),
		);
	}
	
	/**
	 * Get certificates table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_certificates_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_certificates';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			internal_id varchar(36) NOT NULL COMMENT 'UUID for internal use',
			certificate_number varchar(100) NOT NULL COMMENT 'Human-readable certificate number',
			verification_token varchar(64) NOT NULL COMMENT 'Secure random token for verification',
			template_id bigint(20) unsigned NOT NULL COMMENT 'Template used for this certificate',
			template_version_id bigint(20) unsigned NOT NULL COMMENT 'Template version used (immutable)',
			recipient_name varchar(255) NOT NULL,
			recipient_email varchar(255) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active|expired|revoked|replaced|trash',
			issue_date datetime NOT NULL,
			expiry_date datetime DEFAULT NULL,
			data_snapshot longtext NOT NULL COMMENT 'JSON snapshot of certificate data at issue time',
			tags json DEFAULT NULL COMMENT 'JSON array of tags',
			private_notes longtext DEFAULT NULL,
			internal_notes longtext DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'manual|csv|api|scheduled',
			source_details longtext DEFAULT NULL COMMENT 'JSON source details',
			issuer_id bigint(20) unsigned DEFAULT NULL COMMENT 'WordPress user ID',
			api_key_id bigint(20) unsigned DEFAULT NULL COMMENT 'API key ID',
			pdf_file_path text DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			revoked_by bigint(20) unsigned DEFAULT NULL,
			revocation_reason longtext DEFAULT NULL,
			revocation_reason_public tinyint(1) NOT NULL DEFAULT 0,
			reinstated_at datetime DEFAULT NULL,
			reinstated_by bigint(20) unsigned DEFAULT NULL,
			verification_count bigint(20) unsigned NOT NULL DEFAULT 0,
			qr_verification_count bigint(20) unsigned NOT NULL DEFAULT 0,
			manual_verification_count bigint(20) unsigned NOT NULL DEFAULT 0,
			replaced_by bigint(20) unsigned DEFAULT NULL COMMENT 'ID of replacement certificate',
			trashed_at datetime DEFAULT NULL,
			deleted_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY internal_id (internal_id),
			UNIQUE KEY certificate_number (certificate_number),
			KEY verification_token (verification_token),
			KEY template_id (template_id),
			KEY template_version_id (template_version_id),
			KEY status (status),
			KEY issue_date (issue_date),
			KEY expiry_date (expiry_date),
			KEY recipient_name (recipient_name),
			KEY recipient_email (recipient_email),
			KEY source (source),
			KEY issuer_id (issuer_id),
			KEY api_key_id (api_key_id),
			KEY revoked_by (revoked_by),
			KEY replaced_by (replaced_by)
		) $charset_collate;";
	}
	
	/**
	 * Get templates table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_templates_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_templates';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_post_id bigint(20) unsigned NOT NULL COMMENT 'WordPress post ID for title and metadata',
			title varchar(255) NOT NULL,
			slug varchar(255) NOT NULL,
			description text DEFAULT NULL,
			orientation varchar(10) NOT NULL DEFAULT 'portrait' COMMENT 'portrait|landscape',
			page_width decimal(10,2) NOT NULL DEFAULT 210.00 COMMENT 'A4 width in mm',
			page_height decimal(10,2) NOT NULL DEFAULT 297.00 COMMENT 'A4 height in mm',
			elements longtext NOT NULL COMMENT 'JSON array of design elements',
			conditions json DEFAULT NULL COMMENT 'JSON array of conditional rules',
			repeating_blocks json DEFAULT NULL COMMENT 'JSON array of repeating block definitions',
			qr_config json DEFAULT NULL COMMENT 'QR code configuration',
			background_config json DEFAULT NULL COMMENT 'Background configuration',
			webhook_ids json DEFAULT NULL COMMENT 'Webhook IDs triggered when this template is issued',
			is_archived tinyint(1) NOT NULL DEFAULT 0,
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			last_version_id bigint(20) unsigned DEFAULT NULL COMMENT 'Current published version',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY wp_post_id (wp_post_id),
			KEY slug (slug),
			KEY is_archived (is_archived),
			KEY is_deleted (is_deleted)
		) $charset_collate;";
	}
	
	/**
	 * Get template versions table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_template_versions_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_template_versions';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template_id bigint(20) unsigned NOT NULL,
			version_number int unsigned NOT NULL DEFAULT 1,
			elements longtext NOT NULL COMMENT 'JSON array of design elements',
			conditions json DEFAULT NULL COMMENT 'JSON array of conditional rules',
			repeating_blocks json DEFAULT NULL COMMENT 'JSON array of repeating block definitions',
			variables json DEFAULT NULL COMMENT 'JSON array of variable definitions',
			qr_config json DEFAULT NULL COMMENT 'QR code configuration',
			background_config json DEFAULT NULL COMMENT 'Background configuration',
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY template_id (template_id),
			KEY version_number (template_id, version_number),
			KEY created_by (created_by),
			KEY created_at (created_at)
		) $charset_collate;";
	}
	
	/**
	 * Get variables table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_variables_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_variables';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plugin_network_id bigint(20) unsigned DEFAULT NULL COMMENT 'Network ID for multisite shared variables',
			variable_key varchar(100) NOT NULL COMMENT 'Internal key',
			label varchar(255) NOT NULL,
			field_type varchar(50) NOT NULL DEFAULT 'text' COMMENT 'text|textarea|number|email|date|url|checkbox|select',
			field_options json DEFAULT NULL COMMENT 'JSON field options',
			is_required tinyint(1) NOT NULL DEFAULT 0,
			default_value text DEFAULT NULL,
			default_visible tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Visible on certificates by default',
			is_system tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Built-in system variable',
			sort_order int NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY variable_key (variable_key),
			KEY plugin_network_id (plugin_network_id),
			KEY field_type (field_type),
			KEY is_system (is_system),
			KEY sort_order (sort_order)
		) $charset_collate;";
	}
	
	/**
	 * Get template variables table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_template_variables_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_template_variables';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template_id bigint(20) unsigned NOT NULL,
			variable_id bigint(20) unsigned NOT NULL,
			is_required tinyint(1) NOT NULL DEFAULT 0,
			default_value text DEFAULT NULL,
			sort_order int NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY template_variable (template_id, variable_id),
			KEY variable_id (variable_id),
			KEY sort_order (sort_order)
		) $charset_collate;";
	}
	
	/**
	 * Get sequences table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_sequences_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_sequences';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plugin_network_id bigint(20) unsigned DEFAULT NULL COMMENT 'Network ID for shared sequences',
			name varchar(255) NOT NULL COMMENT 'Sequence name',
			current_value bigint(20) unsigned NOT NULL DEFAULT 0,
			padding int NOT NULL DEFAULT 1 COMMENT 'Zero padding digits',
			last_allocated_at datetime DEFAULT NULL,
			last_allocated_by bigint(20) unsigned DEFAULT NULL,
			is_global tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name),
			KEY plugin_network_id (plugin_network_id),
			KEY is_global (is_global)
		) $charset_collate;";
	}
	
	/**
	 * Get certificate fields table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_certificate_fields_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_certificate_fields';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			certificate_id bigint(20) unsigned NOT NULL,
			variable_key varchar(100) NOT NULL,
			variable_label varchar(255) NOT NULL,
			value text NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY certificate_id (certificate_id),
			KEY variable_key (certificate_id, variable_key)
		) $charset_collate;";
	}
	
	/**
	 * Get audit log table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_audit_log_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_audit_log';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			certificate_id bigint(20) unsigned DEFAULT NULL,
			event varchar(100) NOT NULL COMMENT 'Issued|Edited|Revoked|Reinstated|Replaced|Expired|Trashed|Deleted|PDFRegenerated|EmailResent',
			occurred_at datetime NOT NULL,
			source varchar(20) NOT NULL COMMENT 'manual|csv|api|scheduled|system',
			source_details longtext DEFAULT NULL COMMENT 'JSON source details',
			changed_data longtext DEFAULT NULL COMMENT 'JSON old and new values',
			performer_type varchar(20) NOT NULL DEFAULT 'user' COMMENT 'user|api|system',
			performer_id bigint(20) unsigned DEFAULT NULL,
			performer_details longtext DEFAULT NULL COMMENT 'JSON performer info',
			related_certificate_id bigint(20) unsigned DEFAULT NULL,
			related_event varchar(100) DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL COMMENT 'Stored briefly for security, then removed',
			meta json DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY certificate_id (certificate_id),
			KEY event (event),
			KEY occurred_at (occurred_at),
			KEY source (source),
			KEY performer_type (performer_type),
			KEY performer_id (performer_id)
		) $charset_collate;";
	}
	
	/**
	 * Get operational logs table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_operational_logs_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_operational_logs';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_level varchar(10) NOT NULL DEFAULT 'info' COMMENT 'info|warning|error|critical',
			log_category varchar(100) NOT NULL COMMENT 'PDF|Email|Webhook|CSV|Queue|System',
			message text NOT NULL,
			context longtext DEFAULT NULL COMMENT 'JSON context data',
			stack_trace longtext DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY log_level (log_level),
			KEY log_category (log_category),
			KEY created_at (created_at)
		) $charset_collate;";
	}
	
	/**
	 * Get webhooks table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_webhooks_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_webhooks';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			url varchar(1000) NOT NULL,
			events json NOT NULL COMMENT 'JSON array of events',
			payload_fields json DEFAULT NULL COMMENT 'Selected certificate fields to send',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			secret varchar(64) NOT NULL COMMENT 'HMAC signing secret',
			last_status varchar(20) DEFAULT NULL COMMENT 'success|failed|pending',
			last_attempt_at datetime DEFAULT NULL,
			last_response_code int DEFAULT NULL,
			last_error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active (is_active),
			KEY last_status (last_status)
		) $charset_collate;";
	}
	
	/**
	 * Get webhook deliveries table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_webhook_deliveries_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_webhook_deliveries';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			webhook_id bigint(20) unsigned NOT NULL,
			certificate_id bigint(20) unsigned DEFAULT NULL,
			event varchar(100) NOT NULL,
			payload longtext NOT NULL COMMENT 'JSON payload',
			headers longtext NOT NULL COMMENT 'JSON headers with signature',
			request_body longtext NOT NULL COMMENT 'JSON request body',
			response_code int DEFAULT NULL,
			response_body text DEFAULT NULL,
			retry_count int NOT NULL DEFAULT 0,
			max_retries int NOT NULL DEFAULT 3,
			status varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|success|failed',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY webhook_id (webhook_id),
			KEY certificate_id (certificate_id),
			KEY event (event),
			KEY status (status),
			KEY retry_count (retry_count),
			KEY created_at (created_at)
		) $charset_collate;";
	}
	
	/**
	 * Get API keys table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_api_keys_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_api_keys';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			secret_hash varchar(255) NOT NULL COMMENT 'Hashed secret',
			secret_prefix varchar(8) NOT NULL COMMENT 'Prefix for key display',
			description text DEFAULT NULL,
			permissions json DEFAULT NULL COMMENT 'JSON permissions',
			template_ids json DEFAULT NULL COMMENT 'JSON array of template IDs',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			last_used_at datetime DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY secret_prefix (secret_prefix),
			KEY is_active (is_active),
			KEY created_by (created_by),
			KEY created_at (created_at)
		) $charset_collate;";
	}
	
	/**
	 * Get scheduled jobs table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_scheduled_jobs_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_scheduled_jobs';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(50) NOT NULL COMMENT 'certificate_issuance|expiry_reminder',
			certificate_id bigint(20) unsigned DEFAULT NULL,
			scheduled_at datetime NOT NULL,
			arguments longtext DEFAULT NULL COMMENT 'JSON arguments',
			is_completed tinyint(1) NOT NULL DEFAULT 0,
			completed_at datetime DEFAULT NULL,
			failed tinyint(1) NOT NULL DEFAULT 0,
			failure_reason text DEFAULT NULL,
			retry_count int NOT NULL DEFAULT 0,
			max_retries int NOT NULL DEFAULT 3,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY job_type (job_type),
			KEY scheduled_at (scheduled_at),
			KEY is_completed (is_completed),
			KEY failed (failed),
			KEY certificate_id (certificate_id)
		) $charset_collate;";
	}
	
	/**
	 * Get download tokens table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_download_tokens_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_download_tokens';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			certificate_id bigint(20) unsigned NOT NULL,
			token varchar(64) NOT NULL,
			expires_at datetime NOT NULL,
			used tinyint(1) NOT NULL DEFAULT 0,
			used_at datetime DEFAULT NULL,
			ip_address varchar(45) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY token (token),
			KEY certificate_id (certificate_id),
			KEY expires_at (expires_at)
		) $charset_collate;";
	}
	
	/**
	 * Get idempotency keys table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_idempotency_keys_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_idempotency_keys';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_hash varchar(255) NOT NULL,
			certificate_id bigint(20) unsigned DEFAULT NULL,
			api_key_id bigint(20) unsigned DEFAULT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY key_hash (key_hash),
			KEY certificate_id (certificate_id),
			KEY expires_at (expires_at)
		) $charset_collate;";
	}

	/**
	 * Get signed verifiable credentials table SQL.
	 *
	 * Signed credentials are immutable records. Lifecycle changes are exposed
	 * through the credential-status endpoint referenced by each credential.
	 */
	private function get_verifiable_credentials_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_verifiable_credentials';

		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			certificate_id bigint(20) unsigned NOT NULL,
			credential_jwt longtext NOT NULL,
			key_id varchar(191) NOT NULL DEFAULT 'key-1',
			issued_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY certificate_id (certificate_id),
			KEY key_id (key_id)
		) $charset_collate;";
	}
	
	/**
	 * Get template tags table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_template_tags_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_template_tags';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template_id bigint(20) unsigned NOT NULL,
			tag varchar(100) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY template_tag (template_id, tag),
			KEY tag (tag)
		) $charset_collate;";
	}
	
	/**
	 * Get certificate tags table SQL
	 *
	 * @param string $charset_collate Charset and collate
	 * @return string
	 */
	private function get_certificate_tags_table_sql( string $charset_collate ): string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_certificate_tags';
		
		return "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			certificate_id bigint(20) unsigned NOT NULL,
			tag varchar(100) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY certificate_tag (certificate_id, tag),
			KEY tag (tag)
		) $charset_collate;";
	}
}
