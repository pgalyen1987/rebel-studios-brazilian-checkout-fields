<?php
/**
 * CEP → address and CNPJ → company lookups.
 *
 * Two complaints from the incumbent's reviews, both about the same missing
 * capability:
 *
 *   "não preencher automaticamente o endereço após preencher o CEP"
 *   "buscar automático os dados da empresa ao digitar o CNPJ"
 *
 * This is the feature customers notice most, because it removes six fields of
 * typing on a phone. It is also where a checkout plugin most easily breaks a
 * store, so three rules apply:
 *
 * 1. The lookup runs SERVER-SIDE, through this plugin's own REST route. Calling
 *    a third-party API straight from checkout JavaScript leaks the shopper's
 *    address to that provider from their own browser and breaks under strict
 *    CSP. It also means an outage is handled in one place.
 *
 * 2. Responses are CACHED in transients. A CEP maps to an address that changes
 *    approximately never, and Brazilian stores run repeat traffic over the same
 *    postcodes. Cached lookups are free and instant.
 *
 * 3. A failed lookup NEVER blocks checkout. If ViaCEP is down the customer
 *    types their address as they always did. The most damaging thing a checkout
 *    plugin can do is refuse a sale because someone else's API is unavailable.
 *
 * Both providers are free and keyless: ViaCEP for postcodes, BrasilAPI for the
 * public CNPJ registry.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Lookup {

	const NAMESPACE   = 'rsbcf/v1';
	const CACHE_HOURS = 24 * 30;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/cep/(?P<cep>[0-9-]{8,9})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_cep' ),
				// Public on purpose: this runs on the checkout page for logged-out
				// shoppers. It exposes nothing private -- postcode-to-street is
				// already public data -- and it is rate limited below.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cnpj/(?P<cnpj>[0-9./-]{14,18})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_cnpj' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Crude per-IP throttle so an open endpoint cannot be used to hammer the
	 * upstream providers through this store.
	 */
	private static function throttled( $bucket ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$key = 'rsbcf_rate_' . $bucket . '_' . md5( $ip );
		$hits = (int) get_transient( $key );

		if ( $hits > 60 ) {
			return true;
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );

		return false;
	}

	public static function handle_cep( $request ) {
		$cep = RSBCF_Validator::digits( $request['cep'] );

		if ( ! RSBCF_Validator::is_cep( $cep ) ) {
			return new WP_REST_Response( array( 'found' => false, 'reason' => 'invalid' ), 200 );
		}
		if ( self::throttled( 'cep' ) ) {
			return new WP_REST_Response( array( 'found' => false, 'reason' => 'throttled' ), 200 );
		}

		$cache_key = 'rsbcf_cep_' . $cep;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$response = wp_remote_get(
			"https://viacep.com.br/ws/{$cep}/json/",
			array( 'timeout' => 6, 'user-agent' => 'rsbcf/' . RSBCF_VERSION )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Deliberately not cached: a provider outage must not be remembered
			// for a month.
			return new WP_REST_Response( array( 'found' => false, 'reason' => 'upstream' ), 200 );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! empty( $body['erro'] ) || empty( $body['logradouro'] ) && empty( $body['localidade'] ) ) {
			$payload = array( 'found' => false, 'reason' => 'not_found' );
			set_transient( $cache_key, $payload, DAY_IN_SECONDS );
			return new WP_REST_Response( $payload, 200 );
		}

		$payload = array(
			'found'        => true,
			'address'      => isset( $body['logradouro'] ) ? $body['logradouro'] : '',
			'neighborhood' => isset( $body['bairro'] ) ? $body['bairro'] : '',
			'city'         => isset( $body['localidade'] ) ? $body['localidade'] : '',
			'state'        => isset( $body['uf'] ) ? $body['uf'] : '',
		);

		set_transient( $cache_key, $payload, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return new WP_REST_Response( $payload, 200 );
	}

	public static function handle_cnpj( $request ) {
		$cnpj = RSBCF_Validator::digits( $request['cnpj'] );

		if ( ! RSBCF_Validator::is_cnpj( $cnpj ) ) {
			return new WP_REST_Response( array( 'found' => false, 'reason' => 'invalid' ), 200 );
		}
		if ( self::throttled( 'cnpj' ) ) {
			return new WP_REST_Response( array( 'found' => false, 'reason' => 'throttled' ), 200 );
		}

		$cache_key = 'rsbcf_cnpj_' . $cnpj;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$response = wp_remote_get(
			"https://brasilapi.com.br/api/cnpj/v1/{$cnpj}",
			array( 'timeout' => 8, 'user-agent' => 'rsbcf/' . RSBCF_VERSION )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_REST_Response( array( 'found' => false, 'reason' => 'upstream' ), 200 );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['razao_social'] ) ) {
			$payload = array( 'found' => false, 'reason' => 'not_found' );
			set_transient( $cache_key, $payload, DAY_IN_SECONDS );
			return new WP_REST_Response( $payload, 200 );
		}

		$payload = array(
			'found'        => true,
			'legal_name'   => $body['razao_social'],
			'trade_name'   => isset( $body['nome_fantasia'] ) ? $body['nome_fantasia'] : '',
			'address'      => isset( $body['logradouro'] ) ? $body['logradouro'] : '',
			'number'       => isset( $body['numero'] ) ? $body['numero'] : '',
			'neighborhood' => isset( $body['bairro'] ) ? $body['bairro'] : '',
			'city'         => isset( $body['municipio'] ) ? $body['municipio'] : '',
			'state'        => isset( $body['uf'] ) ? $body['uf'] : '',
			'cep'          => isset( $body['cep'] ) ? RSBCF_Validator::digits( $body['cep'] ) : '',
		);

		set_transient( $cache_key, $payload, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return new WP_REST_Response( $payload, 200 );
	}
}
