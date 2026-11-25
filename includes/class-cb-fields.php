<?php
/**
 * The field registry: what Brazilian checkout needs, and how each one behaves.
 *
 * Every field here is independently switchable between off / optional /
 * required. That is the architecture, not a convenience, and it comes straight
 * out of the incumbent's reviews -- the single most repeated complaint is that
 * CPF could not be turned off:
 *
 *   "O plugin torna o campo CPF 100% obrigatório, mesmo usando outro plugin
 *    para desativar não adianta, inclusive usando via código PHP também não
 *    é possível remover."
 *
 * A store selling digital goods to consumers does not need a CPF; a store
 * invoicing companies needs CNPJ and Razão Social and nothing else. Forcing one
 * shape on both is what makes a plugin uninstallable, so the default for every
 * field is OPTIONAL and the store decides.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Fields {

	const OFF      = 'off';
	const OPTIONAL = 'optional';
	const REQUIRED = 'required';

	/**
	 * Person-or-company switch. Stores that sell to both need to ask.
	 */
	const PERSON_TYPE_KEY = 'billing_rsbcf_person_type';

	/**
	 * Field definitions.
	 *
	 * `only` marks fields that belong to one person type: a Razão Social makes
	 * no sense on a natural person, and an RG makes none on a company. The
	 * checkout shows and validates them accordingly rather than asking every
	 * customer for everything.
	 */
	public static function definitions() {
		return array(
			'billing_rsbcf_cpf' => array(
				'label'    => __( 'CPF', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::OPTIONAL,
				'only'     => 'person',
				'mask'     => '000.000.000-00',
				'validate' => 'cpf',
				'priority' => 21,
				'class'    => array( 'form-row-first' ),
			),
			'billing_rsbcf_rg' => array(
				'label'    => __( 'RG', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::OFF,
				'only'     => 'person',
				'mask'     => '',
				'validate' => '',
				'priority' => 22,
				'class'    => array( 'form-row-last' ),
			),
			'billing_rsbcf_birthdate' => array(
				'label'    => __( 'Data de nascimento', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::OFF,
				'only'     => 'person',
				'mask'     => '00/00/0000',
				'validate' => 'date',
				'priority' => 23,
				'class'    => array( 'form-row-first' ),
			),
			'billing_rsbcf_cnpj' => array(
				'label'    => __( 'CNPJ', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::OPTIONAL,
				'only'     => 'company',
				'mask'     => '00.000.000/0000-00',
				'validate' => 'cnpj',
				'priority' => 24,
				'class'    => array( 'form-row-first' ),
			),
			// Explicitly missing from the incumbent and asked for by name:
			// "trazer o campo razão social (não tem)".
			'billing_rsbcf_company_legal_name' => array(
				'label'    => __( 'Razão Social', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::OPTIONAL,
				'only'     => 'company',
				'mask'     => '',
				'validate' => '',
				'priority' => 25,
				'class'    => array( 'form-row-last' ),
			),
			'billing_rsbcf_ie' => array(
				'label'    => __( 'Inscrição Estadual', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::OFF,
				'only'     => 'company',
				'mask'     => '',
				'validate' => '',
				'priority' => 26,
				'class'    => array( 'form-row-wide' ),
			),
			'billing_rsbcf_number' => array(
				'label'    => __( 'Número', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::REQUIRED,
				'only'     => 'both',
				'mask'     => '',
				'validate' => '',
				'priority' => 61,
				'class'    => array( 'form-row-first' ),
			),
			'billing_rsbcf_neighborhood' => array(
				'label'    => __( 'Bairro', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::REQUIRED,
				'only'     => 'both',
				'mask'     => '',
				'validate' => '',
				'priority' => 62,
				'class'    => array( 'form-row-last' ),
			),
			'billing_rsbcf_cell' => array(
				'label'    => __( 'Celular', 'rebel-studios-brazilian-checkout-fields' ),
				'default'  => self::OFF,
				'only'     => 'both',
				'mask'     => '(00) 00000-0000',
				'validate' => 'phone',
				'priority' => 101,
				'class'    => array( 'form-row-wide' ),
			),
		);
	}

	/**
	 * The configured mode for one field, honouring the store's settings.
	 */
	public static function mode( $key ) {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return self::OFF;
		}
		$settings = get_option( 'rsbcf_fields', array() );
		$mode     = isset( $settings[ $key ] ) ? $settings[ $key ] : $definitions[ $key ]['default'];

		return in_array( $mode, array( self::OFF, self::OPTIONAL, self::REQUIRED ), true )
			? $mode
			: self::OFF;
	}

	public static function is_enabled( $key ) {
		return self::OFF !== self::mode( $key );
	}

	public static function is_required( $key ) {
		return self::REQUIRED === self::mode( $key );
	}

	/**
	 * Fields active for a given person type ('person' | 'company').
	 */
	public static function active_for( $person_type ) {
		$out = array();
		foreach ( self::definitions() as $key => $definition ) {
			if ( ! self::is_enabled( $key ) ) {
				continue;
			}
			if ( 'both' !== $definition['only'] && $definition['only'] !== $person_type ) {
				continue;
			}
			$out[ $key ] = $definition;
		}
		return $out;
	}

	/**
	 * Does this store ask whether the buyer is a person or a company?
	 *
	 * Only when it actually has both kinds of field switched on -- a
	 * consumer-only store should never see the selector.
	 */
	public static function needs_person_type() {
		$has_person = $has_company = false;
		foreach ( self::definitions() as $key => $definition ) {
			if ( ! self::is_enabled( $key ) ) {
				continue;
			}
			if ( 'person' === $definition['only'] ) {
				$has_person = true;
			}
			if ( 'company' === $definition['only'] ) {
				$has_company = true;
			}
		}
		return $has_person && $has_company;
	}

	/**
	 * Does this field apply to a buyer of this person type ('person' | 'company')?
	 *
	 * A document for one kind of buyer is skipped for the other kind, but only when the store
	 * asks which kind the buyer is. A store that collects only company documents (CNPJ and Razão
	 * Social, CPF switched off) shows no selector, so every buyer gets those fields and they must
	 * be checked. Skipping them there let a classic-checkout order through with the required CNPJ
	 * empty or with a CNPJ that fails its check digits.
	 */
	public static function applies_to( $key, $person_type ) {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return false;
		}
		$only = $definitions[ $key ]['only'];

		return 'both' === $only || ! self::needs_person_type() || $only === $person_type;
	}

	/**
	 * Validate one submitted value. Returns an error string, or '' when fine.
	 */
	public static function validation_error( $key, $value, $person_type ) {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $key ] ) || ! self::is_enabled( $key ) ) {
			return '';
		}

		$definition = $definitions[ $key ];
		if ( ! self::applies_to( $key, $person_type ) ) {
			return '';
		}

		$value = trim( (string) $value );
		$label = $definition['label'];

		if ( '' === $value ) {
			return self::is_required( $key )
				/* translators: %s: checkout field label, e.g. CPF. */
				? sprintf( __( '%s é um campo obrigatório.', 'rebel-studios-brazilian-checkout-fields' ), $label )
				: '';
		}

		// An optional field that was filled in still has to be valid. Storing a
		// malformed CPF is the failure the incumbent's reviewers describe: the
		// order completes and the document is useless on the invoice.
		switch ( $definition['validate'] ) {
			case 'cpf':
				if ( ! RSBCF_Validator::is_cpf( $value ) ) {
					return __( 'CPF inválido. Confira os números digitados.', 'rebel-studios-brazilian-checkout-fields' );
				}
				break;
			case 'cnpj':
				if ( ! RSBCF_Validator::is_cnpj( $value ) ) {
					return __( 'CNPJ inválido. Confira os números digitados.', 'rebel-studios-brazilian-checkout-fields' );
				}
				break;
			case 'phone':
				if ( ! RSBCF_Validator::is_phone( $value ) ) {
					return __( 'Telefone inválido. Use DDD + número.', 'rebel-studios-brazilian-checkout-fields' );
				}
				break;
			case 'date':
				if ( ! preg_match( '#^\d{2}/\d{2}/\d{4}$#', $value ) ) {
					return __( 'Data inválida. Use o formato DD/MM/AAAA.', 'rebel-studios-brazilian-checkout-fields' );
				}
				break;
		}

		return '';
	}

	/**
	 * Normalise for storage: documents as digits, so they are queryable and
	 * comparable regardless of how the customer typed them.
	 */
	public static function normalize( $key, $value ) {
		$definitions = self::definitions();
		$validate    = isset( $definitions[ $key ] ) ? $definitions[ $key ]['validate'] : '';

		if ( in_array( $validate, array( 'cpf', 'cnpj', 'phone' ), true ) ) {
			return RSBCF_Validator::digits( $value );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Masked value for display -- admin screens, emails, the customer's account.
	 */
	public static function display( $key, $value ) {
		$definitions = self::definitions();
		$validate    = isset( $definitions[ $key ] ) ? $definitions[ $key ]['validate'] : '';

		switch ( $validate ) {
			case 'cpf':
				return RSBCF_Validator::format_cpf( $value );
			case 'cnpj':
				return RSBCF_Validator::format_cnpj( $value );
			case 'phone':
				return RSBCF_Validator::format_phone( $value );
		}
		return $value;
	}

	public static function label( $key ) {
		$definitions = self::definitions();
		return isset( $definitions[ $key ] ) ? $definitions[ $key ]['label'] : $key;
	}
}
