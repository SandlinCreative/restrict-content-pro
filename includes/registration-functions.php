<?php
/**
 * Registration Functions
 *
 * Processes the registration form
 *
 * @package     Restrict Content Pro
 * @subpackage  Registration Functions
 * @copyright   Copyright (c) 2017, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.5
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;


/**
 * Register a new user
 *
 * @access public
 * @since  1.0
 * @return void
 */
function rcp_process_registration() {

	// check nonce
	if ( ! ( isset( $_POST["rcp_register_nonce"] ) && wp_verify_nonce( $_POST['rcp_register_nonce'], 'rcp-register-nonce' ) ) ) {
		return;
	}

	global $rcp_options, $rcp_levels_db;

	$subscription_id     = rcp_get_registration()->get_subscription();
	$discount            = isset( $_POST['rcp_discount'] ) ? sanitize_text_field( strtolower( $_POST['rcp_discount'] ) ) : '';
	$price               = number_format( (float) $rcp_levels_db->get_level_field( $subscription_id, 'price' ), 2 );
	$price               = str_replace( ',', '', $price );
	$subscription        = $rcp_levels_db->get_level( $subscription_id );
	$auto_renew          = rcp_registration_is_recurring();
	$trial_duration      = $rcp_levels_db->trial_duration( $subscription_id );
	$trial_duration_unit = $rcp_levels_db->trial_duration_unit( $subscription_id );
	// if both today's total and the recurring total are 0, the there is a full discount
	// if this is not a recurring subscription only check today's total
	$full_discount = ( $auto_renew ) ? ( rcp_get_registration()->get_total() == 0 && rcp_get_registration()->get_recurring_total() == 0 ) : ( rcp_get_registration()->get_total() == 0 );

	// get the selected payment method/gateway
	if( ! isset( $_POST['rcp_gateway'] ) ) {
		$gateway = 'paypal';
	} else {
		$gateway = sanitize_text_field( $_POST['rcp_gateway'] );
	}

	// Change gateway to "free" if this subscription doesn't require payment.
	if ( $full_discount || empty( $price ) ) {
		$gateway = 'free';
	}

	rcp_log( sprintf( 'Started new registration for subscription #%d via %s.', $subscription_id, $gateway ) );

	/***********************
	* validate the form
	***********************/

	do_action( 'rcp_before_form_errors', $_POST );

	$is_ajax   = isset( $_POST['rcp_ajax'] );

	$user_data = rcp_validate_user_data();

	if( ! rcp_is_registration() ) {
		// no subscription level was chosen
		rcp_errors()->add( 'no_level', __( 'Please choose a subscription level', 'rcp' ), 'register' );
	}

	if( $subscription_id && $price == 0 && $subscription->duration > 0 && rcp_has_used_trial( $user_data['id'] ) ) {
		// this ensures that users only sign up for a free trial once
		rcp_errors()->add( 'free_trial_used', __( 'You may only sign up for a free trial once', 'rcp' ), 'register' );
	}

	if( ! empty( $discount ) ) {

		// make sure we have a valid discount
		if( rcp_validate_discount( $discount, $subscription_id ) ) {

			// check if the user has already used this discount
			if ( $price > 0 && ! $user_data['need_new'] && rcp_user_has_used_discount( $user_data['id'] , $discount ) && apply_filters( 'rcp_discounts_once_per_user', false, $discount, $subscription_id ) ) {
				rcp_errors()->add( 'discount_already_used', __( 'You can only use the discount code once', 'rcp' ), 'register' );
			}

		} else {
			// the entered discount code is incorrect
			rcp_errors()->add( 'invalid_discount', __( 'The discount you entered is invalid', 'rcp' ), 'register' );
		}

	}

	// Validate extra fields in gateways with the 2.1+ gateway API
	if( ! has_action( 'rcp_gateway_' . $gateway ) && $price > 0 && ! $full_discount ) {

		$gateways    = new RCP_Payment_Gateways;
		$gateway_var = $gateways->get_gateway( $gateway );
		$gateway_obj = new $gateway_var['class'];
		$gateway_obj->validate_fields();
	}

	do_action( 'rcp_form_errors', $_POST );

	// retrieve all error messages, if any
	$errors = rcp_errors()->get_error_messages();

	if ( ! empty( $errors ) && $is_ajax ) {
		rcp_log( sprintf( 'Registration cancelled with the following errors: %s.', implode( ', ', $errors ) ) );

		wp_send_json_error( array(
			'success'          => false,
			'errors'           => rcp_get_error_messages_html( 'register' ),
			'nonce'            => wp_create_nonce( 'rcp-register-nonce' ),
			'gateway'          => array(
				'slug'     => $gateway,
				'supports' => ! empty( $gateway_obj->supports ) ? $gateway_obj->supports : false
			)
		) );

	} elseif( $is_ajax ) {
		wp_send_json_success( array(
			'success'          => true,
			'total'            => rcp_get_registration()->get_total(),
			'gateway'          => array(
				'slug'     => $gateway,
				'supports' => ! empty( $gateway_obj->supports ) ? $gateway_obj->supports : false
			),
			'level'            => array(
				'trial'        => ! empty( $trial_duration )
			)
		) );

	}

	// only create the user if there are no errors
	if( ! empty( $errors ) ) {
		return;
	}

	if( $user_data['need_new'] ) {

		$display_name = trim( $user_data['first_name'] . ' ' . $user_data['last_name'] );

		$user_data['id'] = wp_insert_user( array(
				'user_login'      => $user_data['login'],
				'user_pass'       => $user_data['password'],
				'user_email'      => $user_data['email'],
				'first_name'      => $user_data['first_name'],
				'last_name'       => $user_data['last_name'],
				'display_name'    => ! empty( $display_name ) ? $display_name : $user_data['login'],
				'user_registered' => date( 'Y-m-d H:i:s' )
			)
		);

	}

	if ( empty( $user_data['id'] ) ) {
		return;
	}

	// Setup the member object
	$member = new RCP_Member( $user_data['id'] );

	update_user_meta( $user_data['id'], '_rcp_new_subscription', '1' );

	$subscription_key = rcp_generate_subscription_key();

	$old_subscription_id = $member->get_subscription_id();

	$member_has_trialed = $member->has_trialed();

	if( $old_subscription_id ) {
		update_user_meta( $user_data['id'], '_rcp_old_subscription_id', $old_subscription_id );
	}

	// Delete pending payment ID. A new one may be created for paid subscriptions.
	delete_user_meta( $user_data['id'], 'rcp_pending_payment_id' );

	// Delete old pending data that may have been added in previous versions.
	delete_user_meta( $user_data['id'], 'rcp_pending_expiration_date' );
	delete_user_meta( $user_data['id'], 'rcp_pending_subscription_level' );
	delete_user_meta( $user_data['id'], 'rcp_pending_subscription_key' );

	// Backwards compatibility pre-2.9: set pending subscription key.
	update_user_meta( $user_data['id'], 'rcp_pending_subscription_key', $subscription_key );

	// Create a pending payment
	$amount = ( ! empty( $trial_duration ) && ! rcp_has_used_trial() ) ? 0.00 : rcp_get_registration()->get_total();
	$payment_data = array(
		'date'                  => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
		'subscription'          => $subscription->name,
		'object_id'             => $subscription->id,
		'object_type'           => 'subscription',
		'gateway'               => $gateway,
		'subscription_key'      => $subscription_key,
		'amount'                => $amount,
		'user_id'               => $user_data['id'],
		'status'                => 'pending',
		'subtotal'              => $subscription->price,
		'credits'               => $member->get_prorate_credit_amount(),
		'fees'                  => rcp_get_registration()->get_total_fees() + $member->get_prorate_credit_amount(),
		'discount_amount'       => rcp_get_registration()->get_total_discounts(),
		'discount_code'         => $discount,
	);

	$rcp_payments = new RCP_Payments();
	$payment_id   = $rcp_payments->insert( $payment_data );
	update_user_meta( $user_data['id'], 'rcp_pending_payment_id', $payment_id );

	if( ! $member->get_subscription_id() || $member->is_expired() || in_array( $member->get_status(), array( 'expired', 'pending' ) ) ) {

		// Ensure no pending level details are set
		delete_user_meta( $user_data['id'], 'rcp_pending_subscription_level' );
		delete_user_meta( $user_data['id'], 'rcp_pending_subscription_key' );

		$member->set_status( 'pending' );

	} else {

		// Flag the member as having just upgraded
		update_user_meta( $user_data['id'], '_rcp_just_upgraded', current_time( 'timestamp' ) );

	}

	// Remove trialing status, if it exists
	if ( ! $trial_duration || $trial_duration && $member_has_trialed ) {
		delete_user_meta( $user_data['id'], 'rcp_is_trialing' );
	}

	/**
	 * Triggers after all the form data has been processed, but before the user is sent to the payment gateway.
	 * The user's membership is pending at this point.
	 *
	 * @param array $_POST      Posted data.
	 * @param int   $user_id    ID of the user registering.
	 * @param float $price      Price of the membership.
	 * @param int   $payment_id ID of the pending payment associated with this registration.
	 */
	do_action( 'rcp_form_processing', $_POST, $user_data['id'], $price, $payment_id );

	// process a paid subscription
	if( $price > '0' || $trial_duration ) {

		if( ! empty( $discount ) && $full_discount ) {

			// Cancel existing subscription.
			if ( $member->can_cancel() ) {
				$member->cancel_payment_profile( false );
			}

			// Full discount with auto renew should never expire.
			if ( '2' != rcp_get_auto_renew_behavior() ) {
				update_user_meta( $user_data['id'], 'rcp_pending_expiration_date', 'none' );
			}

			// Complete payment. This also activates the membership.
			$rcp_payments->update( $payment_id, array( 'status' => 'complete' ) );

			rcp_log( sprintf( 'Completed registration to level #%d with full discount for user #%d.', $subscription_id, $user_data['id'] ) );
			rcp_login_user_in( $user_data['id'], $user_data['login'] );
			wp_redirect( rcp_get_return_url( $user_data['id'] ) ); exit;

		}

		// log the new user in
		rcp_login_user_in( $user_data['id'], $user_data['login'] );

		$redirect = rcp_get_return_url( $user_data['id'] );

		$subscription_data = array(
			'price'               => rcp_get_registration()->get_total( true, false ), // get total without the fee
			'recurring_price'     => rcp_get_registration()->get_recurring_total( true, false ), // get recurring total without the fee
			'discount'            => rcp_get_registration()->get_total_discounts(),
			'discount_code'       => $discount,
			'fee'                 => rcp_get_registration()->get_total_fees(),
			'length'              => $subscription->duration,
			'length_unit'         => strtolower( $subscription->duration_unit ),
			'subscription_id'     => $subscription->id,
			'subscription_name'   => $subscription->name,
			'key'                 => $subscription_key,
			'user_id'             => $user_data['id'],
			'user_name'           => $user_data['login'],
			'user_email'          => $user_data['email'],
			'currency'            => rcp_get_currency(),
			'auto_renew'          => $auto_renew,
			'return_url'          => $redirect,
			'new_user'            => $user_data['need_new'],
			'trial_duration'      => $trial_duration,
			'trial_duration_unit' => $trial_duration_unit,
			'trial_eligible'      => ! $member_has_trialed,
			'post_data'           => $_POST,
			'payment_id'          => $payment_id
		);

		// if giving the user a credit, make sure the credit does not exceed the first payment
		if ( $subscription_data['fee'] < 0 && abs( $subscription_data['fee'] ) > $subscription_data['price'] ) {
			$subscription_data['fee'] = -1 * $subscription_data['price'];
		}

		update_user_meta( $user_data['id'], 'rcp_pending_subscription_amount', round( $subscription_data['price'] + $subscription_data['fee'], 2 ) );

		// send all of the subscription data off for processing by the gateway
		rcp_send_to_gateway( $gateway, apply_filters( 'rcp_subscription_data', $subscription_data ) );

	// process a free or trial subscription
	} else {

		// Cancel existing subscription.
		if ( $member->can_cancel() ) {
			$member->cancel_payment_profile( false );
		}

		$member->set_recurring( false );

		// Complete payment. This also activates the membership.
		$rcp_payments->update( $payment_id, array( 'status' => 'complete' ) );

		if( $user_data['need_new'] ) {

			if( ! isset( $rcp_options['disable_new_user_notices'] ) ) {

				// send an email to the admin alerting them of the registration
				wp_new_user_notification( $user_data['id']) ;

			}

			// log the new user in
			rcp_login_user_in( $user_data['id'], $user_data['login'] );

		}

		rcp_log( sprintf( 'Completed free registration to level #%d for user #%d.', $subscription_id, $user_data['id'] ) );

		// send the newly created user to the redirect page after logging them in
		wp_redirect( rcp_get_return_url( $user_data['id'] ) ); exit;

	} // end price check

}
add_action( 'init', 'rcp_process_registration', 100 );
add_action( 'wp_ajax_rcp_process_register_form', 'rcp_process_registration', 100 );
add_action( 'wp_ajax_nopriv_rcp_process_register_form', 'rcp_process_registration', 100 );

