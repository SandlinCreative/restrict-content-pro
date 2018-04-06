<?php
/**
 * RCP Member class
 *
 * @package     Restrict Content Pro
 * @subpackage  Classes/Member
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.1
 */

class RCP_Member extends WP_User {

	/**
	 * Retrieves the status of the member
	 *
	 * @access  public
	 * @since   2.1
	 * @return  string
	 */
	public function get_status() {

		$status = get_user_meta( $this->ID, 'rcp_status', true );

		// double check that the status and expiration match. Update if needed
		if( in_array( $status, array( 'active', 'cancelled' ) ) && $this->is_expired() ) {

			rcp_log( sprintf( 'Expiring member %d via get_status() method. Expiration Date: %s; Subscription Level: %s', $this->ID, $this->get_expiration_date(), $this->get_subscription_name() ) );

			$status = 'expired';
			$this->set_status( $status );

		}

		if( empty( $status ) ) {
			$status = 'free';
		}

		return apply_filters( 'rcp_member_get_status', $status, $this->ID, $this );

	}

	/**
	 * Sets the status of a member
	 *
	 * @param  string $new_status New status to set.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool Whether or not the status was updated.
	 */
	public function set_status( $new_status = '' ) {

		$ret        = false;
		$old_status = get_user_meta( $this->ID, 'rcp_status', true );

		/**
		 * Filters the value of the status set on the member.
		 *
		 * @since 2.9.1
		 *
		 * @param string $new_status The new status to assign to the member.
		 * @param int    $member_id The member ID.
		 * @param string $old_status The previous status assigned to the member.
		 * @param object $this Current instance of the RCP_Member object.
		 */
		$new_status = apply_filters( 'rcp_set_status_value', $new_status, $this->ID, $old_status, $this );

		if( ! empty( $new_status ) ) {

			update_user_meta( $this->ID, 'rcp_status', $new_status );

			if( 'expired' != $new_status ) {
				delete_user_meta( $this->ID, '_rcp_expired_email_sent');
			}

			if( 'expired' == $new_status || 'cancelled' == $new_status ) {
				$this->set_recurring( false );
			}

			do_action( 'rcp_set_status', $new_status, $this->ID, $old_status, $this );
			do_action( "rcp_set_status_{$new_status}", $this->ID, $old_status, $this );

			// Record the status change
			if( $old_status && ( $old_status != $new_status ) ) {
				rcp_log( sprintf( 'Member #%d status changed from %s to %s.', $this->ID, $old_status, $new_status ) );
				$this->add_note( sprintf( __( 'Member\'s status changed from %s to %s', 'rcp' ), $old_status, $new_status ) );
			}

			if ( ! $old_status ) {
				rcp_log( sprintf( 'Member #%d status set to %s.', $this->ID, $new_status ) );
				$this->add_note( sprintf( __( 'Member\'s status set to %s', 'rcp' ), $new_status ) );
			}

			$ret = true;
		}

		return $ret;

	}

	/**
	 * Retrieves the expiration date of the member
	 *
	 * @param  bool $formatted Whether or not the returned value should be formatted.
	 * @param  bool $pending   Whether or not to check the pending expiration date.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  string
	 */
	public function get_expiration_date( $formatted = true, $pending = true ) {

		if( $pending ) {

			$expiration = get_user_meta( $this->ID, 'rcp_pending_expiration_date', true );

		}

		if( empty( $expiration ) || ! $pending ) {

			$expiration = get_user_meta( $this->ID, 'rcp_expiration', true );

		}

		if( $expiration ) {
			$expiration = $expiration != 'none' ? $expiration : 'none';
		}

		if( $formatted && 'none' != $expiration ) {
			$expiration = date_i18n( get_option( 'date_format' ), strtotime( $expiration, current_time( 'timestamp' ) ) );
		}

		return apply_filters( 'rcp_member_get_expiration_date', $expiration, $this->ID, $this, $formatted, $pending );

	}

	/**
	 * Retrieves the expiration date of the member as a timestamp
	 *
	 * @access  public
	 * @since   2.1
	 * @return  int|false
	 */
	public function get_expiration_time() {

		$expiration = $this->get_expiration_date( false );
		$timestamp  = ( $expiration && 'none' != $expiration ) ? strtotime( $expiration, current_time( 'timestamp' ) ) : false;

		return apply_filters( 'rcp_member_get_expiration_time', $timestamp, $this->ID, $this );

	}

	/**
	 * Sets the expiration date for a member
	 *
	 * Should be passed as a MYSQL date string.
	 *
	 * @param   string $new_date New date as a MySQL date string.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool Whether or not the expiration date was updated.
	 */
	public function set_expiration_date( $new_date = '' ) {

		$ret      = false;
		$old_date = $this->get_expiration_date( false, false );

		// Return early if there's no change in expiration date
		if ( empty( $new_date ) || ( ! empty( $old_date ) && ( $old_date == $new_date ) ) ) {
			return $ret;
		}

		if ( update_user_meta( $this->ID, 'rcp_expiration', $new_date ) ) {

			// Record the status change
			if ( empty( $old_date ) ) {

				$note = sprintf( __( 'Member\'s expiration set to %s', 'rcp' ), $new_date );

			} else {

				$note = sprintf( __( 'Member\'s expiration changed from %s to %s', 'rcp' ), $old_date, $new_date );

			}

		} else {
			// If update_user_meta() fails for some reason.
			$note = sprintf( __( 'Member\'s expiration date failed to be updated to %s', 'rcp' ), $new_date );
		}

		$this->add_note( $note );

		delete_user_meta( $this->ID, 'rcp_pending_expiration_date' );

		do_action( 'rcp_set_expiration_date', $this->ID, $new_date, $old_date );

		$ret = true;

		return $ret;

	}

