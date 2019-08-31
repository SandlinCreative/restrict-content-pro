<?php
/**
 * RCP Registration Class
 *
 * @package     Restrict Content Pro
 * @subpackage  Classes/Registration
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       2.5
 */

class RCP_Registration {

	/**
	 * Store the subscription for the registration
	 *
	 * @since 2.5
	 * @var int
	 */
	protected $subscription = 0;

	/**
	 * Type of registration: new, renewal, upgrade
	 *
	 * @since 3.1
	 * @var string
	 */
	protected $registration_type = 'new';

	/**
	 * Current membership, if this is a renewal or upgrade
	 *
	 * @since 3.1
	 * @var RCP_Membership|false
	 */
	protected $membership = false;

	/**
	 * Store the discounts for the registration
	 *
	 * @since 2.5
	 * @var array
	 */
	protected $discounts = array();

	/**
	 * Store the fees/credits for the registration. Credits are negative fees.
	 *
	 * @since 2.5
	 * @var array
	 */
	protected $fees = array();

	/**
	 * Get things started.
	 *
	 * @param int         $level_id ID of the membership level for this registration.
	 * @param null|string $discount Discount code to apply to this registration.
	 *
	 * @return void
	 */
	public function __construct( $level_id = 0, $discount = null ) {

		if ( $level_id ) {
			$this->set_subscription( $level_id );
		}

		$this->set_registration_type();
		$this->maybe_add_signup_fee();

		if ( $level_id && $discount ) {
			$this->add_discount( strtolower( $discount ) );
		}

		do_action( 'rcp_registration_init', $this );
	}

	/**
	 * Set the subscription for this registration
	 *
	 * @since 2.5
	 * @param $subscription_id
	 *
	 * @return bool
	 */
	public function set_subscription( $subscription_id ) {
		if ( ! $subscription = rcp_get_subscription_details( $subscription_id ) ) {
			return false;
		}

		$this->subscription = $subscription_id;

		return true;
	}

	/**
	 * Add a signup fee if this is not a "renewal".
	 *
	 * @since 3.1
	 * @return void
	 */
	protected function maybe_add_signup_fee() {

		if ( empty( $this->subscription ) || ! $subscription = rcp_get_subscription_details( $this->subscription ) ) {
			return;
		}

		if ( empty( $subscription->fee ) ) {
			return;
		}

		$add_signup_fee = 'renewal' != $this->get_registration_type();

		/**
		 * Filters whether or not the signup fee should be applied.
		 *
		 * @param bool             $add_signup_fee Whether or not to add the signup fee.
		 * @param object           $subscription   Membership level object.
		 * @param RCP_Registration $this           Registration object.
		 *
		 * @since 3.1
		 */
		$add_signup_fee = apply_filters( 'rcp_apply_signup_fee_to_registration', $add_signup_fee, $subscription, $this );

		if ( ! $add_signup_fee ) {
			return;
		}

		$description = ( $subscription->fee > 0 ) ? __( 'Signup Fee', 'rcp' ) : __( 'Signup Credit', 'rcp' );
		$this->add_fee( $subscription->fee, $description );

	}

	/**
	 * Get registration subscription
	 *
	 * @deprecated 3.0 Use `RCP_Registration::get_membership_level_id()` instead.
	 * @see RCP_Registration::get_membership_level_id()
	 *
	 * @since 2.5
	 * @return int
	 */
	public function get_subscription() {
		return $this->get_membership_level_id();
	}

	/**
	 * Get the ID number of the membership level this registration is for.
	 *
	 * @access public
	 * @since 3.0
	 * @return int ID of the membership level.
	 */
	public function get_membership_level_id() {
		return $this->subscription;
	}