/**
 * Provide the default registration values when checking out with Stripe Checkout.
 *
 * @return void
 */
function rcp_handle_stripe_checkout() {

	if ( isset( $_POST['rcp_ajax'] ) || empty( $_POST['rcp_gateway'] ) || empty( $_POST['stripeEmail'] ) || 'stripe_checkout' !== $_POST['rcp_gateway'] ) {
		return;
	}

	if ( empty( $_POST['rcp_user_email'] ) ) {
		$_POST['rcp_user_email'] = $_POST['stripeEmail'];
	}

	if ( empty( $_POST['rcp_user_login'] ) ) {
		$_POST['rcp_user_login'] = $_POST['stripeEmail'];
	}

	if ( empty( $_POST['rcp_user_first'] ) ) {
		$user_email = explode( '@', $_POST['rcp_user_email'] );
		$_POST['rcp_user_first'] = $user_email[0];
	}

	if ( empty( $_POST['rcp_user_last'] ) ) {
		$_POST['rcp_user_last'] = '';
	}

	if ( empty( $_POST['rcp_user_pass'] ) ) {
		$_POST['rcp_user_pass'] = wp_generate_password();
	}

	if ( empty( $_POST['rcp_user_pass_confirm'] ) ) {
		$_POST['rcp_user_pass_confirm'] = $_POST['rcp_user_pass'];
	}

}
add_action( 'rcp_before_form_errors', 'rcp_handle_stripe_checkout' );


