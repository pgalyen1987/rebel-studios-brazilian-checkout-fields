<?php
/**
 * Block (React) checkout integration.
 *
 * This is the half the incumbent does not have, and the reason its installed
 * base is stranded rather than merely inconvenienced. WooCommerce made the
 * block checkout the default; a plugin that only hooks the classic
 * `woocommerce_checkout_fields` filter adds no fields at all on a block
 * checkout, so a store that migrates silently stops collecting CPF and only
 * finds out when an invoice is rejected.
 *
 * Fields are registered through WooCommerce's own additional-checkout-fields
 * API (8.9+), which renders them in the React checkout and persists them to the
 * customer and the order. Where each field goes matters:
 *
 * - The buyer's identity (person type, CPF, CNPJ, Razão Social, RG, IE, birth
 *   date, mobile) goes in the `contact` section, once. Registered as `address`
 *   fields they were copied into the shipping address, so a store that ships
 *   asked for the CPF under "Shipping address", and twice when billing differed.
 * - Número and Bairro are genuinely part of an address, so they stay `address`
 *   fields and exist for billing and shipping alike.
 *
 * When the store collects both personal and company documents, a Pessoa Física /
 * Pessoa Jurídica selector drives JSON-schema rules: each document field is shown
 * and required only for its person type, and WooCommerce enforces the same rules
 * on the server when the order is placed.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Blocks_Checkout {

	const PERSON_TYPE_ID = 'rsbcf/person-type';

	/** WooCommerce's order meta prefix for `contact` and `order` fields. */
	const OTHER_PREFIX = '_wc_other/';

	/** Fields that describe a place rather than the buyer. */
	const ADDRESS_KEYS = array( 'billing_rsbcf_number', 'billing_rsbcf_neighborhood' );

	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Whether this WooCommerce is new enough to have the API.
	 */
	private static function supported() {
		return function_exists( 'woocommerce_register_additional_checkout_field' );
	}

	/** `billing_rsbcf_company_legal_name` -> `rsbcf/company-legal-name`. */
	public static function field_id( $key ) {
		return 'rsbcf/' . str_replace( array( 'billing_rsbcf_', '_' ), array( '', '-' ), $key );
	}

	/**
	 * A JSON-schema rule that holds when the buyer is (or is not) a company.
	 *
	 * `properties` passes vacuously when a property is absent, so "is a company" must also
	 * require the key; "is not a company" deliberately does not, so a form where the selector
	 * has no value yet behaves like Pessoa Física, its default.
	 */
	private static function company_rule( $is_company ) {
		$fields = array(
			'type'       => 'object',
			'properties' => array(
				self::PERSON_TYPE_ID => $is_company ? array( 'const' => 'company' ) : array( 'not' => array( 'const' => 'company' ) ),
			),
		);
		if ( $is_company ) {
			$fields['required'] = array( self::PERSON_TYPE_ID );
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'type'       => 'object',
					'properties' => array( 'additional_fields' => $fields ),
				),
			),
		);
	}

	public static function register() {
		if ( ! self::supported() ) {
			return;
		}

		$has_person_type = RSBCF_Fields::needs_person_type();

		if ( $has_person_type ) {
			add_filter(
				'woocommerce_get_default_value_for_' . self::PERSON_TYPE_ID,
				static function ( $value ) {
					return $value ? $value : 'person';
				}
			);
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::PERSON_TYPE_ID,
					'label'                      => __( 'Tipo de cliente', 'rebel-studios-brazilian-checkout-fields' ),
					'location'                   => 'contact',
					'type'                       => 'select',
					'required'                   => true,
					'show_in_order_confirmation' => false,
					'options'                    => array(
						array(
							'value' => 'person',
							'label' => __( 'Pessoa Física', 'rebel-studios-brazilian-checkout-fields' ),
						),
						array(
							'value' => 'company',
							'label' => __( 'Pessoa Jurídica', 'rebel-studios-brazilian-checkout-fields' ),
						),
					),
				)
			);
		}

		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			if ( ! RSBCF_Fields::is_enabled( $key ) ) {
				continue;
			}

			$required       = RSBCF_Fields::is_required( $key );
			$hidden         = false;
			$only           = $definition['only'];
			$optional_label = null;

			if ( $has_person_type && 'both' !== $only ) {
				if ( self::supports_rules() ) {
					// A personal document is hidden for a company and vice versa; "required" only
					// applies while the field is showing, or a company could never check out.
					$is_company_field = 'company' === $only;
					$hidden           = self::company_rule( ! $is_company_field );
					$required         = $required ? self::company_rule( $is_company_field ) : false;
				} else {
					// Shown to every buyer; "required for this person type" is checked in validate_contact().
					// WooCommerce would label it "CNPJ (optional)" while refusing a company without one,
					// so the optional label says who has to fill it in: "CNPJ (obrigatório para PJ)".
					if ( $required ) {
						$optional_label = self::required_for_label( $definition['label'], $only );
					}
					$required = false;
				}
			}

			$is_address = in_array( $key, self::ADDRESS_KEYS, true );

			$field = array(
				'id'                         => self::field_id( $key ),
				'label'                      => $definition['label'],
				'location'                   => $is_address ? 'address' : 'contact',
				'type'                       => 'text',
				'required'                   => $required,
				'hidden'                     => $hidden,
				// Número and Bairro are merged into the formatted address (RSBCF_Orders), so
				// WooCommerce's own list would print them a second time.
				'show_in_order_confirmation' => ! $is_address,
				'attributes'                 => array_filter(
					array(
						'data-cb-mask'  => $definition['mask'],
						'data-cb-field' => $key,
						'autocomplete'  => 'off',
					)
				),
				'validate_callback'          => static function ( $value ) use ( $key, $definition ) {
					if ( '' === trim( (string) $value ) ) {
						return null;
					}
					$error = RSBCF_Fields::validation_error( $key, $value, $definition['only'] );
					return $error ? new WP_Error( 'rsbcf_invalid_' . $key, $error ) : null;
				},
				// WooCommerce prints this stored value as-is wherever it lists the field, and has no
				// display filter, so documents are kept masked here (529.982.247-25). The canonical
				// digits-only copy that integrations read is written by mirror_to_classic_meta().
				'sanitize_callback'          => static function ( $value ) use ( $key ) {
					return RSBCF_Fields::display( $key, RSBCF_Fields::normalize( $key, $value ) );
				},
			);
			if ( null !== $optional_label ) {
				$field['optionalLabel'] = $optional_label;
			}
			woocommerce_register_additional_checkout_field( $field );
		}

		if ( $has_person_type && ! self::supports_rules() ) {
			add_action( 'woocommerce_blocks_validate_location_contact_fields', array( __CLASS__, 'validate_contact' ), 10, 2 );
		}

		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'mirror_to_classic_meta' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce reads JSON-schema rules in `required` and `hidden` (9.9.0 and later).
	 *
	 * Earlier versions keep the rule arrays as plain values: a field hidden by a rule is never
	 * rendered and a field required by a rule is required of everyone. On 9.8.7 the block checkout
	 * showed no CPF, CNPJ or Razão Social at all, and with a document set to required it refused
	 * every buyer ("CNPJ is required" on a field nobody could see).
	 */
	private static function supports_rules() {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9.0', '>=' );
	}

	/**
	 * "CNPJ (obrigatório para PJ)": the label WooCommerce before 9.9 shows for a document that every
	 * buyer sees but only one person type must fill in. Short on purpose: the block checkout cuts a
	 * label at the input's width, and "... para Pessoa Jurídica" lost its end on a 390 px phone.
	 *
	 * @param string $label Field label.
	 * @param string $only  'person' | 'company'.
	 */
	public static function required_for_label( $label, $only ) {
		return 'company' === $only
			/* translators: %s: checkout field label, e.g. CNPJ. PJ = Pessoa Jurídica. */
			? sprintf( __( '%s (obrigatório para PJ)', 'rebel-studios-brazilian-checkout-fields' ), $label )
			/* translators: %s: checkout field label, e.g. CPF. PF = Pessoa Física. */
			: sprintf( __( '%s (obrigatório para PF)', 'rebel-studios-brazilian-checkout-fields' ), $label );
	}

	/**
	 * WooCommerce before 9.9: a document required for one person type is checked here, when the
	 * order is placed, because those versions cannot make a field required for one selector value.
	 *
	 * @param WP_Error $errors Errors WooCommerce will show.
	 * @param array    $fields Contact field id => value.
	 */
	public static function validate_contact( $errors, $fields ) {
		$fields = is_array( $fields ) ? $fields : array();
		$type   = isset( $fields[ self::PERSON_TYPE_ID ] ) && 'company' === $fields[ self::PERSON_TYPE_ID ] ? 'company' : 'person';

		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			if ( $definition['only'] !== $type || ! RSBCF_Fields::is_required( $key ) ) {
				continue;
			}
			$id = self::field_id( $key );
			if ( '' === trim( (string) ( isset( $fields[ $id ] ) ? $fields[ $id ] : '' ) ) ) {
				/* translators: %s: checkout field label, e.g. CPF. */
				$errors->add( 'rsbcf_required_' . $key, sprintf( __( '%s é um campo obrigatório.', 'rebel-studios-brazilian-checkout-fields' ), $definition['label'] ) );
			}
		}
	}

	/**
	 * Mirror block-checkout values onto the same `_billing_rsbcf_*` / `_shipping_rsbcf_*` order meta
	 * the classic path writes.
	 *
	 * Without this an order placed through the block checkout stores its documents under the
	 * blocks API's own key shape, and every downstream consumer -- the admin panel, the emails,
	 * the formatted addresses, an NF-e integration reading the order -- would have to know which
	 * checkout the customer happened to use. One storage shape, whichever checkout wrote it.
	 *
	 * Only fields that belong to the chosen person type are copied: a customer who typed a CPF
	 * and then switched to Pessoa Jurídica leaves that CPF in the form state, and it must not
	 * end up on a company's order.
	 */
	public static function mirror_to_classic_meta( $order, $request ) {
		$person_type = 'person';
		if ( RSBCF_Fields::needs_person_type() ) {
			$person_type = 'company' === $order->get_meta( self::OTHER_PREFIX . self::PERSON_TYPE_ID ) ? 'company' : 'person';
			$order->update_meta_data( '_' . RSBCF_Fields::PERSON_TYPE_KEY, $person_type );
		}

		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			if ( ! RSBCF_Fields::is_enabled( $key ) ) {
				continue;
			}
			$id = self::field_id( $key );

			if ( in_array( $key, self::ADDRESS_KEYS, true ) ) {
				foreach ( array( 'billing', 'shipping' ) as $group ) {
					$value = $order->get_meta( '_wc_' . $group . '/' . $id );
					if ( '' !== (string) $value ) {
						$order->update_meta_data( '_' . str_replace( 'billing_', $group . '_', $key ), RSBCF_Fields::normalize( $key, $value ) );
					}
				}
				continue;
			}

			if ( 'both' !== $definition['only'] && RSBCF_Fields::needs_person_type() && $definition['only'] !== $person_type ) {
				$order->delete_meta_data( '_' . $key );
				// Before WooCommerce 9.9 both documents are on screen, and the one for the other
				// person type would otherwise stay in WooCommerce's own copy and on the receipt.
				$order->delete_meta_data( self::OTHER_PREFIX . $id );
				continue;
			}
			$value = $order->get_meta( self::OTHER_PREFIX . $id );
			if ( '' !== (string) $value ) {
				$order->update_meta_data( '_' . $key, RSBCF_Fields::normalize( $key, $value ) );
			}
		}
	}
}
