<?php
/**
 * Personal-data requests: the documents this plugin stores go out with WordPress's
 * "Export Personal Data" and are removed by "Erase Personal Data".
 *
 * WooCommerce's exporters and erasers know only WooCommerce's own fields. Without these hooks
 * an export left out the CPF, RG and birth date, and an erasure (with WooCommerce set to remove
 * order data on request) left them on the order and in the customer's saved profile. A CPF is
 * personal data under the LGPD; a store that honours a deletion request has to be able to
 * delete it.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Privacy {

	/** Unknown to wp_privacy_anonymize_data(), so it anonymises to '' and WooCommerce deletes the meta. */
	const DELETE = 'rsbcf_delete';

	public static function init() {
		add_filter( 'woocommerce_privacy_export_order_personal_data_meta', array( __CLASS__, 'order_export_meta' ) );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta', array( __CLASS__, 'order_erase_meta' ) );
		add_filter( 'woocommerce_privacy_export_customer_personal_data', array( __CLASS__, 'customer_export' ), 10, 2 );
		add_filter( 'woocommerce_privacy_erase_personal_data_customer', array( __CLASS__, 'customer_erase' ), 10, 2 );
	}

	/**
	 * Meta key => label for everything this plugin stores, in the shape both checkouts write
	 * (_billing_rsbcf_cpf; on the customer, billing_rsbcf_cpf) and, when $with_blocks, the block
	 * checkout's own copies (_wc_other/rsbcf/cpf, _wc_billing/rsbcf/number, ...).
	 */
	private static function keys( $prefix, $with_blocks ) {
		$keys = array( $prefix . RSBCF_Fields::PERSON_TYPE_KEY => __( 'Tipo de cliente', 'rebel-studios-brazilian-checkout-fields' ) );
		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			$label                 = $definition['label'];
			$keys[ $prefix . $key ] = $label;
			$is_address            = in_array( $key, RSBCF_Blocks_Checkout::ADDRESS_KEYS, true );
			if ( $is_address ) {
				$keys[ $prefix . str_replace( 'billing_', 'shipping_', $key ) ] = $label;
			}
			if ( ! $with_blocks ) {
				continue;
			}
			$id = RSBCF_Blocks_Checkout::field_id( $key );
			if ( $is_address ) {
				$keys[ '_wc_billing/' . $id ]  = $label;
				$keys[ '_wc_shipping/' . $id ] = $label;
			} else {
				$keys[ RSBCF_Blocks_Checkout::OTHER_PREFIX . $id ] = $label;
			}
		}
		if ( $with_blocks ) {
			$keys[ RSBCF_Blocks_Checkout::OTHER_PREFIX . RSBCF_Blocks_Checkout::PERSON_TYPE_ID ] = __( 'Tipo de cliente', 'rebel-studios-brazilian-checkout-fields' );
		}
		return $keys;
	}

	/**
	 * Export: the classic-shape keys only. Block-checkout orders carry the same values there
	 * too (RSBCF_Blocks_Checkout::mirror_to_classic_meta), so listing both would repeat them.
	 */
	public static function order_export_meta( $meta ) {
		return array_merge( (array) $meta, self::keys( '_', false ) );
	}

	/** Erase: every copy, whichever checkout wrote it. */
	public static function order_erase_meta( $meta ) {
		return array_merge( (array) $meta, array_fill_keys( array_keys( self::keys( '_', true ) ), self::DELETE ) );
	}

	public static function customer_export( $personal_data, $customer ) {
		$seen = array();
		foreach ( self::keys( '', true ) as $key => $label ) {
			$value = $customer->get_meta( $key );
			if ( '' === (string) $value || ! is_scalar( $value ) || isset( $seen[ $label . '|' . $value ] ) ) {
				continue;
			}
			$seen[ $label . '|' . $value ] = true;
			$personal_data[]               = array(
				'name'  => $label,
				'value' => $value,
			);
		}
		return $personal_data;
	}

	public static function customer_erase( $response, $customer ) {
		$removed = false;
		foreach ( array_keys( self::keys( '', true ) ) as $key ) {
			if ( '' !== (string) $customer->get_meta( $key ) ) {
				$customer->delete_meta_data( $key );
				$removed = true;
			}
		}
		if ( $removed ) {
			$customer->save();
			$response['messages'][]    = __( 'Documentos brasileiros (CPF, CNPJ, RG e afins) removidos do perfil do cliente.', 'rebel-studios-brazilian-checkout-fields' );
			$response['items_removed'] = true;
		}
		return $response;
	}
}