/**
 * Validate and setup the user data for registration
 *
 * @access      public
 * @since       1.5
 * @return      array
 */
function rcp_validate_user_data() {

	$user = array();

	if( ! is_user_logged_in() ) {
		$user['id']		          = 0;
		$user['login']		      = sanitize_text_field( $_POST['rcp_user_login'] );
		$user['email']		      = sanitize_text_field( $_POST['rcp_user_email'] );
		$user['first_name'] 	  = sanitize_text_field( $_POST['rcp_user_first'] );
		$user['last_name']	 	  = sanitize_text_field( $_POST['rcp_user_last'] );
		$user['password']		  = sanitize_text_field( $_POST['rcp_user_pass'] );
		$user['password_confirm'] = sanitize_text_field( $_POST['rcp_user_pass_confirm'] );
		$user['need_new']         = true;
	} else {
		$userdata 		  = get_userdata( get_current_user_id() );
		$user['id']       = $userdata->ID;
		$user['login'] 	  = $userdata->user_login;
		$user['email'] 	  = $userdata->user_email;
		$user['need_new'] = false;
	}


	if( $user['need_new'] ) {
		if( username_exists( $user['login'] ) ) {
			// Username already registered
			rcp_errors()->add( 'username_unavailable', __( 'Username already taken', 'rcp' ), 'register' );
		}
		if( ! rcp_validate_username( $user['login'] ) ) {
			// invalid username
			rcp_errors()->add( 'username_invalid', __( 'Invalid username', 'rcp' ), 'register' );
		}
		if( empty( $user['login'] ) ) {
			// empty username
			rcp_errors()->add( 'username_empty', __( 'Please enter a username', 'rcp' ), 'register' );
		}
		if( ! is_email( $user['email'] ) ) {
			//invalid email
			rcp_errors()->add( 'email_invalid', __( 'Invalid email', 'rcp' ), 'register' );
		}
		if( email_exists( $user['email'] ) ) {
			//Email address already registered
			rcp_errors()->add( 'email_used', __( 'Email already registered', 'rcp' ), 'register' );
		}
		if( empty( $user['password'] ) ) {
			// passwords do not match
			rcp_errors()->add( 'password_empty', __( 'Please enter a password', 'rcp' ), 'register' );
		}
		if( $user['password'] !== $user['password_confirm'] ) {
			// passwords do not match
			rcp_errors()->add( 'password_mismatch', __( 'Passwords do not match', 'rcp' ), 'register' );
		}
	}

	return apply_filters( 'rcp_user_registration_data', $user );
}