	/**
	 * Set registration type
	 *
	 * This is based on the following query strings:
	 *
	 *        - $_REQUEST['registration_type'] - Will either be "renewal" or "upgrade". If empty, we assume "new".
	 *        - $_REQUEST['membership_id'] - This must be provided for renewals and upgrades so we know which membership
	 *                                       to work with.
	 *
	 * @since 3.1
	 * @return void
	 */
	public function set_registration_type() {

		$this->registration_type = 'new'; // default;

		if ( ! empty( $_REQUEST['registration_type'] ) && 'new' != $_REQUEST['registration_type'] && ! empty( $_REQUEST['membership_id'] ) ) {

			/**
			 * The `registration_type` query arg is set, it's NOT `new`, and we have a membership ID.
			 */
			$membership = rcp_get_membership( absint( $_REQUEST['membership_id'] ) );

			if ( ! empty( $membership ) && $membership->get_customer()->get_user_id() == get_current_user_id() ) {
				$this->membership        = $membership;
				$this->registration_type = sanitize_text_field( $_REQUEST['registration_type'] );
			}

		} elseif ( ! rcp_multiple_memberships_enabled() && $this->get_membership_level_id() ) {

			/**
			 * Multiple memberships not enabled, and we have a selected membership level ID on the form.
			 * We determine if it's a renewal or upgrade based on the user's current membership level and
			 * the one they've selected on the registration form.
			 */

			$customer            = rcp_get_customer();
			$previous_membership = ! empty( $customer ) ? rcp_get_customer_single_membership( $customer->get_id() ) : false;

			if ( ! empty( $previous_membership ) ) {
				$this->membership = $previous_membership;

				if ( $this->membership->get_object_id() == $this->get_membership_level_id() ) {
					$this->registration_type = 'renewal';
				} else {
					$this->registration_type = 'upgrade';
				}
			}

		}

		if ( 'upgrade' == $this->registration_type && is_object( $this->membership ) && $this->get_membership_level_id() ) {
			/**
			 * If we have the type listed as an "upgrade", we'll run a few extra checks to determine
			 * if we should change this to "downgrade".
			 */
			// Figure out if this is a downgrade instead.
			$membership_level          = rcp_get_subscription_details( $this->get_membership_level_id() );
			$previous_membership_level = rcp_get_subscription_details( $this->membership->get_object_id() );

			$days_in_old_cycle = rcp_get_days_in_cycle( $previous_membership_level->duration_unit, $previous_membership_level->duration );
			$days_in_new_cycle = rcp_get_days_in_cycle( $membership_level->duration_unit, $membership_level->duration );

			$old_price_per_day = $days_in_old_cycle > 0 ? $previous_membership_level->price / $days_in_old_cycle : $previous_membership_level->price;
			$new_price_per_day = $days_in_new_cycle > 0 ? $this->get_recurring_total( true, false ) / $days_in_new_cycle : $this->get_recurring_total( true, false );

			rcp_log( sprintf( 'Old price per day: %s (ID #%d); New price per day: %s (ID #%d)', $old_price_per_day, $previous_membership_level->id, $new_price_per_day, $membership_level->id ) );

			if ( $old_price_per_day > $new_price_per_day ) {
				$this->registration_type = 'downgrade';
			}
		}

	}

	/**
	 * Get the registration type
	 *
	 * @since 3.1
	 * @return string
	 */
	public function get_registration_type() {
		return $this->registration_type;
	}

	/**
	 * Get the existing membership object that is being renewed or upgraded
	 *
	 * @since 3.1
	 * @return RCP_Membership|false
	 */
	public function get_membership() {
		return $this->membership;
	}

	/**
	 * Determine whether or not the level being registered for has a trial that the current user is eligible
	 * for. This will return false if there is a trial but the user is not eligible for it.
	 *
	 * @access public
	 * @since 3.0.6
	 * @return bool
	 */
	public function is_trial() {

		$membership_level = rcp_get_subscription_details( $this->get_membership_level_id() );

		if ( empty( $membership_level ) ) {
			return false;
		}

		$trial_duration = $membership_level->trial_duration;

		if ( empty( $trial_duration ) ) {
			return false;
		}

		// There is a trial, but let's check eligibility.

		$customer = rcp_get_customer_by_user_id();

		// No customer, which means they're brand new, which means they're eligible.
		if ( empty( $customer ) ) {
			return true;
		}

		return ! $customer->has_trialed();

	}

	/**
	 * Add discount to the registration
	 *
	 * @since      2.5
	 * @param      $code
	 * @param bool $recurring
	 *
	 * @return bool
	 */
	public function add_discount( $code, $recurring = true ) {
		if ( ! rcp_validate_discount( $code, $this->subscription ) ) {
			return false;
		}

		$this->discounts[ $code ] = $recurring;
		return true;
	}

	/**
	 * Get registration discounts
	 *
	 * @since 2.5
	 * @return array|bool
	 */
	public function get_discounts() {
		if ( empty( $this->discounts ) ) {
			return false;
		}

		return $this->discounts;
	}

	/**
	 * Add fee to the registration. Use negative fee for credit.
	 *
	 * @since      2.5
	 * @param float $amount
	 * @param null $description
	 * @param bool $recurring
	 * @param bool $proration
	 *
	 * @return bool
	 */
	public function add_fee( $amount, $description = null, $recurring = false, $proration = false ) {

		$fee = array(
			'amount'     => floatval( $amount ),
			'description'=> sanitize_text_field( $description ),
			'recurring'  => (bool) $recurring,
			'proration'  => (bool) $proration,
		);

		$id = md5( serialize( $fee ) );

		if ( isset( $this->fees[ $id ] ) ) {
			return false;
		}

		$this->fees[ $id ] = apply_filters( 'rcp_registration_add_fee', $fee, $this );

		return true;
	}

	/**
	 * Get registration fees
	 *
	 * @since 2.5
	 * @return array|bool
	 */
	public function get_fees() {
		if ( empty( $this->fees ) ) {
			return false;
		}

		return $this->fees;
	}

