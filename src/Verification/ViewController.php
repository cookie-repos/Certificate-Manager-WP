<?php
/**
 * Verification View Controller
 *
 * This is a public-facing controller. The form submissions it handles use
 * WordPress nonces embedded in the public form markup (wp_nonce_field), not
 * admin-ajax nonces. phpcs cannot always detect the nonce check that happens
 * inside the form-handling branches, so the warnings are suppressed below.
 *
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 *
 * @package CertificateManager
 */

namespace CertificateManager\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verification view controller class
 */
class ViewController {
	
	/**
	 * Verification service
	 *
	 * @var Services\VerificationService
	 */
	private $verification_service;

	/**
	 * Signed verifiable credential service.
	 *
	 * @var mixed
	 */
	private $verifiable_credential_service;
	
	/**
	 * Constructor
	 *
	 * @param Services\VerificationService $verification_service Verification service.
	 * @param mixed $verifiable_credential_service Verifiable credential service.
	 */
	public function __construct( $verification_service, $verifiable_credential_service ) {
		$this->verification_service = $verification_service;
		$this->verifiable_credential_service = $verifiable_credential_service;
	}
	
	/**
	 * Initialize frontend functionality
	 */
	public function init() {
		// OID4VCI discovery is deliberately served at the standard well-known
		// locations on the site's origin, not under wp-json. This hook runs
		// before WordPress parses the public request and does not require a
		// permalink flush when the plugin is updated.
		add_action( 'parse_request', array( $this, 'handle_openid4vci_discovery_request' ), 0 );
		add_action( 'template_redirect', array( $this, 'handle_public_view' ) );
	}

	/**
	 * Serve the two discovery documents required by OpenID4VCI's
	 * pre-authorized-code flow.
	 *
	 * @param \WP $wp WordPress request object.
	 */
	public function handle_openid4vci_discovery_request( $wp ): void {
		if ( ! $this->verifiable_credential_service->is_wallet_issuance_available() ) {
			return;
		}
		$request_path = '/' . ltrim( (string) $wp->request, '/' );
		$site_path    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$suffix       = '' === $site_path ? '' : '/' . $site_path;
		$issuer_path  = '/.well-known/openid-credential-issuer' . $suffix;
		$oauth_path   = '/.well-known/oauth-authorization-server' . $suffix;
		$request_path = untrailingslashit( $request_path );

		if ( untrailingslashit( $issuer_path ) === $request_path ) {
			$this->render_openid_json( $this->verifiable_credential_service->get_openid4vci_issuer_metadata() );
		}
		if ( untrailingslashit( $oauth_path ) === $request_path ) {
			$this->render_openid_json( $this->verifiable_credential_service->get_openid4vci_authorization_server_metadata() );
		}
	}

	/**
	 * Send an uncacheable JSON discovery response and end the public request.
	 *
	 * @param array $payload Discovery metadata.
	 */
	private function render_openid_json( array $payload ): void {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON API response.
		exit;
	}
	
	/**
	 * Handle public certificate view
	 */
	public function handle_public_view() {
		if ( '1' === sanitize_text_field( wp_unslash( $_GET['cm_credential'] ?? '' ) ) ) {
			$this->download_verifiable_credential();
			return;
		}
		if ( ! empty( $_GET['cm_wallet_claim'] ) ) {
			$this->handle_wallet_claim();
			return;
		}
		$this->redirect_legacy_verification_request();
		$this->redirect_canonical_verification_request();
		if ( ! $this->is_canonical_verification_request() && ! $this->is_verification_page() ) {
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'cm_request_certificates' === sanitize_key( wp_unslash( $_POST['cm_action'] ?? '' ) ) ) {
			$this->handle_certificate_request();
			return;
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'cm_request_wallet_link' === sanitize_key( wp_unslash( $_POST['cm_action'] ?? '' ) ) ) {
			$this->handle_wallet_link_request();
			return;
		}

		$short_token = sanitize_text_field( wp_unslash( $_GET['v'] ?? '' ) );
		$certificate_number = sanitize_text_field( wp_unslash( $_GET['certificate_number'] ?? '' ) );
		if ( '' === $short_token && '' === $certificate_number ) {
			$this->render_verification_result( array( 'status' => 'lookup' ) );
			exit;
		}

		$retry_after = $this->record_verification_attempt();
		if ( $retry_after ) {
			status_header( 429 );
			$this->render_verification_result( array(
				'status' => 'invalid',
				/* translators: %d: number of seconds to wait before retrying */
				'error' => sprintf( __( 'Too many verification attempts. Please wait %d seconds and try again.', 'certificate-manager' ), $retry_after ),
			) );
			exit;
		}

		$result = $short_token
			? $this->verification_service->verify_by_short_token( $short_token )
			: $this->verification_service->verify_by_number( $certificate_number );

		if ( is_wp_error( $result ) ) {
			status_header( 404 );
			$this->render_verification_result( array(
				'status' => 'invalid',
				'error' => $result->get_error_message(),
			) );
			exit;
		}

		$this->render_verification_result( $result );
		exit;
	}

