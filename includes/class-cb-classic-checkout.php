<?php
/**
 * Classic (shortcode) checkout integration.
 *
 * Two failures from the incumbent's reviews shape this file:
 *
 *   "Formulários com campos desalinhados e fora de ordem" -- fields are given
 *   explicit priorities and half-row classes so they land in a predictable
 *   order instead of wherever the theme happens to drop them.
 *
 *   "pluggin não é compativel com pagseguro e checkout do woocommerce" --
 *   every field this plugin adds is namespaced `billing_rsbcf_*`, so it cannot
 *   collide with a gateway's or another plugin's field names. The incumbent
 *   used bare `billing_cpf`, which is the name three other plugins also use.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Classic_Checkout {

	/** Field argument: draw the required marker although WooCommerce does not enforce the field. */
	const LABEL_REQUIRED = 'rsbcf_label_required';

	public static function init() {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_fields' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'account_fields' ), 20 );
		add_filter( 'woocommerce_form_field_args', array( __CLASS__, 'label_args' ), 10, 2 );
	}

	/**
	 * Person-or-company selector, shown only when the store has both kinds
	 * of field switched on.
	 */
	private static function person_type_field() {
		return array(
			'type'     => 'select',
			'label'    => __( 'Tipo de cliente', 'rebel-studios-brazilian-checkout-fields' ),
			'required' => true,
			'class'    => array( 'form-row-wide', 'cb-person-type' ),
			'options'  => array(
				'person'  => __( 'Pessoa Física', 'rebel-studios-brazilian-checkout-fields' ),
				'company' => __( 'Pessoa Jurídica', 'rebel-studios-brazilian-checkout-fields' ),
			),
			'default'  => 'person',
			'priority' => 20,
		);
	}

	/**
	 * Whether WooCommerce's own required flag carries this field: required, and shown to every
	 * buyer (número and bairro, or any document when the store does not ask person or company).
	 * WooCommerce then prints its own "is a required field" notice for it.
	 */
	private static function core_required( $key ) {
		return RSBCF_Fields::is_required( $key ) && RSBCF_Fields::applies_to( $key, 'person' ) && RSBCF_Fields::applies_to( $key, 'company' );
	}

	public static function add_fields( $fields ) {
		if ( RSBCF_Fields::needs_person_type() ) {
			$fields['billing'][ RSBCF_Fields::PERSON_TYPE_KEY ] = self::person_type_field();
		}

		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			if ( ! RSBCF_Fields::is_enabled( $key ) ) {
				continue;
			}

			$classes = $definition['class'];
			// Person-type-specific fields are toggled by the script; marking them
			// here keeps the markup declarative rather than hard-coding selectors.
			if ( 'both' !== $definition['only'] ) {
				$classes[] = 'cb-only-' . $definition['only'];
			}

			$fields['billing'][ $key ] = array(
				'type'              => 'text',
				'label'             => $definition['label'],
				// WooCommerce enforces (and labels) required fields that every buyer gets.
				// A document that depends on the person-type selector is checked in
				// validate() instead, or a natural person would be asked for a CNPJ.
				'required'          => self::core_required( $key ),
				// ...but its label still says required: the script shows it only to the buyers
				// who must fill it in. See label_args().
				self::LABEL_REQUIRED => RSBCF_Fields::is_required( $key ) && ! self::core_required( $key ),
				'class'             => $classes,
				'priority'          => $definition['priority'],
				'custom_attributes' => array_filter(
					array(
						'data-cb-mask'     => $definition['mask'],
						'data-cb-validate' => $definition['validate'],
						'data-cb-field'    => $key,
					)
				),
			);
		}

		// Shipping needs the same street-level fields or the address is unusable
		// for a Brazilian carrier.
		foreach ( array( 'billing_rsbcf_number' => 'shipping_rsbcf_number', 'billing_rsbcf_neighborhood' => 'shipping_rsbcf_neighborhood' ) as $billing_key => $shipping_key ) {
			if ( ! RSBCF_Fields::is_enabled( $billing_key ) || ! isset( $fields['shipping'] ) ) {
				continue;
			}
			$definition                       = RSBCF_Fields::definitions()[ $billing_key ];
			$fields['shipping'][ $shipping_key ] = array(
				'type'              => 'text',
				'label'             => $definition['label'],
				'required'          => RSBCF_Fields::is_required( $billing_key ),
				'class'             => $definition['class'],
				'priority'          => $definition['priority'],
				'custom_attributes' => array( 'data-cb-field' => $shipping_key ),
			);
		}

		return $fields;
	}

	/**
	 * Label a document that is required for one person type as required.
	 *
	 * With the person/company selector on, a required CNPJ is enforced in validate() and not by
	 * WooCommerce (whose required flag cannot depend on the selector), so WooCommerce labelled it
	 * "CNPJ (optional)" while refusing every company that left it empty. The field is only shown
	 * to the person type it belongs to, so whenever a buyer can see it, it is required of them.
	 * This changes the drawn field only (marker, aria-required): what WooCommerce validates comes
	 * from the checkout fields, where the flag stays off.
	 *
	 * @param array  $args Field arguments woocommerce_form_field() is about to draw.
	 * @param string $key  Field key.
	 */
	public static function label_args( $args, $key ) {
		if ( is_array( $args ) && ! empty( $args[ self::LABEL_REQUIRED ] ) ) {
			$args['required'] = true;
		}
		return $args;
	}

	/**
	 * Server-side validation. The browser masks are a convenience; this is the
	 * check that actually decides, because the incumbent's reviewers describe
	 * orders completing with documents that were never validated at all.
	 */
	public static function validate( $data, $errors ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		$person_type = isset( $_POST[ RSBCF_Fields::PERSON_TYPE_KEY ] )
			? sanitize_text_field( wp_unslash( $_POST[ RSBCF_Fields::PERSON_TYPE_KEY ] ) )
			: 'person';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $person_type, array( 'person', 'company' ), true ) ) {
			$person_type = 'person';
		}

		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			if ( ! RSBCF_Fields::is_enabled( $key ) ) {
				continue;
			}
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === trim( $value ) && self::core_required( $key ) ) {
				// WooCommerce has already reported this one as a required field.
				continue;
			}
			$error = RSBCF_Fields::validation_error( $key, $value, $person_type );
			if ( $error ) {
				$errors->add( 'rsbcf_' . $key, $error );
			}
		}
	}

	/**
	 * The same fields on the account "billing address" screen, so a returning
	 * customer's stored document is reused instead of retyped.
	 */
	public static function account_fields( $fields ) {
		if ( is_checkout() ) {
			return $fields;
		}

		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			if ( ! RSBCF_Fields::is_enabled( $key ) ) {
				continue;
			}
			$fields[ $key ] = array(
				'label'             => $definition['label'],
				'required'          => false,
				'class'             => $definition['class'],
				'priority'          => $definition['priority'],
				'custom_attributes' => array_filter( array( 'data-cb-mask' => $definition['mask'] ) ),
			);
		}

		return $fields;
	}
}