	/**
	 * Get the total number of fees
	 *
	 * @since 2.5
	 * @param null $total
	 * @param bool $only_recurring | set to only get fees that are recurring
	 *
	 * @return float
	 */
	public function get_total_fees( $total = null, $only_recurring = false ) {

		if ( ! $this->get_fees() ) {
			return 0;
		}

		$fees = 0;

		foreach( $this->get_fees() as $fee ) {
			if ( $only_recurring && ! $fee['recurring'] ) {
				continue;
			}

			$fees += $fee['amount'];
		}

		// if total is present, make sure that any negative fees are not
		// greater than the total.
		if ( $total && ( $fees + $total ) < 0 ) {
			$fees = -1 * $total;
		}

		return apply_filters( 'rcp_registration_get_total_fees', (float) $fees, $total, $only_recurring, $this );

	}

	/**
	 * Get the signup fees
	 *
	 * @since 2.5
	 *
	 * @return float
	 */
	public function get_signup_fees() {

		if ( ! $this->get_fees() ) {
			return 0;
		}

		$fees = 0;

		foreach( $this->get_fees() as $fee ) {

			if ( $fee['proration'] ) {
				continue;
			}

			if ( $fee['recurring'] ) {
				continue;
			}

			$fees += $fee['amount'];
		}

		return apply_filters( 'rcp_registration_get_signup_fees', (float) $fees, $this );

	}

	/**
	 * Get the total proration amount
	 *
	 * @since 2.5
	 *
	 * @return float
	 */
	public function get_proration_credits() {

		if ( ! $this->get_fees() ) {
			return 0;
		}

		$proration = 0;

		foreach( $this->get_fees() as $fee ) {

			if ( ! $fee['proration'] ) {
				continue;
			}

			$proration += $fee['amount'];

		}

		return apply_filters( 'rcp_registration_get_proration_fees', (float) $proration, $this );

	}

	/**
	 * Get the total discounts
	 *
	 * @since 2.5
	 * @param null $total
	 * @param bool $only_recurring | set to only get discounts that are recurring
	 *
	 * @return int|mixed
	 */
	public function get_total_discounts( $total = null, $only_recurring = false ) {

		if ( ! $registration_discounts = $this->get_discounts() ) {
			return 0;
		}

		if ( ! $total ) {
			$total = rcp_get_subscription_price( $this->subscription );
		}

		$original_total = $total;

		foreach( $registration_discounts as $registration_discount => $recurring ) {

			if ( $only_recurring && ! $recurring ) {
				continue;
			}

			$discounts    = new RCP_Discounts();
			$discount_obj = $discounts->get_by( 'code', $registration_discount );

			if( $only_recurring && is_object( $discount_obj ) && ! empty( $discount_obj->one_time ) ) {
				continue;
			}

			if ( is_object( $discount_obj ) ) {
				// calculate the after-discount price
				$total = $discounts->calc_discounted_price( $total, $discount_obj->amount, $discount_obj->unit );
			}
		}

		// make sure the discount is not > 100%
		if ( 0 > $total ) {
			$total = 0;
		}

		$total = round( $total, rcp_currency_decimal_filter() );

		return apply_filters( 'rcp_registration_get_total_discounts', (float) ( $original_total - $total ), $original_total, $only_recurring, $this );

	}

	/**
	 * Get the registration total due today
	 *
	 * @param bool $discounts | Include discounts?
	 * @param bool $fees      | Include fees?
	 *
	 * @since 2.5
	 * @return float
	 */
	public function get_total( $discounts = true, $fees = true ) {

		if ( $this->is_trial() ) {
			return 0;
		}

		$total = rcp_get_subscription_price( $this->subscription );

		if ( $discounts ) {
			$total -= $this->get_total_discounts( $total );
		}

		if ( 0 > $total ) {
			$total = 0;
		}

		if ( $fees ) {
			$total += $this->get_signup_fees();
			$total += $this->get_proration_credits();
		}

		if ( 0 > $total ) {
			$total = 0;
		}

		$total = round( $total, rcp_currency_decimal_filter() );

		return apply_filters( 'rcp_registration_get_total', floatval( $total ), $this );

	}

	/**
	 * Get the registration recurring total
	 *
	 * @param bool $discounts | Include discounts?
	 * @param bool $fees      | Include fees?
	 *
	 * @since 2.5
	 * @return float
	 */
	public function get_recurring_total( $discounts = true, $fees = true  ) {

		$membership_level = rcp_get_subscription_details( $this->subscription );

		if ( ! empty( $membership_level->duration ) ) {
			$total = $membership_level->price;
		} else {
			$total = 0;
		}

		if ( $discounts ) {
			$total -= $this->get_total_discounts( $total, true );
		}

		if ( $fees ) {
			$total += $this->get_total_fees( $total, true );
		}

		if ( 0 > $total ) {
			$total = 0;
		}

		$total = round( $total, rcp_currency_decimal_filter() );

		return apply_filters( 'rcp_registration_get_recurring_total', floatval( $total ), $this );

	}


}