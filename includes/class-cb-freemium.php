<?php
/**
 * Free / Pro gate.
 *
 * wp.org rule: this free plugin ships NO paid code. The Pro tier is a separate add-on sold
 * off-site; when active and licensed it answers the `rsbcf_is_pro` filter true. `is_pro()` is
 * therefore false for the free plugin alone, and nothing here gates existing behaviour -- the free
 * plugin does exactly what it always did.
 *
 * WHY THIS EXISTS AT ALL. Until 2026-11-16 this plugin had no notion of a Pro tier: no upgrade
 * link, no mention of one anywhere in the code. 197 people had installed it and none of them had
 * any way to learn that Inscricao Estadual validation and the fiscal CSV exist. The plugin
 * collects an IE and never checks it; the add-on is the half that checks it. Saying so once, where
 * the user is configuring that exact field, is the whole point.
 *
 * @package RebelStudios\CheckoutBrasil
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Freemium {

	const IS_PRO_FILTER = 'rsbcf_is_pro';
	const UPGRADE_URL   = 'https://rebelstudiossoftware.com/checkout-brasil-pro.html';
	const PRODUCT       = 'rebel-studios-brazilian-checkout-fields';

	public static function is_pro() {
		return (bool) apply_filters( self::IS_PRO_FILTER, false );
	}

	/** Upgrade URL with campaign tagging, so a sale can be traced back to the screen that caused it. */
	public static function upgrade_url( $utm_content = 'generic' ) {
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => 'admin',
				'utm_campaign' => self::PRODUCT,
				'utm_content'  => sanitize_key( $utm_content ),
			),
			self::UPGRADE_URL
		);
	}

	/** A small inline "Pro" pill, for a gated control. */
	public static function badge( $utm_content = 'badge' ) {
		if ( self::is_pro() ) {
			return '';
		}
		return sprintf(
			' <a class="rsbcf-pro-pill" target="_blank" rel="noopener" href="%s" style="%s">%s</a>',
			esc_url( self::upgrade_url( $utm_content ) ),
			'display:inline-block;margin-left:.4em;padding:1px 7px;border-radius:999px;'
				. 'background:#2271b1;color:#fff;font-size:11px;line-height:1.7;text-decoration:none;vertical-align:middle',
			esc_html__( 'Pro', 'rebel-studios-brazilian-checkout-fields' )
		);
	}

	/**
	 * One honest line, shown where the limit is actually felt.
	 *
	 * Deliberately not a dashboard-wide banner or a dismissible nag: wordpress.org asks that
	 * upsells stay out of the way, and a notice someone has to close every week earns a one-star
	 * review rather than a sale.
	 */
	public static function ie_note() {
		if ( self::is_pro() ) {
			return '';
		}
		return sprintf(
			'<p class="description" style="margin-top:.6em">%s <a target="_blank" rel="noopener" href="%s">%s</a></p>',
			esc_html__( 'A Inscrição Estadual é guardada, mas não conferida: cada estado usa uma regra diferente, e uma IE inválida só é recusada na hora de emitir a NF-e.', 'rebel-studios-brazilian-checkout-fields' ),
			esc_url( self::upgrade_url( 'ie_row' ) ),
			esc_html__( 'Validar nas 27 UFs com o Checkout Brasil Pro', 'rebel-studios-brazilian-checkout-fields' )
		);
	}
}
