<?php
/**
 * Cron Functions
 *
 * Schedules events.
 *
 * @package     Restrict Content Pro
 * @subpackage  Cron Functions
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

/**
 * Set up the following cron job events:
 *
 * Daily: Expired users check
 * @see rcp_check_for_expired_users()
 *
 * Daily: Send expiring soon notices
 * @see rcp_check_for_soon_to_expire_users()
 *
 * Daily: Check and update member counts
 * @see rcp_check_member_counts()
 *
 * @return void
 */
function rcp_setup_cron_jobs() {

	if ( ! wp_next_scheduled( 'rcp_expired_users_check' ) ) {
		wp_schedule_event( current_time( 'timestamp' ), 'daily', 'rcp_expired_users_check' );
	}

	if ( ! wp_next_scheduled( 'rcp_send_expiring_soon_notice' ) ) {
		wp_schedule_event( current_time( 'timestamp' ), 'daily', 'rcp_send_expiring_soon_notice' );
	}

	if ( ! wp_next_scheduled( 'rcp_check_member_counts' ) ) {
		wp_schedule_event( current_time( 'timestamp' ), 'daily', 'rcp_check_member_counts' );
	}

	if ( ! wp_next_scheduled( 'rcp_mark_abandoned_payments' ) ) {
		wp_schedule_event( current_time( 'timestamp' ), 'daily', 'rcp_mark_abandoned_payments' );
	}
}
add_action('wp', 'rcp_setup_cron_jobs');

/**
 * Check for expired users
 *
 * Runs each day and checks for expired users. If their account has expired, their status
 * is updated to "expired" and, based on settings, they may receive an email.
 *
 * @see rcp_email_on_expiration()
 *
 * @return void
 */
function rcp_check_for_expired_users() {

	rcp_log( 'Starting rcp_check_for_expired_users() cron job.' );

	global $wpdb;

	$current_time = date( 'Y-m-d H:i:s', strtotime( '-1 day', current_time( 'timestamp' ) ) );

	$query = "SELECT ID FROM $wpdb->users
		INNER JOIN $wpdb->usermeta ON ($wpdb->users.ID = $wpdb->usermeta.user_id)
		INNER JOIN $wpdb->usermeta AS mt1 ON ($wpdb->users.ID = mt1.user_id)
		INNER JOIN $wpdb->usermeta AS mt2 ON ($wpdb->users.ID = mt2.user_id)
		WHERE 1=1 AND ( ($wpdb->usermeta.meta_key = 'rcp_expiration'
			AND CAST($wpdb->usermeta.meta_value AS DATETIME) < '$current_time')
			AND  (mt1.meta_key = 'rcp_expiration'
				AND CAST(mt1.meta_value AS CHAR) != 'none')
			AND  (mt2.meta_key = 'rcp_status'
				AND CAST(mt2.meta_value AS CHAR) IN('active', 'cancelled')) )
		ORDER BY user_login ASC LIMIT 9999";

	$query = apply_filters( 'rcp_check_for_expired_users_query_filter', $query );

	$expired_members = $wpdb->get_results( $query );
	$expired_members = wp_list_pluck( $expired_members, 'ID' );
	$expired_members = apply_filters( 'rcp_check_for_expired_users_members_filter', $expired_members );

	if( $expired_members ) {
		foreach( $expired_members as $key => $member_id ) {

			$expiration_date = rcp_get_expiration_timestamp( $member_id );
			if( $expiration_date && strtotime( '-2 days', current_time( 'timestamp' ) ) > $expiration_date ) {
				rcp_log( sprintf( 'Expiring user #%d via cron job.', $member_id ) );
				rcp_set_status( $member_id, 'expired' );
			}
		}
	} else {
		rcp_log( 'No expired users found.' );
	}
}
//add_action( 'admin_init', 'rcp_check_for_expired_users' );
add_action( 'rcp_expired_users_check', 'rcp_check_for_expired_users' );

/**
 * Check for soon-to-expire users
 *
 * Runs each day and checks for members that are soon to expire. Based on settings, each
 * member gets sent an expiry notice email.
 *
 * @uses rcp_get_renewal_reminder_period()
 * @uses rcp_email_expiring_notice()
 *
 * @return void
 */
function rcp_check_for_soon_to_expire_users() {

	rcp_log( 'Starting rcp_check_for_soon_to_expire_users() cron job.' );

	$reminders = new RCP_Reminders();
	$reminders->send_reminders();

}
add_action( 'rcp_send_expiring_soon_notice', 'rcp_check_for_soon_to_expire_users' );

/**
 * Counts the active members on a subscription level to ensure counts are accurate.
 *
 * Runs once per day
 *
 * @since 2.6
 *
 * @return void
 */
function rcp_check_member_counts() {

	rcp_log( 'Starting rcp_check_member_counts() cron job.' );

	global $rcp_levels_db;
	$levels = $rcp_levels_db->get_levels();

	if( ! $levels ) {
		return;
	}

	$statuses = array( 'active', 'pending', 'cancelled', 'expired', 'free' );

	foreach( $levels as $level ) {

		foreach( $statuses as $status ) {

			$count = rcp_count_members( $level->id, $status );
			$key   = $level->id . '_' . $status . '_member_count';
			$rcp_levels_db->update_meta( $level->id, $key, $count );

		}

	}
}
add_action( 'rcp_check_member_counts', 'rcp_check_member_counts' );

/**
 * Find pending payments that are more than a week old and mark them as abandoned.
 *
 * @since 2.9
 * @return void
 */
function rcp_mark_abandoned_payments() {

	rcp_log( 'Starting rcp_mark_abandoned_payments() cron job.' );

	/**
	 * @var RCP_Payments $rcp_payments_db
	 */
	global $rcp_payments_db;

	$args = array(
		'fields' => 'id',
		'number' => 9999,
		'status' => 'pending',
		'date'   => array(
			'end' => date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) )
		)
	);

	$payments = $rcp_payments_db->get_payments( $args );

	if ( $payments ) {
		foreach ( $payments as $payment ) {
			$rcp_payments_db->update( $payment->id, array( 'status' => 'abandoned' ) );
		}
	}

}
add_action( 'rcp_mark_abandoned_payments', 'rcp_mark_abandoned_payments' );
