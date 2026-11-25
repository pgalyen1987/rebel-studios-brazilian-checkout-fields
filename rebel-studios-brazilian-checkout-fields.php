<?php
/**
 * Plugin Name:       Rebel Studios Brazilian Checkout Fields for WooCommerce
 * Plugin URI:        https://rebelstudiossoftware.com/checkout-brasil.html
 * Description:       Campos brasileiros no checkout do WooCommerce: CPF, CNPJ, Razão Social, RG, IE, número e bairro. Validação real, preenchimento automático por CEP e CNPJ, e os dados no pedido, nos e-mails e na nota fiscal. Cada campo pode ser desativado.
 * Version:           1.0.6
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Rebel Studios Software
 * Author URI:        https://rebelstudiossoftware.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rebel-studios-brazilian-checkout-fields
 * Domain Path:       /languages
 * WC requires at least: 8.2
 * WC tested up to:   11.1
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

define( 'RSBCF_VERSION', '1.0.6' );
define( 'RSBCF_FILE', __FILE__ );
define( 'RSBCF_PATH', plugin_dir_path( __FILE__ ) );
define( 'RSBCF_URL', plugin_dir_url( __FILE__ ) );
define( 'RSBCF_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with High Performance Order Storage and the block
 * checkout before WooCommerce builds its incompatibility notice.
 *
 * The plugin this replaces predates both. On a current WooCommerce install it
 * shows up in the admin as incompatible, which is the visible symptom of the
 * abandonment and the reason stores go looking for an alternative.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RSBCF_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', RSBCF_FILE, true );
	}
);

/**
 * WooCommerce is a hard dependency. Fail loudly in the admin and do nothing
 * else -- never fatal a site because a dependency is missing.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Checkout Brasil precisa do WooCommerce ativo.', 'rebel-studios-brazilian-checkout-fields' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once RSBCF_PATH . 'includes/class-cb-freemium.php';
		require_once RSBCF_PATH . 'includes/class-cb-validator.php';
		require_once RSBCF_PATH . 'includes/class-cb-fields.php';
		require_once RSBCF_PATH . 'includes/class-cb-lookup.php';
		require_once RSBCF_PATH . 'includes/class-cb-classic-checkout.php';
		require_once RSBCF_PATH . 'includes/class-cb-blocks-checkout.php';
		require_once RSBCF_PATH . 'includes/class-cb-orders.php';
		require_once RSBCF_PATH . 'includes/class-cb-settings.php';
		require_once RSBCF_PATH . 'includes/class-cb-diagnostics.php';
		require_once RSBCF_PATH . 'includes/class-cb-privacy.php';

		RSBCF_Lookup::init();
		RSBCF_Classic_Checkout::init();
		RSBCF_Blocks_Checkout::init();
		RSBCF_Orders::init();
		RSBCF_Settings::init();
		RSBCF_Privacy::init();

		load_plugin_textdomain( 'rebel-studios-brazilian-checkout-fields', false, dirname( RSBCF_BASENAME ) . '/languages' );

		add_action( 'wp_enqueue_scripts', 'rsbcf_enqueue_checkout_assets' );
	}
);

/**
 * Front-end assets, checkout only. Nothing this plugin ships should load on a
 * page that has no checkout on it.
 */
function rsbcf_enqueue_checkout_assets() {
	if ( ! function_exists( 'is_checkout' ) ) {
		return;
	}
	if ( ! is_checkout() && ! is_account_page() ) {
		return;
	}

	wp_enqueue_script(
		'rebel-studios-brazilian-checkout-fields',
		RSBCF_URL . 'assets/js/checkout.js',
		array( 'jquery' ),
		RSBCF_VERSION,
		true
	);

	wp_localize_script(
		'rebel-studios-brazilian-checkout-fields',
		'CheckoutBrasil',
		array(
			'restUrl'      => esc_url_raw( rest_url( RSBCF_Lookup::NAMESPACE ) ),
			'autofillCep'  => 'yes' === RSBCF_Settings::option( 'autofill_cep' ),
			'autofillCnpj' => 'yes' === RSBCF_Settings::option( 'autofill_cnpj' ),
			'personType'   => RSBCF_Fields::needs_person_type(),
			'i18n'         => array(
				'invalidCpf'  => __( 'CPF inválido.', 'rebel-studios-brazilian-checkout-fields' ),
				'invalidCnpj' => __( 'CNPJ inválido.', 'rebel-studios-brazilian-checkout-fields' ),
				'searching'   => __( 'Buscando…', 'rebel-studios-brazilian-checkout-fields' ),
			),
		)
	);

	wp_enqueue_style(
		'rebel-studios-brazilian-checkout-fields',
		RSBCF_URL . 'assets/css/checkout.css',
		array(),
		RSBCF_VERSION
	);
}
