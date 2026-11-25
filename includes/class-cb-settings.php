<?php
/**
 * Settings: one screen, one control per field, three states.
 *
 * The incumbent has no way to turn a field off, and its reviewers tried
 * everything -- another plugin, then raw PHP -- before giving up and writing a
 * one-star review. So the settings model is deliberately blunt: every field is
 * Desativado / Opcional / Obrigatório, nothing else, and the store owner never
 * has to touch code to change their mind.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Settings {

	const OPTION = 'rsbcf_fields';
	const SLUG   = 'rebel-studios-brazilian-checkout-fields';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . RSBCF_BASENAME, array( __CLASS__, 'action_links' ) );
		// The screen is a WooCommerce submenu open to manage_woocommerce, but options.php asks for
		// manage_options unless told otherwise, so a Shop Manager could open it and not save it.
		add_filter( 'option_page_capability_rsbcf', array( __CLASS__, 'capability' ) );
	}

	public static function capability() {
		return 'manage_woocommerce';
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Checkout Brasil', 'rebel-studios-brazilian-checkout-fields' ),
			__( 'Checkout Brasil', 'rebel-studios-brazilian-checkout-fields' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'rsbcf',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize( $input ) {
		$allowed = array( RSBCF_Fields::OFF, RSBCF_Fields::OPTIONAL, RSBCF_Fields::REQUIRED );
		$clean   = array();

		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			$value          = isset( $input[ $key ] ) ? $input[ $key ] : $definition['default'];
			$clean[ $key ]  = in_array( $value, $allowed, true ) ? $value : $definition['default'];
		}

		$clean['autofill_cep']  = ! empty( $input['autofill_cep'] ) ? 'yes' : 'no';
		$clean['autofill_cnpj'] = ! empty( $input['autofill_cnpj'] ) ? 'yes' : 'no';

		return $clean;
	}

	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configurações', 'rebel-studios-brazilian-checkout-fields' ) . '</a>' );
		return $links;
	}

	public static function option( $key, $default = 'yes' ) {
		$settings = get_option( self::OPTION, array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = get_option( self::OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Checkout Brasil', 'rebel-studios-brazilian-checkout-fields' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Cada campo pode ser desativado, opcional ou obrigatório. Nada é forçado.', 'rebel-studios-brazilian-checkout-fields' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'rsbcf' ); ?>

				<table class="widefat striped" style="max-width:820px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Campo', 'rebel-studios-brazilian-checkout-fields' ); ?></th>
							<th><?php esc_html_e( 'Aplica-se a', 'rebel-studios-brazilian-checkout-fields' ); ?></th>
							<th><?php esc_html_e( 'Comportamento', 'rebel-studios-brazilian-checkout-fields' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( RSBCF_Fields::definitions() as $key => $definition ) : ?>
						<?php $current = isset( $settings[ $key ] ) ? $settings[ $key ] : $definition['default']; ?>
						<tr>
							<td><strong><?php echo esc_html( $definition['label'] ); ?></strong><?php echo 'billing_rsbcf_ie' === $key ? wp_kses_post( RSBCF_Freemium::badge( 'ie_field' ) ) : ''; ?></td>
							<td>
								<?php
								$applies = array(
									'person'  => __( 'Pessoa Física', 'rebel-studios-brazilian-checkout-fields' ),
									'company' => __( 'Pessoa Jurídica', 'rebel-studios-brazilian-checkout-fields' ),
									'both'    => __( 'Ambos', 'rebel-studios-brazilian-checkout-fields' ),
								);
								echo esc_html( $applies[ $definition['only'] ] );
								?>
							</td>
							<td>
								<?php foreach ( array(
									RSBCF_Fields::OFF      => __( 'Desativado', 'rebel-studios-brazilian-checkout-fields' ),
									RSBCF_Fields::OPTIONAL => __( 'Opcional', 'rebel-studios-brazilian-checkout-fields' ),
									RSBCF_Fields::REQUIRED => __( 'Obrigatório', 'rebel-studios-brazilian-checkout-fields' ),
								) as $value => $label ) : ?>
									<label style="margin-right:1.2em">
										<input type="radio"
											name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>"
											value="<?php echo esc_attr( $value ); ?>"
											<?php checked( $current, $value ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php echo wp_kses_post( RSBCF_Freemium::ie_note() ); ?>

				<h2><?php esc_html_e( 'Preenchimento automático', 'rebel-studios-brazilian-checkout-fields' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Endereço pelo CEP', 'rebel-studios-brazilian-checkout-fields' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[autofill_cep]" value="yes"
									<?php checked( self::option( 'autofill_cep' ), 'yes' ); ?> />
								<?php esc_html_e( 'Preencher rua, bairro, cidade e estado a partir do CEP (ViaCEP)', 'rebel-studios-brazilian-checkout-fields' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Empresa pelo CNPJ', 'rebel-studios-brazilian-checkout-fields' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[autofill_cnpj]" value="yes"
									<?php checked( self::option( 'autofill_cnpj' ), 'yes' ); ?> />
								<?php esc_html_e( 'Preencher Razão Social e endereço a partir do CNPJ (BrasilAPI)', 'rebel-studios-brazilian-checkout-fields' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'As consultas são feitas pelo seu servidor, não pelo navegador do cliente, e ficam em cache. Se o serviço estiver fora do ar, o cliente digita o endereço normalmente — a compra nunca é bloqueada.', 'rebel-studios-brazilian-checkout-fields' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr style="margin:2.5em 0">
			<?php RSBCF_Diagnostics::render(); ?>
		</div>
		<?php
	}
}
