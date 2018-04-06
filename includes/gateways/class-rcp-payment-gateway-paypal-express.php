<?php
/**
 * PayPal Express Gateway class
 *
 * @package     Restrict Content Pro
 * @subpackage  Classes/Gateways/PayPal Express
 * @copyright   Copyright (c) 2017, Pippin Williamson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.1
*/

class RCP_Payment_Gateway_PayPal_Express extends RCP_Payment_Gateway {

	private $api_endpoint;
	private $checkout_url;
	protected $username;
	protected $password;
	protected $signature;

	/**
	 * Get things going
	 *
	 * @access public
	 * @since  2.1
	 * @return void
	 */
	public function init() {

		global $rcp_options;

		$this->supports[]  = 'one-time';
		$this->supports[]  = 'recurring';
		$this->supports[]  = 'fees';
		$this->supports[] = 'trial';

		if( $this->test_mode ) {

			$this->api_endpoint = 'https://api-3t.sandbox.paypal.com/nvp';
			$this->checkout_url = 'https://www.sandbox.paypal.com/webscr&cmd=_express-checkout&token=';

		} else {

			$this->api_endpoint = 'https://api-3t.paypal.com/nvp';
			$this->checkout_url = 'https://www.paypal.com/webscr&cmd=_express-checkout&token=';

		}

		if( rcp_has_paypal_api_access() ) {

			$creds = rcp_get_paypal_api_credentials();

			$this->username  = $creds['username'];
			$this->password  = $creds['password'];
			$this->signature = $creds['signature'];

		}

	}

