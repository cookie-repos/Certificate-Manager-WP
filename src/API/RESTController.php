<?php
/**
 * Certificate Manager REST API Controller
 *
 * @package CertificateManager
 */

namespace CertificateManager\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for certificate management
 */
class RESTController {
	
	/**
	 * Namespace
	 *
	 * @var string
	 */
	const VERSION = 'v1';
	
	/**
	 * Namespace prefix
	 *
	 * @var string
	 */
	const NAMESPACE = 'certificate-manager/' . self::VERSION;
	
	/**
	 * Issuance service
	 *
	 * @var mixed
	 */
	private $issuance_service;
	
	/**
	 * Verification service
	 *
	 * @var mixed
	 */
	private $verification_service;
	
	/**
	 * Template repository
	 *
	 * @var mixed
	 */
	private $template_repo;
	
	/**
	 * API key repository
	 *
	 * @var mixed
	 */
	private $api_key_repo;
	
	/**
	 * Settings
	 *
	 * @var mixed
	 */
	private $settings;

	/**
	 * Verifiable credentials service.
	 *
	 * @var mixed
	 */
	private $verifiable_credential_service;

	/**
	 * Idempotency service.
	 *
	 * @var mixed
	 */
	private $idempotency_service;

	/**
	 * Webhook repository.
	 *
	 * @var mixed
	 */
	private $webhook_repo;

	/**
	 * Webhook service.
	 *
	 * @var mixed
	 */
	private $webhook_service;
	
