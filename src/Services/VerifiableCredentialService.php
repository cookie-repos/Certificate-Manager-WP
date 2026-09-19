<?php
/**
 * W3C Verifiable Credentials service.
 *
 * @package CertificateManager
 */

namespace CertificateManager\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CertificateManager\Core\Settings;
use CertificateManager\Repositories\CertificateRepository;
use CertificateManager\Repositories\TemplateRepository;

/**
 * Issues signed W3C VC-JWT credentials for certificates.
 *
 * The VC-JWT proof format avoids a JSON-LD canonicalisation dependency while
 * remaining a portable W3C Verifiable Credential representation.
 */
class VerifiableCredentialService {
	const VC_CONTEXT = 'https://www.w3.org/ns/credentials/v2';
	const OB_CONTEXT = 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json';
	const OB_SCHEMA  = 'https://purl.imsglobal.org/spec/ob/v3p0/schema/json/ob_v3p0_achievementcredential_schema.json';
	const KEY_OPTION  = 'certificate_manager_vc_signing_key';
	const CREDENTIAL_PROFILE = 'open-badges-3-public-v2';
	const WALLET_CONFIGURATION_ID = 'CertificateManagerW3CVCJWT';
	const WALLET_OFFER_TRANSIENT  = 'cm_oid4vci_offer_';
	const WALLET_ACCESS_TRANSIENT = 'cm_oid4vci_access_';
	const WALLET_CLAIM_TRANSIENT  = 'cm_oid4vci_claim_';

	private $certificate_repo;
	private $template_repo;
	private $settings;

	public function __construct( CertificateRepository $certificate_repo, TemplateRepository $template_repo, Settings $settings ) {
		$this->certificate_repo = $certificate_repo;
		$this->template_repo   = $template_repo;
		$this->settings        = $settings;
	}

	/**
	 * Create and persist the signed credential for an issued certificate.
	 *
	 * @param int $certificate_id Certificate ID.
	 * @return string|\WP_Error VC-JWT string or error.
	 */
	public function issue( int $certificate_id ) {
		if ( ! $this->settings->get( 'verifiable_credentials_enabled', true ) ) {
			return new \WP_Error( 'verifiable_credentials_disabled', __( 'Verifiable credential issuing is disabled.', 'certificate-manager' ) );
		}

		$existing = $this->get_stored_credential( $certificate_id );
		if ( $existing ) {
			return $existing;
		}

		$certificate = $this->certificate_repo->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found.', 'certificate-manager' ) );
		}

		$key = $this->get_or_create_signing_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$jwt = $this->sign( $this->build_payload( $certificate ), $key );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_manager_verifiable_credentials';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is WP-prefixed.
		$stored_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE certificate_id = %d", $certificate_id ) );
		$data = array(
			'credential_jwt' => $jwt,
			// This identifies the privacy profile of the stored payload, not the signing key.
			'key_id'         => self::CREDENTIAL_PROFILE,
			'issued_at'      => current_time( 'mysql', true ),
		);
		if ( $stored_id ) {
			$saved = false !== $wpdb->update( $table_name, $data, array( 'id' => $stored_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$data['certificate_id'] = $certificate_id;
			$data['created_at'] = current_time( 'mysql' );
			$saved = (bool) $wpdb->insert( $table_name, $data, array( '%s', '%s', '%s', '%d', '%s' ) );
		}

		if ( ! $saved ) {
			return new \WP_Error( 'credential_storage_failed', __( 'The signed credential could not be saved.', 'certificate-manager' ) );
		}

		return $jwt;
	}

	/**
	 * Return a signed VC-JWT by the public verification-token prefix.
	 *
	 * @param string $token Short verification token.
	 * @return string|\WP_Error
	 */
	public function get_for_short_token( string $token ) {
		$certificate = $this->find_certificate_by_short_token( $token );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found.', 'certificate-manager' ) );
		}

		return $this->issue( (int) $certificate['id'] );
	}

