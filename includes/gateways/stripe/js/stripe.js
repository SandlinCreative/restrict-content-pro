// Get bootstrapped data from the page.
var rcpStripe = window.rcpStripe || {};

// Configure Stripe API.
rcpStripe.Stripe = Stripe( rcpStripe.keys.publishable );

// Setup Elements.
rcpStripe.Elements = rcpStripe.Stripe.elements();
rcpStripe.elements = {
	card: rcpStripe.Elements.create( 'card', rcpStripe.elementsConfig ),
}

/**
 * Generate a notice element.
 *
 * @param {string} message The notice text.
 * @return {Element} HTML element containing errors.
 */
function rcpStripeGenerateNotice( message ) {
	var span = document.createElement( 'span' );
	span.innerText = message;

	var notice = document.createElement( 'p' );
	notice.classList.add( 'rcp_error' );
	notice.appendChild( span );

	var wrapper = document.createElement( 'div' );
	wrapper.classList.add( 'rcp_message' );
	wrapper.classList.add( 'error' );
	wrapper.appendChild( notice );

	return wrapper;
}

/**
 * Show or hide errors based on input to the Card Element.
 *
 * @param {Event} event Change event on the Card Element.
 */
function rcpStripeToggleElementErrors( event ) {
	var errorContainer = document.getElementById( 'rcp-card-element-errors' );

	errorContainer.innerHTML = '';

	if ( event.error ) {
		errorContainer.appendChild( rcpStripeGenerateNotice( event.error.message ) );
	}
}