/**
 * Get the registration success/return URL
 *
 * @param       $user_id int The user ID we have just registered
 *
 * @access      public
 * @since       1.5
 * @return      string
 */
function rcp_get_return_url( $user_id = 0 ) {

	global $rcp_options;

	if( isset( $rcp_options['redirect'] ) ) {
		$redirect = get_permalink( $rcp_options['redirect'] );
	} else {
		$redirect = home_url();
	}
	return apply_filters( 'rcp_return_url', $redirect, $user_id );
}

/**
 * Determine if the current page is a registration page
 *
 * @access      public
 * @since       2.0
 * @return      bool
 */
function rcp_is_registration_page() {

	global $rcp_options, $post;

	$ret = false;

	if ( isset( $rcp_options['registration_page'] ) ) {
		$ret = is_page( $rcp_options['registration_page'] );
	}

	if ( ! empty( $post ) && has_shortcode( $post->post_content, 'register_form' ) ) {
		$ret = true;
	}

	return apply_filters( 'rcp_is_registration_page', $ret );
}

/**
 * Get the auto renew behavior
 *
 * 1 == All subscriptions auto renew
 * 2 == No subscriptions auto renew
 * 3 == Customer chooses whether to auto renew
 *
 * @access      public
 * @since       2.0
 * @return      int
 */
function rcp_get_auto_renew_behavior() {

	global $rcp_options, $rcp_level;


	// Check for old disable auto renew option
	if( isset( $rcp_options['disable_auto_renew'] ) ) {
		$rcp_options['auto_renew'] = '2';
		unset( $rcp_options['disable_auto_renew'] );
		update_option( 'rcp_settings', $rcp_options );
	}

	$behavior = isset( $rcp_options['auto_renew'] ) ? $rcp_options['auto_renew'] : '3';

	if( $rcp_level ) {
		$level = rcp_get_subscription_details( $rcp_level );
		if( $level->price == '0' ) {
			$behavior = '2';
		}
	}

	return apply_filters( 'rcp_auto_renew_behavior', $behavior );
}

/**
 * When new subscriptions are registered, a flag is set
 *
 * This removes the flag as late as possible so other systems can hook into
 * rcp_set_status and perform actions on new subscriptions
 *
 * @param string $status  User's membership status.
 * @param int    $user_id ID of the member.
 *
 * @access      public
 * @since       2.3.6
 * @return      void
 */
function rcp_remove_new_subscription_flag( $status, $user_id ) {

	if ( ! in_array( $status, array( 'active', 'free' ) ) ) {
		return;
	}

	delete_user_meta( $user_id, '_rcp_old_subscription_id' );
	delete_user_meta( $user_id, '_rcp_new_subscription' );
}
add_action( 'rcp_set_status', 'rcp_remove_new_subscription_flag', 9999999, 2 );

/**
 * When upgrading subscriptions, the new level / key are stored as pending. Once payment is received, the pending
 * values are set as the permanent values.
 *
 * See https://github.com/restrictcontentpro/restrict-content-pro/issues/294
 *
 * @param string     $status     User's membership status.
 * @param int        $user_id    ID of the user.
 * @param string     $old_status Previous membership status.
 * @param RCP_Member $member     Member object.
 *
 * @access      public
 * @since       2.4.3
 * @return      void
 */
function rcp_set_pending_subscription_on_upgrade( $status, $user_id, $old_status, $member ) {

	if( 'active' !== $status ) {
		return;
	}

	$subscription_id  = get_user_meta( $user_id, 'rcp_pending_subscription_level', true );
	$subscription_key = get_user_meta( $user_id, 'rcp_pending_subscription_key', true );

	if( ! empty( $subscription_id ) && ! empty( $subscription_key ) ) {

		$member->set_subscription_id( $subscription_id );
		$member->set_subscription_key( $subscription_key );

		delete_user_meta( $user_id, 'rcp_pending_subscription_level' );
		delete_user_meta( $user_id, 'rcp_pending_subscription_key' );

	}
}
add_action( 'rcp_set_status', 'rcp_set_pending_subscription_on_upgrade', 10, 4 );

/**
 * Adjust subscription member counts on status changes
 *
 * @param string     $status     User's membership status.
 * @param int        $user_id    ID of the user.
 * @param string     $old_status Previous membership status.
 * @param RCP_Member $member     Member object.
 *
 * @access      public
 * @since       2.6
 * @return      void
 */