	/**
	 * Calculates the new expiration date for a member
	 *
	 * @param   bool $force_now Whether or not to force an update.
	 * @param   bool $trial     Whether or not this is for a free trial.
	 *
	 * @access  public
	 * @since   2.4
	 * @return  String Date in Y-m-d H:i:s format or "none" if is a lifetime member
	 */
	public function calculate_expiration( $force_now = false, $trial = false ) {

		// Authorize.net still uses this.
		$pending_exp = get_user_meta( $this->ID, 'rcp_pending_expiration_date', true );

		if( ! empty( $pending_exp ) ) {
			return $pending_exp;
		}

		// Get the member's current expiration date
		$expiration = $this->get_expiration_time();

		// Determine what date to use as the start for the new expiration calculation
		if( ! $force_now && $expiration > current_time( 'timestamp' ) && ! $this->is_expired() && $this->get_status() == 'active' ) {

			$base_timestamp = $expiration;

		} else {

			$base_timestamp = current_time( 'timestamp' );

		}

		$subscription_id = $this->get_pending_subscription_id();

		if( empty( $subscription_id ) ) {
			$subscription_id = $this->get_subscription_id();
		}

		$subscription = rcp_get_subscription_details( $subscription_id );

		if( $subscription->duration > 0 ) {

			if ( $subscription->trial_duration > 0 && $trial ) {
				$expire_timestamp  = strtotime( '+' . $subscription->trial_duration . ' ' . $subscription->trial_duration_unit . ' 23:59:59', $base_timestamp );
			} else {
				$expire_timestamp  = strtotime( '+' . $subscription->duration . ' ' . $subscription->duration_unit . ' 23:59:59', $base_timestamp );
			}

			$extension_days    = array( '29', '30', '31' );

			if( in_array( date( 'j', $expire_timestamp ), $extension_days ) && 'day' !== $subscription->duration_unit ) {

				/*
				 * Here we extend the expiration date by 1-3 days in order to account for "walking" payment dates in PayPal.
				 *
				 * See https://github.com/pippinsplugins/restrict-content-pro/issues/239
				 */

				$month = date( 'n', $expire_timestamp );

				if( $month < 12 ) {
					$month += 1;
					$year   = date( 'Y' );
				} else {
					$month  = 1;
					$year   = date( 'Y' ) + 1;
				}

				$timestamp  = mktime( 0, 0, 0, $month, 1, $year );

				$expiration = date( 'Y-m-d 23:59:59', $timestamp );
			}

			$expiration = date( 'Y-m-d 23:59:59', $expire_timestamp );

		} else {

			$expiration = 'none';

		}

		return apply_filters( 'rcp_member_calculated_expiration', $expiration, $this->ID, $this );

	}

	/**
	 * Sets the joined date for a member
	 *
	 * @param  string $date            Join date in MySQL date format.
	 * @param  int    $subscription_id ID of the subscription level.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public function set_joined_date( $date = '', $subscription_id = 0 ) {

		if( empty( $date ) ) {
			$date = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		}

		if( empty( $subscription_id ) ) {
			$subscription_id = $this->get_subscription_id();
		}

		$ret = update_user_meta( $this->ID, 'rcp_joined_date_' . $subscription_id, $date );

		do_action( 'rcp_set_joined_date', $this->ID, $date, $this );

		return $ret;

	}

	/**
	 * Retrieves the joined date for a subscription
	 *
	 * @param   int $subscription_id ID of the subscription level.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  string Joined date
	 */
	public function get_joined_date( $subscription_id = 0 ) {

		if( empty( $subscription_id ) ) {
			$subscription_id = $this->get_subscription_id();
		}

		$date = get_user_meta( $this->ID, 'rcp_joined_date_' . $subscription_id, true );

		// Joined dates were not stored until RCP 2.6. For older accounts, look up first payment record.
		if( empty( $date ) ) {

			$sub_name = rcp_get_subscription_name( $subscription_id );
			$args     = array( 'user_id' => $this->ID, 'subscription' => $sub_name, 'order' => 'ASC', 'number' => 1 );
			$payments = new RCP_Payments;
			$payments = $payments->get_payments( $args );

			if( $payments ) {
				$payment = reset( $payments );
				$date    = $payment->date;
				$this->set_joined_date( $date, $subscription_id );
			}
		}

		return apply_filters( 'rcp_get_joined_date', $date, $this->ID, $subscription_id, $this );

	}

	/**
	 * Sets the renewed date for a member
	 *
	 * @param   string $date Renewed date in MySQL format.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public function set_renewed_date( $date = '' ) {

		if( get_user_meta( $this->ID, '_rcp_new_subscription', true ) ) {
			return; // This is a new subscription so do not set anything
		}

		if( empty( $date ) ) {
			$date = date( 'Y-m-d H:i:s' );
		}

		$ret = update_user_meta( $this->ID, 'rcp_renewed_date_' . $this->get_subscription_id(), $date );

		do_action( 'rcp_set_renewed_date', $this->ID, $date, $this );

		return $ret;

	}

	/**
	 * Retrieves the renewed date for a subscription
	 *
	 * @param   int $subscription_id ID of the subscription level.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  string Renewed date
	*/
	public function get_renewed_date( $subscription_id = 0 ) {

		if( empty( $subscription_id ) ) {
			$subscription_id = $this->get_subscription_id();
		}

		$date = get_user_meta( $this->ID, 'rcp_renewed_date_' . $this->get_subscription_id(), true );

		return apply_filters( 'rcp_get_renewed_date', $date, $this->ID, $subscription_id, $this );

	}