	/**
	 * Constructor
	 *
	 * @param mixed $issuance_service Issuance service
	 * @param mixed $verification_service Verification service
	 * @param mixed $template_repo Template repository
	 * @param mixed $api_key_repo API key repository
	 * @param mixed $settings Settings
	 * @param mixed $verifiable_credential_service Verifiable credentials service
	 * @param mixed $idempotency_service Idempotency service
	 * @param mixed $webhook_repo Webhook repository
	 * @param mixed $webhook_service Webhook service
	 */
	public function __construct( $issuance_service, $verification_service, $template_repo, $api_key_repo, $settings, $verifiable_credential_service, $idempotency_service, $webhook_repo, $webhook_service ) {
		$this->issuance_service = $issuance_service;
		$this->verification_service = $verification_service;
		$this->template_repo = $template_repo;
		$this->api_key_repo = $api_key_repo;
		$this->settings = $settings;
		$this->verifiable_credential_service = $verifiable_credential_service;
		$this->idempotency_service = $idempotency_service;
		$this->webhook_repo = $webhook_repo;
		$this->webhook_service = $webhook_service;
	}
	
	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// Certificate issuance
		register_rest_route( self::NAMESPACE, '/certificates', array(
			'methods' => 'POST',
			'callback' => array( $this, 'issue_certificate' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// Certificate retrieval
		register_rest_route( self::NAMESPACE, '/certificates/(?P<id>\\d+)', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_certificate' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// Certificate update
		register_rest_route( self::NAMESPACE, '/certificates/(?P<id>\\d+)', array(
			'methods' => 'PUT',
			'callback' => array( $this, 'update_certificate' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// Certificate revocation
		register_rest_route( self::NAMESPACE, '/certificates/(?P<id>\\d+)/revocation', array(
			'methods' => 'POST',
			'callback' => array( $this, 'revoke_certificate' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// Certificate replacement
		register_rest_route( self::NAMESPACE, '/certificates/(?P<id>\\d+)/replacement', array(
			'methods' => 'POST',
			'callback' => array( $this, 'replace_certificate' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// API key management
		register_rest_route( self::NAMESPACE, '/api-keys', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_api_keys' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		register_rest_route( self::NAMESPACE, '/api-keys', array(
			'methods' => 'POST',
			'callback' => array( $this, 'create_api_key' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// Template information
		register_rest_route( self::NAMESPACE, '/templates/(?P<id>\\d+)', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_template_info' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// Test webhook
		register_rest_route( self::NAMESPACE, '/test-webhook', array(
			'methods' => 'POST',
			'callback' => array( $this, 'test_webhook' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );
		
		// Documentation endpoint
		register_rest_route( self::NAMESPACE, '/documentation', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_documentation' ),
			'permission_callback' => array( $this, 'authenticate_api_request' ),
		) );

		// Public metadata needed to independently validate VC-JWT credentials.
		register_rest_route( self::NAMESPACE, '/credentials/issuer', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_credential_issuer' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/credentials/keys', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_credential_keys' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/credentials/status/(?P<token>[A-Za-z0-9_-]{8,32})', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_credential_status' ),
			'permission_callback' => '__return_true',
		) );

		// OpenID4VCI public offer and issuer endpoints. The offer's short-lived
		// pre-authorized code becomes a credential-only access token; no admin
		// API capability is exposed through these routes.
		register_rest_route( self::NAMESPACE, '/openid4vci/offers/(?P<code>[A-Za-z0-9_-]{32,96})', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_wallet_credential_offer' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/openid4vci/token', array(
			'methods' => 'POST',
			'callback' => array( $this, 'exchange_wallet_credential_offer' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/openid4vci/credential', array(
			'methods' => 'POST',
			'callback' => array( $this, 'get_wallet_credential' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Return the public credential issuer profile.
	 */
	public function get_credential_issuer() {
		return new \WP_REST_Response( $this->verifiable_credential_service->get_issuer_profile(), 200 );
	}

	/**
	 * Return the public JSON Web Key Set used by VC-JWT credentials.
	 */
	public function get_credential_keys() {
		$result = $this->verifiable_credential_service->get_jwks();
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ), 503 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Return current lifecycle status for a credential's public token.
	 */
	public function get_credential_status( $request ) {
		$result = $this->verifiable_credential_service->get_status_for_short_token( (string) $request->get_param( 'token' ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ), 404 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Return an OpenID4VCI credential offer by reference.
	 */
	public function get_wallet_credential_offer( $request ) {
		$result = $this->verifiable_credential_service->get_wallet_offer( (string) $request->get_param( 'code' ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_credential_offer', 'error_description' => $result->get_error_message() ), 404 );
		}
		return new \WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * Exchange a pre-authorized code for a credential-only OAuth token.
	 */
	public function exchange_wallet_credential_offer( $request ) {
		if ( 'urn:ietf:params:oauth:grant-type:pre-authorized_code' !== (string) $request->get_param( 'grant_type' ) ) {
			return new \WP_REST_Response( array( 'error' => 'unsupported_grant_type' ), 400, array( 'Cache-Control' => 'no-store' ) );
		}
		$result = $this->verifiable_credential_service->redeem_wallet_offer( (string) $request->get_param( 'pre-authorized_code' ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_code(), 'error_description' => $result->get_error_message() ), 400, array( 'Cache-Control' => 'no-store' ) );
		}
		return new \WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * Return the VC-JWT to a wallet that holds a valid short-lived access token.
	 */
	public function get_wallet_credential( $request ) {
		$authorization = (string) $request->get_header( 'authorization' );
		if ( 0 !== stripos( $authorization, 'Bearer ' ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_token' ), 401, array( 'Cache-Control' => 'no-store', 'WWW-Authenticate' => 'Bearer' ) );
		}
		$configuration = (string) $request->get_param( 'credential_configuration_id' );
		if ( \CertificateManager\Services\VerifiableCredentialService::WALLET_CONFIGURATION_ID !== $configuration ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_credential_request' ), 400, array( 'Cache-Control' => 'no-store' ) );
		}
		$result = $this->verifiable_credential_service->get_wallet_credential( trim( substr( $authorization, 7 ) ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_token', 'error_description' => $result->get_error_message() ), 401, array( 'Cache-Control' => 'no-store', 'WWW-Authenticate' => 'Bearer error="invalid_token"' ) );
		}
		return new \WP_REST_Response( array( 'credentials' => array( array( 'credential' => $result ) ) ), 200, array( 'Cache-Control' => 'no-store' ) );
	}
	
	/**
	 * Authenticate API request
	 *
	 * @param WP_REST_Request $request Request
	 * @return bool
	 */
	public function authenticate_api_request( $request ) {
		// Get authorization header
		$auth = $request->get_header( 'authorization' );
		
		if ( ! $auth || strpos( $auth, 'Bearer ' ) !== 0 ) {
			return false;
		}
		
		$token = trim( substr( $auth, 7 ) );
		
		// Validate API key
		$api_key = $this->api_key_repo->get_by_token( $token );
		
		if ( ! $api_key || ! $api_key['is_active'] ) {
			return false;
		}
		
		// Update last used time
		$this->api_key_repo->update_last_used( (int) $api_key['id'] );
		
		return true;
	}
	
	/**
	 * Issue certificate via API
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function issue_certificate( $request ) {
		$params = $request->get_params();
		
		// Validate required fields
		if ( empty( $params['template_id'] ) ) {
			return new \WP_REST_Response( array(
				'error' => 'invalid_request',
				'message' => __( 'template_id is required', 'certificate-manager' ),
			), 400 );
		}

		if ( ! $this->request_is_allowed( $request, 'issue', absint( $params['template_id'] ) ) ) {
			return $this->forbidden_response();
		}
		
		if ( empty( $params['recipient_name'] ) ) {
			return new \WP_REST_Response( array(
				'error' => 'invalid_request',
				'message' => __( 'recipient_name is required', 'certificate-manager' ),
			), 400 );
		}

		$api_key_id = $this->get_api_key_id( $request );
		if ( ! $api_key_id ) {
			return $this->forbidden_response();
		}

		// Check idempotency after the key and requested template have been authorised.
		$idempotency_key = trim( (string) $request->get_header( 'Idempotency-Key' ) );
		if ( '' !== $idempotency_key ) {
			$existing = $this->idempotency_service->get_certificate_by_idempotency_key( $idempotency_key, $api_key_id );
			if ( $existing ) {
				return new \WP_REST_Response( $existing, 200 );
			}
		}
		
		// Get template
		$template = $this->template_repo->get_template( $params['template_id'] );
		if ( ! $template ) {
			return new \WP_REST_Response( array(
				'error' => 'invalid_template',
				'message' => __( 'Template not found', 'certificate-manager' ),
			), 404 );
		}
		
		// Check if template allows API issuance
		if ( ! $template['allow_api_issuance'] ) {
			return new \WP_REST_Response( array(
				'error' => 'api_not_allowed',
				'message' => __( 'API issuance not enabled for this template', 'certificate-manager' ),
			), 403 );
		}
		
		// Validate data against template fields
		$errors = $this->validate_certificate_data( $params, $params['template_id'] );
		if ( ! empty( $errors ) ) {
			return new \WP_REST_Response( array(
				'error' => 'validation_failed',
				'messages' => $errors,
			), 400 );
		}
		
		// Issue certificate
		$result = $this->issuance_service->issue_certificate_from_api( $params, $api_key_id );
		
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array(
				'error' => $result->get_error_code(),
				'message' => $result->get_error_message(),
			), 400 );
		}
		
		// Store idempotency record if provided
		if ( $idempotency_key ) {
			$this->idempotency_service->store_idempotency_record( $idempotency_key, $result['id'], $api_key_id );
		}
		
		return new \WP_REST_Response( $result, 201 );
	}
	
	/**
	 * Get certificate
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function get_certificate( $request ) {
		$certificate_id = $request->get_param( 'id' );
		
		$certificate = $this->issuance_service->get_certificate_repository()->get_certificate( $certificate_id );
		
		if ( ! $certificate ) {
			return new \WP_REST_Response( array(
				'error' => 'not_found',
				'message' => __( 'Certificate not found', 'certificate-manager' ),
			), 404 );
		}

		if ( ! $this->request_is_allowed( $request, 'read', absint( $certificate['template_id'] ) ) ) {
			return $this->forbidden_response();
		}
		
		return new \WP_REST_Response( $certificate, 200 );
	}
	
	/**
	 * Update certificate
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function update_certificate( $request ) {
		$certificate_id = $request->get_param( 'id' );
		$params = $request->get_params();
		
		$certificate = $this->issuance_service->get_certificate_repository()->get_certificate( $certificate_id );
		
		if ( ! $certificate ) {
			return new \WP_REST_Response( array(
				'error' => 'not_found',
				'message' => __( 'Certificate not found', 'certificate-manager' ),
			), 404 );
		}

		if ( ! $this->request_is_allowed( $request, 'edit', absint( $certificate['template_id'] ) ) ) {
			return $this->forbidden_response();
		}
		
		$result = $this->issuance_service->update_certificate( $certificate_id, $params );
		
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array(
				'error' => $result->get_error_code(),
				'message' => $result->get_error_message(),
			), 400 );
		}
		
		return new \WP_REST_Response( $result, 200 );
	}
	
	/**
	 * Revoke certificate
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function revoke_certificate( $request ) {
		$certificate_id = $request->get_param( 'id' );
		$params = $request->get_params();
		$certificate = $this->issuance_service->get_certificate_repository()->get_certificate( $certificate_id );
		if ( ! $certificate ) {
			return new \WP_REST_Response( array( 'error' => 'not_found', 'message' => __( 'Certificate not found', 'certificate-manager' ) ), 404 );
		}
		if ( ! $this->request_is_allowed( $request, 'revoke', absint( $certificate['template_id'] ) ) ) {
			return $this->forbidden_response();
		}
		
		$reason = $params['reason'] ?? '';
		$public = isset( $params['make_public'] ) ? (bool) $params['make_public'] : false;
		
		$result = $this->issuance_service->revoke_certificate( $certificate_id, $reason, $public );
		
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array(
				'error' => $result->get_error_code(),
				'message' => $result->get_error_message(),
			), 400 );
		}
		
		return new \WP_REST_Response( $result, 200 );
	}
	
	/**
	 * Replace certificate
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function replace_certificate( $request ) {
		$original_id = $request->get_param( 'id' );
		$params = $request->get_params();
		
		if ( empty( $params['replacement_id'] ) ) {
			return new \WP_REST_Response( array(
				'error' => 'invalid_request',
				'message' => __( 'replacement_id is required', 'certificate-manager' ),
			), 400 );
		}

		$certificate_repo = $this->issuance_service->get_certificate_repository();
		$original = $certificate_repo->get_certificate( $original_id );
		$replacement = $certificate_repo->get_certificate( $params['replacement_id'] );
		if ( ! $original || ! $replacement ) {
			return new \WP_REST_Response( array( 'error' => 'not_found', 'message' => __( 'Certificate not found', 'certificate-manager' ) ), 404 );
		}
		if ( ! $this->request_is_allowed( $request, 'replace', absint( $original['template_id'] ) ) || ! $this->request_is_allowed( $request, 'replace', absint( $replacement['template_id'] ) ) ) {
			return $this->forbidden_response();
		}
		
		$result = $this->issuance_service->replace_certificate( $original_id, $params['replacement_id'] );
		
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array(
				'error' => $result->get_error_code(),
				'message' => $result->get_error_message(),
			), 400 );
		}
		
		return new \WP_REST_Response( $result, 200 );
	}
	
	/**
	 * Get API keys
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function get_api_keys( $request ) {
		if ( ! $this->request_is_allowed( $request, 'manage_api_keys' ) ) {
			return $this->forbidden_response();
		}
		$api_keys = $this->api_key_repo->get_all();
		
		return new \WP_REST_Response( $api_keys, 200 );
	}
	
	/**
	 * Create API key
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function create_api_key( $request ) {
		if ( ! $this->request_is_allowed( $request, 'manage_api_keys' ) ) {
			return $this->forbidden_response();
		}
		$params = $request->get_params();
		unset( $params['created_by'] );
		
		if ( empty( $params['name'] ) ) {
			return new \WP_REST_Response( array(
				'error' => 'invalid_request',
				'message' => __( 'name is required', 'certificate-manager' ),
			), 400 );
		}
		
		$result = $this->api_key_repo->create( $params );
		
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array(
				'error' => $result->get_error_code(),
				'message' => $result->get_error_message(),
			), 400 );
		}
		
		return new \WP_REST_Response( $result, 201 );
	}
	
	/**
	 * Get template info for API documentation
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function get_template_info( $request ) {
		$template_id = $request->get_param( 'id' );
		if ( ! $this->request_is_allowed( $request, 'read', absint( $template_id ) ) ) {
			return $this->forbidden_response();
		}
		
		$template = $this->template_repo->get_template_with_version( $template_id );
		
		if ( ! $template ) {
			return new \WP_REST_Response( array(
				'error' => 'not_found',
				'message' => __( 'Template not found', 'certificate-manager' ),
			), 404 );
		}
		
		$variables = $this->template_repo->get_template_variables( $template_id );
		
		return new \WP_REST_Response( array(
			'template' => $template,
			'variables' => $variables,
			'api_examples' => $this->generate_api_examples( $template, $variables ),
		), 200 );
	}
	
	/**
	 * Test webhook
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function test_webhook( $request ) {
		if ( ! $this->request_is_allowed( $request, 'manage_integrations' ) ) {
			return $this->forbidden_response();
		}
		$params = $request->get_params();
		
		if ( empty( $params['webhook_id'] ) ) {
			return new \WP_REST_Response( array(
				'error' => 'invalid_request',
				'message' => __( 'webhook_id is required', 'certificate-manager' ),
			), 400 );
		}
		
		$webhook = $this->webhook_repo->get_by_id( $params['webhook_id'] );
		
		if ( ! $webhook ) {
			return new \WP_REST_Response( array(
				'error' => 'not_found',
				'message' => __( 'Webhook not found', 'certificate-manager' ),
			), 404 );
		}
		
		$result = $this->webhook_service->test_webhook( $webhook );
		
		return new \WP_REST_Response( $result, 200 );
	}
	
	/**
	 * Get API documentation
	 *
	 * @param WP_REST_Request $request Request
	 * @return WP_REST_Response
	 */
	public function get_documentation( $request ) {
		if ( ! $this->request_is_allowed( $request, 'read' ) ) {
			return $this->forbidden_response();
		}
		$templates = $this->template_repo->get_templates();
		$api_key = $this->get_api_key_from_request( $request );
		$scoped_template_ids = $api_key['template_ids'] ?? array();
		
		$templates_list = array();
		foreach ( $templates['data'] as $template ) {
			if ( $scoped_template_ids && ! in_array( absint( $template['id'] ), $scoped_template_ids, true ) ) {
				continue;
			}
			$variables = $this->template_repo->get_template_variables( $template['id'] );
			$templates_list[] = array(
				'id' => $template['id'],
				'name' => $template['title'],
				'variables' => count( $variables ),
				'allow_api_issuance' => $template['allow_api_issuance'] ?? false,
			);
		}
		
		return new \WP_REST_Response( array(
			'version' => self::VERSION,
			'base_url' => rest_url( self::NAMESPACE ),
			'authentication' => array(
				'method' => 'Bearer token',
				'header' => 'Authorization',
			),
			'endpoints' => array(
				'POST /certificates' => array(
					'description' => __( 'Issue a new certificate', 'certificate-manager' ),
					'required_fields' => array( 'template_id', 'recipient_name' ),
				),
				'GET /certificates/{id}' => array(
					'description' => __( 'Get certificate details', 'certificate-manager' ),
				),
				'PUT /certificates/{id}' => array(
					'description' => __( 'Update certificate', 'certificate-manager' ),
				),
				'POST /certificates/{id}/revocation' => array(
					'description' => __( 'Revoke certificate', 'certificate-manager' ),
				),
				'POST /certificates/{id}/replacement' => array(
					'description' => __( 'Replace certificate', 'certificate-manager' ),
				),
				'GET /templates/{id}' => array(
					'description' => __( 'Get template info and API examples', 'certificate-manager' ),
				),
			),
			'templates' => $templates_list,
		), 200 );
	}
	
	/**
	 * Generate API examples for template
	 *
	 * @param array $template Template data
	 * @param array $variables Template variables
	 * @return array
	 */
	private function generate_api_examples( array $template, array $variables ): array {
		return array(
			'curl' => $this->generate_curl_example( $template, $variables ),
			'php' => $this->generate_php_example( $template, $variables ),
			'javascript' => $this->generate_javascript_example( $template, $variables ),
			'zapier' => $this->generate_zapier_example( $template, $variables ),
			'make' => $this->generate_make_example( $template, $variables ),
			'n8n' => $this->generate_n8n_example( $template, $variables ),
		);
	}
	
	/**
	 * Generate cURL example
	 *
	 * @param array $template Template data
	 * @param array $variables Variable keys
	 * @return string
	 */
	private function generate_curl_example( array $template, array $variables ): string {
		$example_data = array(
			'template_id' => $template['id'],
			'recipient_name' => 'John Doe',
		);
		
		foreach ( $variables as $var ) {
			switch ( $var['field_type'] ) {
				case 'email':
					$example_data[ $var['key'] ] = 'recipient@example.com';
					break;
				case 'number':
				case 'integer':
					$example_data[ $var['key'] ] = 100;
					break;
				case 'checkbox':
					$example_data[ $var['key'] ] = true;
					break;
				default:
					$example_data[ $var['key'] ] = 'Sample value';
					break;
			}
		}
		
		$body = json_encode( $example_data, JSON_PRETTY_PRINT );
		
		return "curl -X POST " . home_url( '/wp-json/certificate-manager/v1/certificates' ) . " \\\n  -H 'Authorization: Bearer YOUR_API_KEY' \\\n  -H 'Idempotency-Key: unique-identifier-123' \\\n  -H 'Content-Type: application/json' \\\n  -d '" . $body . "'";
	}
	
	/**
	 * Generate PHP example
	 *
	 * @param array $template Template data
	 * @param array $variables Variable keys
	 * @return string
	 */
	private function generate_php_example( array $template, array $variables ): string {
		$example_data = array(
			'template_id' => $template['id'],
			'recipient_name' => 'John Doe',
		);
		
		foreach ( $variables as $var ) {
			switch ( $var['field_type'] ) {
				case 'email':
					$example_data[ $var['key'] ] = 'recipient@example.com';
					break;
				case 'number':
				case 'integer':
					$example_data[ $var['key'] ] = 100;
					break;
				case 'checkbox':
					$example_data[ $var['key'] ] = true;
					break;
				default:
					$example_data[ $var['key'] ] = 'Sample value';
					break;
			}
		}
		
		$body = var_export( $example_data, true );
		
		return "\$client = new GuzzleHttp\Client();\n\n" .
			"\\$response = \$client->post( '" . home_url( '/wp-json/certificate-manager/v1/certificates' ) . "', [\n" .
			"    'headers' => [\n" .
			"        'Authorization' => 'Bearer YOUR_API_KEY',\n" .
			"        'Idempotency-Key' => 'unique-identifier-123',\n" .
			"        'Content-Type' => 'application/json',\n" .
			"    ],\n" .
			"    'json' => " . $body . ",\n" .
			"]);\n\n" .
			"\\$data = json_decode( \\$response->getBody(), true );";
	}
	
	/**
	 * Generate JavaScript example
	 *
	 * @param array $template Template data
	 * @param array $variables Variable keys
	 * @return string
	 */
	private function generate_javascript_example( array $template, array $variables ): string {
		$example_data = array(
			'template_id' => $template['id'],
			'recipient_name' => 'John Doe',
		);
		
		foreach ( $variables as $var ) {
			switch ( $var['field_type'] ) {
				case 'email':
					$example_data[ $var['key'] ] = 'recipient@example.com';
					break;
				case 'number':
				case 'integer':
					$example_data[ $var['key'] ] = 100;
					break;
				case 'checkbox':
					$example_data[ $var['key'] ] = true;
					break;
				default:
					$example_data[ $var['key'] ] = 'Sample value';
					break;
			}
		}
		
		$body = json_encode( $example_data, JSON_PRETTY_PRINT );
		
		return "fetch( '" . home_url( '/wp-json/certificate-manager/v1/certificates' ) . "', {\n" .
			"    method: 'POST',\n" .
			"    headers: {\n" .
			"        'Authorization': 'Bearer YOUR_API_KEY',\n" .
			"        'Idempotency-Key': 'unique-identifier-123',\n" .
			"        'Content-Type': 'application/json',\n" .
			"    },\n" .
			"    body: JSON.stringify( " . $body . " ),\n" .
			"} )\n" .
			".then( response => response.json() )\n" .
			".then( data => console.log( data ) );";
	}
	
	/**
	 * Generate Zapier example
	 *
	 * @param array $template Template data
	 * @param array $variables Variable keys
	 * @return string
	 */
	private function generate_zapier_example( array $template, array $variables ): string {
		$example_data = array(
			'template_id' => $template['id'],
			'recipient_name' => '{{Recipient Name}}',
		);
		
		foreach ( $variables as $var ) {
			$example_data[ $var['key'] ] = '{' . ucwords( str_replace( '_', ' ', $var['key'] ) ) . '}';
		}
		
		return "URL: " . home_url( '/wp-json/certificate-manager/v1/certificates' ) . "\n" .
			"Method: POST\n" .
			"Content-Type: application/json\n" .
			"Authorization: Bearer {API Key}\n" .
			"Idempotency-Key: {Unique ID}\n\n" .
			"Body:\n" . json_encode( $example_data, JSON_PRETTY_PRINT );
	}
	
	/**
	 * Generate Make example
	 *
	 * @param array $template Template data
	 * @param array $variables Variable keys
	 * @return string
	 */
	private function generate_make_example( array $template, array $variables ): string {
		$example_data = array(
			'template_id' => $template['id'],
			'recipient_name' => '{{Recipient Name}}',
		);
		
		foreach ( $variables as $var ) {
			$example_data[ $var['key'] ] = '{' . ucwords( str_replace( '_', ' ', $var['key'] ) ) . '}';
		}
		
		return "Module: HTTP - Make a web request\n" .
			"Method: POST\n" .
			"URL: " . home_url( '/wp-json/certificate-manager/v1/certificates' ) . "\n" .
			"Headers:\n" .
			"  Authorization: Bearer {API Key}\n" .
			"  Idempotency-Key: {Unique ID}\n" .
			"  Content-Type: application/json\n\n" .
			"Body:\n" . json_encode( $example_data, JSON_PRETTY_PRINT );
	}
	
	/**
	 * Generate n8n example
	 *
	 * @param array $template Template data
	 * @param array $variables Variable keys
	 * @return string
	 */
	private function generate_n8n_example( array $template, array $variables ): string {
		$example_data = array(
			'template_id' => $template['id'],
			'recipient_name' => '={{$json["recipient_name"]}}',
		);
		
		foreach ( $variables as $var ) {
			$example_data[ $var['key'] ] = '={{$json["' . $var['key'] . '"]}}';
		}
		
		return "Node: HTTP Request\n" .
			"Method: POST\n" .
			"URL: " . home_url( '/wp-json/certificate-manager/v1/certificates' ) . "\n" .
			"Headers:\n" .
			'  Authorization: Bearer {{ $json["api_key"] }}' . "\n" .
			'  Idempotency-Key: {{ $json["idempotency_key"] }}' . "\n" .
			"  Content-Type: application/json\n\n" .
			"Body (JSON):\n" . json_encode( $example_data, JSON_PRETTY_PRINT );
	}
	
	/**
	 * Get API key ID from request
	 *
	 * @param WP_REST_Request $request Request
	 * @return int|false
	 */
	private function get_api_key_id( $request ) {
		$api_key = $this->get_api_key_from_request( $request );
		return $api_key ? (int) $api_key['id'] : false;
	}

	/**
	 * Resolve the active API key carried by the request.
	 */
	private function get_api_key_from_request( $request ) {
		$auth = $request->get_header( 'authorization' );
		if ( ! $auth || strpos( $auth, 'Bearer ' ) !== 0 ) {
			return false;
		}
		return $this->api_key_repo->get_by_token( trim( substr( $auth, 7 ) ) );
	}

	/**
	 * Check an authenticated API key's operation and optional template scope.
	 */
	private function request_is_allowed( $request, string $permission, int $template_id = 0 ): bool {
		$api_key = $this->get_api_key_from_request( $request );
		if ( ! $api_key || empty( $api_key['is_active'] ) || ! $this->api_key_repo->has_permission( $api_key, $permission ) ) {
			return false;
		}
		$template_ids = $api_key['template_ids'] ?? array();
		return ! $template_id || empty( $template_ids ) || in_array( $template_id, $template_ids, true );
	}

	/**
	 * Return a consistent response for a valid key that lacks access.
	 */
	private function forbidden_response(): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'error' => 'forbidden',
			'message' => __( 'This API key does not have permission for that operation.', 'certificate-manager' ),
		), 403 );
	}
	
	/**
	 * Validate certificate data against template fields
	 *
	 * @param array $data Certificate data
	 * @param int $template_id Template ID
	 * @return array
	 */
	private function validate_certificate_data( array $data, int $template_id ): array {
		$errors = array();
		$template_vars = $this->template_repo->get_template_variables( $template_id );
		
		foreach ( $template_vars as $var ) {
			if ( $var['is_required'] && ( ! isset( $data[ $var['key'] ] ) || $data[ $var['key'] ] === '' ) ) {
				$errors[] = sprintf(
					__( '%s is required', 'certificate-manager' ),
					$var['label']
				);
			}
			
			if ( isset( $data[ $var['key'] ] ) && $data[ $var['key'] ] !== '' ) {
				if ( ! $this->validate_field_value( $data[ $var['key'] ], $var['field_type'] ) ) {
					$errors[] = sprintf(
						__( '%s is invalid', 'certificate-manager' ),
						$var['label']
					);
				}
			}
		}
		
		return $errors;
	}
	
	/**
	 * Validate field value
	 *
	 * @param mixed $value Value
	 * @param string $field_type Field type
	 * @return bool
	 */
	private function validate_field_value( $value, string $field_type ): bool {
		switch ( $field_type ) {
			case 'email':
				return is_email( $value );
			case 'number':
			case 'integer':
			case 'decimal':
				return is_numeric( $value );
			case 'url':
				return filter_var( $value, FILTER_VALIDATE_URL ) !== false;
			case 'date':
				return (bool) strtotime( $value );
			default:
				return true;
		}
	}
}