	/**
	 * Return current public status without altering the signed credential.
	 *
	 * @param string $token Short verification token.
	 * @return array|\WP_Error
	 */
	public function get_status_for_short_token( string $token ) {
		$certificate = $this->find_certificate_by_short_token( $token );
		if ( ! $certificate ) {
			return new \WP_Error( 'certificate_not_found', __( 'Certificate not found.', 'certificate-manager' ) );
		}

		$status = $certificate['status'];
		if ( 'active' === $status && ! empty( $certificate['expiry_date'] ) && strtotime( $certificate['expiry_date'] ) < time() ) {
			$status = 'expired';
		}

		return array(
			'id'                    => $this->credential_url( $token ),
			'type'                  => 'CertificateManagerCredentialStatus',
			'statusPurpose'         => 'revocation',
			'status'                => $status,
			'certificateNumber'     => $certificate['certificate_number'],
			'checkedAt'             => gmdate( 'c' ),
			'verificationPage'      => add_query_arg( 'v', rawurlencode( $token ), $this->settings->get_verification_base_url() ),
		);
	}

	/**
	 * Issuer profile used by the signed credential.
	 *
	 * @return array
	 */
	public function get_issuer_profile(): array {
		return array(
			'id'          => $this->issuer_url(),
			'type'        => array( 'Profile' ),
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url( '/' ),
			'description' => get_bloginfo( 'description' ),
		);
	}

	/**
	 * Publish the public signing key in JWKS form.
	 *
	 * @return array|\WP_Error
	 */
	public function get_jwks() {
		$key = $this->get_or_create_signing_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		return array( 'keys' => array( $key['jwk'] ) );
	}

	/**
	 * Whether this installation can safely offer credentials to a wallet.
	 * OID4VCI endpoints are required to be served over HTTPS.
	 *
	 * @return bool
	 */
	public function is_wallet_issuance_available(): bool {
		return (bool) $this->settings->get( 'verifiable_credentials_enabled', true )
			&& (bool) $this->settings->get( 'wallet_issuance_enabled', true )
			&& 'https' === strtolower( (string) wp_parse_url( $this->get_openid4vci_issuer_identifier(), PHP_URL_SCHEME ) );
	}

	/**
	 * OpenID4VCI credential issuer identifier.
	 *
	 * This intentionally differs from the W3C issuer-profile REST document:
	 * OpenID discovery requires an HTTPS URL whose well-known metadata lives on
	 * the site's own origin.
	 *
	 * @return string
	 */
	public function get_openid4vci_issuer_identifier(): string {
		return untrailingslashit( home_url( '/' ) );
	}

	/**
	 * Return OpenID4VCI credential issuer metadata.
	 *
	 * The credential is deliberately offered without holder binding. The public
	 * verification URL is already a bearer link, so advertising key binding here
	 * would be misleading. A future recipient-authenticated issuance flow can
	 * add a separate, holder-bound configuration.
	 *
	 * @return array
	 */
	public function get_openid4vci_issuer_metadata(): array {
		$claims = array(
			array( 'path' => array( 'name' ), 'display' => array( array( 'name' => __( 'Certificate', 'certificate-manager' ) ) ) ),
			array( 'path' => array( 'validFrom' ), 'display' => array( array( 'name' => __( 'Issue date', 'certificate-manager' ) ) ) ),
			array( 'path' => array( 'credentialSubject', 'achievement', 'name' ), 'display' => array( array( 'name' => __( 'Achievement', 'certificate-manager' ) ) ) ),
		);
		if ( $this->settings->get( 'verifiable_credentials_include_recipient_name', false ) ) {
			$claims[] = array( 'path' => array( 'credentialSubject', 'name' ), 'display' => array( array( 'name' => __( 'Recipient', 'certificate-manager' ) ) ) );
		}

		return array(
			'credential_issuer' => $this->get_openid4vci_issuer_identifier(),
			'credential_endpoint' => rest_url( 'certificate-manager/v1/openid4vci/credential' ),
			'credential_configurations_supported' => array(
				self::WALLET_CONFIGURATION_ID => array(
					'format' => 'jwt_vc_json',
					'scope' => 'certificate_manager_credential',
					'credential_signing_alg_values_supported' => array( 'RS256' ),
					'credential_definition' => array( 'type' => array( 'VerifiableCredential', 'OpenBadgeCredential' ) ),
					'credential_metadata' => array(
						'display' => array( array(
							/* translators: %s: site name */
							'name' => sprintf( __( '%s certificate', 'certificate-manager' ), get_bloginfo( 'name' ) ),
							'locale' => str_replace( '_', '-', determine_locale() ),
							'background_color' => '#315efb',
							'text_color' => '#ffffff',
						) ),
						'claims' => $claims,
					),
				),
			),
		);
	}

