<?php
/**
 * Self-diagnostics — the support-load reducer.
 *
 * Almost every support thread on a checkout plugin opens as "não funciona" with
 * no version, no theme, no gateway and no error. That costs four round-trips
 * before anyone knows what is wrong, and those round-trips are the entire
 * support burden. The plugin this replaces has reviews that are literally
 * "Não tem suporte, ninguém me responde" — the vendor stopped answering because
 * answering was expensive.
 *
 * So this panel answers the four questions first, on the store's own screen:
 *
 *   Which checkout is this store actually using?   (classic vs blocks — the
 *     single most common cause of "the fields disappeared")
 *   Is HPOS on, and does the plugin see it?
 *   Can this server reach the lookup providers right now?
 *   Is another plugin claiming the same field names?
 *
 * Plus a copy-paste report, so a thread that would have taken six messages
 * arrives complete in the first one. Every minute spent here is repaid many
 * times over in threads that never get opened.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Diagnostics {

	const OK   = 'ok';
	const WARN = 'warn';
	const INFO = 'info';

	/**
	 * Field names the older Brazilian plugins and several form editors claim.
	 * A store running two of them gets duplicated or fighting fields, and the
	 * symptom ("o CPF aparece duas vezes") reads like a bug in whichever plugin
	 * the user blames first.
	 */
	private static function conflicting_plugins() {
		$suspects = array(
			'woocommerce-extra-checkout-fields-for-brazil/woocommerce-extra-checkout-fields-for-brazil.php'
				=> 'Brazilian Market on WooCommerce',
			'woo-checkout-field-editor-pro/checkout-form-designer.php' => 'Checkout Field Editor (Pro)',
			'flexible-checkout-fields/flexible-checkout-fields.php'    => 'Flexible Checkout Fields',
			'checkout-field-editor/checkout-field-editor.php'          => 'Checkout Field Editor',
			// This plugin's own earlier build (sold on Gumroad before the rename): different field
			// names, so with both active every field appears twice.
			'checkout-brasil/checkout-brasil.php'                      => 'Checkout Brasil (versão anterior deste plugin)',
		);
		$found = array();
		foreach ( $suspects as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $label;
			}
		}
		return $found;
	}

	/**
	 * Which checkout the store is actually rendering.
	 *
	 * This is the highest-value line in the whole panel: a store that switched
	 * to the block checkout with the old plugin installed silently stopped
	 * collecting CPF, and nobody could tell them why.
	 */
	private static function checkout_kind() {
		$page_id = (int) get_option( 'woocommerce_checkout_page_id' );
		if ( ! $page_id ) {
			return array( self::WARN, __( 'Página de checkout não configurada no WooCommerce.', 'rebel-studios-brazilian-checkout-fields' ) );
		}
		$page = get_post( $page_id );
		if ( ! $page ) {
			return array( self::WARN, __( 'A página de checkout configurada não existe.', 'rebel-studios-brazilian-checkout-fields' ) );
		}
		if ( has_block( 'woocommerce/checkout', $page ) ) {
			return array( self::OK, __( 'Checkout em blocos (padrão atual do WooCommerce).', 'rebel-studios-brazilian-checkout-fields' ) );
		}
		if ( has_shortcode( $page->post_content, 'woocommerce_checkout' ) ) {
			return array( self::OK, __( 'Checkout clássico (shortcode).', 'rebel-studios-brazilian-checkout-fields' ) );
		}
		return array( self::WARN, __( 'Não foi possível identificar o tipo de checkout nesta página.', 'rebel-studios-brazilian-checkout-fields' ) );
	}

	/**
	 * Live provider reachability. Cached briefly so opening the settings page
	 * repeatedly does not hammer ViaCEP.
	 */
	private static function provider_status( $which ) {
		$key = 'rsbcf_diag_' . $which;
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}

		$url = 'cep' === $which
			? 'https://viacep.com.br/ws/01310100/json/'
			: 'https://brasilapi.com.br/api/cnpj/v1/19131243000197';

		$response = wp_remote_get( $url, array( 'timeout' => 6, 'user-agent' => 'rsbcf/' . RSBCF_VERSION ) );

		if ( is_wp_error( $response ) ) {
			$out = array( self::WARN, sprintf(
				/* translators: %s: error message */
				__( 'Sem resposta (%s). O cliente digita o endereço normalmente — a compra não é bloqueada.', 'rebel-studios-brazilian-checkout-fields' ),
				$response->get_error_message() ) );
		} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$out = array( self::WARN, sprintf(
				/* translators: %d: HTTP status code */
				__( 'Respondeu HTTP %d. A compra não é bloqueada.', 'rebel-studios-brazilian-checkout-fields' ),
				wp_remote_retrieve_response_code( $response ) ) );
		} else {
			$out = array( self::OK, __( 'Respondendo normalmente.', 'rebel-studios-brazilian-checkout-fields' ) );
		}

		set_transient( $key, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * The full report, as label => [status, detail].
	 */
	public static function report() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$rows = array();
		$rows[ __( 'Checkout Brasil', 'rebel-studios-brazilian-checkout-fields' ) ] = array( self::INFO, RSBCF_VERSION );
		$rows[ __( 'WordPress', 'rebel-studios-brazilian-checkout-fields' ) ]       = array( self::INFO, get_bloginfo( 'version' ) );
		$rows[ __( 'PHP', 'rebel-studios-brazilian-checkout-fields' ) ]             = array( self::INFO, PHP_VERSION );
		$rows[ __( 'WooCommerce', 'rebel-studios-brazilian-checkout-fields' ) ]     = array(
			defined( 'WC_VERSION' ) ? self::OK : self::WARN,
			defined( 'WC_VERSION' ) ? WC_VERSION : __( 'não detectado', 'rebel-studios-brazilian-checkout-fields' )
		);

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$rows[ __( 'HPOS (armazenamento de pedidos)', 'rebel-studios-brazilian-checkout-fields' ) ] = array(
			self::OK,
			$hpos ? __( 'Ativado — e este plugin é compatível.', 'rebel-studios-brazilian-checkout-fields' )
			      : __( 'Desativado (posts). Também suportado.', 'rebel-studios-brazilian-checkout-fields' )
		);

		$rows[ __( 'Tipo de checkout', 'rebel-studios-brazilian-checkout-fields' ) ] = self::checkout_kind();
		$rows[ __( 'Campos em blocos', 'rebel-studios-brazilian-checkout-fields' ) ] = array(
			function_exists( 'woocommerce_register_additional_checkout_field' ) ? self::OK : self::WARN,
			function_exists( 'woocommerce_register_additional_checkout_field' )
				? __( 'API disponível — os campos aparecem no checkout em blocos.', 'rebel-studios-brazilian-checkout-fields' )
				: __( 'WooCommerce antigo demais para o checkout em blocos; só o clássico funciona.', 'rebel-studios-brazilian-checkout-fields' )
		);

		$rows[ __( 'Consulta de CEP (ViaCEP)', 'rebel-studios-brazilian-checkout-fields' ) ]   = self::provider_status( 'cep' );
		$rows[ __( 'Consulta de CNPJ (BrasilAPI)', 'rebel-studios-brazilian-checkout-fields' ) ] = self::provider_status( 'cnpj' );

		$conflicts = self::conflicting_plugins();
		$rows[ __( 'Plugins que usam os mesmos campos', 'rebel-studios-brazilian-checkout-fields' ) ] = $conflicts
			? array( self::WARN, sprintf(
				/* translators: %s: comma-separated plugin names */
				__( 'Ativo(s): %s. Usar dois plugins de campos ao mesmo tempo geralmente duplica os campos no checkout.', 'rebel-studios-brazilian-checkout-fields' ),
				implode( ', ', $conflicts ) ) )
			: array( self::OK, __( 'Nenhum detectado.', 'rebel-studios-brazilian-checkout-fields' ) );

		// The mode is an internal token ('optional'/'required'); printing it raw
		// left English words inside an otherwise Portuguese panel.
		$mode_label = array(
			RSBCF_Fields::OPTIONAL => __( 'opcional', 'rebel-studios-brazilian-checkout-fields' ),
			RSBCF_Fields::REQUIRED => __( 'obrigatório', 'rebel-studios-brazilian-checkout-fields' ),
		);
		$active = array();
		foreach ( RSBCF_Fields::definitions() as $key => $definition ) {
			if ( RSBCF_Fields::is_enabled( $key ) ) {
				$mode      = RSBCF_Fields::mode( $key );
				$active[] = $definition['label'] . ' (' . ( $mode_label[ $mode ] ?? $mode ) . ')';
			}
		}
		$rows[ __( 'Campos ativos', 'rebel-studios-brazilian-checkout-fields' ) ] = array(
			$active ? self::INFO : self::WARN,
			$active ? implode( ', ', $active ) : __( 'Nenhum campo ativo — o plugin não fará nada.', 'rebel-studios-brazilian-checkout-fields' )
		);

		$theme = wp_get_theme();
		$rows[ __( 'Tema', 'rebel-studios-brazilian-checkout-fields' ) ] = array( self::INFO, $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );

		return $rows;
	}

	/**
	 * Plain-text version for pasting into a support thread.
	 */
	public static function as_text() {
		$lines = array( '--- Checkout Brasil: diagnóstico ---' );
		foreach ( self::report() as $label => $row ) {
			$lines[] = sprintf( '%s: %s', $label, wp_strip_all_tags( $row[1] ) );
		}
		$lines[] = '--- fim ---';
		return implode( "\n", $lines );
	}

	public static function render() {
		$rows = self::report();
		$icon = array(
			self::OK   => '<span style="color:#1a7f37">●</span>',
			self::WARN => '<span style="color:#bb8800">▲</span>',
			self::INFO => '<span style="color:#666">●</span>',
		);
		?>
		<h2><?php esc_html_e( 'Diagnóstico', 'rebel-studios-brazilian-checkout-fields' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Antes de abrir um chamado, copie o relatório abaixo e cole na sua mensagem. Na maioria das vezes a resposta já está aqui.', 'rebel-studios-brazilian-checkout-fields' ); ?>
		</p>
		<table class="widefat striped" style="max-width:820px">
			<tbody>
			<?php foreach ( $rows as $label => $row ) : ?>
				<tr>
					<td style="width:34%"><strong><?php echo esc_html( $label ); ?></strong></td>
					<td><?php echo wp_kses_post( $icon[ $row[0] ] ); ?> <?php echo esc_html( $row[1] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin-top:1em">
			<textarea id="cb-diag-text" readonly rows="8"
				style="width:100%;max-width:820px;font-family:monospace;font-size:12px"><?php
				echo esc_textarea( self::as_text() );
			?></textarea>
		</p>
		<p>
			<button type="button" class="button" onclick="
				var t=document.getElementById('cb-diag-text');
				t.select(); document.execCommand('copy');
				this.textContent='<?php echo esc_js( __( 'Copiado', 'rebel-studios-brazilian-checkout-fields' ) ); ?>';
			"><?php esc_html_e( 'Copiar relatório', 'rebel-studios-brazilian-checkout-fields' ); ?></button>
		</p>
		<?php
	}
}