function rcp_increment_subscription_member_count_on_status_change( $status, $user_id, $old_status, $member ) {

	$pending_sub_id = $member->get_pending_subscription_id();
	$old_sub_id     = get_user_meta( $user_id, '_rcp_old_subscription_id', true );
	$sub_id         = $member->get_subscription_id();

	if( $old_sub_id && (int) $sub_id === (int) $old_sub_id && $status === $old_status ) {
		return;
	}

	if( ! empty( $pending_sub_id ) ) {

		rcp_increment_subscription_member_count( $pending_sub_id, $status );

	} elseif( $status !== $old_status ) {

		rcp_increment_subscription_member_count( $sub_id, $status );

	}

	if( ! empty( $old_status ) && $old_status !== $status ) {
		rcp_decrement_subscription_member_count( $sub_id, $old_status );
	}

	if( $old_sub_id ) {
		rcp_decrement_subscription_member_count( $old_sub_id, $old_status );
	}

}
add_action( 'rcp_set_status', 'rcp_increment_subscription_member_count_on_status_change', 9, 4 );

/**
 * Determine if this registration is recurring
 *
 * @since 2.5
 * @return bool
 */
function rcp_registration_is_recurring() {

	$auto_renew = false;

	if ( '3' == rcp_get_auto_renew_behavior() ) {
		$auto_renew = isset( $_POST['rcp_auto_renew'] );
	}

	if ( '1' == rcp_get_auto_renew_behavior() ) {
		$auto_renew = true;
	}

	// make sure this gateway supports recurring payments
	if ( $auto_renew && ! empty( $_POST['rcp_gateway'] ) ) {
		$auto_renew = rcp_gateway_supports( sanitize_text_field( $_POST['rcp_gateway'] ), 'recurring' );
	}

	if ( $auto_renew && ! empty( $_POST['rcp_level'] ) ) {
		$details = rcp_get_subscription_details( $_POST['rcp_level'] );

		// check if this is an unlimited or free subscription
		if ( empty( $details->duration ) || empty( $details->price ) ) {
			$auto_renew = false;
		}
	}

	if( ! rcp_get_registration_recurring_total() > 0 ) {
		$auto_renew = false;
	}

	return apply_filters( 'rcp_registration_is_recurring', $auto_renew );

}

/**
 * Add the registration total before the gateway fields
 *
 * @since 2.5
 * @return void
 */
function rcp_registration_total_field() {
	?>
	<div class="rcp_registration_total"></div>
<?php
}
add_action( 'rcp_after_register_form_fields', 'rcp_registration_total_field' );

/**
 * Get formatted total for this registration
 *
 * @param bool $echo Whether or not to echo the value.
 *
 * @since      2.5
 * @return string|bool|void
 */
function rcp_registration_total( $echo = true ) {
	$total = rcp_get_registration_total();

	// the registration has not been setup yet
	if ( false === $total ) {
		return false;
	}

	if ( 0 < $total ) {
		$total = rcp_currency_filter( $total );
	} else {
		$total = __( 'free', 'rcp' );
	}

	global $rcp_levels_db;

	$level               = $rcp_levels_db->get_level( rcp_get_registration()->get_subscription() );
	$trial_duration      = $rcp_levels_db->trial_duration( $level->id );
	$trial_duration_unit = $rcp_levels_db->trial_duration_unit( $level->id );

	if ( ! empty( $trial_duration ) && ! rcp_has_used_trial() ) {
		$total = sprintf( __( 'Free trial - %s', 'rcp' ), $trial_duration . ' ' .  rcp_filter_duration_unit( $trial_duration_unit, $trial_duration ) );
	}

	$total = apply_filters( 'rcp_registration_total', $total );

	if ( $echo ) {
		echo $total;
	}

	return $total;
}

/**
 * Get the total for this registration
 *
 * @since  2.5
 * @return float|false
 */
function rcp_get_registration_total() {

	if ( ! rcp_is_registration() ) {
		return false;
	}

	return rcp_get_registration()->get_total();
}

/**
 * Get formatted recurring total for this registration
 *
 * @param bool $echo Whether or not to echo the value.
 *
 * @since  2.5
 * @return string|bool|void
 */
function rcp_registration_recurring_total( $echo = true ) {
	$total = rcp_get_registration_recurring_total();

	// the registration has not been setup yet
	if ( false === $total ) {
		return false;
	}

	if ( 0 < $total ) {
		$total = rcp_currency_filter( $total );
		$subscription = rcp_get_subscription_details( rcp_get_registration()->get_subscription() );

		if ( $subscription->duration == 1 ) {
			$total .= '/' . rcp_filter_duration_unit( $subscription->duration_unit, 1 );
		} else {
			$total .= sprintf( __( ' every %s %s', 'rcp' ), $subscription->duration, rcp_filter_duration_unit( $subscription->duration_unit, $subscription->duration ) );
		}
	} else {
		$total = __( 'free', 'rcp' );;
	}

	$total = apply_filters( 'rcp_registration_recurring_total', $total );

	if ( $echo ) {
		echo $total;
	}

	return $total;
}

/**
 * Get the recurring total payment
 *
 * @since 2.5
 * @return bool|int
 */
function rcp_get_registration_recurring_total() {

	if ( ! rcp_is_registration() ) {
		return false;
	}

	return rcp_get_registration()->get_recurring_total();
}

/**
 * Is the registration object setup?
 *
 * @since 2.5
 * @return bool
 */
function rcp_is_registration() {
	return (bool) rcp_get_registration()->get_subscription();
}

