<?php
/**
 * Certificate Manager Settings Admin
 *
 * @package CertificateManager
 */

namespace CertificateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings admin controller
 */
class SettingsAdmin {
	
	/**
	 * Settings instance
	 *
	 * @var Core\Settings
	 */
	private $settings;
	private $capabilities;
	private $cert_repo;
	private $template_repo;
	private $webhook_repo;
	
	/**
	 * Constructor
	 *
	 * @param Core\Settings $settings Settings instance
	 */
	public function __construct( \CertificateManager\Core\Settings $settings, \CertificateManager\Core\Capabilities $capabilities, $cert_repo, $template_repo, $webhook_repo ) {
		$this->settings = $settings;
		$this->capabilities = $capabilities;
		$this->cert_repo = $cert_repo;
		$this->template_repo = $template_repo;
		$this->webhook_repo = $webhook_repo;
	}
	
	/**
	 * Initialize settings page
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'render_pixabay_setup_notice' ) );
	}

	public function enqueue_scripts( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'cm_settings' !== $page ) {
			return;
		}

		wp_enqueue_style( 'certificate-manager-admin', plugins_url( '../Assets/css/admin.css', __FILE__ ), array(), CERTIFICATE_MANAGER_VERSION );
		wp_enqueue_script( 'certificate-manager-admin', plugins_url( '../Assets/js/admin.js', __FILE__ ), array( 'jquery' ), CERTIFICATE_MANAGER_VERSION, true );
		wp_localize_script( 'certificate-manager-admin', 'cmAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'cm-admin-nonce' ),
			'l10n' => array(
				'error' => __( 'An error occurred', 'certificate-manager' ),
			),
		) );
	}

	public function render_pixabay_setup_notice() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'cm_' ) || ! current_user_can( 'cm_manage_settings' ) || '' !== trim( (string) $this->settings->get( 'pixabay_api_key', '' ) ) ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Complete Certificate Manager setup:', 'certificate-manager' ); ?></strong> <?php esc_html_e( 'add your free Pixabay API key to search for certificate backgrounds, badges and other artwork without bundling stock images in the plugin.', 'certificate-manager' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=cm_settings' ) ); ?>"><?php esc_html_e( 'Add Pixabay API key', 'certificate-manager' ); ?></a> <a class="button" href="https://pixabay.com/api/docs/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get a Pixabay API key', 'certificate-manager' ); ?></a></p>
		</div>
		<?php
	}
	
	/**
	 * Add settings page to menu
	 */
	public function add_settings_page() {
		add_submenu_page(
			'cm_certificates',
			__( 'Settings', 'certificate-manager' ),
			__( 'Settings', 'certificate-manager' ),
			'cm_manage_settings',
			'cm_settings',
			array( $this, 'render_settings_page' )
		);
	}
	