	/**
	 * Renews a member's membership by updating status and expiration date
	 *
	 * Does NOT handle payment processing for the renewal. This should be called after receiving a renewal payment.
	 *
	 * @param   bool   $recurring  Whether or not the membership is recurring.
	 * @param   string $status     Membership status.
	 * @param   string $expiration Membership expiration date in MySQL format.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  void|false
	 */
	public function renew( $recurring = false, $status = 'active', $expiration = '' ) {

		rcp_log( sprintf( 'Starting membership renewal for user #%d. Subscription ID: %d; Current Expiration Date: %s', $this->ID, $this->get_subscription_id(), $this->get_expiration_date() ) );

		$subscription_id = $this->get_pending_subscription_id();

		if( empty( $subscription_id ) ) {
			$subscription_id = $this->get_subscription_id();
		}

		if( ! $subscription_id ) {
			return false;
		}

		$subscription_level = rcp_get_subscription_details( $subscription_id );

		if ( ! $expiration ) {
			$expiration   = apply_filters( 'rcp_member_renewal_expiration', $this->calculate_expiration(), $subscription_level, $this->ID );
		}

		do_action( 'rcp_member_pre_renew', $this->ID, $expiration, $this );

		$this->set_expiration_date( $expiration );

		if( ! empty( $status ) ) {
			$this->set_status( $status );
		}

		$this->set_recurring( $recurring );
		$this->set_renewed_date();

		// Add the role if the user doesn't already have it.
		$role = ! empty( $subscription_level->role ) ? $subscription_level->role : get_option( 'default_role', 'subscriber' );
		if ( ! in_array( $role, $this->roles ) ) {
			$this->add_role( $role );
		}

		delete_user_meta( $this->ID, '_rcp_expired_email_sent' );

		do_action( 'rcp_member_post_renew', $this->ID, $expiration, $this );

		rcp_log( sprintf( 'Completed membership renewal for user #%d. Subscription ID: %d; New Expiration Date: %s; New Status: %s', $this->ID, $subscription_id, $expiration, $this->get_status() ) );

	}

	/**
	 * Sets a member's membership as cancelled by updating status
	 *
	 * Does NOT handle actual cancellation of subscription payments, that is done in rcp_process_member_cancellation(). This should be called after a member is successfully cancelled.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  void
	 */
	public function cancel() {

		if( 'cancelled' === $this->get_status() ) {
			return; // Bail if already set to cancelled
		}

		do_action( 'rcp_member_pre_cancel', $this->ID, $this );

		$this->set_status( 'cancelled' );

		do_action( 'rcp_member_post_cancel', $this->ID, $this );

	}

	/**
	 * Determines if the member can cancel their subscription on site
	 *
	 * @access  public
	 * @since   2.7.2
	 * @return  bool True if the member can cancel, false if not.
	 */
	public function can_cancel() {

		$ret = false;

		if( $this->is_recurring() && $this->is_active() && 'cancelled' !== $this->get_status() ) {

			// Check if the member is a Stripe customer
			if( rcp_is_stripe_subscriber( $this->ID ) ) {

				$ret = true;

			} elseif ( rcp_is_paypal_subscriber( $this->ID ) && rcp_has_paypal_api_access() ) {

				$ret = true;

			} elseif ( rcp_is_2checkout_subscriber( $this->ID ) && defined( 'TWOCHECKOUT_ADMIN_USER' ) && defined( 'TWOCHECKOUT_ADMIN_PASSWORD' ) ) {

				$ret = true;

			} elseif ( rcp_is_authnet_subscriber( $this->ID ) && rcp_has_authnet_api_access() ) {

				$ret = true;

			} elseif ( rcp_is_braintree_subscriber( $this->ID ) && rcp_has_braintree_api_access() ) {

				$ret = true;

			}

		}

		return apply_filters( 'rcp_member_can_cancel', $ret, $this->ID );

	}