	/**
	 * Process registration
	 *
	 * @access public
	 * @since  2.1
	 * @return void
	 */
	public function process_signup() {

		global $rcp_options;

		if( $this->auto_renew ) {
			$amount = $this->amount;
		} else {
			$amount = $this->initial_amount;
		}

		$cancel_url = wp_get_referer();
		if ( empty( $cancel_url ) ) {
			$cancel_url = get_permalink( $rcp_options['registration_page'] );
		}

		$args = array(
			'USER'                           => $this->username,
			'PWD'                            => $this->password,
			'SIGNATURE'                      => $this->signature,
			'VERSION'                        => '124',
			'METHOD'                         => 'SetExpressCheckout',
			'PAYMENTREQUEST_0_AMT'           => $amount,
			'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
			'PAYMENTREQUEST_0_CURRENCYCODE'  => strtoupper( $this->currency ),
			'PAYMENTREQUEST_0_ITEMAMT'       => $amount,
			'PAYMENTREQUEST_0_SHIPPINGAMT'   => 0,
			'PAYMENTREQUEST_0_TAXAMT'        => 0,
			'PAYMENTREQUEST_0_DESC'          => html_entity_decode( substr( $this->subscription_name, 0, 127 ), ENT_COMPAT, 'UTF-8' ),
			'PAYMENTREQUEST_0_CUSTOM'        => $this->user_id,
			'PAYMENTREQUEST_0_NOTIFYURL'     => add_query_arg( 'listener', 'EIPN', home_url( 'index.php' ) ),
			'EMAIL'                          => $this->email,
			'RETURNURL'                      => add_query_arg( array( 'rcp-confirm' => 'paypal_express', 'user_id' => $this->user_id ), get_permalink( $rcp_options['registration_page'] ) ),
			'CANCELURL'                      => $cancel_url,
			'REQCONFIRMSHIPPING'             => 0,
			'NOSHIPPING'                     => 1,
			'ALLOWNOTE'                      => 0,
			'ADDROVERRIDE'                   => 0,
			'PAGESTYLE'                      => ! empty( $rcp_options['paypal_page_style'] ) ? trim( $rcp_options['paypal_page_style'] ) : '',
			'SOLUTIONTYPE'                   => 'Sole',
			'LANDINGPAGE'                    => 'Billing',
		);

		if( $this->auto_renew && ! empty( $this->length ) ) {
			$args['L_BILLINGAGREEMENTDESCRIPTION0'] = html_entity_decode( substr( $this->subscription_name, 0, 127 ), ENT_COMPAT, 'UTF-8' );
			$args['L_BILLINGTYPE0']                 = 'RecurringPayments';
			$args['RETURNURL']                      = add_query_arg( array( 'rcp-recurring' => '1' ), $args['RETURNURL'] );
		}

		if ( $this->is_trial() ) {
			$args['PAYMENTREQUEST_0_CUSTOM'] .= '|trial';
		}

		$request = wp_remote_post( $this->api_endpoint, array(
			'timeout' => 45,
			'httpversion' => '1.1',
			'body' => $args
		) );
		$body    = wp_remote_retrieve_body( $request );
		$code    = wp_remote_retrieve_response_code( $request );
		$message = wp_remote_retrieve_response_message( $request );

		if( is_wp_error( $request ) ) {

			$this->error_message = $request->get_error_message();
			do_action( 'rcp_registration_failed', $this );
			do_action( 'rcp_paypal_express_signup_payment_failed', $request, $this );

			$error = '<p>' . __( 'An unidentified error occurred.', 'rcp' ) . '</p>';
			$error .= '<p>' . $request->get_error_message() . '</p>';

			wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => '401' ) );

		} elseif ( 200 == $code && 'OK' == $message ) {

			if( is_string( $body ) ) {
				wp_parse_str( $body, $body );
			}

			if( 'failure' === strtolower( $body['ACK'] ) ) {

				$this->error_message = $body['L_LONGMESSAGE0'];
				do_action( 'rcp_registration_failed', $this );

				$error = '<p>' . __( 'PayPal token creation failed.', 'rcp' ) . '</p>';
				$error .= '<p>' . __( 'Error message:', 'rcp' ) . ' ' . $body['L_LONGMESSAGE0'] . '</p>';
				$error .= '<p>' . __( 'Error code:', 'rcp' ) . ' ' . $body['L_ERRORCODE0'] . '</p>';

				wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => '401' ) );

			} else {

				// Successful token
				wp_redirect( $this->checkout_url . $body['TOKEN'] );
				exit;

			}

		} else {

			do_action( 'rcp_registration_failed', $this );
			wp_die( __( 'Something has gone wrong, please try again', 'rcp' ), __( 'Error', 'rcp' ), array( 'back_link' => true, 'response' => '401' ) );

		}

	}

	/**
	 * Validate additional fields during registration submission
	 *
	 * @access public
	 * @since  2.1
	 * @return void
	 */
	public function validate_fields() {

		if( ! rcp_has_paypal_api_access() ) {
			rcp_errors()->add( 'no_paypal_api', __( 'You have not configured PayPal API access. Please configure it in Restrict &rarr; Settings', 'rcp' ), 'register' );
		}

	}

	/**
	 * Process payment confirmation after returning from PayPal
	 *
	 * @access public
	 * @since  2.1
	 * @return void
	 */
	public function process_confirmation() {

		if ( isset( $_POST['rcp_ppe_confirm_nonce'] ) && wp_verify_nonce( $_POST['rcp_ppe_confirm_nonce'], 'rcp-ppe-confirm-nonce' ) ) {

			$details = $this->get_checkout_details( $_POST['token'] );

			if( ! empty( $_GET['rcp-recurring'] ) ) {

				// Successful payment, now create the recurring profile

				$args = array(
					'USER'                => $this->username,
					'PWD'                 => $this->password,
					'SIGNATURE'           => $this->signature,
					'VERSION'             => '124',
					'TOKEN'               => $_POST['token'],
					'METHOD'              => 'CreateRecurringPaymentsProfile',
					'PROFILESTARTDATE'    => date( 'Y-m-d\TH:i:s', strtotime( '+' . $details['subscription']['duration'] . ' ' . $details['subscription']['duration_unit'], current_time( 'timestamp' ) ) ),
					'BILLINGPERIOD'       => ucwords( $details['subscription']['duration_unit'] ),
					'BILLINGFREQUENCY'    => $details['subscription']['duration'],
					'AMT'                 => $details['AMT'],
					'INITAMT'             => $details['initial_amount'],
					'CURRENCYCODE'        => $details['CURRENCYCODE'],
					'FAILEDINITAMTACTION' => 'CancelOnFailure',
					'L_BILLINGTYPE0'      => 'RecurringPayments',
					'DESC'                => html_entity_decode( substr( $details['subscription']['name'], 0, 127 ), ENT_COMPAT, 'UTF-8' ),
					'BUTTONSOURCE'        => 'EasyDigitalDownloads_SP'
				);

				if ( $args['INITAMT'] < 0 ) {
					unset( $args['INITAMT'] );
				}

				if ( ! empty( $details['is_trial'] ) ) {
					// Set profile start date to the end of the free trial.
					$args['PROFILESTARTDATE'] = date( 'Y-m-d\TH:i:s', strtotime( '+' . $details['subscription']['trial_duration'] . ' ' . $details['subscription']['trial_duration_unit'], current_time( 'timestamp' ) ) );

					unset( $args['INITAMT'] );
				}

				$request = wp_remote_post( $this->api_endpoint, array(
					'timeout' => 45,
					'httpversion' => '1.1',
					'body' => $args
				) );
				$body    = wp_remote_retrieve_body( $request );
				$code    = wp_remote_retrieve_response_code( $request );
				$message = wp_remote_retrieve_response_message( $request );

				if( is_wp_error( $request ) ) {

					$error = '<p>' . __( 'An unidentified error occurred.', 'rcp' ) . '</p>';
					$error .= '<p>' . $request->get_error_message() . '</p>';

					wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => '401' ) );

				} elseif ( 200 == $code && 'OK' == $message ) {

					if( is_string( $body ) ) {
						wp_parse_str( $body, $body );
					}

					if( 'failure' === strtolower( $body['ACK'] ) ) {

						$error = '<p>' . __( 'PayPal payment processing failed.', 'rcp' ) . '</p>';
						$error .= '<p>' . __( 'Error message:', 'rcp' ) . ' ' . $body['L_LONGMESSAGE0'] . '</p>';
						$error .= '<p>' . __( 'Error code:', 'rcp' ) . ' ' . $body['L_ERRORCODE0'] . '</p>';

						wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => '401' ) );

					} else {

						$custom = explode( '|', $details['PAYMENTREQUEST_0_CUSTOM'] );
						$member = new RCP_Member( $custom[0] );

						if( $member->just_upgraded() && $member->can_cancel() ) {
							$cancelled = $member->cancel_payment_profile( false );
						}

						$member->set_payment_profile_id( $body['PROFILEID'] );

						wp_redirect( esc_url_raw( rcp_get_return_url() ) ); exit;

					}

				} else {

					wp_die( __( 'Something has gone wrong, please try again', 'rcp' ), __( 'Error', 'rcp' ), array( 'back_link' => true, 'response' => '401' ) );

				}

			} else {

				// One time payment

				$args = array(
					'USER'                           => $this->username,
					'PWD'                            => $this->password,
					'SIGNATURE'                      => $this->signature,
					'VERSION'                        => '124',
					'METHOD'                         => 'DoExpressCheckoutPayment',
					'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
					'TOKEN'                          => $_POST['token'],
					'PAYERID'                        => $_POST['payer_id'],
					'PAYMENTREQUEST_0_AMT'           => $details['AMT'],
					'PAYMENTREQUEST_0_ITEMAMT'       => $details['AMT'],
					'PAYMENTREQUEST_0_SHIPPINGAMT'   => 0,
					'PAYMENTREQUEST_0_TAXAMT'        => 0,
					'PAYMENTREQUEST_0_CURRENCYCODE'  => $details['CURRENCYCODE'],
					'BUTTONSOURCE'                   => 'EasyDigitalDownloads_SP'
				);

				$request = wp_remote_post( $this->api_endpoint, array(
					'timeout' => 45,
					'httpversion' => '1.1',
					'body' => $args
				) );
				$body    = wp_remote_retrieve_body( $request );
				$code    = wp_remote_retrieve_response_code( $request );
				$message = wp_remote_retrieve_response_message( $request );

				if( is_wp_error( $request ) ) {

					$error = '<p>' . __( 'An unidentified error occurred.', 'rcp' ) . '</p>';
					$error .= '<p>' . $request->get_error_message() . '</p>';

					wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => '401' ) );

				} elseif ( 200 == $code && 'OK' == $message ) {

					if( is_string( $body ) ) {
						wp_parse_str( $body, $body );
					}

					if( 'failure' === strtolower( $body['ACK'] ) ) {

						$error = '<p>' . __( 'PayPal payment processing failed.', 'rcp' ) . '</p>';
						$error .= '<p>' . __( 'Error message:', 'rcp' ) . ' ' . $body['L_LONGMESSAGE0'] . '</p>';
						$error .= '<p>' . __( 'Error code:', 'rcp' ) . ' ' . $body['L_ERRORCODE0'] . '</p>';

						wp_die( $error, __( 'Error', 'rcp' ), array( 'response' => '401' ) );

					} else {

						// Confirm a one-time payment
						$member = new RCP_Member( $details['CUSTOM'] );

						if( $member->just_upgraded() && $member->can_cancel() ) {

							$cancelled = $member->cancel_payment_profile( false );

							if( $cancelled ) {

								$member->set_payment_profile_id( '' );

							}

						}

						$payment_data = array(
							'date'             => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
							'subscription'     => $member->get_pending_subscription_name(),
							'payment_type'     => 'PayPal Express One Time',
							'subscription_key' => $member->get_pending_subscription_key(),
							'amount'           => $body['PAYMENTINFO_0_AMT'],
							'user_id'          => $member->ID,
							'transaction_id'   => $body['PAYMENTINFO_0_TRANSACTIONID'],
							'status'           => 'complete'
						);

						$rcp_payments = new RCP_Payments;
						$rcp_payments->update( $member->get_pending_payment_id(), $payment_data );

						// Membership is activated via rcp_complete_registration()

						wp_redirect( esc_url_raw( rcp_get_return_url() ) ); exit;

					}

				} else {

					wp_die( __( 'Something has gone wrong, please try again', 'rcp' ), __( 'Error', 'rcp' ), array( 'back_link' => true, 'response' => '401' ) );

				}

			}


		} elseif ( ! empty( $_GET['token'] ) && ! empty( $_GET['PayerID'] ) ) {

			add_filter( 'the_content', array( $this, 'confirmation_form' ), 9999999 );

		}

	}

	/**
	 * Display the confirmation form
	 *
	 * @since 2.1
	 * @return string
	 */
	public function confirmation_form() {

		global $rcp_checkout_details;

		$token                = sanitize_text_field( $_GET['token'] );
		$rcp_checkout_details = $this->get_checkout_details( $token );

		if ( ! is_array( $rcp_checkout_details ) ) {
			$error = is_wp_error( $rcp_checkout_details ) ? $rcp_checkout_details->get_error_message() : __( 'Invalid response code from PayPal', 'rcp' );
			return '<p>' . sprintf( __( 'An unexpected PayPal error occurred. Error message: %s.', 'rcp' ), $error ) . '</p>';
		}

		ob_start();
		rcp_get_template_part( 'paypal-express-confirm' );
		return ob_get_clean();
	}

	/**
	 * Process PayPal IPN
	 *
	 * @access public
	 * @since  2.1
	 * @return void
	 */
	public function process_webhooks() {

		if( ! isset( $_GET['listener'] ) || strtoupper( $_GET['listener'] ) != 'EIPN' ) {
			return;
		}

		rcp_log( 'Starting to process PayPal Express IPN.' );

		$user_id = 0;
		$posted  = apply_filters('rcp_ipn_post', $_POST ); // allow $_POST to be modified

		if( ! empty( $posted['recurring_payment_id'] ) ) {

			$user_id = rcp_get_member_id_from_profile_id( $posted['recurring_payment_id'] );

		}

		if( empty( $user_id ) && ! empty( $posted['custom'] ) && is_numeric( $posted['custom'] ) ) {

			$user_id = absint( $posted['custom'] );

		}

		if( empty( $user_id ) && ! empty( $posted['payer_email'] ) ) {

			$user    = get_user_by( 'email', $posted['payer_email'] );
			$user_id = $user ? $user->ID : false;

		}

		$member = new RCP_Member( $user_id );

		if( ! $member || ! $member->ID > 0 ) {
			rcp_log( 'Exiting PayPal Express IPN - member ID not found.' );

			die( 'no member found' );
		}

		$subscription_id = $member->get_pending_subscription_id();

		if( empty( $subscription_id ) ) {

			$subscription_id = $member->get_subscription_id();

		}

		if( ! $subscription_id ) {
			rcp_log( 'Exiting PayPal Express IPN - no subscription ID for member.' );

			die( 'no subscription for member found' );
		}

		if( ! $subscription_level = rcp_get_subscription_details( $subscription_id ) ) {
			rcp_log( 'Exiting PayPal Express IPN - no subscription level found.' );

			die( 'no subscription level found' );
		}

		$amount = number_format( (float) $posted['mc_gross'], 2, '.', '' );

		// setup the payment info in an array for storage
		$pending_key = $member->get_pending_subscription_key();
		$payment_data = array(
			'date'             => date( 'Y-m-d H:i:s', strtotime( $posted['payment_date'] ) ),
			'subscription'     => $subscription_level->name,
			'payment_type'     => $posted['txn_type'],
			'subscription_key' => ! empty( $pending_key ) ? $pending_key : $member->get_subscription_key(),
			'amount'           => $amount,
			'user_id'          => $user_id,
			'transaction_id'   => $posted['txn_id'],
			'status'           => 'complete'
		);

		do_action( 'rcp_valid_ipn', $payment_data, $user_id, $posted );

		/* now process the kind of subscription/payment */

		$rcp_payments       = new RCP_Payments();
		$pending_payment_id = $member->get_pending_payment_id();

		// Subscriptions
		switch ( $posted['txn_type'] ) :

			case "recurring_payment_profile_created":

				rcp_log( 'Processing PayPal Express recurring_payment_profile_created IPN.' );

				if ( isset( $posted['initial_payment_txn_id'] ) ) {
					$transaction_id = ( 'Completed' == $posted['initial_payment_status'] ) ? $posted['initial_payment_txn_id'] : '';
				} else {
					$transaction_id = $posted['ipn_track_id'];
				}

				if ( empty( $transaction_id ) || $rcp_payments->payment_exists( $transaction_id ) ) {
					rcp_log( sprintf( 'Breaking out of PayPal Express IPN recurring_payment_profile_created. Transaction ID not given or payment already exists. TXN ID: %s', $transaction_id ) );

					break;
				}

				// setup the payment info in an array for storage
				$payment_data['date']           = date( 'Y-m-d H:i:s', strtotime( $posted['time_created'] ) );
				$payment_data['amount']         = number_format( (float) $posted['initial_payment_amount'], 2, '.', '' );
				$payment_data['transaction_id'] = sanitize_text_field( $transaction_id );

				if ( ! empty( $pending_payment_id ) ) {

					$payment_id = $pending_payment_id;
					$member->set_recurring( true );

					// This activates the membership.
					$rcp_payments->update( $pending_payment_id, $payment_data );

				} else {

					$payment_id = $rcp_payments->insert( $payment_data );

					$expiration = date( 'Y-m-d 23:59:59', strtotime( $posted['next_payment_date'] ) );
					$member->renew( $member->is_recurring(), 'active', $expiration );

				}

				do_action( 'rcp_webhook_recurring_payment_profile_created', $member, $this );
				do_action( 'rcp_gateway_payment_processed', $member, $payment_id, $this );

				break;
			case "recurring_payment" :

				rcp_log( 'Processing PayPal Express recurring_payment IPN.' );

				// when a user makes a recurring payment
				update_user_meta( $user_id, 'rcp_paypal_subscriber', $posted['payer_id'] );

				$member->set_payment_profile_id( $posted['recurring_payment_id'] );

				$member->renew( true );

				// record this payment in the database
				$payment_id = $rcp_payments->insert( $payment_data );

				do_action( 'rcp_ipn_subscr_payment', $user_id );
				do_action( 'rcp_webhook_recurring_payment_processed', $member, $payment_id, $this );
				do_action( 'rcp_gateway_payment_processed', $member, $payment_id, $this );

				die( 'successful recurring_payment' );

				break;

			case "recurring_payment_profile_cancel" :

				rcp_log( 'Processing PayPal Express recurring_payment_profile_cancel IPN.' );

				if( ! $member->just_upgraded() ) {

					if( isset( $posted['initial_payment_status'] ) && 'Failed' == $posted['initial_payment_status'] ) {
						// Initial payment failed, so set the user back to pending.
						$member->set_status( 'pending' );
						$member->add_note( __( 'Initial payment failed in PayPal Express.', 'rcp' ) );

						$this->error_message = __( 'Initial payment failed.', 'rcp' );
						do_action( 'rcp_registration_failed', $this );
						do_action( 'rcp_paypal_express_initial_payment_failed', $member, $posted, $this );
					} else {
						// user is marked as cancelled but retains access until end of term
						$member->cancel();

						// set the use to no longer be recurring
						delete_user_meta( $user_id, 'rcp_paypal_subscriber' );

						do_action( 'rcp_ipn_subscr_cancel', $user_id );
						do_action( 'rcp_webhook_cancel', $member, $this );
					}

					die( 'successful recurring_payment_profile_cancel' );

				}

				break;

			case "recurring_payment_failed" :
			case "recurring_payment_suspended_due_to_max_failed_payment" :

			rcp_log( 'Processing PayPal Express recurring_payment_failed or recurring_payment_suspended_due_to_max_failed_payment IPN.' );

				if( 'cancelled' !== $member->get_status() ) {

					$member->set_status( 'expired' );

				}

				if ( ! empty( $posted['txn_id'] ) ) {

					$this->webhook_event_id = sanitize_text_field( $posted['txn_id'] );

				} elseif ( ! empty( $posted['ipn_track_id'] ) ) {

					$this->webhook_event_id = sanitize_text_field( $posted['ipn_track_id'] );
				}

				do_action( 'rcp_ipn_subscr_failed' );

				do_action( 'rcp_recurring_payment_failed', $member, $this );

				die( 'successful recurring_payment_failed or recurring_payment_suspended_due_to_max_failed_payment' );

				break;

			case "web_accept" :

				rcp_log( sprintf( 'Processing PayPal Express web_accept IPN. Payment status: %s', $posted['payment_status'] ) );

				switch ( strtolower( $posted['payment_status'] ) ) :

					case 'completed' :

						if( $member->just_upgraded() && $member->can_cancel() ) {
							$cancelled = $member->cancel_payment_profile( false );
							if( $cancelled ) {

								$member->set_payment_profile_id( '' );

							}
						}

						if ( empty( $payment_data['transaction_id'] ) || $rcp_payments->payment_exists( $payment_data['transaction_id'] ) ) {
							rcp_log( sprintf( 'Not inserting PayPal Express web_accept payment. Transaction ID not given or payment already exists. TXN ID: %s', $payment_data['transaction_id'] ) );
						} else {
							$rcp_payments->insert( $payment_data );
						}

						// Member was already activated.

						break;

					case 'denied' :
					case 'expired' :
					case 'failed' :
					case 'voided' :
						if ( $member->is_active() ) {
							$member->cancel();
						} else {
							rcp_log( sprintf( 'Member #%d is not active - not cancelling account.', $member->ID ) );
						}
						break;

				endswitch;


				die( 'successful web_accept' );

			break;

		endswitch;

	}

	/**
	 * Get checkout details
	 *
	 * @param string $token
	 *
	 * @return array|bool|string|WP_Error
	 */
	public function get_checkout_details( $token = '' ) {

		$args = array(
			'USER'      => $this->username,
			'PWD'       => $this->password,
			'SIGNATURE' => $this->signature,
			'VERSION'   => '124',
			'METHOD'    => 'GetExpressCheckoutDetails',
			'TOKEN'     => $token
		);

		$request = wp_remote_post( $this->api_endpoint, array(
			'timeout'     => 45,
			'httpversion' => '1.1',
			'body'        => $args
		) );
		$body    = wp_remote_retrieve_body( $request );
		$code    = wp_remote_retrieve_response_code( $request );
		$message = wp_remote_retrieve_response_message( $request );

		if( is_wp_error( $request ) ) {

			return $request;

		} elseif ( 200 == $code && 'OK' == $message ) {

			if( is_string( $body ) ) {
				wp_parse_str( $body, $body );
			}

			$member = new RCP_Member( absint( $_GET['user_id'] ) );

			$subscription_id = $member->get_pending_subscription_id();

			if( empty( $subscription_id ) ) {
				$subscription_id = $member->get_subscription_id();
			}

			$body['subscription']   = (array) rcp_get_subscription_details( $subscription_id );
			$body['initial_amount'] = get_user_meta( $member->ID, 'rcp_pending_subscription_amount', true );

			$custom = explode( '|', $body['PAYMENTREQUEST_0_CUSTOM'] );

			if ( ! empty( $custom[1] ) && 'trial' === $custom[1] && ! empty( $body['subscription']['trial_duration'] ) && ! empty( $body['subscription']['trial_duration_unit'] ) ) {
				$body['is_trial'] = true;
			}

			return $body;

		}

		return false;

	}

}