	/**
	 * Render a wallet page reached only through a short-lived private email link.
	 */
	private function handle_wallet_claim(): void {
		$claim = $this->verifiable_credential_service->get_wallet_claim( sanitize_text_field( wp_unslash( $_GET['cm_wallet_claim'] ?? '' ) ) );
		if ( is_wp_error( $claim ) ) {
			status_header( 404 );
			$this->render_verification_result( array( 'status' => 'invalid', 'error' => __( 'This private wallet link is invalid or has expired.', 'certificate-manager' ) ) );
			exit;
		}
		$result = $this->verification_service->verify_by_short_token( (string) $claim['token'] );
		if ( is_wp_error( $result ) || 'valid' !== ( $result['status'] ?? '' ) ) {
			status_header( 404 );
			$this->render_verification_result( array( 'status' => 'invalid', 'error' => __( 'This private wallet link is no longer available.', 'certificate-manager' ) ) );
			exit;
		}
		$result['wallet_claim'] = true;
		$this->render_verification_result( $result );
		exit;
	}

	/**
	 * Deliver the recipient's signed W3C VC-JWT without WordPress
	 * JSON-encoding the compact JWS representation.
	 */
	private function download_verifiable_credential(): void {
		$token = sanitize_text_field( wp_unslash( $_GET['v'] ?? '' ) );
		$credential = $this->verifiable_credential_service->get_for_short_token( $token );
		if ( is_wp_error( $credential ) ) {
			$status = 'certificate_not_found' === $credential->get_error_code() ? 404 : 503;
			status_header( $status );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional server-side log for failed credential download.
			wp_die( esc_html( $credential->get_error_message() ), esc_html__( 'Signed credential unavailable', 'certificate-manager' ), array( 'response' => $status ) );
		}
		status_header( 200 );
		header( 'Content-Type: application/vc+jwt; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="certificate-credential.jwt"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $credential; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- compact signed JWT.
		exit;
	}

	private function is_canonical_verification_request(): bool {
		return '1' === sanitize_text_field( wp_unslash( $_GET['cm_verify'] ?? '' ) );
	}

	private function redirect_canonical_verification_request(): void {
		if ( ! $this->is_canonical_verification_request() ) {
			return;
		}

		$settings = new \CertificateManager\Core\Settings();
		$destination_url = $settings->get_verification_destination_url();
		$canonical_url = $settings->get_verification_base_url();
		if ( untrailingslashit( $destination_url ) === untrailingslashit( $canonical_url ) ) {
			return;
		}

		$parameters = array_filter( array(
			'v' => sanitize_text_field( wp_unslash( $_GET['v'] ?? '' ) ),
			'certificate_number' => sanitize_text_field( wp_unslash( $_GET['certificate_number'] ?? '' ) ),
		) );
		wp_safe_redirect( add_query_arg( $parameters, $destination_url ), 302 );
		exit;
	}

	private function redirect_legacy_verification_request(): void {
		global $wp;

		if ( ! is_404() || 'verify-certificate' !== trim( (string) $wp->request, '/' ) ) {
			return;
		}

		$settings = new \CertificateManager\Core\Settings();
		$verification_url = $settings->get_verification_destination_url();
		$legacy_url = home_url( '/verify-certificate/' );
		if ( untrailingslashit( $verification_url ) === untrailingslashit( $legacy_url ) ) {
			return;
		}

		$parameters = array_filter( array(
			'v' => sanitize_text_field( wp_unslash( $_GET['v'] ?? '' ) ),
			'certificate_number' => sanitize_text_field( wp_unslash( $_GET['certificate_number'] ?? '' ) ),
		) );
		wp_safe_redirect( add_query_arg( $parameters, $verification_url ), 302 );
		exit;
	}
	
	/**
	 * Check if the current page is the public verification page.
	 *
	 * @return bool
	 */
	private function is_verification_page(): bool {
		$settings = new \CertificateManager\Core\Settings();
		$page_id = absint( $settings->get( 'verification_page_id', 0 ) );
		$custom_url = $settings->get_verification_destination_url();
		$custom_page_id = absint( url_to_postid( $custom_url ) );
		if ( ! $page_id ) {
			$page_id = absint( get_option( 'certificate_manager_verification_page_id', 0 ) );
		}
		return ( $page_id && is_page( $page_id ) ) || ( $custom_page_id && is_page( $custom_page_id ) ) || is_page( 'verify-certificate' );
	}

	private function record_verification_attempt(): int {
		$settings = new \CertificateManager\Core\Settings();
		$limit = min( 30, max( 1, absint( $settings->get( 'rate_limit_requests', 10 ) ) ) );
		$window = min( HOUR_IN_SECONDS, max( 30, absint( $settings->get( 'rate_limit_window', MINUTE_IN_SECONDS ) ) ) );
		$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'cm_verify_' . hash( 'sha256', $ip_address ?: 'unknown' );
		$now = time();
		$attempts = get_transient( $key );
		if ( ! is_array( $attempts ) || empty( $attempts['expires_at'] ) || $attempts['expires_at'] <= $now ) {
			$attempts = array( 'count' => 0, 'expires_at' => $now + $window );
		}
		if ( $attempts['count'] >= $limit ) {
			return max( 1, $attempts['expires_at'] - $now );
		}
		$attempts['count']++;
		set_transient( $key, $attempts, max( 1, $attempts['expires_at'] - $now ) );
		return 0;
	}

	private function handle_certificate_request() {
		$settings = new \CertificateManager\Core\Settings();
		if ( ! $settings->get( 'enable_certificate_requests', false ) ) {
			$this->render_verification_result( array( 'status' => 'invalid', 'error' => __( 'Certificate requests are not available.', 'certificate-manager' ) ) );
			exit;
		}
		$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'cm_certificate_request_' . hash( 'sha256', $ip_address ?: 'unknown' );
		$attempts = absint( get_transient( $key ) );
		if ( $attempts < 5 && isset( $_POST['cm_request_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cm_request_nonce'] ) ), 'cm_request_certificates' ) ) {
			set_transient( $key, $attempts + 1, HOUR_IN_SECONDS );
			$email = sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) );
			if ( $email ) {
				global $wpdb;
				$table_name = $wpdb->prefix . 'certificate_manager_certificates';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is WP-prefixed; no caching for public verification endpoint.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, certificate_number, verification_token FROM {$table_name} WHERE recipient_email = %s AND status = %s", $email, 'active' ), ARRAY_A );
				if ( $rows ) {
					$certificates = array_map( function ( $certificate ) {
						return array( 'certificate_number' => $certificate['certificate_number'], 'verification_url' => add_query_arg( 'v', substr( $certificate['verification_token'], 0, 16 ), $this->get_verification_url() ) );
					}, $rows );
					do_action( 'certificate_manager_certificates_requested', array( 'recipient_email' => $email, 'certificate_count' => count( $certificates ), 'certificates' => $certificates ) );
				}
			}
		}
		$this->render_verification_result( array( 'status' => 'request_sent', 'error' => __( 'If certificates are available for that email address, you will receive them shortly.', 'certificate-manager' ) ) );
		exit;
	}

	/**
	 * Match an email against the currently viewed certificate and send (or
	 * webhook) its private wallet link without revealing whether it matched.
	 */
	private function handle_wallet_link_request(): void {
		$ip_address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'cm_wallet_link_request_' . hash( 'sha256', $ip_address ?: 'unknown' );
		$attempts = absint( get_transient( $key ) );
		if ( $attempts < 5 && isset( $_POST['cm_wallet_link_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cm_wallet_link_nonce'] ) ), 'cm_request_wallet_link' ) ) {
			set_transient( $key, $attempts + 1, HOUR_IN_SECONDS );
			$email = sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) );
			$token = sanitize_text_field( wp_unslash( $_POST['verification_token'] ?? '' ) );
			if ( $email && $token ) {
				$claim = $this->verifiable_credential_service->create_wallet_claim_for_email( $token, $email );
				if ( ! is_wp_error( $claim ) ) {
					$claim['recipient_email'] = $email;
					$delivery_mode = ( new \CertificateManager\Core\Settings() )->get( 'wallet_link_delivery_mode', 'email' );
					$data = array(
						'certificate_id' => $claim['certificate_id'],
						'certificate_number' => $claim['certificate_number'],
						'recipient_name' => $claim['recipient_name'],
						'recipient_email' => $email,
						'wallet_claim_url' => $claim['claim_url'],
						'status' => 'active',
					);
					if ( in_array( $delivery_mode, array( 'email', 'both' ), true ) ) {
						do_action( 'certificate_manager_wallet_link_requested', $claim );
					}
					if ( in_array( $delivery_mode, array( 'webhook', 'both' ), true ) ) {
						do_action( 'certificate_manager_wallet_link_webhook_requested', $data );
					}
				}
			}
		}
		$this->render_verification_result( array( 'status' => 'request_sent', 'error' => __( 'If that email matches this certificate, a private wallet link will be sent shortly.', 'certificate-manager' ) ) );
		exit;
	}

	private function get_verification_url(): string {
		$settings = new \CertificateManager\Core\Settings();
		return $settings->get_verification_base_url();
	}

	private function get_verification_style(): string {
		$settings = new \CertificateManager\Core\Settings();
		$style = sanitize_key( $settings->get( 'verification_style', 'editorial' ) );
		return in_array( $style, array( 'editorial', 'registry', 'heritage', 'folio', 'night' ), true ) ? $style : 'editorial';
	}

	private function get_verification_accent(): string {
		$settings = new \CertificateManager\Core\Settings();
		$accent = sanitize_key( $settings->get( 'verification_accent', 'indigo' ) );
		return in_array( $accent, array( 'indigo', 'emerald', 'violet', 'amber', 'charcoal' ), true ) ? $accent : 'indigo';
	}

	private function get_verification_theme_variables( string $style, string $accent ): string {
		$layouts = array(
			'editorial' => '--cm-background:#f7f7f5;--cm-surface:#ffffff;--cm-text:#18211e;--cm-muted:#626a65;--cm-border:#dfe3dd;--cm-shadow:0 18px 46px rgba(25,36,29,.08);',
			'registry' => '--cm-background:#edf2f6;--cm-surface:#ffffff;--cm-text:#12233b;--cm-muted:#576b80;--cm-border:#cfdbe6;--cm-shadow:0 16px 38px rgba(20,49,79,.11);',
			'heritage' => '--cm-background:#f4f1e9;--cm-surface:#fffdf8;--cm-text:#172d4d;--cm-muted:#6f6657;--cm-border:#e6dac0;--cm-shadow:0 22px 60px rgba(50,40,20,.14);',
			'folio' => '--cm-background:#f3f1ed;--cm-surface:#ffffff;--cm-text:#20201f;--cm-muted:#6a6864;--cm-border:#dedbd4;--cm-shadow:0 14px 36px rgba(34,31,26,.09);',
			'night' => '--cm-background:#121619;--cm-surface:#1c2327;--cm-text:#f2f4f1;--cm-muted:#b9c3c2;--cm-border:#344046;--cm-shadow:0 24px 70px rgba(0,0,0,.32);',
		);
		$accents = array(
			'indigo' => '--cm-primary:#315efb;--cm-primary-soft:#e8edff;--cm-orb:#b9c9ff;',
			'emerald' => '--cm-primary:#0b8a61;--cm-primary-soft:#e2f6ee;--cm-orb:#a8dec9;',
			'violet' => '--cm-primary:#7641d9;--cm-primary-soft:#f0e9ff;--cm-orb:#ceb8ff;',
			'amber' => '--cm-primary:#a66a00;--cm-primary-soft:#fff2d5;--cm-orb:#f0c770;',
			'charcoal' => '--cm-primary:#292929;--cm-primary-soft:#ececec;--cm-orb:#cfcfcf;',
		);
		return ( $layouts[ $style ] ?? $layouts['editorial'] ) . ( $accents[ $accent ] ?? $accents['indigo'] );
	}
	
	/**
	 * Render verification result
	 *
	 * @param array $result Verification result.
	 */
	private function render_verification_result( array $result ) {
		$certificate = $result['certificate'] ?? array();
		$status = $result['status'] ?? 'invalid';
		$status_labels = array(
			'lookup' => __( 'Verify a Certificate', 'certificate-manager' ),
			'valid' => __( 'Valid Certificate', 'certificate-manager' ),
			'revoked' => __( 'This certificate has been revoked', 'certificate-manager' ),
			'expired' => __( 'This certificate has expired', 'certificate-manager' ),
			'replaced' => __( 'This certificate has been replaced', 'certificate-manager' ),
			'request_sent' => __( 'Certificate request received', 'certificate-manager' ),
			'invalid' => __( 'Certificate not found', 'certificate-manager' ),
		);
		$status_label = $status_labels[ $status ] ?? $status_labels['invalid'];
		$is_valid = 'valid' === $status;
		$is_lookup = 'lookup' === $status;
		$certificate_number = $certificate['certificate_number'] ?? '';
		$fields = $result['public_fields'] ?? array();
		$standard_fields = array(
			'certificate_number' => __( 'Certificate Number', 'certificate-manager' ),
			'recipient_name' => __( 'Recipient', 'certificate-manager' ),
			'issue_date' => __( 'Issue Date', 'certificate-manager' ),
			'expiry_date' => __( 'Expiration Date', 'certificate-manager' ),
		);
		$style = $this->get_verification_style();
		$accent = $this->get_verification_accent();
		$theme_variables = $this->get_verification_theme_variables( $style, $accent );
		$is_negative = in_array( $status, array( 'invalid', 'revoked', 'expired', 'replaced' ), true );
		$certificate_requests_enabled = ( new \CertificateManager\Core\Settings() )->get( 'enable_certificate_requests', false );
		$credentials_enabled = ( new \CertificateManager\Core\Settings() )->get( 'verifiable_credentials_enabled', true );
		$wallet_claim = ! empty( $result['wallet_claim'] );
		$wallet_offer = false;
		$wallet_qr = false;
		$wallet_request_available = $is_valid && $credentials_enabled && ! $wallet_claim && ! empty( $certificate['verification_token_short'] ) && $this->verifiable_credential_service->is_wallet_issuance_available();
		if ( $wallet_claim && $is_valid && $credentials_enabled && ! empty( $certificate['verification_token_short'] ) && $this->verifiable_credential_service->is_wallet_issuance_available() ) {
			$wallet_offer = $this->verifiable_credential_service->create_wallet_offer( $certificate['verification_token_short'] );
			if ( ! is_wp_error( $wallet_offer ) ) {
				$wallet_qr = ( new \CertificateManager\Services\QRService() )->generate_qr( $wallet_offer['deep_link'], array( 'width' => 32, 'height' => 32, 'margin' => 1, 'error_correction' => 'M' ) );
			} else {
				$wallet_offer = false;
			}
		}
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php
			/* translators: %s: certificate number */
			echo esc_html( $certificate_number ? sprintf( __( 'Certificate #%s', 'certificate-manager' ), $certificate_number ) : __( 'Certificate Verification', 'certificate-manager' ) ); ?></title>
			<?php wp_head(); ?>
			<style>
				body.cm-verification-page { <?php echo esc_html( $theme_variables ); ?> margin:0; min-height:100vh; background:var(--cm-background); color:var(--cm-text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
				.cm-verification-shell { box-sizing:border-box; min-height:100vh; padding:72px 24px; position:relative; overflow:hidden; }
				.cm-verification-shell:before,.cm-verification-shell:after { background:var(--cm-orb); border-radius:999px; content:""; filter:blur(4px); opacity:.32; pointer-events:none; position:absolute; }
				.cm-verification-shell:before { height:340px; right:-110px; top:-160px; width:340px; }
				.cm-verification-shell:after { bottom:-160px; height:300px; left:-130px; width:300px; }
				.cm-verification-result { background:var(--cm-surface); border:1px solid var(--cm-border); border-radius:24px; box-shadow:var(--cm-shadow); box-sizing:border-box; margin:0 auto; max-width:860px; overflow:hidden; padding:0; position:relative; }
				.cm-verification-header { background:var(--cm-primary-soft); border-bottom:1px solid var(--cm-border); padding:42px 48px 34px; }
				.cm-verification-eyebrow { color:var(--cm-primary); font-size:12px; font-weight:750; letter-spacing:.11em; margin:0 0 12px; text-transform:uppercase; }
				.cm-verification-header h1 { color:var(--cm-text); font-size:clamp(30px,5vw,46px); letter-spacing:-.045em; line-height:1.05; margin:0; }
				.cm-verification-content { padding:38px 48px 42px; }
				.cm-result-banner { align-items:center; background:#e8f8ef; border:1px solid #b7e7c8; border-radius:16px; display:flex; gap:16px; margin-bottom:26px; padding:17px 20px; }
				.cm-result-banner.is-negative { background:#fff0f0; border-color:#f4c1c1; }
				.cm-result-icon { align-items:center; background:#16874b; border-radius:999px; color:#fff; display:flex; font-size:20px; font-weight:800; height:34px; justify-content:center; line-height:1; width:34px; }
				.cm-result-banner.is-negative .cm-result-icon { background:#cf3434; }
				.cm-result-label { color:var(--cm-muted); display:block; font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
				.cm-result-banner strong { color:var(--cm-text); display:block; font-size:18px; margin-top:2px; }
				.cm-template-name { color:var(--cm-muted); font-size:16px; margin:0 0 24px; }
				.cm-message { color:var(--cm-muted); font-size:17px; line-height:1.65; margin:0 0 24px; }
				.cm-meta { border-collapse:separate; border-spacing:0; width:100%; }
				.cm-meta th,.cm-meta td { border-bottom:1px solid var(--cm-border); font-size:16px; line-height:1.55; padding:17px 12px; text-align:left; vertical-align:top; }
				.cm-meta th { color:var(--cm-muted); font-size:13px; font-weight:750; letter-spacing:.035em; text-transform:uppercase; width:31%; }
				.cm-meta td { color:var(--cm-text); font-weight:540; }
				.cm-lookup-form { display:flex; gap:12px; margin-top:26px; max-width:620px; }
				.cm-lookup-form input { background:#fff; border:1px solid var(--cm-border); border-radius:11px; box-shadow:none; box-sizing:border-box; color:var(--cm-text); flex:1; font:inherit; min-height:48px; padding:0 14px; }
				.cm-lookup-form input:focus { border-color:var(--cm-primary); box-shadow:0 0 0 3px var(--cm-primary-soft); outline:0; }
				.cm-lookup-form button { background:var(--cm-primary); border:1px solid var(--cm-primary); border-radius:11px; color:#fff; cursor:pointer; font:inherit; font-weight:700; min-height:48px; padding:0 20px; }
				.cm-digital-credential { background:var(--cm-primary-soft); border:1px solid var(--cm-border); border-radius:16px; margin-top:28px; padding:22px 24px; }
				.cm-digital-credential-label { color:var(--cm-primary); display:block; font-size:12px; font-weight:750; letter-spacing:.08em; text-transform:uppercase; }
				.cm-digital-credential h2 { color:var(--cm-text); font-size:20px; letter-spacing:-.025em; line-height:1.25; margin:7px 0 6px; }
				.cm-digital-credential p { color:var(--cm-muted); font-size:14px; line-height:1.55; margin:0; max-width:620px; }
				.cm-credential-actions { align-items:center; display:flex; flex-wrap:wrap; gap:10px; margin-top:17px; }
				.cm-credential-download,.cm-wallet-add { border-radius:11px; display:inline-block; font-size:14px; font-weight:700; padding:13px 17px; text-decoration:none; }
				.cm-wallet-add { background:var(--cm-primary); color:#fff; }
				.cm-credential-download { background:transparent; border:1px solid var(--cm-primary); color:var(--cm-primary); }
				.cm-credential-download:hover,.cm-credential-download:focus,.cm-wallet-add:hover,.cm-wallet-add:focus { opacity:.9; }
				.cm-credential-download:hover,.cm-credential-download:focus { color:var(--cm-primary); }
				.cm-wallet-add:hover,.cm-wallet-add:focus { color:#fff; }
				.cm-wallet-note { color:var(--cm-muted); font-size:12px; line-height:1.5; margin:14px 0 0; }
				.cm-wallet-request { border-top:1px solid var(--cm-border); margin-top:18px; padding-top:17px; }
				.cm-wallet-request label { color:var(--cm-text); display:block; font-size:14px; font-weight:700; margin-bottom:6px; }
				.cm-wallet-request input { background:#fff; border:1px solid var(--cm-border); border-radius:9px; box-sizing:border-box; font:inherit; max-width:360px; min-height:44px; padding:0 12px; width:100%; }
				.cm-wallet-request button { background:var(--cm-primary); border:0; border-radius:9px; color:#fff; cursor:pointer; font:inherit; font-size:14px; font-weight:700; margin:9px 0 0; min-height:44px; padding:0 14px; }
				.cm-wallet-request p { font-size:12px; margin-top:8px; }
				.cm-wallet-qr { border-top:1px solid var(--cm-border); margin-top:18px; padding-top:17px; }
				.cm-wallet-qr summary { color:var(--cm-primary); cursor:pointer; font-size:13px; font-weight:700; }
				.cm-wallet-qr-inner { align-items:center; display:flex; gap:16px; margin-top:14px; }
				.cm-wallet-qr img { background:#fff; border:1px solid var(--cm-border); border-radius:10px; display:block; height:124px; padding:8px; width:124px; }
				.cm-wallet-qr p { font-size:13px; max-width:370px; }
				.cm-credential-technical { border-top:1px solid var(--cm-border); color:var(--cm-muted); font-size:12px; margin-top:17px; padding-top:14px; }
				.cm-credential-technical summary { cursor:pointer; font-weight:700; }
				.cm-credential-technical ul { line-height:1.6; margin:9px 0 0 18px; padding:0; }
				.cm-verification-footer { border-top:1px solid var(--cm-border); color:var(--cm-muted); font-size:13px; margin-top:32px; padding-top:22px; }
				/* Editorial: restrained, considered and deliberately free of decorative gradients. */
				.cm-verification-editorial .cm-verification-shell { padding:54px 24px; }
				.cm-verification-editorial .cm-verification-shell:before,.cm-verification-editorial .cm-verification-shell:after { display:none; }
				.cm-verification-editorial .cm-verification-result { border-radius:2px; border-top:4px solid var(--cm-primary); max-width:780px; }
				.cm-verification-editorial .cm-verification-header { background:var(--cm-surface); border-bottom:1px solid var(--cm-border); padding:38px 48px 28px; }
				.cm-verification-editorial .cm-verification-header h1 { font-family:Georgia,"Times New Roman",serif; font-size:clamp(34px,5vw,48px); font-weight:500; letter-spacing:-.035em; }
				.cm-verification-editorial .cm-verification-content { padding:34px 48px 42px; }
				.cm-verification-editorial .cm-result-banner { background:transparent; border:0; border-bottom:1px solid var(--cm-border); border-radius:0; padding:0 0 22px; }
				.cm-verification-editorial .cm-meta th { font-size:12px; width:35%; }
				.cm-verification-editorial .cm-digital-credential { background:#fbfcfa; border-radius:2px; border-left:3px solid var(--cm-primary); }

				/* Registry: a clear, institutional register with a solid masthead. */
				.cm-verification-registry .cm-verification-shell { padding:42px 24px; }
				.cm-verification-registry .cm-verification-shell:before,.cm-verification-registry .cm-verification-shell:after { display:none; }
				.cm-verification-registry .cm-verification-result { border-radius:8px; max-width:900px; }
				.cm-verification-registry .cm-verification-header { background:var(--cm-primary); border:0; padding:34px 46px; }
				.cm-verification-registry .cm-verification-header h1,.cm-verification-registry .cm-verification-eyebrow { color:#fff; }
				.cm-verification-registry .cm-verification-header h1 { font-size:clamp(31px,4vw,42px); letter-spacing:-.035em; }
				.cm-verification-registry .cm-verification-eyebrow { opacity:.78; }
				.cm-verification-registry .cm-verification-content { padding:30px 46px 40px; }
				.cm-verification-registry .cm-result-banner { border-radius:6px; box-shadow:inset 4px 0 0 #16874b; }
				.cm-verification-registry .cm-result-banner.is-negative { box-shadow:inset 4px 0 0 #cf3434; }
				.cm-verification-registry .cm-meta { border-top:1px solid var(--cm-border); }
				.cm-verification-registry .cm-meta th { background:#f5f8fa; width:32%; }
				.cm-verification-registry .cm-digital-credential { border-radius:7px; }

				/* Heritage: formal certificate language without decorative clutter. */
				.cm-verification-heritage .cm-verification-shell { padding:86px 24px; }
				.cm-verification-heritage .cm-verification-shell:before,.cm-verification-heritage .cm-verification-shell:after { display:none; }
				.cm-verification-heritage .cm-verification-result { border:7px double var(--cm-primary); border-radius:0; max-width:760px; }
				.cm-verification-heritage .cm-verification-header { background:var(--cm-surface); border-bottom:1px solid var(--cm-border); padding:52px 48px 34px; text-align:center; }
				.cm-verification-heritage .cm-verification-header h1 { font-family:Georgia,"Times New Roman",serif; font-size:clamp(34px,5vw,50px); letter-spacing:-.03em; }
				.cm-verification-heritage .cm-verification-content { padding:38px 56px 46px; }
				.cm-verification-heritage .cm-result-banner { background:transparent; border-color:var(--cm-primary); border-radius:0; justify-content:center; text-align:center; }
				.cm-verification-heritage .cm-result-banner.is-negative { background:#fff6f3; border-color:#c85a43; }
				.cm-verification-heritage .cm-result-icon { border-radius:0; }
				.cm-verification-heritage .cm-meta th { font-family:Georgia,"Times New Roman",serif; font-size:15px; letter-spacing:0; text-transform:none; }
				.cm-verification-heritage .cm-digital-credential { background:transparent; border-color:var(--cm-primary); border-radius:0; text-align:center; }
				.cm-verification-heritage .cm-digital-credential h2 { font-family:Georgia,"Times New Roman",serif; }

				/* Folio: a contemporary two-column page for organisations with a modern identity. */
				.cm-verification-folio .cm-verification-shell { padding:42px 24px; }
				.cm-verification-folio .cm-verification-shell:before,.cm-verification-folio .cm-verification-shell:after { display:none; }
				.cm-verification-folio .cm-verification-result { border-radius:0; box-shadow:none; display:grid; grid-template-columns:minmax(250px,32%) 1fr; max-width:1040px; }
				.cm-verification-folio .cm-verification-header { align-items:flex-start; background:var(--cm-primary); border:0; display:flex; flex-direction:column; justify-content:flex-end; min-height:250px; padding:42px 34px; }
				.cm-verification-folio .cm-verification-header h1,.cm-verification-folio .cm-verification-eyebrow { color:#fff; }
				.cm-verification-folio .cm-verification-content { padding:38px 42px; }
				.cm-verification-folio .cm-result-banner { background:transparent; border:0; border-bottom:2px solid var(--cm-primary); border-radius:0; padding:0 0 20px; }
				.cm-verification-folio .cm-result-banner.is-negative { background:transparent; border-color:#cf3434; }
				.cm-verification-folio .cm-result-icon { border-radius:3px; }
				.cm-verification-folio .cm-meta th { width:36%; }
				.cm-verification-folio .cm-digital-credential { border-left:3px solid var(--cm-primary); border-radius:0; }

				/* Night: a confident dark register with accessible contrast. */
				.cm-verification-night .cm-verification-shell:before { background:var(--cm-primary); filter:none; height:420px; opacity:.14; right:-130px; top:-180px; width:420px; }
				.cm-verification-night .cm-verification-shell:after { background:var(--cm-primary); filter:none; height:330px; left:-150px; opacity:.1; width:330px; }
				.cm-verification-night .cm-verification-result { border-color:var(--cm-border); border-radius:12px; }
				.cm-verification-night .cm-verification-header { background:#20292e; border-color:var(--cm-border); }
				.cm-verification-night .cm-verification-header h1 { color:var(--cm-text); }
				.cm-verification-night .cm-result-banner { background:#173328; border-color:#286141; }
				.cm-verification-night .cm-result-banner.is-negative { background:#3b2225; border-color:#7d3e45; }
				.cm-verification-night .cm-result-banner strong,.cm-verification-night .cm-meta td { color:var(--cm-text); }
				.cm-verification-night .cm-meta th,.cm-verification-night .cm-template-name { color:var(--cm-muted); }
				.cm-verification-night .cm-lookup-form input,.cm-verification-night .cm-wallet-request input { background:#151b1f; border-color:var(--cm-border); color:var(--cm-text); }
				.cm-verification-night .cm-digital-credential { background:#202a2d; border-color:#3a484d; }
				.cm-verification-night .cm-credential-download { border-color:var(--cm-primary); color:var(--cm-text); }
				.cm-verification-night .cm-credential-download:hover,.cm-verification-night .cm-credential-download:focus { color:var(--cm-text); }
				.cm-verification-night .cm-wallet-qr img { border-color:var(--cm-border); }

				@media (max-width:600px) { .cm-verification-shell,.cm-verification-editorial .cm-verification-shell,.cm-verification-registry .cm-verification-shell,.cm-verification-heritage .cm-verification-shell,.cm-verification-folio .cm-verification-shell { padding:24px 14px; } .cm-verification-result { border-radius:10px; } .cm-verification-heritage .cm-verification-result { border-width:5px; border-radius:0; } .cm-verification-header,.cm-verification-editorial .cm-verification-header,.cm-verification-heritage .cm-verification-header,.cm-verification-registry .cm-verification-header { padding:30px 24px 26px; } .cm-verification-editorial .cm-verification-content,.cm-verification-heritage .cm-verification-content,.cm-verification-content { padding:26px 24px 30px; } .cm-verification-folio .cm-verification-result { display:block; } .cm-verification-folio .cm-verification-header { min-height:0; padding:32px 24px; } .cm-verification-folio .cm-verification-content { padding:26px 24px 30px; } .cm-lookup-form { flex-direction:column; } .cm-meta th,.cm-meta td { display:block; padding:10px 0; width:auto; } .cm-meta th { border-bottom:0; padding-top:18px; } .cm-meta td { padding-bottom:18px; } .cm-digital-credential { padding:19px; } .cm-digital-credential h2 { font-size:18px; } .cm-credential-actions { align-items:stretch; flex-direction:column; } .cm-credential-actions a { text-align:center; } .cm-wallet-qr-inner { align-items:flex-start; flex-direction:column; } }
			</style>
		</head>
		<body <?php body_class( 'cm-verification-page cm-verification-' . $style ); ?>>
			<main class="cm-verification-shell"><div class="cm-verification-result">
				<header class="cm-verification-header"><p class="cm-verification-eyebrow"><?php esc_html_e( 'Certificate register', 'certificate-manager' ); ?></p><h1><?php esc_html_e( 'Certificate Verification', 'certificate-manager' ); ?></h1></header>
				<section class="cm-verification-content">
				<?php if ( $is_lookup ) : ?>
					<p class="cm-message"><?php esc_html_e( 'Enter the certificate number to check its current status.', 'certificate-manager' ); ?></p>
					<form class="cm-lookup-form" method="get" action="<?php echo esc_url( $this->get_verification_url() ); ?>">
						<label class="screen-reader-text" for="cm-certificate-number"><?php esc_html_e( 'Certificate number', 'certificate-manager' ); ?></label><input type="text" id="cm-certificate-number" name="certificate_number" placeholder="CERT-2026-0001" required autocomplete="off">
						<button type="submit"><?php esc_html_e( 'Verify certificate', 'certificate-manager' ); ?></button>
					</form>
					<?php if ( $certificate_requests_enabled ) : ?><form class="cm-lookup-form" method="post" action="<?php echo esc_url( $this->get_verification_url() ); ?>"><input type="hidden" name="cm_action" value="cm_request_certificates"><?php wp_nonce_field( 'cm_request_certificates', 'cm_request_nonce' ); ?><label class="screen-reader-text" for="cm-request-email"><?php esc_html_e( 'Email address', 'certificate-manager' ); ?></label><input type="email" id="cm-request-email" name="recipient_email" placeholder="<?php esc_attr_e( 'Email address to request certificates', 'certificate-manager' ); ?>" required autocomplete="email"><button type="submit"><?php esc_html_e( 'Request certificates', 'certificate-manager' ); ?></button></form><p class="cm-message"><?php esc_html_e( 'For privacy, we only confirm requests by email when active certificates are found.', 'certificate-manager' ); ?></p><?php endif; ?>
				<?php else : ?>
					<div class="cm-result-banner<?php echo $is_negative ? ' is-negative' : ''; ?>"><span class="cm-result-icon" aria-hidden="true"><?php echo $is_negative ? '!' : '&#10003;'; ?></span><div><span class="cm-result-label"><?php esc_html_e( 'Verification result', 'certificate-manager' ); ?></span><strong><?php echo esc_html( $status_label ); ?></strong></div></div>
				<?php if ( ! empty( $result['error'] ) ) : ?>
					<p class="cm-message"><?php echo esc_html( $result['error'] ); ?></p>
				<?php else : ?>
					<?php if ( ! empty( $result['template']['name'] ) ) : ?><p class="cm-template-name"><?php echo esc_html( $result['template']['name'] ); ?></p><?php endif; ?>
					<table class="cm-meta"><tbody>
					<?php foreach ( $standard_fields as $key => $label ) : ?>
						<?php if ( ! empty( $certificate[ $key ] ) ) : ?><tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $certificate[ $key ] ); ?></td></tr><?php endif; ?>
					<?php endforeach; ?>
					<?php foreach ( $fields as $key => $field ) : ?>
						<?php if ( isset( $standard_fields[ $key ] ) || empty( $field['value'] ) ) { continue; } ?>
						<tr><th><?php echo esc_html( $field['label'] ); ?></th><td><?php echo nl2br( esc_html( $field['value'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
					<?php if ( $is_valid && $credentials_enabled && ! empty( $certificate['verification_token_short'] ) ) : ?>
						<section class="cm-digital-credential" aria-label="<?php esc_attr_e( 'Signed digital credential', 'certificate-manager' ); ?>">
							<span class="cm-digital-credential-label"><?php esc_html_e( 'Signed digital credential', 'certificate-manager' ); ?></span>
							<h2><?php esc_html_e( 'W3C Verifiable Credential 2.0', 'certificate-manager' ); ?></h2>
							<p><?php esc_html_e( 'Cryptographically signed and independently verifiable against the issuer’s live status record.', 'certificate-manager' ); ?></p>
							<div class="cm-credential-actions">
								<?php if ( $wallet_offer ) : ?><a class="cm-wallet-add" href="<?php echo esc_url( $wallet_offer['deep_link'], array( 'openid-credential-offer' ) ); ?>"><?php esc_html_e( 'Add to compatible wallet', 'certificate-manager' ); ?></a><?php endif; ?>
								<a class="cm-credential-download" href="<?php echo esc_url( add_query_arg( array( 'cm_credential' => '1', 'v' => $certificate['verification_token_short'] ), home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Download signed credential', 'certificate-manager' ); ?></a>
							</div>
							<?php if ( $wallet_offer ) : ?>
								<p class="cm-wallet-note"><?php esc_html_e( 'This private link was sent to the certificate email address. Compatible wallets receive the same privacy-minimised credential available for download here.', 'certificate-manager' ); ?></p>
								<?php if ( $wallet_qr ) : ?><details class="cm-wallet-qr"><summary><?php esc_html_e( 'Use a wallet on another device', 'certificate-manager' ); ?></summary><div class="cm-wallet-qr-inner"><img src="<?php echo esc_attr( $wallet_qr ); ?>" alt="<?php esc_attr_e( 'QR code to add this credential to a compatible wallet', 'certificate-manager' ); ?>"><p><?php esc_html_e( 'Open your compatible credential wallet and scan this short-lived code. It expires in 10 minutes and can be used once.', 'certificate-manager' ); ?></p></div></details><?php endif; ?>
							<?php endif; ?>
							<?php if ( $wallet_request_available ) : ?><form class="cm-wallet-request" method="post" action="<?php echo esc_url( $this->get_verification_url() ); ?>"><input type="hidden" name="cm_action" value="cm_request_wallet_link"><input type="hidden" name="verification_token" value="<?php echo esc_attr( $certificate['verification_token_short'] ); ?>"><?php wp_nonce_field( 'cm_request_wallet_link', 'cm_wallet_link_nonce' ); ?><label for="cm-wallet-email"><?php esc_html_e( 'Add this certificate to your wallet', 'certificate-manager' ); ?></label><input type="email" id="cm-wallet-email" name="recipient_email" placeholder="<?php esc_attr_e( 'Your certificate email address', 'certificate-manager' ); ?>" required autocomplete="email"><button type="submit"><?php esc_html_e( 'Email me a private wallet link', 'certificate-manager' ); ?></button><p><?php esc_html_e( 'For privacy, we only send the link when the email matches this certificate. We never reveal whether it matched.', 'certificate-manager' ); ?></p></form><?php endif; ?>
							<details class="cm-credential-technical"><summary><?php esc_html_e( 'Credential details', 'certificate-manager' ); ?></summary><ul><li><?php esc_html_e( 'Standard: W3C Verifiable Credentials Data Model 2.0', 'certificate-manager' ); ?></li><li><?php esc_html_e( 'Format: VC-JWT, signed with the issuer’s published RS256 key', 'certificate-manager' ); ?></li><li><?php esc_html_e( 'Status: checked against the issuer’s live certificate record', 'certificate-manager' ); ?></li><?php if ( $wallet_offer ) : ?><li><?php esc_html_e( 'Wallet hand-off: OpenID for Verifiable Credential Issuance (OpenID4VCI)', 'certificate-manager' ); ?></li><?php endif; ?></ul></details>
						</section>
					<?php endif; ?>
				<?php endif; ?>
				<?php endif; ?>
					<footer class="cm-verification-footer"><?php
				/* translators: %s: site name */
				echo esc_html( sprintf( __( 'Verified through %s', 'certificate-manager' ), get_bloginfo( 'name' ) ) ); ?></footer>
				</section>
			</div></main>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}
}