/**
 * Get the registration object. If it hasn't been setup, setup an empty
 * registration object.
 *
 * @return RCP_Registration
 */
function rcp_get_registration() {
	global $rcp_registration;

	// setup empty registration object if one doesn't exist
	if ( ! is_a( $rcp_registration, 'RCP_Registration' ) ) {
		rcp_setup_registration();
	}

	return $rcp_registration;
}

/**
 * Setup the registration object
 *
 * Auto setup cart on page load if $_POST parameters are found
 *
 * @param int|null    $level_id ID of the subscription level for this registration.
 * @param string|null $discount Discount code to apply to this registration.
 *
 * @since  2.5
 * @return void
 */
function rcp_setup_registration( $level_id = null, $discount = null ) {
	global $rcp_registration;

	$rcp_registration = new RCP_Registration( $level_id, $discount );
	do_action( 'rcp_setup_registration', $level_id, $discount );
}

/**
 * Automatically setup the registration object
 *
 * @uses rcp_setup_registration()
 *
 * @return void
 */
function rcp_setup_registration_init() {

	if ( empty( $_POST['rcp_level'] ) ) {
		return;
	}

	$level_id = abs( $_POST['rcp_level'] );
	$discount = ! empty( $_REQUEST['discount'] ) ? sanitize_text_field( $_REQUEST['discount'] ) : null;
	$discount = ! empty( $_POST['rcp_discount'] ) ? sanitize_text_field( $_POST['rcp_discount'] ) : $discount;

	rcp_setup_registration( $level_id, $discount );
}
add_action( 'init', 'rcp_setup_registration_init' );


/**
 * Filter levels to only show valid upgrade levels
 *
 * @since 2.5
 * @return array Array of subscriptions.
 */
function rcp_filter_registration_upgrade_levels() {

	remove_filter( 'rcp_get_levels', 'rcp_filter_registration_upgrade_levels' );

	$levels = rcp_get_upgrade_paths();

	add_filter( 'rcp_get_levels', 'rcp_filter_registration_upgrade_levels' );

	return $levels;

}

/**
 * Hook into registration page and filter upgrade path
 */
add_action( 'rcp_before_subscription_form_fields', 'rcp_filter_registration_upgrade_levels' );

/**
 * Add prorate credit to member registration
 *
 * @param RCP_Registration $registration
 *
 * @since 2.5
 * @return void
 */
function rcp_add_prorate_fee( $registration ) {
	if ( ! $amount = rcp_get_member_prorate_credit() ) {
		return;
	}

	// If renewing their current subscription, no proration.
	if ( is_user_logged_in() && rcp_get_subscription_id() == $registration->get_subscription() ) {
		return;
	}

	$registration->add_fee( -1 * $amount, __( 'Proration Credit', 'rcp' ), false, true );

	rcp_log( sprintf( 'Adding %.2f proration credits to registration for user #%d.', $amount, get_current_user_id() ) );
}
add_action( 'rcp_registration_init', 'rcp_add_prorate_fee' );

/**
 * Add message to checkout specifying proration credit
 *
 * @since 2.5
 * @return void
 */
function rcp_add_prorate_message() {
	$upgrade_paths = rcp_get_upgrade_paths( get_current_user_id() );
	$has_upgrade   = false;

	/*
	 * The proration message should only be shown if the user has at least one upgrade
	 * option available where the price is greater than $0.
	 */
	if ( ! empty( $upgrade_paths ) ) {
		foreach ( $upgrade_paths  as $subscription_level ) {
			if ( $subscription_level->id != rcp_get_subscription_id() && ( $subscription_level->price > 0 || $subscription_level->fee > 0 ) ) {
				$has_upgrade = true;
				break;
			}
		}
	}

	if ( ( ! $amount = rcp_get_member_prorate_credit() ) || ( ! $has_upgrade ) ) {
		return;
	}

	$prorate_message = sprintf( '<p>%s</p>', __( 'If you upgrade or downgrade your account, the new subscription will be prorated up to %s for the first payment. Prorated prices are shown below.', 'rcp' ) );

	printf( apply_filters( 'rcp_registration_prorate_message', $prorate_message ), esc_html( rcp_currency_filter( $amount ) ) );
}
add_action( 'rcp_before_subscription_form_fields', 'rcp_add_prorate_message' );

/**
 * Removes the reminder sent flags when the member's status is set to active.
 * This allows the reminders to be re-sent for the next subscription period.
 *
 * @param string     $status     User's membership status.
 * @param int        $user_id    ID of the user.
 * @param string     $old_status Old status from before the update.
 * @param RCP_Member $member     Member object.
 *
 * @since 2.5.5
 * @return void
 */
function rcp_remove_expiring_soon_email_sent_flag( $status, $user_id, $old_status, $member ) {

	if ( 'active' !== $status ) {
		return;
	}

	global $wpdb;

	$query = $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $user_id, '_rcp_reminder_sent_' . absint( $member->get_subscription_id() ) . '_%' );
	$wpdb->query( $query );

}
add_action( 'rcp_set_status', 'rcp_remove_expiring_soon_email_sent_flag', 10, 4 );

/**
 * Trigger email verification during registration.
 *
 * @uses rcp_send_email_verification()
 *
 * @param array $posted  Posted form data.
 * @param int   $user_id ID of the user making this registration.
 * @param float $price   Price of the subscription level.
 *
 * @return void
 */
