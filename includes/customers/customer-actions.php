<?php
/**
 * Customer Actions
 *
 * @package   restrict-content-pro
 * @copyright Copyright (c) 2019, Restrict Content Pro team
 * @license   GPL2+
 * @since     3.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Set the customer's `has_trialed` flag when their trialing membership is set to active.
 *
 * @param string $old_status    Previous membership status.
 * @param int    $membership_id ID of the membership that was just set to active.
 *
 * @since 3.1.2
 * @return void
 */
function rcp_set_customer_trialing_flag( $old_status, $membership_id ) {

	$membership = rcp_get_membership( $membership_id );

	if ( $membership && $membership->is_trialing() ) {
		$membership->get_customer()->update( array(
			'has_trialed' => true
		) );

		$membership->get_customer()->add_note( sprintf( __( 'Free trial used via membership "%s" (#%d).', 'rcp' ), $membership->get_membership_level_name(), $membership_id ) );
	}

}
add_action( 'rcp_transition_membership_status_active', 'rcp_set_customer_trialing_flag', 10, 2 );