	/**
	 * Return OAuth authorization-server metadata for the short-lived,
	 * anonymous pre-authorized-code flow used by the public verification page.
	 *
	 * @return array
	 */
	public function get_openid4vci_authorization_server_metadata(): array {
		return array(
			'issuer' => $this->get_openid4vci_issuer_identifier(),
			'token_endpoint' => rest_url( 'certificate-manager/v1/openid4vci/token' ),
			'grant_types_supported' => array( 'urn:ietf:params:oauth:grant-type:pre-authorized_code' ),
			'pre-authorized_grant_anonymous_access_supported' => true,
		);
	}

	/**
	 * Create a short-lived, single-use wallet credential offer for a verified
	 * certificate. Possession of this offer is sufficient by design because the
	 * verification page and its credential are public.
	 *
	 * @param string $token Short verification token.
	 * @return array|\WP_Error Offer URLs and expiry data.
	 */
	public function create_wallet_offer( string $token ) {
		if ( ! $this->is_wallet_issuance_available() ) {
			return new \WP_Error( 'wallet_issuance_unavailable', __( 'Wallet issuance requires HTTPS and must be enabled by the issuer.', 'certificate-manager' ) );
		}
		if ( ! $this->is_wallet_issuable( $token ) ) {
			return new \WP_Error( 'credential_unavailable', __( 'A wallet credential is not available for this certificate.', 'certificate-manager' ) );
		}

		try {
			$code = $this->base64url_encode( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'wallet_offer_failed', __( 'A secure wallet offer could not be created.', 'certificate-manager' ) );
		}

		set_transient( self::WALLET_OFFER_TRANSIENT . hash( 'sha256', $code ), array(
			'token' => $token,
			'created_at' => time(),
		), 10 * MINUTE_IN_SECONDS );
		$offer_url = rest_url( 'certificate-manager/v1/openid4vci/offers/' . rawurlencode( $code ) );

		return array(
			'offer_url' => $offer_url,
			'deep_link' => add_query_arg( 'credential_offer_uri', $offer_url, 'openid-credential-offer://' ),
			'expires_at' => time() + ( 10 * MINUTE_IN_SECONDS ),
		);
	}

	/**
	 * Create a private wallet-claim link only after the visitor has supplied the
	 * certificate's stored recipient email address. The email itself is never
	 * encoded in the link or placed in the credential.
	 *
	 * @param string $token Short verification token.
	 * @param string $email Recipient email supplied by the visitor.
	 * @return array|\WP_Error
	 */
	public function create_wallet_claim_for_email( string $token, string $email ) {
		if ( ! $this->is_wallet_issuance_available() ) {
			return new \WP_Error( 'wallet_issuance_unavailable', __( 'Wallet issuance is not available.', 'certificate-manager' ) );
		}
		$certificate = $this->find_certificate_by_short_token( $token );
		$stored_email = is_array( $certificate ) ? strtolower( trim( (string) ( $certificate['recipient_email'] ?? '' ) ) ) : '';
		if ( ! $certificate || ! $stored_email || ! hash_equals( $stored_email, strtolower( trim( $email ) ) ) || ! $this->is_wallet_issuable( $token ) ) {
			return new \WP_Error( 'wallet_claim_unavailable', __( 'A private wallet link is not available.', 'certificate-manager' ) );
		}

		try {
			$claim_code = $this->base64url_encode( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'wallet_claim_failed', __( 'A secure wallet link could not be created.', 'certificate-manager' ) );
		}
		set_transient( self::WALLET_CLAIM_TRANSIENT . hash( 'sha256', $claim_code ), array( 'token' => $token ), 15 * MINUTE_IN_SECONDS );
		return array(
			'claim_url' => add_query_arg( 'cm_wallet_claim', $claim_code, home_url( '/' ) ),
			'expires_at' => time() + ( 15 * MINUTE_IN_SECONDS ),
			'certificate_id' => (int) $certificate['id'],
			'certificate_number' => (string) $certificate['certificate_number'],
			'recipient_name' => (string) $certificate['recipient_name'],
		);
	}

