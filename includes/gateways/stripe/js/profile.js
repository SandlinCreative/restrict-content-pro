/* global rcpStripe, rcpStripeToggleElementErrors */

// Setup on page load.
( function() {
	var container = document.getElementById( 'rcp-card-wrapper' );

	if ( ! container ) {
		return;
	}

	container.innerHTML = '';

	// Need to dynamically generate a container to hold errors under the card field.
	var errorContainer = document.createElement( 'div' );
	errorContainer.id = 'rcp-card-element-errors';

	var cardContainer = document.createElement( 'div' );
	cardContainer.id = 'rcp-card-element';

	container.appendChild( cardContainer );
	container.appendChild( errorContainer );

	// Field is available, mount.
	rcpStripe.elements.card.mount( '#rcp-card-element' );

	// Handle errors.
	rcpStripe.elements.card.addEventListener( 'change', rcpStripeToggleElementErrors );
} )();

/**
 * Attempt to generate a token when the form is submitted.
 *
 * @param {Event} e Form submission event.
 */
function rcpStripeSubmitBillingCardUpdate( e ) {
	// Need to halt here, since `createToken` returns a promise.
	e.preventDefault();

	var additionalData = {
		name: document.querySelector( '.rcp_card_name' ).value,
	};

	/**
	 * Attempt to create a token from the card (and additional) information.
	 *
	 * @param {Object} result Token creation result.
	 */
	rcpStripe.Stripe.createToken( rcpStripe.elements.card, additionalData ).then( function( result ) {
		if ( result.error ) {
			rcpStripeToggleElementErrors( result );
		} else {
			var inputField = document.createElement( 'input' );
			inputField.value = result.token.id;
			inputField.type = 'hidden';
			inputField.name = 'stripeToken';

			form.appendChild( inputField );

			// Resubmit form after it's valid.
			form.removeEventListener( 'submit', rcpStripeSubmitBillingCardUpdate );
			form.submit();
		}
	} );
}

var form = document.getElementById( 'rcp_update_card_form' );

form.addEventListener( 'submit', rcpStripeSubmitBillingCardUpdate );
