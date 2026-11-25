<?php
/**
 * CPF / CNPJ / CEP validation.
 *
 * The incumbent's most repeated complaint is that validation does not validate:
 * "Os campos aparecem corretamente porém ele não faz as validações que diz
 * fazer. Como exemplo validar CPF, TELEFONE." A checkout that accepts an
 * invalid CPF is worse than one with no field at all, because the store only
 * discovers the bad document when the invoice is rejected.
 *
 * Both algorithms are the official mod-11 check-digit schemes. They are
 * deliberately implemented here rather than pulled from a library so the plugin
 * has no runtime dependency and works on any host.
 *
 * @package RebelStudiosBrazilianCheckoutFields
 */

defined( 'ABSPATH' ) || exit;

class RSBCF_Validator {

	/**
	 * Strip everything that is not a digit.
	 */
	public static function digits( $value ) {
		return preg_replace( '/\D/', '', (string) $value );
	}

	/**
	 * Validate a CPF using the official mod-11 check digits.
	 *
	 * Rejects the eleven repeated-digit strings (000.000.000-00 and friends).
	 * They satisfy the arithmetic but are not issuable, and they are what gets
	 * typed when a customer wants the field to go away.
	 */
	public static function is_cpf( $cpf ) {
		$cpf = self::digits( $cpf );

		if ( 11 !== strlen( $cpf ) ) {
			return false;
		}
		if ( preg_match( '/^(\d)\1{10}$/', $cpf ) ) {
			return false;
		}

		for ( $position = 9; $position < 11; $position++ ) {
			$sum = 0;
			for ( $i = 0; $i < $position; $i++ ) {
				$sum += (int) $cpf[ $i ] * ( $position + 1 - $i );
			}
			$digit = ( ( $sum * 10 ) % 11 ) % 10;
			if ( (int) $cpf[ $position ] !== $digit ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a CNPJ using the official mod-11 check digits.
	 */
	public static function is_cnpj( $cnpj ) {
		$cnpj = self::digits( $cnpj );

		if ( 14 !== strlen( $cnpj ) ) {
			return false;
		}
		if ( preg_match( '/^(\d)\1{13}$/', $cnpj ) ) {
			return false;
		}

		$weights_first  = array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );
		$weights_second = array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 );

		foreach ( array( $weights_first, $weights_second ) as $index => $weights ) {
			$sum = 0;
			foreach ( $weights as $i => $weight ) {
				$sum += (int) $cnpj[ $i ] * $weight;
			}
			$remainder = $sum % 11;
			$digit     = ( $remainder < 2 ) ? 0 : 11 - $remainder;
			if ( (int) $cnpj[ 12 + $index ] !== $digit ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A CEP is eight digits. Range validity is the lookup's job, not ours --
	 * refusing a structurally valid CEP because a third-party API is down would
	 * block a sale for a reason that has nothing to do with the customer.
	 */
	public static function is_cep( $cep ) {
		return 8 === strlen( self::digits( $cep ) );
	}

	/**
	 * Brazilian mobile and landline numbers, with area code: 10 or 11 digits.
	 */
	public static function is_phone( $phone ) {
		$phone = self::digits( $phone );
		$length = strlen( $phone );

		if ( 10 !== $length && 11 !== $length ) {
			return false;
		}
		// Area codes run 11-99; there is no DDD below 11.
		if ( (int) substr( $phone, 0, 2 ) < 11 ) {
			return false;
		}
		// An 11-digit number is a mobile and must carry the leading 9.
		if ( 11 === $length && '9' !== $phone[2] ) {
			return false;
		}

		return true;
	}

	/** Format helpers -- storage is always digits, display is masked. */

	public static function format_cpf( $cpf ) {
		$cpf = self::digits( $cpf );
		if ( 11 !== strlen( $cpf ) ) {
			return $cpf;
		}
		return preg_replace( '/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf );
	}

	public static function format_cnpj( $cnpj ) {
		$cnpj = self::digits( $cnpj );
		if ( 14 !== strlen( $cnpj ) ) {
			return $cnpj;
		}
		return preg_replace( '/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj );
	}

	public static function format_cep( $cep ) {
		$cep = self::digits( $cep );
		if ( 8 !== strlen( $cep ) ) {
			return $cep;
		}
		return substr( $cep, 0, 5 ) . '-' . substr( $cep, 5 );
	}

	public static function format_phone( $phone ) {
		$phone  = self::digits( $phone );
		$length = strlen( $phone );
		if ( 11 === $length ) {
			return preg_replace( '/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $phone );
		}
		if ( 10 === $length ) {
			return preg_replace( '/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $phone );
		}
		return $phone;
	}
}