	/**
	 * Register settings
	 */
	public function settings_init() {
		register_setting( 'certificate_manager_settings', 'certificate_manager_settings', array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}
	
	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		$settings = $this->settings->get_all();
		$preview_style = sanitize_key( $settings['verification_style'] ?? 'editorial' );
		$preview_style = in_array( $preview_style, array( 'editorial', 'registry', 'heritage', 'folio', 'night' ), true ) ? $preview_style : 'editorial';
		$preview_accent = in_array( $settings['verification_accent'] ?? 'indigo', array( 'indigo', 'emerald', 'violet', 'amber', 'charcoal' ), true ) ? $settings['verification_accent'] : 'indigo';
		$preview_credentials_enabled = ! isset( $settings['verifiable_credentials_enabled'] ) || ! empty( $settings['verifiable_credentials_enabled'] );
		$purge_status = sanitize_key( wp_unslash( $_GET['cm_purge_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter.
		$purge_count = absint( $_GET['cm_purge_count'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter.
		?>
		<div class="wrap certificate-manager-settings">
			<h1><?php esc_html_e( 'Certificate Manager Settings', 'certificate-manager' ); ?></h1>
			<?php if ( 'certificates_cleared' === $purge_status ) : ?><div class="notice notice-success is-dismissible"><p><?php
			/* translators: %d: number of certificates cleared */
			echo esc_html( sprintf( _n( '%d certificate was permanently cleared. Audit and operational logs were kept.', '%d certificates were permanently cleared. Audit and operational logs were kept.', $purge_count, 'certificate-manager' ), $purge_count ) ); ?></p></div><?php endif; ?>
			<?php if ( 'templates_cleared' === $purge_status ) : ?><div class="notice notice-success is-dismissible"><p><?php
			/* translators: %d: number of templates cleared */
			echo esc_html( sprintf( _n( '%d template was permanently cleared. Audit and operational logs were kept.', '%d templates were permanently cleared. Audit and operational logs were kept.', $purge_count, 'certificate-manager' ), $purge_count ) ); ?></p></div><?php endif; ?>
			<?php if ( 'confirmation_failed' === $purge_status ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Nothing was deleted. Confirm the checkbox and enter the requested phrase exactly.', 'certificate-manager' ); ?></p></div><?php endif; ?>
			<?php if ( 'templates_require_certificate_purge' === $purge_status ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Clear all certificates before permanently clearing templates, so certificate history is not orphaned.', 'certificate-manager' ); ?></p></div><?php endif; ?>
			<?php if ( 'purge_failed' === $purge_status ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Nothing further was deleted because the cleanup could not be completed.', 'certificate-manager' ); ?></p></div><?php endif; ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'certificate_manager_settings' );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cm-pixabay-api-key"><?php esc_html_e( 'Pixabay API key', 'certificate-manager' ); ?></label></th>
						<td>
							<input class="regular-text" id="cm-pixabay-api-key" type="password" autocomplete="off" name="certificate_manager_settings[pixabay_api_key]" value="<?php echo esc_attr( $settings['pixabay_api_key'] ?? '' ); ?>">
							<p class="description"><?php esc_html_e( 'Used only for Pixabay searches in the template designer. The key stays on your server, and selected images are imported into your WordPress Media Library.', 'certificate-manager' ); ?> <a href="https://pixabay.com/api/docs/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'certificate-manager' ); ?></a>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Live preview', 'certificate-manager' ); ?></th>
						<td>
							<div id="cm-verification-preview" class="cm-verification-preview is-<?php echo esc_attr( $preview_style ); ?> accent-<?php echo esc_attr( $preview_accent ); ?><?php echo $preview_credentials_enabled ? ' has-credential' : ''; ?>">
								<div class="cm-verification-preview-card">
									<div class="cm-verification-preview-header"><span><?php esc_html_e( 'Certificate register', 'certificate-manager' ); ?></span><strong><?php esc_html_e( 'Certificate Verification', 'certificate-manager' ); ?></strong></div>
									<div class="cm-verification-preview-content"><div class="cm-verification-preview-status"><b>✓</b><span><small><?php esc_html_e( 'Verification result', 'certificate-manager' ); ?></small><strong><?php esc_html_e( 'Valid Certificate', 'certificate-manager' ); ?></strong></span></div><div class="cm-verification-preview-row"><span><?php esc_html_e( 'Recipient', 'certificate-manager' ); ?></span><strong><?php esc_html_e( 'Alex Morgan', 'certificate-manager' ); ?></strong></div><div class="cm-verification-preview-row"><span><?php esc_html_e( 'Certificate no.', 'certificate-manager' ); ?></span><strong>CERT-2026-0001</strong></div><section class="cm-verification-preview-credential"><span><?php esc_html_e( 'Signed digital credential', 'certificate-manager' ); ?></span><strong><?php esc_html_e( 'W3C Verifiable Credential 2.0', 'certificate-manager' ); ?></strong><small><?php esc_html_e( 'Cryptographically signed and independently verifiable.', 'certificate-manager' ); ?></small><button type="button" disabled><?php esc_html_e( 'Email me a private wallet link', 'certificate-manager' ); ?></button><button class="cm-verification-preview-download" type="button" disabled><?php esc_html_e( 'Download signed credential', 'certificate-manager' ); ?></button></section></div>
								</div>
							</div>
							<p class="description"><span id="cm-verification-preview-label"><?php echo esc_html( ucfirst( $preview_style ) . ' / ' . ucfirst( $preview_accent ) ); ?></span> <?php esc_html_e( 'updates as you change the options below. Save settings to publish the choice.', 'certificate-manager' ); ?></p>
							<style>
								.cm-verification-preview { --cm-preview-primary:#315efb; --cm-preview-soft:#e8edff; background:#f7f7f5; border:1px solid #dfe3dd; box-sizing:border-box; max-width:620px; min-height:250px; overflow:hidden; padding:25px 30px; }
								.cm-verification-preview.accent-emerald { --cm-preview-primary:#0b8a61; --cm-preview-soft:#e2f6ee; }
								.cm-verification-preview.accent-violet { --cm-preview-primary:#7641d9; --cm-preview-soft:#f0e9ff; }
								.cm-verification-preview.accent-amber { --cm-preview-primary:#a66a00; --cm-preview-soft:#fff2d5; }
								.cm-verification-preview.accent-charcoal { --cm-preview-primary:#292929; --cm-preview-soft:#ececec; }
								.cm-verification-preview-card { background:#fff; border:1px solid #dfe3dd; border-radius:2px; border-top:4px solid var(--cm-preview-primary); box-shadow:0 8px 22px rgba(25,36,29,.08); color:#18211e; margin:auto; max-width:510px; overflow:hidden; }
								.cm-verification-preview-header { background:#fff; padding:20px 24px; }
								.cm-verification-preview-header span,.cm-verification-preview-status small { color:var(--cm-preview-primary); display:block; font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
								.cm-verification-preview-header strong { display:block; font-family:Georgia,"Times New Roman",serif; font-size:24px; font-weight:500; letter-spacing:-.035em; margin-top:6px; }
								.cm-verification-preview-content { padding:20px 24px; }
								.cm-verification-preview-status { align-items:center; background:transparent; border:0; border-bottom:1px solid #dfe3dd; border-radius:0; display:flex; gap:10px; margin-bottom:14px; padding:0 0 13px; }
								.cm-verification-preview-status b { align-items:center; background:#16874b; border-radius:50%; color:#fff; display:flex; height:24px; justify-content:center; width:24px; }
								.cm-verification-preview-status strong { display:block; font-size:13px; margin-top:2px; }
								.cm-verification-preview-row { border-top:1px solid #e5e9f1; display:flex; justify-content:space-between; padding:10px 0; }
								.cm-verification-preview-row span { color:#667085; font-size:12px; }
								.cm-verification-preview-row strong { font-size:12px; }
								.cm-verification-preview-credential { background:#fbfcfa; border:1px solid #dfe3dd; border-left:3px solid var(--cm-preview-primary); border-radius:2px; margin-top:16px; padding:13px; }
								.cm-verification-preview:not(.has-credential) .cm-verification-preview-credential { display:none; }
								.cm-verification-preview-credential > span { color:var(--cm-preview-primary); display:block; font-size:9px; font-weight:750; letter-spacing:.08em; text-transform:uppercase; }
								.cm-verification-preview-credential > strong { display:block; font-size:13px; line-height:1.35; margin:5px 0 3px; }
								.cm-verification-preview-credential > small { color:#58657a; display:block; font-size:11px; }
								.cm-verification-preview-credential button { background:var(--cm-preview-primary); border:0; border-radius:4px; color:#fff; font-size:11px; font-weight:700; margin-top:10px; padding:7px 9px; }
								.cm-verification-preview-credential .cm-verification-preview-download { background:transparent; border:1px solid var(--cm-preview-primary); color:var(--cm-preview-primary); margin-left:5px; }
								.cm-verification-preview.is-registry { background:#edf2f6; border-color:#cfdbe6; padding:24px; }
								.cm-verification-preview.is-registry .cm-verification-preview-card { border-color:#cfdbe6; border-radius:8px; border-top:1px solid #cfdbe6; box-shadow:0 9px 20px rgba(20,49,79,.11); color:#12233b; }
								.cm-verification-preview.is-registry .cm-verification-preview-header { background:var(--cm-preview-primary); padding:19px 24px; }
								.cm-verification-preview.is-registry .cm-verification-preview-header span,.cm-verification-preview.is-registry .cm-verification-preview-header strong { color:#fff; }
								.cm-verification-preview.is-registry .cm-verification-preview-header strong { font-family:inherit; font-weight:700; }
								.cm-verification-preview.is-registry .cm-verification-preview-status { background:#e9f8ef; border:1px solid #b9e6c9; border-left:4px solid #16874b; border-radius:5px; padding:10px; }
								.cm-verification-preview.is-registry .cm-verification-preview-row { background:#f5f8fa; padding-left:9px; padding-right:9px; }
								.cm-verification-preview.is-registry .cm-verification-preview-credential { background:var(--cm-preview-soft); border:1px solid color-mix(in srgb, var(--cm-preview-primary) 26%, #fff); border-radius:6px; }
								.cm-verification-preview.is-heritage { background:#f4f1e9; padding:28px 52px; }
								.cm-verification-preview.is-heritage .cm-verification-preview-card { border:5px double var(--cm-preview-primary); border-radius:0; border-top:5px double var(--cm-preview-primary); box-shadow:none; text-align:center; }
								.cm-verification-preview.is-heritage .cm-verification-preview-header { background:#fffdf8; }
								.cm-verification-preview.is-heritage .cm-verification-preview-status { background:#fff; border:1px solid var(--cm-preview-primary); border-radius:0; justify-content:center; padding:10px; }
								.cm-verification-preview.is-heritage .cm-verification-preview-credential { background:#fff; border:1px solid var(--cm-preview-primary); border-radius:0; text-align:center; }
								.cm-verification-preview.is-folio { background:#f3f1ed; padding:24px; }
								.cm-verification-preview.is-folio .cm-verification-preview-card { border-color:#dedbd4; border-radius:0; border-top:1px solid #dedbd4; box-shadow:none; display:grid; grid-template-columns:35% 65%; max-width:540px; }
								.cm-verification-preview.is-folio .cm-verification-preview-header { background:var(--cm-preview-primary); display:flex; flex-direction:column; justify-content:flex-end; padding:20px; }
								.cm-verification-preview.is-folio .cm-verification-preview-header span,.cm-verification-preview.is-folio .cm-verification-preview-header strong { color:#fff; }
								.cm-verification-preview.is-folio .cm-verification-preview-header strong { font-family:inherit; font-size:19px; font-weight:700; }
								.cm-verification-preview.is-folio .cm-verification-preview-content { padding:16px; }
								.cm-verification-preview.is-folio .cm-verification-preview-status { background:transparent; border:0; border-bottom:2px solid var(--cm-preview-primary); border-radius:0; padding:0 0 10px; }
								.cm-verification-preview.is-folio .cm-verification-preview-credential { border-left:3px solid var(--cm-preview-primary); border-radius:0; }
								.cm-verification-preview.is-night { background:#121619; border-color:#344046; }
								.cm-verification-preview.is-night .cm-verification-preview-card { background:#1c2327; border-color:#344046; border-radius:12px; border-top:1px solid #344046; box-shadow:0 12px 30px rgba(0,0,0,.3); color:#f2f4f1; }
								.cm-verification-preview.is-night .cm-verification-preview-header { background:#20292e; border-bottom:1px solid #344046; }
								.cm-verification-preview.is-night .cm-verification-preview-header strong,.cm-verification-preview.is-night .cm-verification-preview-row strong { color:#f2f4f1; font-family:inherit; font-weight:700; }
								.cm-verification-preview.is-night .cm-verification-preview-row { border-color:#344046; }
								.cm-verification-preview.is-night .cm-verification-preview-row span,.cm-verification-preview.is-night .cm-verification-preview-credential > small { color:#b9c3c2; }
								.cm-verification-preview.is-night .cm-verification-preview-status { background:#173328; border-color:#286141; padding:10px; }
								.cm-verification-preview.is-night .cm-verification-preview-credential { background:#202a2d; border-color:#3a484d; border-radius:8px; }
								@media (max-width:782px) { .cm-verification-preview { max-width:100%; } .cm-verification-preview.is-heritage { padding:20px; } }
							</style>
							<script>
								document.addEventListener( 'DOMContentLoaded', function () {
									var preview = document.getElementById( 'cm-verification-preview' );
									var layout = document.getElementById( 'cm-verification-style' );
									var accent = document.getElementById( 'cm-verification-accent' );
									var credentialToggle = document.getElementById( 'cm-verifiable-credentials-enabled' );
									var label = document.getElementById( 'cm-verification-preview-label' );
									function updatePreview() {
										preview.className = 'cm-verification-preview is-' + layout.value + ' accent-' + accent.value + ( credentialToggle && credentialToggle.checked ? ' has-credential' : '' );
										label.textContent = layout.options[ layout.selectedIndex ].text.split( ' —' )[ 0 ] + ' / ' + accent.options[ accent.selectedIndex ].text;
									}
									layout.addEventListener( 'change', updatePreview );
									accent.addEventListener( 'change', updatePreview );
									if ( credentialToggle ) { credentialToggle.addEventListener( 'change', updatePreview ); }
								} );
							</script>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cm-verification-style"><?php esc_html_e( 'Verification layout', 'certificate-manager' ); ?></label></th>
						<td>
							<select id="cm-verification-style" name="certificate_manager_settings[verification_style]">
								<option value="editorial" <?php selected( $preview_style, 'editorial' ); ?>><?php esc_html_e( 'Editorial — refined, quiet and typography-led', 'certificate-manager' ); ?></option>
								<option value="registry" <?php selected( $preview_style, 'registry' ); ?>><?php esc_html_e( 'Registry — structured and institutional', 'certificate-manager' ); ?></option>
								<option value="heritage" <?php selected( $preview_style, 'heritage' ); ?>><?php esc_html_e( 'Heritage — formal certificate presentation', 'certificate-manager' ); ?></option>
								<option value="folio" <?php selected( $preview_style, 'folio' ); ?>><?php esc_html_e( 'Folio — contemporary split-page layout', 'certificate-manager' ); ?></option>
								<option value="night" <?php selected( $preview_style, 'night' ); ?>><?php esc_html_e( 'Night — high-contrast dark register', 'certificate-manager' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Applies to the public certificate lookup and verification result pages on desktop and mobile.', 'certificate-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cm-verification-accent"><?php esc_html_e( 'Accent colour', 'certificate-manager' ); ?></label></th>
						<td>
							<select id="cm-verification-accent" name="certificate_manager_settings[verification_accent]">
								<option value="indigo" <?php selected( $settings['verification_accent'] ?? 'indigo', 'indigo' ); ?>><?php esc_html_e( 'Indigo', 'certificate-manager' ); ?></option>
								<option value="emerald" <?php selected( $settings['verification_accent'] ?? 'indigo', 'emerald' ); ?>><?php esc_html_e( 'Emerald', 'certificate-manager' ); ?></option>
								<option value="violet" <?php selected( $settings['verification_accent'] ?? 'indigo', 'violet' ); ?>><?php esc_html_e( 'Violet', 'certificate-manager' ); ?></option>
								<option value="amber" <?php selected( $settings['verification_accent'] ?? 'indigo', 'amber' ); ?>><?php esc_html_e( 'Amber', 'certificate-manager' ); ?></option>
								<option value="charcoal" <?php selected( $settings['verification_accent'] ?? 'indigo', 'charcoal' ); ?>><?php esc_html_e( 'Charcoal', 'certificate-manager' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Used for headings, controls and decorative elements in the selected layout.', 'certificate-manager' ); ?></p>
						</td>
					</tr>
					<?php $this->render_role_permissions(); ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default expiry', 'certificate-manager' ); ?></th>
						<td>
							<input type="hidden" name="certificate_manager_settings[default_expiry_enabled]" value="0">
							<label><input type="checkbox" name="certificate_manager_settings[default_expiry_enabled]" value="1" <?php echo ! empty( $settings['default_expiry_enabled'] ) ? 'checked' : ''; ?>> <?php esc_html_e( 'Enable certificate expiry by default', 'certificate-manager' ); ?></label>
							<p><input class="small-text" type="number" min="1" name="certificate_manager_settings[default_expiry_quantity]" value="<?php echo esc_attr( $settings['default_expiry_quantity'] ?? 1 ); ?>">
							<select name="certificate_manager_settings[default_expiry_unit]">
								<?php foreach ( array( 'hours', 'days', 'months', 'years' ) as $unit ) : ?>
									<option value="<?php echo esc_attr( $unit ); ?>" <?php echo ( $settings['default_expiry_unit'] ?? 'years' ) === $unit ? 'selected' : ''; ?>><?php echo esc_html( ucfirst( $unit ) ); ?></option>
								<?php endforeach; ?>
							</select></p>
							<p><label><?php esc_html_e( 'Show the renewal warning', 'certificate-manager' ); ?> <input class="small-text" type="number" min="1" max="365" name="certificate_manager_settings[expiry_renewal_notice_days]" value="<?php echo esc_attr( $settings['expiry_renewal_notice_days'] ?? 30 ); ?>"> <?php esc_html_e( 'days before expiry', 'certificate-manager' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'Certificates in this period show their remaining days and a prominent replacement action.', 'certificate-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cm-number-format"><?php esc_html_e( 'Certificate number format', 'certificate-manager' ); ?></label></th>
						<td><input class="regular-text" id="cm-number-format" type="text" name="certificate_manager_settings[default_number_format]" value="<?php echo esc_attr( $settings['default_number_format'] ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Certificate requests', 'certificate-manager' ); ?></th>
						<td><label><input type="checkbox" name="certificate_manager_settings[enable_certificate_requests]" value="1" <?php checked( ! empty( $settings['enable_certificate_requests'] ) ); ?>> <?php esc_html_e( 'Allow people to request certificates by email from the verification page.', 'certificate-manager' ); ?></label><p class="description"><?php esc_html_e( 'The page never reveals whether a certificate exists. Matching active certificates trigger the certificate-request webhook instead.', 'certificate-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verifiable credentials', 'certificate-manager' ); ?></th>
					<td><input type="hidden" name="certificate_manager_settings[verifiable_credentials_enabled]" value="0"><label><input id="cm-verifiable-credentials-enabled" type="checkbox" name="certificate_manager_settings[verifiable_credentials_enabled]" value="1" <?php checked( ! isset( $settings['verifiable_credentials_enabled'] ) || ! empty( $settings['verifiable_credentials_enabled'] ) ); ?>> <?php esc_html_e( 'Issue cryptographically signed W3C Verifiable Credentials for new certificates.', 'certificate-manager' ); ?></label><p class="description"><?php esc_html_e( 'Recipients can download a W3C Verifiable Credential 2.0 (VC-JWT) from a valid certificate’s verification page. OpenSSL is required to generate the issuer signing key. Public credentials never include email addresses or arbitrary certificate fields.', 'certificate-manager' ); ?></p><label><input type="hidden" name="certificate_manager_settings[wallet_issuance_enabled]" value="0"><input type="checkbox" name="certificate_manager_settings[wallet_issuance_enabled]" value="1" <?php checked( ! isset( $settings['wallet_issuance_enabled'] ) || ! empty( $settings['wallet_issuance_enabled'] ) ); ?>> <?php esc_html_e( 'Enable private wallet links through OpenID4VCI.', 'certificate-manager' ); ?></label><p class="description"><?php esc_html_e( 'Recipients enter their certificate email address on the public verification page. A matching email receives a short-lived private wallet link. It requires HTTPS.', 'certificate-manager' ); ?></p><label><?php esc_html_e( 'Private wallet-link delivery', 'certificate-manager' ); ?> <select name="certificate_manager_settings[wallet_link_delivery_mode]"><option value="email" <?php selected( $settings['wallet_link_delivery_mode'] ?? 'email', 'email' ); ?>><?php esc_html_e( 'WordPress email', 'certificate-manager' ); ?></option><option value="webhook" <?php selected( $settings['wallet_link_delivery_mode'] ?? 'email', 'webhook' ); ?>><?php esc_html_e( 'Webhook', 'certificate-manager' ); ?></option><option value="both" <?php selected( $settings['wallet_link_delivery_mode'] ?? 'email', 'both' ); ?>><?php esc_html_e( 'Both email and webhook', 'certificate-manager' ); ?></option></select></label><p class="description"><?php esc_html_e( 'Webhook delivery sends a signed wallet-link-requested event to configured webhooks. Use it when another system sends recipient emails.', 'certificate-manager' ); ?></p><label><input type="hidden" name="certificate_manager_settings[verifiable_credentials_include_recipient_name]" value="0"><input type="checkbox" name="certificate_manager_settings[verifiable_credentials_include_recipient_name]" value="1" <?php checked( ! empty( $settings['verifiable_credentials_include_recipient_name'] ) ); ?>> <?php esc_html_e( 'Include the recipient name in the downloadable credential.', 'certificate-manager' ); ?></label><p class="description"><?php esc_html_e( 'Leave this off unless recipients have agreed for their name to be available to anyone with the certificate QR code.', 'certificate-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Certificate issue delivery', 'certificate-manager' ); ?></th>
						<td><label for="cm-certificate-issuance-delivery-mode"><?php esc_html_e( 'When a certificate is issued', 'certificate-manager' ); ?></label> <select id="cm-certificate-issuance-delivery-mode" name="certificate_manager_settings[certificate_issuance_delivery_mode]"><option value="webhook" <?php selected( $settings['certificate_issuance_delivery_mode'] ?? 'webhook', 'webhook' ); ?>><?php esc_html_e( 'Send configured webhooks only', 'certificate-manager' ); ?></option><option value="email" <?php selected( $settings['certificate_issuance_delivery_mode'] ?? 'webhook', 'email' ); ?>><?php esc_html_e( 'Send WordPress email only', 'certificate-manager' ); ?></option><option value="both" <?php selected( $settings['certificate_issuance_delivery_mode'] ?? 'webhook', 'both' ); ?>><?php esc_html_e( 'Send both email and configured webhooks', 'certificate-manager' ); ?></option><option value="none" <?php selected( $settings['certificate_issuance_delivery_mode'] ?? 'webhook', 'none' ); ?>><?php esc_html_e( 'Do not deliver automatically', 'certificate-manager' ); ?></option></select><p class="description"><?php esc_html_e( 'WordPress email uses the configured certificate email subject and body, with a certificate-specific verification link. Webhooks still respect the webhooks linked to the template.', 'certificate-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Certificate email', 'certificate-manager' ); ?></th>
						<td><label for="cm-default-email-subject"><?php esc_html_e( 'Subject', 'certificate-manager' ); ?></label><br><input class="regular-text" id="cm-default-email-subject" type="text" name="certificate_manager_settings[default_email_subject]" value="<?php echo esc_attr( ['default_email_subject'] ?? esc_attr__( 'Your Certificate is Ready', 'certificate-manager' ) ); ?>"><p><label for="cm-default-email-body"><?php esc_html_e( 'Message', 'certificate-manager' ); ?></label><br><textarea class="large-text" rows="7" id="cm-default-email-body" name="certificate_manager_settings[default_email_body]"><?php echo esc_textarea( $settings['default_email_body'] ?? '' ); ?></textarea></p><p class="description"><?php esc_html_e( 'Useful placeholders: {{recipient_name}}, {{certificate_number}}, {{issue_date}}, {{expiry_date}}, {{verification_url}}, {{site_name}}. WordPress sends this through your site’s normal mail configuration.', 'certificate-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verification page', 'certificate-manager' ); ?></th>
						<td>
							<label for="cm-verification-page-id"><?php esc_html_e( 'Use this WordPress page for certificate links and QR codes', 'certificate-manager' ); ?></label>
							<?php wp_dropdown_pages( array( 'name' => 'certificate_manager_settings[verification_page_id]', 'id' => 'cm-verification-page-id', 'selected' => absint( $settings['verification_page_id'] ?? 0 ), 'show_option_none' => __( 'Select a verification page', 'certificate-manager' ), 'option_none_value' => '0' ) ); ?>
							<p class="description"><?php esc_html_e( 'The selected page must contain the [certificate_verification] shortcode. QR codes always use Certificate Manager’s stable link first, then redirect here.', 'certificate-manager' ); ?></p>
							<label for="cm-verification-page-url"><?php esc_html_e( 'Custom verification URL (optional)', 'certificate-manager' ); ?></label><br>
							<input class="regular-text" id="cm-verification-page-url" type="url" name="certificate_manager_settings[verification_page_url]" value="<?php echo esc_attr( $settings['verification_page_url'] ?? '' ); ?>" placeholder="https://example.com/verification/">
							<p class="description"><?php esc_html_e( 'Overrides the selected page. QR codes first open the stable Certificate Manager link, then redirect here with the verification token.', 'certificate-manager' ); ?></p>
							<p><?php esc_html_e( 'Add this shortcode to a page where you want the verification container to appear:', 'certificate-manager' ); ?> <code>[certificate_verification]</code></p>
							<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cm_create_verification_page' ), 'cm_create_verification_page' ) ); ?>"><?php esc_html_e( 'Create verification page', 'certificate-manager' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cm-token-lifetime"><?php esc_html_e( 'Download token lifetime', 'certificate-manager' ); ?></label></th>
						<td><input class="small-text" id="cm-token-lifetime" type="number" min="60" name="certificate_manager_settings[download_token_lifetime]" value="<?php echo esc_attr( $settings['download_token_lifetime'] ?? 900 ); ?>"> <?php esc_html_e( 'seconds', 'certificate-manager' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Log retention', 'certificate-manager' ); ?></th>
						<td>
							<input type="hidden" name="certificate_manager_settings[audit_retention_enabled]" value="0">
							<label><input type="checkbox" name="certificate_manager_settings[audit_retention_enabled]" value="1" <?php echo ! empty( $settings['audit_retention_enabled'] ) ? 'checked' : ''; ?>> <?php esc_html_e( 'Keep audit logs for', 'certificate-manager' ); ?></label>
							<input class="small-text" type="number" min="7" name="certificate_manager_settings[audit_retention_days]" value="<?php echo esc_attr( $settings['audit_retention_days'] ?? 365 ); ?>"> <?php esc_html_e( 'days', 'certificate-manager' ); ?><br>
							<input type="hidden" name="certificate_manager_settings[operational_retention_enabled]" value="0">
							<label><input type="checkbox" name="certificate_manager_settings[operational_retention_enabled]" value="1" <?php echo ! empty( $settings['operational_retention_enabled'] ) ? 'checked' : ''; ?>> <?php esc_html_e( 'Keep operational logs for', 'certificate-manager' ); ?></label>
							<input class="small-text" type="number" min="7" name="certificate_manager_settings[operational_retention_days]" value="<?php echo esc_attr( $settings['operational_retention_days'] ?? 90 ); ?>"> <?php esc_html_e( 'days', 'certificate-manager' ); ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php $this->render_danger_zone(); ?>
		</div>
		<?php
	}

	public function render_webhooks_page() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'cm_manage_settings' ) ) { return; }
		$webhooks = $this->webhook_repo->get_all();
		$payload_fields = $this->get_webhook_payload_field_labels();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Certificate Manager Webhooks', 'certificate-manager' ); ?></h1>
			<style>.cm-issue-webhooks{max-width:1000px}.cm-webhook-form,.cm-webhook-card{margin:18px 0;padding:20px;background:#fff;border:1px solid #c3c4c7}.cm-webhook-form label{display:inline-block;margin:0 16px 12px 0}.cm-webhook-form input[type="text"],.cm-webhook-form input[type="url"]{display:block;margin-top:5px;min-width:260px}.cm-webhook-payload-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px 14px}.cm-webhook-payload-options label{display:block;padding:7px 9px;margin:0;border:1px solid #dcdcde;border-radius:4px}.cm-webhook-list{display:grid;gap:14px}.cm-webhook-card{margin:0}.cm-webhook-card h3{margin-top:0}.cm-webhook-card code{overflow-wrap:anywhere}</style>
			<section class="cm-issue-webhooks"><p><?php esc_html_e( 'Send only the certificate data each endpoint needs. Choose WordPress email, webhooks, or both in Settings.', 'certificate-manager' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="cm-webhook-form"><input type="hidden" name="action" value="cm_save_issue_webhook"><?php wp_nonce_field( 'cm_save_issue_webhook' ); ?><p><label><?php esc_html_e( 'Trigger', 'certificate-manager' ); ?><select name="event"><option value="certificate_issued"><?php esc_html_e( 'Certificate issued', 'certificate-manager' ); ?></option><option value="certificate_request_valid"><?php esc_html_e( 'Valid certificate request', 'certificate-manager' ); ?></option><option value="wallet_link_requested"><?php esc_html_e( 'Private wallet link requested', 'certificate-manager' ); ?></option></select></label> <label><?php esc_html_e( 'Name', 'certificate-manager' ); ?><input type="text" name="name" required placeholder="Zapier"></label> <label><?php esc_html_e( 'HTTPS webhook URL', 'certificate-manager' ); ?><input class="regular-text" type="url" name="url" required placeholder="https://hooks.zapier.com/..." ></label></p><fieldset><legend><?php esc_html_e( 'Data to send', 'certificate-manager' ); ?></legend><p class="description"><?php esc_html_e( 'All fields are selected by default. Untick anything this endpoint does not need.', 'certificate-manager' ); ?></p><div class="cm-webhook-payload-options"><?php foreach ( $payload_fields as $key => $label ) : ?><label><input type="checkbox" name="payload_fields[]" value="<?php echo esc_attr( $key ); ?>" checked> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></div></fieldset><p><button class="button button-primary" type="submit"><?php esc_html_e( 'Add webhook', 'certificate-manager' ); ?></button></p></form>
			<?php if ( $webhooks ) : ?><h2><?php esc_html_e( 'Configured webhooks', 'certificate-manager' ); ?></h2><div class="cm-webhook-list"><?php foreach ( $webhooks as $webhook ) : $selected_fields = ! empty( $webhook['payload_fields'] ) ? $webhook['payload_fields'] : array_keys( $payload_fields ); ?><article class="cm-webhook-card"><h3><?php echo esc_html( $webhook['name'] ); ?><?php if ( empty( $webhook['is_active'] ) ) { esc_html_e( ' (disabled)', 'certificate-manager' ); } ?></h3><p><code><?php echo esc_html( $webhook['url'] ); ?></code></p><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="cm_update_issue_webhook_payload"><input type="hidden" name="webhook_id" value="<?php echo esc_attr( $webhook['id'] ); ?>"><?php wp_nonce_field( 'cm_update_issue_webhook_payload_' . absint( $webhook['id'] ) ); ?><fieldset><legend><?php esc_html_e( 'Data to send', 'certificate-manager' ); ?></legend><div class="cm-webhook-payload-options"><?php foreach ( $payload_fields as $key => $label ) : ?><label><input type="checkbox" name="payload_fields[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected_fields, true ) ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></div></fieldset><p><button class="button" type="submit"><?php esc_html_e( 'Save data selection', 'certificate-manager' ); ?></button></p></form><form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="cm_delete_issue_webhook"><input type="hidden" name="webhook_id" value="<?php echo esc_attr( $webhook['id'] ); ?>"><?php wp_nonce_field( 'cm_delete_issue_webhook_' . absint( $webhook['id'] ) ); ?><button class="button-link-delete" type="submit"><?php esc_html_e( 'Remove webhook', 'certificate-manager' ); ?></button></form></article><?php endforeach; ?></div><?php endif; ?>
			</section>
		</div>
		<?php
	}

	public function save_issue_webhook() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'cm_manage_settings' ) ) { wp_die( esc_html__( 'Permission denied.', 'certificate-manager' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'cm_save_issue_webhook' );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$event = sanitize_key( wp_unslash( $_POST['event'] ?? 'certificate_issued' ) );
		$event = in_array( $event, array( 'certificate_issued', 'certificate_request_valid', 'wallet_link_requested' ), true ) ? $event : 'certificate_issued';
		$payload_fields = $this->sanitize_webhook_payload_fields( $_POST['payload_fields'] ?? array() );
		if ( '' === $name || ! wp_http_validate_url( $url ) || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) { $this->redirect_to_webhooks(); }
		$this->webhook_repo->create( array( 'name' => $name, 'url' => $url, 'events' => array( $event ), 'payload_fields' => $payload_fields, 'enabled' => 1 ) );
		$this->redirect_to_webhooks();
	}

	public function update_issue_webhook_payload() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'cm_manage_settings' ) ) { wp_die( esc_html__( 'Permission denied.', 'certificate-manager' ), '', array( 'response' => 403 ) ); }
		$webhook_id = absint( $_POST['webhook_id'] ?? 0 );
		check_admin_referer( 'cm_update_issue_webhook_payload_' . $webhook_id );
		$this->webhook_repo->update( $webhook_id, array( 'payload_fields' => $this->sanitize_webhook_payload_fields( $_POST['payload_fields'] ?? array() ) ) );
		$this->redirect_to_webhooks();
	}

	public function create_verification_page() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'cm_manage_settings' ) ) { wp_die( esc_html__( 'Permission denied.', 'certificate-manager' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'cm_create_verification_page' );
		$page_id = wp_insert_post( array( 'post_title' => __( 'Verify Certificate', 'certificate-manager' ), 'post_name' => 'verify-certificate', 'post_content' => '[certificate_verification]', 'post_status' => 'publish', 'post_type' => 'page' ), true );
		if ( ! is_wp_error( $page_id ) && $page_id ) { update_option( 'certificate_manager_verification_page_id', $page_id ); $this->settings->update_batch( array( 'verification_page_id' => $page_id, 'verification_page_url' => '' ) ); }
		$this->redirect_to_settings();
	}

	public function delete_issue_webhook() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'cm_manage_settings' ) ) { wp_die( esc_html__( 'Permission denied.', 'certificate-manager' ), '', array( 'response' => 403 ) ); }
		$webhook_id = absint( $_POST['webhook_id'] ?? 0 );
		check_admin_referer( 'cm_delete_issue_webhook_' . $webhook_id );
		$this->webhook_repo->delete( $webhook_id );
		$this->redirect_to_webhooks();
	}

	private function redirect_to_webhooks() {
		wp_safe_redirect( admin_url( 'admin.php?page=cm_integrations' ) );
		exit;
	}

	private function redirect_to_settings() {
		wp_safe_redirect( admin_url( 'admin.php?page=cm_settings' ) );
		exit;
	}

	private function get_webhook_payload_field_labels(): array {
		return array(
			'event' => __( 'Event type', 'certificate-manager' ),
			'certificate_id' => __( 'Certificate ID', 'certificate-manager' ),
			'internal_id' => __( 'Internal ID', 'certificate-manager' ),
			'certificate_number' => __( 'Certificate number', 'certificate-manager' ),
			'recipient_name' => __( 'Recipient name', 'certificate-manager' ),
			'recipient_email' => __( 'Recipient email', 'certificate-manager' ),
			'status' => __( 'Certificate status', 'certificate-manager' ),
			'issue_date' => __( 'Issue date', 'certificate-manager' ),
			'expiry_date' => __( 'Expiry date', 'certificate-manager' ),
			'template_id' => __( 'Template ID', 'certificate-manager' ),
			'template_name' => __( 'Template name', 'certificate-manager' ),
			'template_fields' => __( 'Template variable values', 'certificate-manager' ),
			'certificates' => __( 'Matching certificates', 'certificate-manager' ),
			'certificate_count' => __( 'Matching certificate count', 'certificate-manager' ),
			'wallet_claim_url' => __( 'Private wallet link', 'certificate-manager' ),
			'verification_url' => __( 'Verification link', 'certificate-manager' ),
			'issued_at' => __( 'Webhook sent time', 'certificate-manager' ),
		);
	}

	private function sanitize_webhook_payload_fields( $fields ): array {
		$allowed = array_keys( $this->get_webhook_payload_field_labels() );
		$fields = is_array( $fields ) ? array_map( 'sanitize_key', wp_unslash( $fields ) ) : array();
		$fields = array_values( array_intersect( $allowed, $fields ) );
		return $fields ? $fields : $allowed;
	}

	private function render_danger_zone() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'cm_manage_settings' ) ) {
			return;
		}
		?>
		<section class="cm-danger-zone">
			<h2><?php esc_html_e( 'Testing cleanup', 'certificate-manager' ); ?></h2>
			<p><?php esc_html_e( 'Administrators can permanently remove test certificates or templates. Audit and operational logs are never cleared here.', 'certificate-manager' ); ?></p>
			<div class="cm-danger-zone-actions">
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="cm-purge-form" data-confirmation="DELETE CERTIFICATES" data-label="<?php esc_attr_e( 'all certificates', 'certificate-manager' ); ?>">
					<input type="hidden" name="action" value="cm_purge_data">
					<input type="hidden" name="target" value="certificates">
					<?php wp_nonce_field( 'cm_purge_data_certificates' ); ?>
					<h3><?php esc_html_e( 'Delete all certificates', 'certificate-manager' ); ?></h3>
					<p><?php esc_html_e( 'Permanently deletes every certificate, its saved fields, download tokens, number sequences and generated PDFs. Logs are retained.', 'certificate-manager' ); ?></p>
					<label><input type="checkbox" name="acknowledge" value="1" required> <?php esc_html_e( 'I understand this cannot be undone.', 'certificate-manager' ); ?></label>
					<label><?php esc_html_e( 'Type DELETE CERTIFICATES to continue', 'certificate-manager' ); ?><input type="text" name="confirmation" required autocomplete="off"></label>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Permanently delete certificates', 'certificate-manager' ); ?></button>
				</form>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="cm-purge-form" data-confirmation="DELETE TEMPLATES" data-label="<?php esc_attr_e( 'all templates', 'certificate-manager' ); ?>">
					<input type="hidden" name="action" value="cm_purge_data">
					<input type="hidden" name="target" value="templates">
					<?php wp_nonce_field( 'cm_purge_data_templates' ); ?>
					<h3><?php esc_html_e( 'Delete all templates', 'certificate-manager' ); ?></h3>
					<p><?php esc_html_e( 'Permanently deletes every template, its versions and template mappings. Clear certificates first to protect their historical records. Logs are retained.', 'certificate-manager' ); ?></p>
					<label><input type="checkbox" name="acknowledge" value="1" required> <?php esc_html_e( 'I understand this cannot be undone.', 'certificate-manager' ); ?></label>
					<label><?php esc_html_e( 'Type DELETE TEMPLATES to continue', 'certificate-manager' ); ?><input type="text" name="confirmation" required autocomplete="off"></label>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Permanently delete templates', 'certificate-manager' ); ?></button>
				</form>
			</div>
			<style>
				.cm-danger-zone { border-top:1px solid #d63638; margin-top:36px; max-width:960px; padding-top:24px; }
				.cm-danger-zone h2 { color:#b32d2e; }
				.cm-danger-zone-actions { display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); }
				.cm-purge-form { background:#fff7f7; border:1px solid #f0b8b8; box-sizing:border-box; padding:18px; }
				.cm-purge-form h3 { margin-top:0; }
				.cm-purge-form label { display:block; margin:12px 0; }
				.cm-purge-form input[type="text"] { display:block; margin-top:6px; width:100%; }
				.cm-purge-form .button { border-color:#b32d2e; color:#b32d2e; }
			</style>
		</section>
		<?php
	}

	public function purge_data() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'cm_manage_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'certificate-manager' ), '', array( 'response' => 403 ) );
		}

		$target = sanitize_key( wp_unslash( $_POST['target'] ?? '' ) );
		$phrases = array(
			'certificates' => 'DELETE CERTIFICATES',
			'templates' => 'DELETE TEMPLATES',
		);
		if ( ! isset( $phrases[ $target ] ) ) {
			$this->redirect_after_purge( 'confirmation_failed' );
		}

		check_admin_referer( 'cm_purge_data_' . $target );
		$confirmation = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['confirmation'] ?? '' ) ) ) );
		if ( empty( $_POST['acknowledge'] ) || ! hash_equals( $phrases[ $target ], $confirmation ) ) {
			$this->redirect_after_purge( 'confirmation_failed' );
		}

		if ( 'templates' === $target && $this->cert_repo->count_all() > 0 ) {
			$this->redirect_after_purge( 'templates_require_certificate_purge' );
		}

		$result = 'certificates' === $target ? $this->cert_repo->purge_all() : $this->template_repo->purge_all();
		if ( is_wp_error( $result ) ) {
			$this->redirect_after_purge( 'purge_failed' );
		}
		if ( 'certificates' === $target ) {
			$this->remove_generated_pdfs();
		} else {
			$this->settings->update( 'default_template_id', 0 );
		}

		( new \CertificateManager\Repositories\AuditRepository() )->log( 'system', ucfirst( $target ) . 'Purged', 0, array( 'count' => $result['count'] ), array( 'type' => 'user', 'id' => get_current_user_id() ) );
		$this->redirect_after_purge( $target . '_cleared', absint( $result['count'] ) );
	}

	private function remove_generated_pdfs() {
		$upload_dir = wp_upload_dir();
		$directory = trailingslashit( $upload_dir['basedir'] ) . 'certificate-manager';
		$root = realpath( $directory );
		if ( ! $root ) {
			return;
		}

		$file_paths = glob( trailingslashit( $root ) . '*.pdf' );
		if ( false === $file_paths ) {
			return;
		}
		foreach ( $file_paths as $file_path ) {
			$real_path = realpath( $file_path );
			if ( $real_path && 0 === strpos( $real_path, trailingslashit( $root ) ) ) {
				wp_delete_file( $real_path );
			}
		}
	}

	private function redirect_after_purge( string $status, int $count = 0 ) {
		$url = add_query_arg( array( 'page' => 'cm_settings', 'cm_purge_status' => $status, 'cm_purge_count' => $count ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function render_role_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$roles = get_editable_roles();
		$capabilities = array_filter( $this->capabilities->get_all(), function ( $capability ) {
			return 'settings' !== $capability['group'];
		} );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Team access', 'certificate-manager' ); ?></th>
			<td>
				<p class="description"><?php esc_html_e( 'Choose exactly what each WordPress role can do in Certificate Manager. Administrators always retain full access. Users with the Certificate Issuer role can only view and issue certificates, with no access to other WordPress settings.', 'certificate-manager' ); ?></p>
				<div class="cm-role-permissions">
					<?php foreach ( $roles as $role_key => $role_data ) : ?>
						<?php if ( 'administrator' === $role_key ) { continue; } ?>
						<?php $role = get_role( $role_key ); ?>
						<details class="cm-role-permission">
							<summary><?php echo esc_html( $role_data['name'] ); ?></summary>
							<div class="cm-role-permission-groups">
								<?php foreach ( array( 'certificates' => __( 'Certificates', 'certificate-manager' ), 'templates' => __( 'Templates', 'certificate-manager' ), 'variables' => __( 'Variables', 'certificate-manager' ), 'integrations' => __( 'Integrations', 'certificate-manager' ) ) as $group => $label ) : ?>
									<?php $group_capabilities = array_filter( $capabilities, function ( $capability ) use ( $group ) { return $group === $capability['group']; } ); ?>
									<?php if ( empty( $group_capabilities ) ) { continue; } ?>
									<fieldset><legend><?php echo esc_html( $label ); ?></legend>
										<?php foreach ( $group_capabilities as $capability_key => $capability ) : ?>
											<label><input type="checkbox" name="certificate_manager_settings[role_capabilities][<?php echo esc_attr( $role_key ); ?>][<?php echo esc_attr( $capability_key ); ?>]" value="1" <?php checked( $role && $role->has_cap( 'cm_' . $capability_key ) ); ?>> <?php echo esc_html( $capability['label'] ); ?></label>
										<?php endforeach; ?>
									</fieldset>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
				<style>
					.cm-role-permissions { display:grid; gap:8px; max-width:760px; }
					.cm-role-permission { background:#fff; border:1px solid #dcdcde; }
					.cm-role-permission summary { cursor:pointer; font-weight:600; padding:12px; }
					.cm-role-permission-groups { display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); padding:0 12px 14px; }
					.cm-role-permission fieldset { border:0; margin:0; padding:0; }
					.cm-role-permission legend { font-weight:600; margin-bottom:6px; }
					.cm-role-permission label { display:block; margin:5px 0; }
				</style>
			</td>
		</tr>
		<?php
	}
	
	/**
	 * Sanitize settings
	 *
	 * @param array $input Input values
	 * @return array
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = $this->settings->get_all();

		if ( isset( $input['role_capabilities'] ) && current_user_can( 'manage_options' ) ) {
			$this->save_role_permissions( (array) $input['role_capabilities'] );
		}

		if ( isset( $input['pixabay_api_key'] ) ) {
			$sanitized['pixabay_api_key'] = sanitize_text_field( $input['pixabay_api_key'] );
		}

		if ( isset( $input['verification_style'] ) ) {
			$styles = array( 'editorial', 'registry', 'heritage', 'folio', 'night' );
			$sanitized['verification_style'] = in_array( $input['verification_style'], $styles, true ) ? $input['verification_style'] : 'editorial';
		}

		if ( isset( $input['verification_accent'] ) ) {
			$accents = array( 'indigo', 'emerald', 'violet', 'amber', 'charcoal' );
			$sanitized['verification_accent'] = in_array( $input['verification_accent'], $accents, true ) ? $input['verification_accent'] : 'indigo';
		}

		if ( isset( $input['verification_page_id'] ) ) {
			$page_id = absint( $input['verification_page_id'] );
			$sanitized['verification_page_id'] = $page_id && 'page' === get_post_type( $page_id ) ? $page_id : 0;
			update_option( 'certificate_manager_verification_page_id', $sanitized['verification_page_id'] );
		}

		if ( isset( $input['verification_page_url'] ) ) {
			$verification_url = esc_url_raw( wp_unslash( $input['verification_page_url'] ) );
			$sanitized['verification_page_url'] = $verification_url && wp_http_validate_url( $verification_url ) ? $verification_url : '';
			$custom_page_id = $sanitized['verification_page_url'] ? absint( url_to_postid( $sanitized['verification_page_url'] ) ) : 0;
			if ( $custom_page_id && 'page' === get_post_type( $custom_page_id ) ) {
				$sanitized['verification_page_id'] = $custom_page_id;
				update_option( 'certificate_manager_verification_page_id', $custom_page_id );
			}
		}

		$sanitized['enable_certificate_requests'] = ! empty( $input['enable_certificate_requests'] );
		$sanitized['verifiable_credentials_enabled'] = ! empty( $input['verifiable_credentials_enabled'] );
		$sanitized['wallet_issuance_enabled'] = ! empty( $input['wallet_issuance_enabled'] );
		$sanitized['verifiable_credentials_include_recipient_name'] = ! empty( $input['verifiable_credentials_include_recipient_name'] );
		$sanitized['wallet_link_delivery_mode'] = in_array( $input['wallet_link_delivery_mode'] ?? 'email', array( 'email', 'webhook', 'both' ), true ) ? $input['wallet_link_delivery_mode'] : 'email';
		$sanitized['certificate_issuance_delivery_mode'] = in_array( $input['certificate_issuance_delivery_mode'] ?? 'webhook', array( 'webhook', 'email', 'both', 'none' ), true ) ? $input['certificate_issuance_delivery_mode'] : 'webhook';
		if ( isset( $input['default_email_subject'] ) ) {
			$sanitized['default_email_subject'] = sanitize_text_field( wp_unslash( $input['default_email_subject'] ) );
		}
		if ( isset( $input['default_email_body'] ) ) {
			$sanitized['default_email_body'] = wp_kses_post( wp_unslash( $input['default_email_body'] ) );
		}
		
		if ( isset( $input['default_expiry_enabled'] ) ) {
			$sanitized['default_expiry_enabled'] = (bool) $input['default_expiry_enabled'];
		}
		
		if ( isset( $input['default_expiry_quantity'] ) ) {
			$sanitized['default_expiry_quantity'] = max( 1, (int) $input['default_expiry_quantity'] );
		}
		
		if ( isset( $input['default_expiry_unit'] ) ) {
			$valid_units = array( 'hours', 'days', 'months', 'years' );
			$sanitized['default_expiry_unit'] = in_array( $input['default_expiry_unit'], $valid_units ) ? $input['default_expiry_unit'] : 'years';
		}

		if ( isset( $input['expiry_renewal_notice_days'] ) ) {
			$sanitized['expiry_renewal_notice_days'] = min( 365, max( 1, (int) $input['expiry_renewal_notice_days'] ) );
		}
		
		if ( isset( $input['default_number_format'] ) ) {
			$sanitized['default_number_format'] = sanitize_text_field( $input['default_number_format'] );
		}
		
		if ( isset( $input['download_token_lifetime'] ) ) {
			$sanitized['download_token_lifetime'] = max( 60, (int) $input['download_token_lifetime'] );
		}
		
		if ( isset( $input['audit_retention_enabled'] ) ) {
			$sanitized['audit_retention_enabled'] = (bool) $input['audit_retention_enabled'];
		}
		
		if ( isset( $input['audit_retention_days'] ) ) {
			$sanitized['audit_retention_days'] = max( 7, (int) $input['audit_retention_days'] );
		}
		
		if ( isset( $input['operational_retention_enabled'] ) ) {
			$sanitized['operational_retention_enabled'] = (bool) $input['operational_retention_enabled'];
		}
		
		if ( isset( $input['operational_retention_days'] ) ) {
			$sanitized['operational_retention_days'] = max( 7, (int) $input['operational_retention_days'] );
		}
		
		return $sanitized;
	}

	private function save_role_permissions( array $role_permissions ) {
		$roles = get_editable_roles();
		$capability_keys = array_keys( array_filter( $this->capabilities->get_all(), function ( $capability ) {
			return 'settings' !== $capability['group'];
		} ) );

		foreach ( $roles as $role_key => $role_data ) {
			if ( 'administrator' === $role_key ) {
				continue;
			}

			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}

			$selected = array_map( 'sanitize_key', array_keys( (array) ( $role_permissions[ $role_key ] ?? array() ) ) );
			$selected = array_values( array_intersect( $selected, $capability_keys ) );
			$certificate_actions = array( 'issue_certificates', 'edit_certificates', 'revoke_certificates', 'reinstate_certificates', 'replace_certificates', 'delete_certificates', 'export_certificates' );
			$template_actions = array( 'edit_templates', 'delete_templates', 'publish_templates', 'manage_templates' );
			if ( array_intersect( $selected, $certificate_actions ) ) {
				$selected[] = 'view_certificates';
			}
			if ( array_intersect( $selected, $template_actions ) ) {
				$selected[] = 'view_template';
			}
			$selected = array_unique( $selected );

			foreach ( $capability_keys as $capability_key ) {
				$role->remove_cap( 'cm_' . $capability_key );
			}
			foreach ( $selected as $capability_key ) {
				$role->add_cap( 'cm_' . $capability_key );
			}
		}
	}
}