function rcp_set_email_verification_flag( $posted, $user_id, $price ) {

	global $rcp_options;

	$require_verification = isset( $rcp_options['email_verification'] ) ? $rcp_options['email_verification'] : 'off';
	$required             = in_array( $require_verification, array( 'free', 'all' ) );

	// Not required if this is a paid registration and email verification is required for free only.
	if( $price > 0 && 'free' == $require_verification ) {
		$required = false;
	}

	// Not required if they've already had a subscription level.
	// This prevents email verification from popping up for old users on upgrades/downgrades/renewals.
	if( get_user_meta( $user_id, '_rcp_old_subscription_id', true ) ) {
		$required = false;
	}

	// Bail if verification not required.
	if( ! apply_filters( 'rcp_require_email_verification', $required, $posted, $user_id, $price ) ) {
		return;
	}

	// Email verification already completed.
	if( get_user_meta( $user_id, 'rcp_email_verified', true ) ) {
		return;
	}

	// Add meta flag to indicate they're pending email verification.
	update_user_meta( $user_id, 'rcp_pending_email_verification', strtolower( md5( uniqid() ) ) );

	// Send email.
	rcp_send_email_verification( $user_id );

}
add_action( 'rcp_form_processing', 'rcp_set_email_verification_flag', 10, 3 );

/**
 * Remove subscription data if registration payment fails. Includes:
 *
 *  - Update pending payment status to "Failed"
 *
 * @param RCP_Payment_Gateway $gateway
 *
 * @since  2.8
 * @return void
 */
function rcp_remove_subscription_data_on_failure( $gateway ) {

	// Mark the pending payment as failed.
	if( ! empty( $gateway->user_id ) && is_object( $gateway->payment ) ) {

		/**
		 * @var RCP_Payments $rcp_payments_db
		 */
		global $rcp_payments_db;

		$rcp_payments_db->update( $gateway->payment->id, array( 'status' => 'failed' ) );

	}

	// Log error.
	rcp_log( sprintf( '%s registration failed for user #%d. Error message: %s', rcp_get_gateway_name_from_object( $gateway ), $gateway->user_id, $gateway->error_message ) );

}
add_action( 'rcp_registration_failed', 'rcp_remove_subscription_data_on_failure' );

/**
 * Complete a registration when a payment is completed by updating the following:
 *
 *      - Add discount code to member's profile (if applicable).
 *      - Increase discount code usage (if applicable).
 *      - Mark as trialing (if applicable).
 *      - Remove the role granted by the previous subscription level and apply new one.
 *
 * @uses rcp_add_user_to_subscription()
 *
 * @param int    $payment_id ID of the payment being completed.
 *
 * @since 2.9
 * @return void
 */
function rcp_complete_registration( $payment_id ) {

	/**
	 * @var RCP_Payments $rcp_payments_db
	 */
	global $rcp_payments_db;

	$payment             = $rcp_payments_db->get_payment( $payment_id );
	$member              = new RCP_Member( $payment->user_id );
	$pending_payment_id  = $member->get_pending_payment_id();

	// This doesn't correspond to the most recent registration - bail.
	if ( empty( $pending_payment_id ) || $pending_payment_id != $payment_id ) {
		return;
	}

	rcp_log( sprintf( 'Completing registration for member #%d via payment #%d.', $member->ID, $pending_payment_id ) );

	$subscription_id = $payment->object_id;
	$subscription    = rcp_get_subscription_details( $subscription_id );

	// This updates the expiration date, status, discount code usage, role, etc.
	$args = array(
		'status'           => ( 0 == $subscription->price && 0 == $subscription->duration ) ? 'free' : 'active',
		'subscription_id'  => $subscription_id,
		'discount_code'    => $payment->discount_code,
		'recurring'        => $member->is_recurring(),
		'subscription_key' => $member->get_pending_subscription_key()
	);

	$amount = (float) $payment->amount;

	if ( empty( $amount ) && ! empty( $subscription->trial_duration ) && ! $member->is_trialing() ) {
		$args['trial_duration']      = $subscription->trial_duration;
		$args['trial_duration_unit'] = $subscription->trial_duration_unit;
	}

	rcp_add_user_to_subscription( $payment->user_id, $args );

	// Delete the pending payment record.
	delete_user_meta( $member->ID, 'rcp_pending_payment_id' );

}
add_action( 'rcp_update_payment_status_complete', 'rcp_complete_registration' );

/**
 * Register a user account as an RCP member, assign a subscription level,
 * calculate the expiration date, etc.
 *
 * @param int   $user_id ID of the user to add the subscription to.
 * @param array $args {
 *     Array of subscription arguments. Only `subscription_id` is required.
 *     @type string   $status Optional.    Status to set: free, active, cancelled, or expired. If omitted, set to free or active.
 *     @type int      $subscription_id     Required. ID number of the subscription level to give the user.
 *     @type string   $expiration          Optional. Expiration date to give the user in MySQL format. If omitted, calculated automatically.
 *     @type string   $discount_code       Optional. Name of a discount code to add to the user's profile and increment usage count.
 *     @type string   $subscription_key    Optional. Subscription key to add to the user's profile.
 *     @type int|bool $trial_duration      Optional. Only supply this to give the user a free trial.
 *     @type string   $trial_duration_unit Optional. `day`, `month`, or `year`.
 *     @type bool     $recurring           Optional. Whether or not the subscription is automatically recurring. Default is `false`.
 *     @type string   $payment_profile_id  Optional. Payment profile ID to add to the user's profile.
 * }
 *
 * @since 2.9
 * @return bool
 */
