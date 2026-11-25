<?php
/**
 * Persisting the data and putting it where it is actually needed.
 *
 * This file exists because of the single most damning review the incumbent has:
 *
 *   "Plugin não envia para email ou nota fiscal os dados de CPF, RG ou CNPJ
 *    só adianta na hora da compra, depois os dados são inúteis."
 *
 * Collecting a CPF and then not showing it on the order, the emails, or the
 * invoice makes the whole plugin pointless -- the store asked the customer for
 * a document and then could not use it. Every surface a Brazilian store needs
 * the document on is wired here:
 *
 *   - the admin order screen (and it is searchable)
 *   - the customer and admin emails
 *   - the order-details table the customer sees after paying
 *   - the REST/API order payload, so invoice (NF-e) integrations can read it
 *
 * HPOS: values are written with the CRUD API on the order object rather than
 * update_post_meta, so they work identically on posts storage and on High
 * Performance Order Storage. The incumbent predates HPOS entirely.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Orders {

	public static function init() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_display' ), 10, 1 );
		add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'email_fields' ), 10, 3 );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'order_totals' ), 10, 3 );
		add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'formatted_billing_address' ), 10, 2 );
		add_filter( 'woocommerce_order_formatted_shipping_address', array( __CLASS__, 'formatted_shipping_address' ), 10, 2 );
		add_filter( 'woocommerce_shop_order_search_fields', array( __CLASS__, 'search_fields' ) );
		// With HPOS (WooCommerce's default storage) the order search reads its meta keys from this
		// filter instead; the one above only reaches the legacy posts storage.
		add_filter( 'woocommerce_order_table_search_query_meta_keys', array( __CLASS__, 'search_fields' ) );
		add_action( 'woocommerce_api_order_response', array( __CLASS__, 'api_response' ), 10, 2 );
	}

	/**
	 * Every key this plugin owns, billing and shipping.
	 */
	private static function keys() {
		$keys = array_keys( RSBCF_Fields::definitions() );
		$keys[] = RSBCF_Fields::PERSON_TYPE_KEY;
		$keys[] = 'shipping_rsbcf_number';
		$keys[] = 'shipping_rsbcf_neighborhood';
		return $keys;
	}

	public static function save( $order, $data ) {
		// $data is WooCommerce's own reading of the checkout form, not $_POST. When the buyer
		// does not tick "ship to a different address", WooCommerce copies the billing values into
		// the shipping fields there; $_POST still carries the hidden shipping form, which it
		// pre-fills from the customer's last shipping address. Reading $_POST put that old
		// address's número and bairro on an order shipping to this billing street.
		$data = is_array( $data ) ? $data : array();

		// The person/company selector only hides fields in the browser; a CPF typed before
		// switching to Pessoa Jurídica is still posted, and must not land on a company's order
		// (an NF-e integration would read it). WooCommerce core has already copied every posted
		// billing_* field onto the order by the time this runs, so it must be removed, not skipped.
		$person_type = 'person';
		if ( RSBCF_Fields::needs_person_type() && isset( $data[ RSBCF_Fields::PERSON_TYPE_KEY ] ) ) {
			$person_type = 'company' === $data[ RSBCF_Fields::PERSON_TYPE_KEY ] ? 'company' : 'person';
		}
		$definitions = RSBCF_Fields::definitions();

		foreach ( self::keys() as $key ) {
			if ( isset( $definitions[ $key ] ) && ! RSBCF_Fields::applies_to( $key, $person_type ) ) {
				$order->delete_meta_data( '_' . $key );
				continue;
			}
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( (string) $data[ $key ] );
			if ( '' === $raw ) {
				continue;
			}
			// CRUD, not update_post_meta: this is what makes it HPOS-safe.
			$order->update_meta_data( '_' . $key, RSBCF_Fields::normalize( $key, $raw ) );
		}
	}

	/**
	 * Values present on an order, formatted for reading.
	 */
	public static function values( $order ) {
		$out = array();
		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			$value = $order->get_meta( '_' . $key );
			if ( '' === $value || null === $value ) {
				continue;
			}
			$out[ $key ] = array(
				'label' => $definition['label'],
				'value' => RSBCF_Fields::display( $key, $value ),
				'raw'   => $value,
			);
		}

		/**
		 * Remap or extend the fiscal values attached to an order.
		 *
		 * NF-e integrations each expect their own field names. Rather than
		 * making every one of those a support thread, this filter lets a store
		 * (or an integration) rename or add keys in one place.
		 *
		 * @param array    $out   Field key => [label, value, raw].
		 * @param WC_Order $order The order.
		 */
		return apply_filters( 'rsbcf_order_values', $out, $order );
	}

	/**
	 * The buyer's documents, without Número and Bairro: those are already merged into the
	 * formatted billing and shipping addresses, and listing them again beside the address is noise.
	 */
	private static function document_values( $order ) {
		return array_diff_key( self::values( $order ), array_flip( array( 'billing_rsbcf_number', 'billing_rsbcf_neighborhood' ) ) );
	}

	/**
	 * Orders placed through the block checkout already list these fields, formatted, wherever
	 * WooCommerce shows additional checkout fields (thank-you page, account, emails).
	 */
	private static function woocommerce_lists_fields( $order ) {
		return 'store-api' === $order->get_created_via();
	}

	public static function admin_display( $order ) {
		$values = self::document_values( $order );
		if ( ! $values ) {
			return;
		}

		echo '<div class="cb-order-fields"><h4>' . esc_html__( 'Dados fiscais (Brasil)', 'rebel-studios-brazilian-checkout-fields' ) . '</h4>';
		foreach ( $values as $field ) {
			printf(
				'<p><strong>%s:</strong> %s</p>',
				esc_html( $field['label'] ),
				esc_html( $field['value'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Into both customer and admin emails.
	 */
	public static function email_fields( $fields, $sent_to_admin, $order ) {
		if ( self::woocommerce_lists_fields( $order ) ) {
			return $fields;
		}
		foreach ( self::document_values( $order ) as $key => $field ) {
			$fields[ $key ] = array(
				'label' => $field['label'],
				'value' => $field['value'],
			);
		}
		return $fields;
	}

	/**
	 * Into the order-details table the customer sees on the thank-you page and
	 * in their account.
	 */
	public static function order_totals( $totals, $order, $tax_display ) {
		// In an email the documents go in the order-meta section (email_fields); the totals table
		// is rendered there too, and adding them to both printed everything twice.
		if ( self::woocommerce_lists_fields( $order ) || doing_action( 'woocommerce_email_order_details' ) ) {
			return $totals;
		}
		$values = self::document_values( $order );
		if ( ! $values ) {
			return $totals;
		}

		$insert = array();
		foreach ( $values as $key => $field ) {
			$insert[ 'rsbcf_' . $key ] = array(
				'label' => $field['label'] . ':',
				'value' => $field['value'],
			);
		}

		// Before payment_method so the fiscal data reads with the order, not
		// after the total.
		$position = array_search( 'payment_method', array_keys( $totals ), true );
		if ( false === $position ) {
			return $totals + $insert;
		}

		return array_slice( $totals, 0, $position, true )
			+ $insert
			+ array_slice( $totals, $position, null, true );
	}

	/**
	 * Number and neighbourhood belong in the printed address, or the parcel
	 * cannot be delivered -- a Brazilian address without a número is incomplete.
	 */
	public static function formatted_billing_address( $address, $order ) {
		return self::formatted_address( $address, $order, 'billing' );
	}

	/**
	 * The shipping address is the one a carrier reads. Without this the label, the packing slip
	 * and the "Shipping address" in every email said "Rua Augusta" with no house number and no
	 * neighbourhood -- a Brazilian address a courier cannot deliver to.
	 */
	public static function formatted_shipping_address( $address, $order ) {
		return self::formatted_address( $address, $order, 'shipping' );
	}

	private static function formatted_address( $address, $order, $group ) {
		if ( ! is_array( $address ) ) {
			return $address;
		}
		$number       = $order->get_meta( '_' . $group . '_rsbcf_number' );
		$neighborhood = $order->get_meta( '_' . $group . '_rsbcf_neighborhood' );

		if ( $number && isset( $address['address_1'] ) ) {
			$address['address_1'] .= ', ' . $number;
		}
		if ( $neighborhood ) {
			$address['address_2'] = trim( $neighborhood . ' ' . ( isset( $address['address_2'] ) ? $address['address_2'] : '' ) );
		}

		return $address;
	}

	/**
	 * Make orders findable by document in the admin search box.
	 */
	public static function search_fields( $fields ) {
		$fields[] = '_billing_rsbcf_cpf';
		$fields[] = '_billing_rsbcf_cnpj';
		return $fields;
	}

	/**
	 * Expose to the legacy API payload so NF-e integrations can read the
	 * document without knowing this plugin's internals.
	 */
	public static function api_response( $data, $order ) {
		foreach ( self::values( $order ) as $key => $field ) {
			$data['rsbcf'][ $key ] = $field['raw'];
		}
		return $data;
	}
}