	/**
	 * Resolve a private email-delivered wallet claim.
	 *
	 * @param string $claim_code Private claim code.
	 * @return array|\WP_Error
	 */
	public function get_wallet_claim( string $claim_code ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,96}$/', $claim_code ) ) {
			return new \WP_Error( 'invalid_wallet_claim', __( 'This private wallet link is invalid or has expired.', 'certificate-manager' ) );
		}
		$claim = get_transient( self::WALLET_CLAIM_TRANSIENT . hash( 'sha256', $claim_code ) );
		if ( ! is_array( $claim ) || empty( $claim['token'] ) || ! $this->is_wallet_issuable( (string) $claim['token'] ) ) {
			return new \WP_Error( 'invalid_wallet_claim', __( 'This private wallet link is invalid or has expired.', 'certificate-manager' ) );
		}
		return $claim;
	}

	/**
	 * Return the JSON credential offer represented by a one-time code.
	 *
	 * @param string $code Pre-authorized code.
	 * @return array|\WP_Error
	 */
	public function get_wallet_offer( string $code ) {
		$offer = $this->get_wallet_offer_record( $code );
		if ( ! $offer || ! $this->is_wallet_issuable( $offer['token'] ) ) {
			return new \WP_Error( 'invalid_credential_offer', __( 'This wallet offer has expired or is no longer available.', 'certificate-manager' ) );
		}
		return array(
			'credential_issuer' => $this->get_openid4vci_issuer_identifier(),
			'credential_configuration_ids' => array( self::WALLET_CONFIGURATION_ID ),
			'grants' => array(
				'urn:ietf:params:oauth:grant-type:pre-authorized_code' => array(
					'pre-authorized_code' => $code,
				),
			),
		);
	}

	/**
	 * Exchange a single-use pre-authorized code for a credential-only token.
	 *
	 * @param string $code Pre-authorized code.
	 * @return array|\WP_Error
	 */
	public function redeem_wallet_offer( string $code ) {
		$offer = $this->get_wallet_offer_record( $code );
		if ( ! $offer || ! $this->is_wallet_issuable( $offer['token'] ) ) {
			return new \WP_Error( 'invalid_grant', __( 'The wallet offer is invalid, expired, or no longer available.', 'certificate-manager' ) );
		}
		// Delete before issuing the token so duplicate wallet requests cannot race.
		delete_transient( self::WALLET_OFFER_TRANSIENT . hash( 'sha256', $code ) );

		try {
			$access_token = $this->base64url_encode( random_bytes( 32 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'server_error', __( 'A secure wallet access token could not be created.', 'certificate-manager' ) );
		}
		set_transient( self::WALLET_ACCESS_TRANSIENT . hash( 'sha256', $access_token ), array( 'token' => $offer['token'] ), 5 * MINUTE_IN_SECONDS );
		return array( 'access_token' => $access_token, 'token_type' => 'Bearer', 'expires_in' => 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Return the public VC-JWT authorized by a wallet access token.
	 *
	 * @param string $access_token Access token.
	 * @return string|\WP_Error
	 */
	public function get_wallet_credential( string $access_token ) {
		$access = get_transient( self::WALLET_ACCESS_TRANSIENT . hash( 'sha256', $access_token ) );
		if ( ! is_array( $access ) || empty( $access['token'] ) || ! $this->is_wallet_issuable( $access['token'] ) ) {
			return new \WP_Error( 'invalid_token', __( 'The wallet access token is invalid, expired, or no longer available.', 'certificate-manager' ) );
		}
		return $this->get_for_short_token( (string) $access['token'] );
	}

	/**
	 * Validate a short-lived offer without exposing the transient record.
	 */
	private function get_wallet_offer_record( string $code ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,96}$/', $code ) ) {
			return false;
		}
		$offer = get_transient( self::WALLET_OFFER_TRANSIENT . hash( 'sha256', $code ) );
		return is_array( $offer ) && ! empty( $offer['token'] ) ? $offer : false;
	}

	/**
	 * Only active, unexpired certificates can be imported into a wallet.
	 */
	private function is_wallet_issuable( string $token ): bool {
		$certificate = $this->find_certificate_by_short_token( $token );
		if ( ! $certificate || 'active' !== $certificate['status'] ) {
			return false;
		}
		return empty( $certificate['expiry_date'] ) || strtotime( $certificate['expiry_date'] ) >= time();
	}

	private function build_payload( array $certificate ): array {
		$template = $this->template_repo->get_template( (int) $certificate['template_id'] );
		$template = is_array( $template ) ? $template : array();
		$token    = substr( $certificate['verification_token'], 0, 16 );
		$issuer   = $this->get_issuer_profile();
		$fields   = $this->get_credential_field_values( $certificate );
		$title    = $template['title'] ?? __( 'Certificate', 'certificate-manager' );
		$criteria = $fields['criteria'] ?? $fields['criteria_narrative'] ?? sprintf(
			/* translators: 1: site name, 2: certificate/template title */
			__( 'Awarded by %1$s for completing %2$s.', 'certificate-manager' ),
			get_bloginfo( 'name' ),
			$title
		);
		$subject  = array(
			'id'   => 'urn:uuid:' . $certificate['internal_id'],
			'type' => array( 'AchievementSubject' ),
		);

		// A credential downloaded from a QR verification page is public. Do not
		// add an email, email hash, or name unless the issuer explicitly chooses it.
		if ( $this->settings->get( 'verifiable_credentials_include_recipient_name', false ) && ! empty( $certificate['recipient_name'] ) ) {
			$subject['name'] = $certificate['recipient_name'];
		}

		$achievement = array(
			'id'              => $this->achievement_url( (int) $certificate['template_id'] ),
			'type'            => array( 'Achievement' ),
			'name'            => $title,
			'description'     => $fields['achievement_description'] ?? $fields['description'] ?? $title,
			'achievementType' => $fields['achievement_type'] ?? 'Certificate',
			'creator'         => $issuer,
			'criteria'        => array( 'narrative' => wp_strip_all_tags( (string) $criteria ) ),
		);

		if ( ! empty( $fields['skills'] ) ) {
			$achievement['tag'] = array_values( array_filter( array_map( 'trim', preg_split( '/[,\n]+/', $fields['skills'] ) ) ) );
		}
		if ( ! empty( $fields['alignment_url'] ) ) {
			$achievement['alignment'] = array( array(
				'type'            => 'Alignment',
				'targetUrl'       => esc_url_raw( $fields['alignment_url'] ),
				'targetName'      => $fields['alignment_name'] ?? $fields['skills'] ?? '',
				'targetFramework' => $fields['alignment_framework'] ?? '',
			) );
		}
		$subject['achievement'] = $achievement;

		$payload = array(
			'@context'          => array( self::VC_CONTEXT, self::OB_CONTEXT ),
			'id'                => $this->credential_url( $token ),
			'type'              => array( 'VerifiableCredential', 'OpenBadgeCredential' ),
			'name'              => $title,
			'issuer'            => $issuer,
			'validFrom'         => $this->to_iso8601( $certificate['issue_date'] ),
			'credentialSubject' => $subject,
			'credentialSchema'  => array( array( 'id' => self::OB_SCHEMA, 'type' => '1EdTechJsonSchemaValidator2019' ) ),
			'credentialStatus'  => array(
				'id'     => rest_url( 'certificate-manager/v1/credentials/status/' . $token ),
				'type'   => 'CertificateManagerCredentialStatus',
			),
			'iss'               => $issuer['id'],
			'jti'               => $this->credential_url( $token ),
			'sub'               => $subject['id'],
			'nbf'               => strtotime( $certificate['issue_date'] ),
		);

		if ( ! empty( $certificate['expiry_date'] ) ) {
			$payload['validUntil'] = $this->to_iso8601( $certificate['expiry_date'] );
		}
		if ( ! empty( $fields['evidence'] ) || ! empty( $fields['evidence_url'] ) ) {
			$payload['evidence'] = array( array(
				'type'      => array( 'Evidence' ),
				'id'        => ! empty( $fields['evidence_url'] ) ? esc_url_raw( $fields['evidence_url'] ) : $this->credential_url( $token ),
				'narrative' => wp_strip_all_tags( (string) ( $fields['evidence'] ?? '' ) ),
			) );
		}

		/**
		 * Permit installations to map their own custom variables into credential claims.
		 *
		 * @param array $payload Prepared VC payload.
		 * @param array $certificate Certificate record.
		 * @param array $fields Public custom certificate values, keyed by variable key.
		 */
		return apply_filters( 'certificate_manager_open_badge_payload', $payload, $certificate, $fields );
	}

	private function get_credential_field_values( array $certificate ): array {
		// Do not copy every custom field into a publicly downloadable credential.
		// These are the deliberate credential semantic claims an issuer can map
		// through custom variables; they must not contain personal information.
		$allowed_keys = array_flip( array(
			'criteria',
			'criteria_narrative',
			'achievement_description',
			'description',
			'achievement_type',
			'skills',
			'alignment_url',
			'alignment_name',
			'alignment_framework',
			'evidence',
			'evidence_url',
		) );
		$values     = array();
		foreach ( $this->certificate_repo->get_certificate_fields( (int) $certificate['id'] ) as $field ) {
			$key = sanitize_key( $field['variable_key'] );
			if ( ! isset( $allowed_keys[ $key ] ) ) {
				continue;
			}
			$values[ $key ] = $field['value'];
		}
		return $values;
	}

	private function sign( array $payload, array $key ) {
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT', 'kid' => $key['jwk']['kid'], 'jwk' => $key['jwk'] );
		$input  = $this->base64url_encode( wp_json_encode( $header ) ) . '.' . $this->base64url_encode( wp_json_encode( $payload ) );
		$signature = '';
		if ( ! openssl_sign( $input, $signature, $key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( 'credential_signing_failed', __( 'The verifiable credential could not be signed.', 'certificate-manager' ) );
		}
		return $input . '.' . $this->base64url_encode( $signature );
	}

	private function get_stored_credential( int $certificate_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name uses WP prefix; no caching for credential retrieval.
		return $wpdb->get_var( $wpdb->prepare( "SELECT credential_jwt FROM {$wpdb->prefix}certificate_manager_verifiable_credentials WHERE certificate_id = %d AND key_id = %s", $certificate_id, self::CREDENTIAL_PROFILE ) );
	}

	private function find_certificate_by_short_token( string $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,32}$/', $token ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name uses WP prefix.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}certificate_manager_certificates WHERE verification_token LIKE %s LIMIT 2", $wpdb->esc_like( $token ) . '%' ), ARRAY_A );
		return 1 === count( $rows ) ? $rows[0] : false;
	}

	private function get_or_create_signing_key() {
		$stored = get_option( self::KEY_OPTION, array() );
		if ( is_array( $stored ) && ! empty( $stored['private_key'] ) && ! empty( $stored['jwk'] ) ) {
			$private_key = $this->decrypt_private_key( $stored['private_key'] );
			if ( $private_key ) {
				return array( 'private_key' => $private_key, 'jwk' => $stored['jwk'] );
			}
		}

		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error( 'openssl_required', __( 'OpenSSL is required to issue signed verifiable credentials.', 'certificate-manager' ) );
		}
		$config_path = defined( 'CERTIFICATE_MANAGER_PATH' ) ? CERTIFICATE_MANAGER_PATH . 'src/Assets/openssl.cnf' : '';
		$options = array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA );
		if ( $config_path && is_readable( $config_path ) ) {
			$options['config'] = $config_path;
		}
		$resource = @openssl_pkey_new( $options );
		if ( ! $resource && isset( $options['config'] ) ) {
			// Some hosts provide a complete global OpenSSL configuration. Retry it
			// if the minimal portable configuration is not accepted.
			unset( $options['config'] );
			$resource = @openssl_pkey_new( $options );
		}
		$export_options = isset( $options['config'] ) ? array( 'config' => $options['config'] ) : null;
		if ( ! $resource || ! @openssl_pkey_export( $resource, $private_key, null, $export_options ) ) {
			$this->log_openssl_errors( 'RSA signing key generation' );
			return new \WP_Error( 'key_generation_failed', __( 'This server cannot create the RSA signing key required for signed credentials. Please enable the PHP OpenSSL extension and RSA key generation, then try again.', 'certificate-manager' ) );
		}
		$details = openssl_pkey_get_details( $resource );
		if ( empty( $details['rsa']['n'] ) || empty( $details['rsa']['e'] ) ) {
			return new \WP_Error( 'key_generation_failed', __( 'The generated signing key is invalid.', 'certificate-manager' ) );
		}
		$jwk = array(
			'kty' => 'RSA',
			'use' => 'sig',
			'alg' => 'RS256',
			'kid' => $this->issuer_url() . '#key-1',
			'n'   => $this->base64url_encode( $details['rsa']['n'] ),
			'e'   => $this->base64url_encode( $details['rsa']['e'] ),
		);
		update_option( self::KEY_OPTION, array( 'private_key' => $this->encrypt_private_key( $private_key ), 'jwk' => $jwk ), false );
		return array( 'private_key' => $private_key, 'jwk' => $jwk );
	}

	private function encrypt_private_key( string $private_key ): array {
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $private_key, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
		return array( 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'ciphertext' => base64_encode( $ciphertext ) );
	}

	private function decrypt_private_key( array $encrypted ): string {
		if ( empty( $encrypted['iv'] ) || empty( $encrypted['tag'] ) || empty( $encrypted['ciphertext'] ) ) {
			return '';
		}
		return (string) openssl_decrypt( base64_decode( $encrypted['ciphertext'] ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, base64_decode( $encrypted['iv'] ), base64_decode( $encrypted['tag'] ) );
	}

	/**
	 * Record OpenSSL's diagnostic queue for administrators without exposing
	 * host details to a public verification-page visitor.
	 */
	private function log_openssl_errors( string $operation ): void {
		$errors = array();
		while ( $error = openssl_error_string() ) {
			$errors[] = $error;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- OpenSSL errors are infrastructure-level and cannot be stored in the DB at this point.
		error_log( 'Certificate Manager ' . $operation . ' failed' . ( $errors ? ': ' . implode( ' | ', $errors ) : '' ) );
	}

	private function issuer_url(): string { return rest_url( 'certificate-manager/v1/credentials/issuer' ); }
	private function credential_url( string $token ): string { return add_query_arg( array( 'cm_credential' => '1', 'v' => rawurlencode( $token ) ), home_url( '/' ) ); }
	private function achievement_url( int $template_id ): string { return rest_url( 'certificate-manager/v1/credentials/achievements/' . $template_id ); }
	private function to_iso8601( string $date ): string { return gmdate( 'c', strtotime( $date ) ); }
	private function base64url_encode( string $value ): string { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
}