function rcp_add_user_to_subscription( $user_id, $args = array() ) {

	$defaults = array(
		'status'              => '',
		'subscription_id'     => 0,
		'expiration'          => '',    // Calculated automatically if not provided.
		'discount_code'       => '',    // To add to their profile and increase usage.
		'subscription_key'    => '',
		'trial_duration'      => false, // To set as trialing.
		'trial_duration_unit' => 'day',
	    'recurring'           => false,
	    'payment_profile_id'  => ''
	);

	$args = wp_parse_args( $args, $defaults );

	// Subscription ID is required.
	if ( empty( $args['subscription_id'] ) ) {
		return false;
	}

	$rcp_levels_db       = new RCP_Levels();
	$member              = new RCP_Member( $user_id );
	$old_subscription_id = get_user_meta( $member->ID, '_rcp_old_subscription_id', true );
	$subscription_level  = $rcp_levels_db->get_level( $args['subscription_id'] );

	// Invalid subscription level - bail.
	if ( empty( $subscription_level ) ) {
		return false;
	}

	/*
	 * Set the subscription ID and key
	 */
	$member->set_subscription_id( $args['subscription_id'] );

	if ( ! empty( $args['subscription_key'] ) ) {
		$member->set_subscription_key( $args['subscription_key'] );
	}

	/*
	 * Expiration date
	 * Calculate it if not provided.
	 */
	$expiration = $args['expiration'];
	if ( empty( $expiration ) ) {
		$force_now = $args['recurring'];

		if ( ! $force_now && $old_subscription_id != $subscription_level->id ) {
			$force_now = true;
		}

		$expiration = $member->calculate_expiration( $force_now, $args['trial_duration'] );
	}
	$member->set_expiration_date( $expiration );

	// Delete pending expiration date (used by Authorize.net). We don't need it anymore after this point.
	delete_user_meta( $member->ID, 'rcp_pending_expiration_date' );

	/*
	 * Discount code
	 * Apply the discount code to the member and increase the number of uses.
	 */
	if ( ! empty( $args['discount_code'] ) ) {
		$discounts    = new RCP_Discounts();
		$discount_obj = $discounts->get_by( 'code', $args['discount_code'] );

		// Record the usage of this discount code
		$discounts->add_to_user( $member->ID, $args['discount_code'] );

		// Increase the usage count for the code
		$discounts->increase_uses( $discount_obj->id );
	}

	/*
	 * Update the member's role.
	 * Remove the user's old role and apply the new one.
	 */
	$old_role = get_option( 'default_role', 'subscriber' );

	if ( $old_subscription_id ) {
		$old_level = $rcp_levels_db->get_level( $old_subscription_id );
		$old_role  = ! empty( $old_level->role ) ? $old_level->role : $old_role;
	}

	$member->remove_role( $old_role );

	// Set the user's new role
	$role = ! empty( $subscription_level->role ) ? $subscription_level->role : get_option( 'default_role', 'subscriber' );
	$member->add_role( apply_filters( 'rcp_default_user_level', $role, $subscription_level->id ) );

	/*
	 * Flag the user as trialling. This needs to be done before setting the status in order
	 * to trigger the correct activation email.
	 */
	if ( ( 0 == $subscription_level->price && $subscription_level->duration > 0 ) || ( ! empty( $args['trial_duration'] )&& ! $member->has_trialed() ) ) {
		update_user_meta( $member->ID, 'rcp_has_trialed', 'yes' );
		update_user_meta( $member->ID, 'rcp_is_trialing', 'yes' );
	}

	/*
	 * Set the status
	 * Determine it automatically if not provided.
	 */
	$status = $args['status'];
	if ( empty( $status ) ) {

		if ( 0 == $subscription_level->price && 0 == $subscription_level->duration ) {
			$status = 'free';
		} else {
			$status = 'active';
		}

	}
	$member->set_status( $status );

	/*
	 * All other data
	 */

	// Set join date for this subscription.
	$joined_date = $member->get_joined_date( $args['subscription_id'] );
	if ( empty( $joined_date ) ) {
		$member->set_joined_date( '', $subscription_level->id );
	}

	// Recurring.
	$member->set_recurring( $args['recurring'] );

	// Payment profile ID
	if ( ! empty( $args['payment_profile_id'] ) ) {
		$member->set_payment_profile_id( $args['payment_profile_id'] );
	}

	/**
	 * Registration successful! Hook into this action if you need to execute code
	 * after a successful registration, but not during an automatic renewal.
	 *
	 * @var RCP_Member $member
	 * @since 2.9
	 */
	do_action( 'rcp_successful_registration', $member );

	return true;

}

/**
 * Automatically add new users to a subscription level if enabled
 *
 * @param int $user_id ID of the newly created user.
 *
 * @since 2.9
 * @return void
 */
function rcp_user_register_add_subscription_level( $user_id ) {

	global $rcp_options;

	if ( empty( $rcp_options['auto_add_users'] ) ) {
		return;
	}

	$level_id = absint( $rcp_options['auto_add_users_level'] );

	if ( empty( $level_id ) ) {
		return;
	}

	// Don't run if we're on the registration form.
	if ( did_action( 'rcp_form_errors' ) ) {
		return;
	}

	rcp_add_user_to_subscription( $user_id, array(
		'subscription_id' => $level_id
	) );

	update_user_meta( $user_id, 'rcp_signup_method', 'manual' );

}
add_action( 'user_register', 'rcp_user_register_add_subscription_level' );
