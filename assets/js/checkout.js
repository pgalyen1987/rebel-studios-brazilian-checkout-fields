/**
 * Checkout Brasil — input masks, live validation, CEP/CNPJ autofill.
 *
 * Three rules, all of them from the incumbent's one-star reviews:
 *
 * 1. Masks are applied by rewriting the value as the customer types, not by a
 *    library. "máscaras para RG e data nascimento não funcionam" is the
 *    complaint; a 40-line formatter that always runs beats a dependency that
 *    silently fails to load on a themed checkout.
 *
 * 2. A lookup NEVER blocks the form. Every network path fails to "let them type
 *    it themselves". A checkout that will not submit because ViaCEP is down is
 *    a lost sale caused by the plugin.
 *
 * 3. Autofill never overwrites something the customer already typed. Nothing is
 *    more infuriating than correcting an address and watching it revert.
 *
 * Both checkouts are supported, and they differ in two ways that matter here:
 *
 * - Field ids. The classic checkout uses billing_postcode / billing_rsbcf_cnpj; the
 *   block checkout uses billing-postcode / billing-rsbcf-cnpj. Every
 *   lookup goes through field(), which knows both.
 * - Who owns the value. Block checkout inputs are React-controlled: assigning
 *   input.value directly leaves React's state behind, it re-renders the old value
 *   and moves the caret, and a CNPJ typed digit by digit came out scrambled.
 *   setValue() uses the native setter and dispatches the events React listens to.
 *
 * Handlers are delegated from document.body because the block checkout renders
 * its fields after page load, and the classic checkout replaces them on refresh.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.CheckoutBrasil || {};

	function digits( value ) {
		return String( value || '' ).replace( /\D/g, '' );
	}

	/**
	 * Apply a '0'-templated mask ("000.000.000-00") to a raw digit string.
	 */
	function applyMask( value, mask ) {
		if ( ! mask ) {
			return value;
		}
		var raw = digits( value );
		var out = '';
		var i = 0;

		for ( var m = 0; m < mask.length && i < raw.length; m++ ) {
			if ( '0' === mask[ m ] ) {
				out += raw[ i++ ];
			} else {
				out += mask[ m ];
			}
		}
		return out;
	}

	function isCpf( cpf ) {
		cpf = digits( cpf );
		if ( 11 !== cpf.length || /^(\d)\1{10}$/.test( cpf ) ) {
			return false;
		}
		for ( var position = 9; position < 11; position++ ) {
			var sum = 0;
			for ( var i = 0; i < position; i++ ) {
				sum += parseInt( cpf[ i ], 10 ) * ( position + 1 - i );
			}
			if ( parseInt( cpf[ position ], 10 ) !== ( ( sum * 10 ) % 11 ) % 10 ) {
				return false;
			}
		}
		return true;
	}

	function isCnpj( cnpj ) {
		cnpj = digits( cnpj );
		if ( 14 !== cnpj.length || /^(\d)\1{13}$/.test( cnpj ) ) {
			return false;
		}
		var weights = [
			[ 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ],
			[ 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ]
		];
		for ( var w = 0; w < weights.length; w++ ) {
			var sum = 0;
			for ( var j = 0; j < weights[ w ].length; j++ ) {
				sum += parseInt( cnpj[ j ], 10 ) * weights[ w ][ j ];
			}
			var remainder = sum % 11;
			var digit = remainder < 2 ? 0 : 11 - remainder;
			if ( parseInt( cnpj[ 12 + w ], 10 ) !== digit ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A checkout field by prefix (billing|shipping) and name, in whichever checkout is on the page.
	 * Core fields: address_1, city, state, postcode, company. Plugin fields: rsbcf_<name> -- in the
	 * block checkout the buyer's documents live in the contact section, not an address.
	 */
	function field( prefix, name ) {
		var ids;
		if ( 0 === name.indexOf( 'rsbcf_' ) ) {
			var bare = name.slice( 6 ).replace( /_/g, '-' );
			ids = [ prefix + '_' + name, prefix + '-rsbcf-' + bare, 'contact-rsbcf-' + bare ];
		} else {
			ids = [ prefix + '_' + name, prefix + '-' + name ];
		}
		for ( var k = 0; k < ids.length; k++ ) {
			var el = document.getElementById( ids[ k ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	/** billing|shipping for a field element, from either id style. */
	function prefixOf( el ) {
		return 0 === String( el.id ).indexOf( 'shipping' ) ? 'shipping' : 'billing';
	}

	/**
	 * Write a value so both jQuery listeners and React-controlled inputs see it. `commit` also fires
	 * `change`; leave it off while the customer is typing, because the block checkout treats change
	 * as "done" and syncs the half-typed value to the server.
	 */
	function setValue( el, value, commit ) {
		if ( ! el ) {
			return;
		}
		if ( 'SELECT' === el.tagName ) {
			el.value = value;
		} else {
			var proto = 'TEXTAREA' === el.tagName ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
			Object.getOwnPropertyDescriptor( proto, 'value' ).set.call( el, value );
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
		if ( commit ) {
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	/**
	 * The block checkout's source of truth is WooCommerce's data store, not the inputs: it
	 * re-renders fields from the store after every address sync, so a value written into an input
	 * is overwritten moments later. Autofill there has to go through the store.
	 */
	function blocksCart() {
		var data = window.wp && window.wp.data;
		return data && data.select( 'wc/store/cart' ) && document.querySelector( '.wc-block-checkout' ) ? data : null;
	}

	/** Plugin fields that are part of an address in the block checkout; the rest are contact fields. */
	var BLOCK_ADDRESS_FIELDS = [ 'rsbcf_number', 'rsbcf_neighborhood' ];

	/**
	 * Fill empty fields from a lookup result, in whichever checkout is on the page. In the block
	 * checkout, address values go to the address store and the buyer's details to the checkout's
	 * additional fields.
	 */
	/**
	 * Fill address fields the customer has left empty. Names in `force` are written even when
	 * they already hold something: the state select is never empty (WooCommerce preselects the
	 * store's own state), so without this a CEP from Rio on a Sao Paulo store kept "Sao Paulo".
	 */
	function fillAddress( prefix, values, force ) {
		force = force || [];
		var data = blocksCart();
		if ( data ) {
			var customer = data.select( 'wc/store/cart' ).getCustomerData();
			var current = 'shipping' === prefix ? customer.shippingAddress : customer.billingAddress;
			var extra = data.select( 'wc/store/checkout' ).getAdditionalFields() || {};
			var addressPatch = {};
			var contactPatch = {};
			Object.keys( values ).forEach( function ( name ) {
				if ( ! values[ name ] ) {
					return;
				}
				var isPlugin = 0 === name.indexOf( 'rsbcf_' );
				var key = isPlugin ? 'rsbcf/' + name.slice( 6 ).replace( /_/g, '-' ) : name;
				var forced = -1 !== force.indexOf( name );
				if ( isPlugin && -1 === BLOCK_ADDRESS_FIELDS.indexOf( name ) ) {
					if ( forced || ! $.trim( extra[ key ] || '' ) ) {
						contactPatch[ key ] = values[ name ];
					}
				} else if ( forced || ! $.trim( current[ key ] || '' ) ) {
					addressPatch[ key ] = values[ name ];
				}
			} );
			if ( Object.keys( addressPatch ).length ) {
				data.dispatch( 'wc/store/cart' )[ 'shipping' === prefix ? 'setShippingAddress' : 'setBillingAddress' ]( addressPatch );
			}
			if ( Object.keys( contactPatch ).length ) {
				data.dispatch( 'wc/store/checkout' ).setAdditionalFields( $.extend( {}, extra, contactPatch ) );
			}
			return;
		}
		Object.keys( values ).forEach( function ( name ) {
			var el = field( prefix, name );
			if ( -1 !== force.indexOf( name ) ) {
				if ( values[ name ] && el && el.value !== values[ name ] ) {
					setValue( el, values[ name ], true );
				}
			} else {
				fillIfEmpty( el, values[ name ] );
			}
		} );
	}

	/** True while the customer hasn't started this address: street, city and CEP are all empty. */
	function addressUntouched( prefix ) {
		var data = blocksCart();
		var read;
		if ( data ) {
			var customer = data.select( 'wc/store/cart' ).getCustomerData();
			var address = 'shipping' === prefix ? customer.shippingAddress : customer.billingAddress;
			read = function ( name ) { return address[ name ]; };
		} else {
			read = function ( name ) { var el = field( prefix, name ); return el ? el.value : ''; };
		}
		return [ 'address_1', 'city', 'postcode' ].every( function ( name ) {
			return ! $.trim( read( name ) || '' );
		} );
	}

	/** Only write into a field the customer has left empty. */
	function fillIfEmpty( el, value ) {
		if ( value && el && ! $.trim( el.value ) ) {
			setValue( el, value, true );
		}
	}

	function markField( el, valid, message ) {
		var $row = $( el ).closest( '.form-row, .wc-block-components-text-input' );
		$row.removeClass( 'cb-field-valid cb-field-invalid' );
		$row.find( '.cb-field-error' ).remove();

		if ( null === valid ) {
			return;
		}
		$row.addClass( valid ? 'cb-field-valid' : 'cb-field-invalid' );
		if ( ! valid && message ) {
			$row.append( $( '<span class="cb-field-error"></span>' ).text( message ) );
		}
	}

	/**
	 * GET a lookup; `done` runs only when something was found. The field dims (cb-looking-up)
	 * until the request ends, found or not, rather than for a fixed time.
	 */
	function lookup( path, input, done ) {
		if ( ! cfg.restUrl ) {
			return;
		}
		$( input ).addClass( 'cb-looking-up' );
		$.ajax( {
			url: cfg.restUrl + path,
			method: 'GET',
			timeout: 8000
		} ).done( function ( data ) {
			if ( data && data.found ) {
				done( data );
			}
		} ).always( function () {
			$( input ).removeClass( 'cb-looking-up' );
		} );
		// No .fail handler on purpose: a failed lookup is a non-event. The
		// customer types the address, exactly as they would have anyway.
	}

	function remask( el, mask ) {
		var masked = applyMask( el.value, mask );
		if ( masked === el.value ) {
			return; // also ends the re-entry from setValue's own input event
		}
		var caretAtEnd = el.selectionStart === el.value.length;
		setValue( el, masked );
		if ( caretAtEnd ) {
			el.selectionStart = el.selectionEnd = masked.length;
		}
	}

	function bindMasks() {
		$( document.body ).on( 'input', '[data-cb-mask]', function () {
			remask( this, this.getAttribute( 'data-cb-mask' ) );
		} );

		$( document.body ).on( 'blur', '[data-cb-field]', function () {
			var value = $.trim( this.value );
			var key = this.getAttribute( 'data-cb-field' ) || '';
			if ( ! value ) {
				markField( this, null );
			} else if ( /_cpf$/.test( key ) ) {
				markField( this, isCpf( value ), cfg.i18n && cfg.i18n.invalidCpf );
			} else if ( /_cnpj$/.test( key ) ) {
				markField( this, isCnpj( value ), cfg.i18n && cfg.i18n.invalidCnpj );
			}
		} );
	}

	/**
	 * Mask WooCommerce's own postcode field as a CEP.
	 *
	 * Not one of our fields, but a Brazilian store expects 01310-100 and gets
	 * 01310100. WooCommerce accepts the hyphenated form for BR, so this is
	 * presentation only -- and it is scoped to Brazil so it cannot mangle a
	 * postcode for a store that also ships elsewhere.
	 */
	var POSTCODES = '#billing_postcode, #shipping_postcode, #billing-postcode, #shipping-postcode';
	var CLASSIC_POSTCODES = '#billing_postcode, #shipping_postcode';

	function isBrazil( el ) {
		var country = field( prefixOf( el ), 'country' );
		return ! country || ! country.value || 'BR' === country.value;
	}

	function bindCepMask() {
		// Classic checkout only. The block checkout formats its own postcode, and rewriting it while
		// the address syncs wiped the field at normal typing speed.
		$( document.body ).on( 'input', CLASSIC_POSTCODES, function () {
			if ( isBrazil( this ) ) {
				remask( this, '00000-000' );
			}
		} );
	}

	function bindCepAutofill() {
		if ( ! cfg.autofillCep ) {
			return;
		}
		$( document.body ).on( 'blur change', POSTCODES, function () {
			var input = this;
			var cep = digits( input.value );
			if ( 8 !== cep.length || ! isBrazil( input ) ) {
				return;
			}
			var prefix = prefixOf( input );

			lookup( '/cep/' + cep, input, function ( data ) {
				// A CEP decides the city and the state; street and neighbourhood stay the customer's if typed.
				fillAddress( prefix, { address_1: data.address, rsbcf_neighborhood: data.neighborhood, city: data.city, state: data.state }, [ 'city', 'state' ] );
			} );
		} );
	}

	function bindCnpjAutofill() {
		if ( ! cfg.autofillCnpj ) {
			return;
		}
		$( document.body ).on( 'blur', '#billing_rsbcf_cnpj, #contact-rsbcf-cnpj', function () {
			var input = this;
			var cnpj = digits( input.value );
			if ( 14 !== cnpj.length || ! isCnpj( cnpj ) ) {
				return;
			}
			// The block checkout shows the shipping form when the order ships; fill the form the buyer sees.
			var prefix = document.getElementById( 'shipping-address_1' ) ? 'shipping' : 'billing';
			lookup( '/cnpj/' + cnpj, input, function ( data ) {
				var values = { rsbcf_company_legal_name: data.legal_name, company: data.trade_name || data.legal_name };
				var address = { address_1: data.address, rsbcf_number: data.number, rsbcf_neighborhood: data.neighborhood, city: data.city, postcode: data.cep, state: data.state };
				// The registered address goes in only as a whole, and only while the customer hasn't
				// started one: mixing it into a half-typed address (their CEP, the company's street)
				// ships to a place that doesn't exist. Judged when the answer arrives, not on blur.
				var force = [];
				if ( addressUntouched( prefix ) ) {
					$.extend( values, address );
					force = Object.keys( address );
				}
				fillAddress( prefix, values, force );
			} );
		} );
	}

	/** Show only the fields that belong to the selected person type (classic checkout). */
	function bindPersonType() {
		if ( ! cfg.personType ) {
			return;
		}
		function apply() {
			var type = $( '#billing_rsbcf_person_type' ).val() || 'person';
			$( '.cb-only-person' ).toggleClass( 'cb-hidden', 'person' !== type );
			$( '.cb-only-company' ).toggleClass( 'cb-hidden', 'company' !== type );
		}
		$( document.body ).on( 'change', '#billing_rsbcf_person_type', apply );
		// The classic checkout replaces its markup on every AJAX refresh.
		$( document.body ).on( 'updated_checkout country_to_state_changed', apply );
		apply();
	}

	/**
	 * Keep the buyer's place when WooCommerce drops it (classic checkout).
	 *
	 * Whenever update_order_review comes back with any notice, WooCommerce's checkout script blurs
	 * every field ("Lose focus for all fields"). That answer often lands while the buyer is typing:
	 * changing the CEP starts the update, and the next keystrokes go nowhere. On a store with plain
	 * permalinks a leftover "added to your cart" notice keeps coming back (the favicon and a broken
	 * mini-cart request render 404 pages that save an old copy of the session), so every update
	 * fails: "Consolação" typed into Bairro was stored as "Cção". It happens with this plugin
	 * switched off too, in WooCommerce's own fields.
	 *
	 * The blur and the updated_checkout event run in the same task, so a field that lost focus in
	 * that task with nothing else taking it gets focus and caret back before the next keystroke.
	 */
	function bindKeepFocus() {
		var lost = null;

		// A native listener, not a jQuery one: WooCommerce's .trigger( 'blur' ) on every field also
		// runs jQuery 'focusout' handlers for fields that never had focus.
		document.addEventListener( 'focusout', function ( e ) {
			var el = e.target;
			if ( e.relatedTarget || ! el || ! $( el ).is( 'form.checkout input, form.checkout textarea' ) ) {
				return;
			}
			var record = { el: el, start: null, end: null };
			try {
				record.start = el.selectionStart;
				record.end = el.selectionEnd;
			} catch ( err ) {
				// Inputs without a caret (checkboxes, radios) have no selection to restore.
			}
			lost = record;
			window.setTimeout( function () {
				if ( lost === record ) {
					lost = null;
				}
			}, 0 );
		}, true );

		$( document.body ).on( 'updated_checkout', function ( e, data ) {
			var record = lost;
			lost = null;
			if ( ! record || ! data || 'failure' !== data.result ) {
				return;
			}
			var active = document.activeElement;
			if ( ( active && active !== document.body ) || ! document.body.contains( record.el ) || ! $( record.el ).is( ':visible' ) ) {
				return;
			}
			record.el.focus( { preventScroll: true } );
			if ( null !== record.start ) {
				try {
					record.el.setSelectionRange( record.start, record.end );
				} catch ( err ) {
					// Not every input type supports a selection.
				}
			}
		} );
	}

	$( function () {
		bindKeepFocus();
		bindMasks();
		bindCepMask();
		bindCepAutofill();
		bindCnpjAutofill();
		bindPersonType();
	} );
} )( jQuery );
