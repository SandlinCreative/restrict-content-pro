/* global rcpStripe, rcp_processing, rcp_script_options */

/**
 * Unblock the form, hide loading symbols, and enable registration button.
 */
function rcpStripeEnableForm() {
	jQuery( '#rcp_registration_form #rcp_submit' ).attr( 'disabled', false );
	jQuery( '#rcp_ajax_loading' ).hide();
	jQuery( '#rcp_registration_form' ).unblock();
	jQuery( '#rcp_submit' ).val( rcp_script_options.register );

	rcp_processing = false;
}

// Reliant on jQuery triggers, so setup jQuery.
jQuery( function( $ ) {

	// Attempt to mount the card when the gateway changes.
	$( 'body' ).on( 'rcp_gateway_loaded', function( e, gateway ) {
		if ( ! document.getElementById( 'rcp-card-element' ) ) {
			return;
		}

		// Field is available, mount.
		rcpStripe.elements.card.mount( '#rcp-card-element' );

		// Handle errors.
		rcpStripe.elements.card.addEventListener( 'change', rcpStripeToggleElementErrors );
	} );

	// Listen to form submission to create a token or display errors.
	$( 'body' ).off( 'rcp_register_form_submission' ).on( 'rcp_register_form_submission', function( event, response, form_id ) {

		// Not on Stripe gateway, bail.
		if ( response.gateway.slug !== 'stripe' ) {
			return;
		}

		/*
		 * Bail if the amount due today is 0 AND:
		 * the recurring amount is 0, or auto renew is off
		 */
		if ( ! response.total && ( ! response.recurring_total || ! response.auto_renew ) ) {
			return;
		}
		
		// 100% discount, bail.
		if ( $( '.rcp_gateway_fields' ).hasClass( 'rcp_discounted_100' ) ) {
			return;
		}

		event.preventDefault();

		// Loading...
		$( '.rcp_message.error' ).remove();
		$( '#rcp_registration_form #rcp_submit' ).attr( 'disabled', true );
		$( '#rcp_ajax_loading' ).show();

		var additionalData = {
			name: $( '.card-name' ).val(),
		};

		/**
		 * Attempt to create a token from the card (and additional) information.
		 *
		 * @param {Object} result Token creation result.
		 */
		rcpStripe.Stripe.createToken( rcpStripe.elements.card, additionalData ).then( function( result ) {
			if ( result.error ) {
				rcpStripeEnableForm();
				rcpStripeToggleElementErrors( result );
			} else {
				var form = document.getElementById( 'rcp_registration_form' );

				var inputField = document.createElement( 'input' );
				inputField.value = result.token.id;
				inputField.type = 'hidden';
				inputField.name = 'stripeToken';

				form.appendChild( inputField );
				form.submit();
			}
		} );
	} );
} );