	/**
	 * Cancel the member's payment profile
	 *
	 * @param bool $set_status Whether or not to update the status to 'cancelled'.
	 *
	 * @access  public
	 * @since   2.7.2
	 * @return  bool Whether or not the cancellation was successful.
	 */
	public function cancel_payment_profile( $set_status = true ) {

		global $rcp_options;

		$success = false;

		if( ! $this->can_cancel() ) {
			rcp_log( sprintf( 'Unable to cancel payment profile for member #%d.', $this->ID ) );

			return $success;
		}

		if( rcp_is_stripe_subscriber( $this->ID ) ) {

			if( ! class_exists( 'Stripe\Stripe' ) ) {
				require_once RCP_PLUGIN_DIR . 'includes/libraries/stripe/init.php';
			}

			if ( rcp_is_sandbox() ) {
				$secret_key = trim( $rcp_options['stripe_test_secret'] );
			} else {
				$secret_key = trim( $rcp_options['stripe_live_secret'] );
			}

			\Stripe\Stripe::setApiKey( $secret_key );

			try {

				$subscription_id = $this->get_merchant_subscription_id();
				$customer        = \Stripe\Customer::retrieve( $this->get_payment_profile_id() );

				if( ! empty( $subscription_id ) ) {

					$customer->subscriptions->retrieve( $subscription_id )->cancel( array( 'at_period_end' => false ) );

				} else {

					$customer->cancelSubscription( array( 'at_period_end' => false ) );

				}


				$success = true;

			} catch (\Stripe\Error\InvalidRequest $e) {

				// Invalid parameters were supplied to Stripe's API
				$body = $e->getJsonBody();
				$err  = $body['error'];

				$error = "<h4>" . __( 'An error occurred', 'rcp' ) . "</h4>";
				if( isset( $err['code'] ) ) {
					$error .= "<p>" . __( 'Error code:', 'rcp' ) . " " . $err['code'] ."</p>";
				}
				$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
				$error .= "<p>Message: " . $err['message'] . "</p>";

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			} catch (\Stripe\Error\Authentication $e) {

				// Authentication with Stripe's API failed
				// (maybe you changed API keys recently)

				$body = $e->getJsonBody();
				$err  = $body['error'];

				$error = "<h4>" . __( 'An error occurred', 'rcp' ) . "</h4>";
				if( isset( $err['code'] ) ) {
					$error .= "<p>" . __( 'Error code:', 'rcp' ) . " " . $err['code'] ."</p>";
				}
				$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
				$error .= "<p>Message: " . $err['message'] . "</p>";

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			} catch (\Stripe\Error\ApiConnection $e) {

				// Network communication with Stripe failed

				$body = $e->getJsonBody();
				$err  = $body['error'];

				$error = "<h4>" . __( 'An error occurred', 'rcp' ) . "</h4>";
				if( isset( $err['code'] ) ) {
					$error .= "<p>" . __( 'Error code:', 'rcp' ) . " " . $err['code'] ."</p>";
				}
				$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
				$error .= "<p>Message: " . $err['message'] . "</p>";

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			} catch (\Stripe\Error\Base $e) {

				// Display a very generic error to the user

				$body = $e->getJsonBody();
				$err  = $body['error'];

				$error = "<h4>" . __( 'An error occurred', 'rcp' ) . "</h4>";
				if( isset( $err['code'] ) ) {
					$error .= "<p>" . __( 'Error code:', 'rcp' ) . " " . $err['code'] ."</p>";
				}
				$error .= "<p>Status: " . $e->getHttpStatus() ."</p>";
				$error .= "<p>Message: " . $err['message'] . "</p>";

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			} catch (Exception $e) {

				// Something else happened, completely unrelated to Stripe

				$error = "<h4>" . __( 'An error occurred', 'rcp' ) . "</h4>";
				$error .= print_r( $e, true );

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			}

		} elseif( rcp_is_paypal_subscriber( $this->ID ) ) {

			if( rcp_has_paypal_api_access() && $this->get_payment_profile_id() ) {

				// Set PayPal API key credentials.
				$api_username  = rcp_is_sandbox() ? 'test_paypal_api_username' : 'live_paypal_api_username';
				$api_password  = rcp_is_sandbox() ? 'test_paypal_api_password' : 'live_paypal_api_password';
				$api_signature = rcp_is_sandbox() ? 'test_paypal_api_signature' : 'live_paypal_api_signature';
				$api_endpoint  = rcp_is_sandbox() ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';

				$args = array(
					'USER'      => trim( $rcp_options[ $api_username ] ),
					'PWD'       => trim( $rcp_options[ $api_password ] ),
					'SIGNATURE' => trim( $rcp_options[ $api_signature ] ),
					'VERSION'   => '124',
					'METHOD'    => 'ManageRecurringPaymentsProfileStatus',
					'PROFILEID' => $this->get_payment_profile_id(),
					'ACTION'    => 'Cancel'
				);

				$error_msg = '';
				$request   = wp_remote_post( $api_endpoint, array( 'body' => $args, 'timeout' => 30, 'httpversion' => '1.1' ) );

				if ( is_wp_error( $request ) ) {

					$success   = false;
					$error_msg = $request->get_error_message();

				} else {

					$body    = wp_remote_retrieve_body( $request );
					$code    = wp_remote_retrieve_response_code( $request );
					$message = wp_remote_retrieve_response_message( $request );

					if( is_string( $body ) ) {
						wp_parse_str( $body, $body );
					}

					if( 200 !== (int) $code ) {
						$success = false;
					}

					if( 'OK' !== $message ) {
						$success = false;
					}

					if( isset( $body['ACK'] ) && 'success' === strtolower( $body['ACK'] ) ) {
						$success = true;
					} else {
						$success = false;
						if( isset( $body['L_LONGMESSAGE0'] ) ) {
							$error_msg = $body['L_LONGMESSAGE0'];
						}
					}

				}

				if( ! $success ) {
					wp_die( sprintf( __( 'There was a problem cancelling the subscription, please contact customer support. Error: %s', 'rcp' ), $error_msg ), array( 'response' => 400 ) );
				}

			}

		} elseif( rcp_is_2checkout_subscriber( $this->ID ) ) {

			$cancelled = rcp_2checkout_cancel_member( $this->ID );

			if( is_wp_error( $cancelled ) ) {

				wp_die( $cancelled->get_error_message(), __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			} else {
				$success = true;
			}
		} elseif( rcp_is_authnet_subscriber( $this->ID ) ) {

			$cancelled = rcp_authnet_cancel_member( $this->ID );

			if( is_wp_error( $cancelled ) ) {

				wp_die( $cancelled->get_error_message(), __( 'Error', 'rcp' ), array( 'response' => 401 ) );

			} else {
				$success = true;
			}
		} elseif ( rcp_is_braintree_subscriber( $this->ID ) ) {

			$cancelled = rcp_braintree_cancel_member( $this->ID );

			if ( is_wp_error( $cancelled ) ) {
				wp_die( $cancelled->get_error_message(), __( 'Error', 'rcp' ), array( 'response' => 401 ) );
			} else {
				$success = true;
			}
		}

		if( $success && $set_status ) {
			$this->cancel();
		}

		if( $success ) {
			rcp_log( sprintf( 'Payment profile successfully cancelled for member #%d.', $this->ID ) );
		} else {
			rcp_log( sprintf( 'Failed cancelling payment profile for member #%d.', $this->ID ) );
		}

		return $success;

	}

	/**
	 * Retrieves the profile ID of the member.
	 *
	 * This is used by payment gateways to store customer IDs and other identifiers for payment profiles
	 *
	 * @access  public
	 * @since   2.1
	 * @return  string
	*/
	public function get_payment_profile_id() {

		$profile_id = get_user_meta( $this->ID, 'rcp_payment_profile_id', true );

		return apply_filters( 'rcp_member_get_payment_profile_id', $profile_id, $this->ID, $this );

	}

	/**
	 * Sets the payment profile ID for a member
	 *
	 * This is used by payment gateways to store customer IDs and other identifiers for payment profiles.
	 *
	 * @param  string $profile_id Payment profile ID.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  void
	*/
	public function set_payment_profile_id( $profile_id = '' ) {

		$profile_id = trim( $profile_id );

		do_action( 'rcp_member_pre_set_profile_payment_id', $this->ID, $profile_id, $this );

		update_user_meta( $this->ID, 'rcp_payment_profile_id', $profile_id );

		do_action( 'rcp_member_post_set_profile_payment_id', $this->ID, $profile_id, $this );

	}

	/**
	 * Retrieves the subscription ID of the member from the merchant processor.
	 *
	 * This is used by payment gateways to retrieve the ID of the subscription.
	 *
	 * @access  public
	 * @since   2.5
	 * @return  string
	 */
	public function get_merchant_subscription_id() {

		$subscription_id = get_user_meta( $this->ID, 'rcp_merchant_subscription_id', true );

		return apply_filters( 'rcp_member_get_merchant_subscription_id', $subscription_id, $this->ID, $this );

	}

	/**
	 * Sets the payment profile ID for a member
	 *
	 * This is used by payment gateways to store the ID of the subscription.
	 *
	 * @param  string $subscription_id
	 *
	 * @access  public
	 * @since   2.5
	 * @return  void
	 */
	public function set_merchant_subscription_id( $subscription_id = '' ) {

		$subscription_id = trim( $subscription_id );

		do_action( 'rcp_member_pre_set_merchant_subscription_id', $this->ID, $subscription_id, $this );

		update_user_meta( $this->ID, 'rcp_merchant_subscription_id', $subscription_id );

		do_action( 'rcp_member_post_set_merchant_subscription_id', $this->ID, $subscription_id, $this );

	}

	/**
	 * Retrieves the subscription ID of the member
	 *
	 * @access  public
	 * @since   2.1
	 * @return  int|false
	 */
	public function get_subscription_id() {

		$subscription_id = get_user_meta( $this->ID, 'rcp_subscription_level', true );

		return apply_filters( 'rcp_member_get_subscription_id', $subscription_id, $this->ID, $this );

	}

	/**
	 * Set member's subscription ID
	 *
	 * @param int $subscription_id ID of the subscription level to set.
	 *
	 * @access public
	 * @since  2.7.4
	 * @return void
	 */
	public function set_subscription_id( $subscription_id ) {

		do_action( 'rcp_member_pre_set_subscription_id', $subscription_id, $this->ID, $this );

		update_user_meta( $this->ID, 'rcp_subscription_level', $subscription_id );

		do_action( 'rcp_member_post_set_subscription_id', $subscription_id, $this->ID, $this );

	}

	/**
	 * Retrieves the pending subscription ID of the member
	 *
	 * @access  public
	 * @since   2.4.12
	 * @return  int|false
	 */
	public function get_pending_subscription_id() {

		/**
		 * @var RCP_Payments $rcp_payments_db
		 */
		global $rcp_payments_db;

		$pending_level_id = get_user_meta( $this->ID, 'rcp_pending_subscription_level', true );
		$pending_payment  = $this->get_pending_payment_id();

		if ( ! empty( $pending_payment ) ) {
			$payment          = $rcp_payments_db->get_payment( absint( $pending_payment ) );
			$pending_level_id = $payment->object_id;
		}

		return $pending_level_id;

	}

	/**
	 * Retrieves the subscription key of the member
	 *
	 * @access  public
	 * @since   2.1
	 * @return  string
	 */
	public function get_subscription_key() {

		$subscription_key = get_user_meta( $this->ID, 'rcp_subscription_key', true );

		return apply_filters( 'rcp_member_get_subscription_key', $subscription_key, $this->ID, $this );

	}

	/**
	 * Set member's subscription key
	 *
	 * @param string $subscription_key Key to set. Automatically generated if omitted.
	 *
	 * @access public
	 * @since  2.7.4
	 * @return void
	 */
	public function set_subscription_key( $subscription_key = '' ) {

		if( empty( $subscription_key ) ) {
			$subscription_key = rcp_generate_subscription_key();
		}

		do_action( 'rcp_member_pre_set_subscription_key', $subscription_key, $this->ID, $this );

		update_user_meta( $this->ID, 'rcp_subscription_key', $subscription_key );

		do_action( 'rcp_member_post_set_subscription_key', $subscription_key, $this->ID, $this );

	}

	/**
	 * Retrieves the pending subscription key of the member
	 *
	 * @access  public
	 * @since   2.4.12
	 * @return  string
	 */
	public function get_pending_subscription_key() {

		/**
		 * @var RCP_Payments $rcp_payments_db
		 */
		global $rcp_payments_db;

		$pending_key      = get_user_meta( $this->ID, 'rcp_pending_subscription_key', true );
		$pending_payment  = $this->get_pending_payment_id();

		if ( ! empty( $pending_payment ) ) {
			$payment     = $rcp_payments_db->get_payment( absint( $pending_payment ) );
			$pending_key = $payment->subscription_key;
		}

		return $pending_key;

	}

	/**
	 * Retrieves the current subscription name of the member
	 *
	 * @uses    rcp_get_subscription_name()
	 *
	 * @access  public
	 * @since   2.1
	 * @return  string
	 */
	public function get_subscription_name() {

		$sub_name = rcp_get_subscription_name( $this->get_subscription_id() );

		return apply_filters( 'rcp_member_get_subscription_name', $sub_name, $this->ID, $this );

	}

	/**
	 * Retrieves the pending subscription name of the member.
	 *
	 * @uses    rcp_get_subscription_name()
	 *
	 * @access  public
	 * @since   2.4.12
	 * @return  string
	 */
	public function get_pending_subscription_name() {

		$sub_name = rcp_get_subscription_name( $this->get_pending_subscription_id() );

		return apply_filters( 'rcp_member_get_subscription_name', $sub_name, $this->ID, $this );

	}

	/**
	 * Retrieves all payments belonging to the member
	 *
	 * @access  public
	 * @since   2.1
	 * @return  array|null Array of objects.
	 */
	public function get_payments() {

		$payments = new RCP_Payments;
		$payments = $payments->get_payments( array( 'user_id' => $this->ID ) );

		return apply_filters( 'rcp_member_get_payments', $payments, $this->ID, $this );
	}

	/**
	 * Retrieves the ID number of the currently pending payment.
	 *
	 * @access public
	 * @since 2.9
	 * @return int|bool ID of the pending payment or false if none.
	 */
	public function get_pending_payment_id() {
		return get_user_meta( $this->ID, 'rcp_pending_payment_id', true );
	}

	/**
	 * Retrieves the notes on a member
	 *
	 * @access  public
	 * @since   2.1
	 * @return  string
	 */
	public function get_notes() {

		$notes = get_user_meta( $this->ID, 'rcp_notes', true );

		return apply_filters( 'rcp_member_get_notes', $notes, $this->ID, $this );

	}

	/**
	 * Adds a new note to a member
	 *
	 * @param   string $note Note to add to the member.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  true
	 */
	public function add_note( $note = '' ) {

		$notes = $this->get_notes();

		if( empty( $notes ) ) {
			$notes = '';
		}

		$note = apply_filters( 'rcp_member_pre_add_note', $note, $this->ID, $this );

		$notes .= "\n\n" . date_i18n( 'F j, Y H:i:s', current_time( 'timestamp' ) ) . ' - ' . $note;

		update_user_meta( $this->ID, 'rcp_notes', wp_kses( $notes, array() ) );

		do_action( 'rcp_member_add_note', $note, $this->ID, $this );

		return true;

	}

	/**
	 * Determines if a member has an active subscription, or is cancelled but has not reached EOT
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool
	 */
	public function is_active() {

		$ret = false;

		if( user_can( $this->ID, 'manage_options' ) ) {
			$ret = true;
		} else if( ! $this->is_expired() && in_array( $this->get_status(), array( 'active', 'cancelled' ) ) ) {
			$ret = true;
		}

		return apply_filters( 'rcp_is_active', $ret, $this->ID, $this );

	}

	/**
	 * Determines if a member has a recurring subscription
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool
	 */
	public function is_recurring() {

		$ret       = false;
		$recurring = get_user_meta( $this->ID, 'rcp_recurring', true );

		if( $recurring == 'yes' ) {
			$ret = true;
		}

		return apply_filters( 'rcp_member_is_recurring', $ret, $this->ID, $this );

	}

	/**
	 * Sets whether a member is recurring
	 *
	 * @param   bool $yes True if recurring, false if not.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  void
	 */
	public function set_recurring( $yes = true ) {

		rcp_log( sprintf( 'Updating recurring status for member #%d. Previous: %s; New: %s', $this->ID, var_export( $this->is_recurring(), true ), var_export( $yes, true ) ) );

		if( $yes ) {
			update_user_meta( $this->ID, 'rcp_recurring', 'yes' );
		} else {
			delete_user_meta( $this->ID, 'rcp_recurring' );
		}

		do_action( 'rcp_member_set_recurring', $yes, $this->ID, $this );

	}

	/**
	 * Determines if the member is expired
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool
	 */
	public function is_expired() {

		$ret        = false;
		$expiration = $this->get_expiration_date( false, false );

		if( $expiration && strtotime( 'NOW', current_time( 'timestamp' ) ) > strtotime( $expiration, current_time( 'timestamp' ) ) ) {
			$ret = true;
		}

		if( $expiration == 'none' ) {
			$ret = false;
		}

		return apply_filters( 'rcp_member_is_expired', $ret, $this->ID, $this );

	}

	/**
	 * Determines if the member is currently trailing
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool
	 */
	public function is_trialing() {

		$ret      = false;
		$trialing = get_user_meta( $this->ID, 'rcp_is_trialing', true );

		if( $trialing == 'yes' && $this->is_active() ) {
			$ret = true;
		}

		// Old filter for backwards compatibility
		$ret = apply_filters( 'rcp_is_trialing', $ret, $this->ID );

		return apply_filters( 'rcp_member_is_trialing', $ret, $this->ID, $this );

	}

	/**
	 * Determines if the member has used a trial
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool
	 */
	public function has_trialed() {

		$ret = false;

		if( get_user_meta( $this->ID, 'rcp_has_trialed', true ) == 'yes' ) {
			$ret = true;
		}

		$ret = apply_filters( 'rcp_has_used_trial', $ret, $this->ID );

		return apply_filters( 'rcp_member_has_trialed', $ret, $this->ID );

	}

	/**
	 * Determines if a member is pending email verification.
	 *
	 * @access public
	 * @return bool
	 */
	public function is_pending_verification() {

		$is_pending = get_user_meta( $this->ID, 'rcp_pending_email_verification', true );

		return (bool) apply_filters( 'rcp_is_pending_email_verification', $is_pending, $this->ID, $this );

	}

	/**
	 * Confirm email verification
	 *
	 * @access public
	 * @return void
	 */
	public function verify_email() {

		do_action( 'rcp_member_pre_verify_email', $this->ID, $this );

		delete_user_meta( $this->ID, 'rcp_pending_email_verification' );
		update_user_meta( $this->ID, 'rcp_email_verified', true );

		do_action( 'rcp_member_post_verify_email', $this->ID, $this );

		rcp_log( sprintf( 'Email successfully verified for user #%d.', $this->ID ) );

	}

	/**
	 * Determines if the member can access specified content
	 *
	 * @param   int $post_id ID of the post to check the permissions on.
	 *
	 * @access  public
	 * @since   2.1
	 * @return  bool
	 */
	public function can_access( $post_id = 0 ) {

		// Admins always get access.
		if( user_can( $this->ID, 'manage_options' ) ) {
			return apply_filters( 'rcp_member_can_access', true, $this->ID, $post_id, $this );
		}

		// If the post is unrestricted, everyone gets access.
		if( ! rcp_is_restricted_content( $post_id ) ) {
			return apply_filters( 'rcp_member_can_access', true, $this->ID, $post_id, $this );
		}

		/*
		 * From this point on we assume the post has some kind of restrictions added.
		 */

		// If the user is pending email verification, they don't get access.
		if ( $this->is_pending_verification() ) {
			return apply_filters( 'rcp_member_can_access', false, $this->ID, $post_id, $this );
		}

		// If the user doesn't have an active account, they don't get access.
		if( $this->is_expired() || ! in_array( $this->get_status(), array( 'active', 'free', 'cancelled' ) ) ) {
			return apply_filters( 'rcp_member_can_access', false, $this->ID, $post_id, $this );
		}

		$post_type_restrictions = rcp_get_post_type_restrictions( get_post_type( $post_id ) );
		$sub_id                 = $this->get_subscription_id();

		// Post or post type restrictions.
		if ( empty( $post_type_restrictions ) ) {
			$subscription_levels = rcp_get_content_subscription_levels( $post_id );
			$access_level        = get_post_meta( $post_id, 'rcp_access_level', true );
			$user_level          = get_post_meta( $post_id, 'rcp_user_level', true );
		} else {
			$subscription_levels = array_key_exists( 'subscription_level', $post_type_restrictions ) ? $post_type_restrictions['subscription_level'] : false;
			$access_level        = array_key_exists( 'access_level', $post_type_restrictions ) ? $post_type_restrictions['access_level'] : false;
			$user_level          = array_key_exists( 'user_level', $post_type_restrictions ) ? $post_type_restrictions['user_level'] : false;
		}

		// Assume they have access until proven otherwise.
		$ret = true;

		// Check subscription level restrictions.
		if ( ! empty( $subscription_levels ) ) {

			if( is_string( $subscription_levels ) ) {

				switch( $subscription_levels ) {

					case 'any' :

						$ret = ! empty( $sub_id );
						break;

					case 'any-paid' :

						$ret = $this->is_active();
						break;
				}

			} else {

				if ( in_array( $sub_id, $subscription_levels ) ) {

					$needs_paid = false;

					foreach( $subscription_levels as $level ) {
						$price = rcp_get_subscription_price( $level );
						if ( ! empty( $price ) && $price > 0 ) {
							$needs_paid = true;
						}
					}

					if ( $needs_paid ) {

						$ret = $this->is_active();

					} else {

						$ret = true;
					}

				} else {

					$ret = false;

				}
			}
		}

		// Check post access level restrictions.
		if ( ! rcp_user_has_access( $this->ID, $access_level ) && $access_level > 0 ) {

			$ret = false;

		}

		// Check post user role restrictions.
		if ( $ret && ! empty( $user_level ) && 'all' != strtolower( $user_level ) ) {
			if ( ! user_can( $this->ID, strtolower( $user_level ) ) ) {
				$ret = false;
			}
		}

		// Check term restrictions.
		$has_post_restrictions    = rcp_has_post_restrictions( $post_id );
		$term_restricted_post_ids = rcp_get_post_ids_assigned_to_restricted_terms();

		// since no post-level restrictions, check to see if user is restricted via term
		if ( $ret && ! $has_post_restrictions && in_array( $post_id, $term_restricted_post_ids ) ) {

			$restricted = false;

			$terms = (array) rcp_get_connected_term_ids( $post_id );

			if ( ! empty( $terms ) ) {

				foreach( $terms as $term_id ) {

					$restrictions = rcp_get_term_restrictions( $term_id );

					if ( empty( $restrictions['paid_only'] ) && empty( $restrictions['subscriptions'] ) && ( empty( $restrictions['access_level'] ) || 'None' == $restrictions['access_level'] ) ) {
						if ( count( $terms ) === 1 ) {
							break;
						}
						continue;
					}

					// If only the Paid Only box is checked, check for active subscription and return early if so.
					if ( ! $restricted && ! empty( $restrictions['paid_only'] ) && empty( $restrictions['subscriptions'] ) && empty( $restrictions['access_level'] ) && ! $this->is_active() ) {
						$restricted = true;
						break;
					}

					if ( ! $restricted && ! empty( $restrictions['subscriptions'] ) && ! in_array( $this->get_subscription_id(), $restrictions['subscriptions'] ) ) {
						$restricted = true;
						break;
					}

					if ( ! $restricted && ! empty( $restrictions['access_level'] ) && 'None' !== $restrictions['access_level'] ) {
						if ( $restrictions['access_level'] > 0 && ! rcp_user_has_access( $this->ID, $restrictions['access_level'] ) ) {
							$restricted = true;
							break;
						}
					}
				}
			}

			if ( $restricted ) {
				$ret = false;
			}

		// since user doesn't pass post-level restrictions, see if user is allowed via term
		} else if ( ! $ret && $has_post_restrictions && in_array( $post_id, $term_restricted_post_ids ) ) {

			$allowed = false;

			$terms = (array) rcp_get_connected_term_ids( $post_id );

			if ( ! empty( $terms ) ) {

				foreach( $terms as $term_id ) {

					$restrictions = rcp_get_term_restrictions( $term_id );

					if ( empty( $restrictions['paid_only'] ) && empty( $restrictions['subscriptions'] ) && ( empty( $restrictions['access_level'] ) || 'None' == $restrictions['access_level'] ) ) {
						if ( count( $terms ) === 1 ) {
							break;
						}
						continue;
					}

					// If only the Paid Only box is checked, check for active subscription and return early if so.
					if ( ! $allowed && ! empty( $restrictions['paid_only'] ) && empty( $restrictions['subscriptions'] ) && empty( $restrictions['access_level'] ) && $this->is_active() ) {
						$allowed = true;
						break;
					}

					if ( ! $allowed && ! empty( $restrictions['subscriptions'] ) && in_array( $this->get_subscription_id(), $restrictions['subscriptions'] ) ) {
						$allowed = true;
						break;
					}

					if ( ! $allowed && ! empty( $restrictions['access_level'] ) && 'None' !== $restrictions['access_level'] ) {
						if ( $restrictions['access_level'] > 0 && rcp_user_has_access( $this->ID, $restrictions['access_level'] ) ) {
							$allowed = true;
							break;
						}
					}
				}
			}

			if ( $allowed ) {
				$ret = true;
			}
		}

		return apply_filters( 'rcp_member_can_access', $ret, $this->ID, $post_id, $this );

	}

	/**
	 * Gets the URL to switch to the user
	 * if the User Switching plugin is active
	 *
	 * @access public
	 * @since 2.1
	 * @return string|false
	 */
	public function get_switch_to_url() {

		if( ! class_exists( 'user_switching' ) ) {
		   	return false;
		}

		$link = user_switching::maybe_switch_url( $this );
		if ( $link ) {
			$link = add_query_arg( 'redirect_to', urlencode( home_url() ), $link );
			return $link;
		} else {
			return false;
		}
	}

	/**
	 * Get the prorate credit amount for the user's remaining subscription
	 *
	 * @since 2.5
	 * @return int
	 */
	public function get_prorate_credit_amount() {

		// make sure this is an active, paying subscriber
		if ( ! $this->is_active() ) {
			return 0;
		}

		if ( apply_filters( 'rcp_disable_prorate_credit', false, $this ) ) {
			return 0;
		}

		// get the most recent payment
		foreach( $this->get_payments() as $pmt ) {
			if ( 'complete' != $pmt->status ) {
				continue;
			}

			$payment = $pmt;
			break;
		}

		if ( empty( $payment ) ) {
			return 0;
		}

		if ( ! empty( $payment->object_id ) ) {
			$subscription_id = absint( $payment->object_id );
			$subscription    = rcp_get_subscription_details( $subscription_id );
		} else {
			$subscription    = rcp_get_subscription_details_by_name( $payment->subscription );
			$subscription_id = $this->get_subscription_id();
		}

		// make sure the subscription payment matches the existing subscription
		if ( empty( $subscription->id ) || empty( $subscription->duration ) || $subscription->id != $subscription_id ) {
			return 0;
		}

		$exp_date = $this->get_expiration_date( false );

		// if this is member does not have an expiration date, calculate it
		if ( 'none' == $exp_date ) {
			return 0;
		}

		// make sure we have a valid date
		if ( ! $exp_date = strtotime( $exp_date ) ) {
			return 0;
		}

		$exp_date_dt = date( 'Y-m-d', $exp_date ) . ' 23:59:59';
		$exp_date    = strtotime( $exp_date_dt, current_time( 'timestamp' ) );

		$time_remaining = $exp_date - current_time( 'timestamp' );

		// Calculate the start date based on the expiration date
		if ( ! $start_date = strtotime( $exp_date_dt . ' -' . $subscription->duration . $subscription->duration_unit, current_time( 'timestamp' ) ) ) {
			return 0;
		}

		$total_time = $exp_date - $start_date;

		if ( $time_remaining <= 0 ) {
			return 0;
		}

		// calculate discount as percentage of subscription remaining
		// use the previous payment amount
		if( $subscription->fee > 0 ) {
			$payment->amount -= $subscription->fee;
		}
		$payment_amount       = abs( $payment->amount );
		$percentage_remaining = $time_remaining / $total_time;

		// make sure we don't credit more than 100%
		if ( $percentage_remaining > 1 ) {
			$percentage_remaining = 1;
		}

		$discount = round( $payment_amount * $percentage_remaining, 2 );

		// make sure they get a discount. This shouldn't ever run
		if ( ! $discount > 0 ) {
			$discount = $payment_amount;
		}

		return apply_filters( 'rcp_member_prorate_credit', floatval( $discount ), $this->ID, $this );

	}

	/**
	 * Get details about the member's card on file
	 *
	 * @since 2.5
	 * @return array
	 */
	public function get_card_details() {

		// Each gateway hooks in to retrieve the details from the merchant API
		return apply_filters( 'rcp_get_card_details', array(), $this->ID, $this );

	}

	/**
	 * Determines if the customer just upgraded
	 *
	 * @since 2.5
	 * @return int|false - Timestamp reflecting the date/time of the latest upgrade, or false.
	 */
	public function just_upgraded() {

		$upgraded = get_user_meta( $this->ID, '_rcp_just_upgraded', true );

		if( ! empty( $upgraded ) ) {

			$limit = strtotime( '-5 minutes', current_time( 'timestamp' ) );

			if( $limit > $upgraded ) {

				$upgraded = false;

			}

		}

		return apply_filters( 'rcp_member_just_upgraded', $upgraded, $this->ID, $this );
	}

}
